<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\IngestionJob;
use App\Services\AccessResolver;
use App\Services\AuditLogger;
use App\Services\RagCoreClient;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index(Request $request, AccessResolver $access): JsonResponse
    {
        $permitted = $access->readableCollectionIds($request->user());
        $query = Document::query()->whereIn('collection_id', $permitted);

        if ($request->filled('collection_id')) {
            $query->where('collection_id', $request->string('collection_id')->toString());
        }

        return response()->json(
            $query->orderByDesc('updated_at')->paginate((int) $request->integer('per_page', 25))
        );
    }

    public function store(
        Request $request,
        AccessResolver $access,
        RagCoreClient $rag,
        AuditLogger $audit,
    ): JsonResponse {
        $maxKb = (int) config('rag.max_upload_mb', 50) * 1024;
        $validated = $request->validate([
            'collection_id' => ['required', 'uuid', 'exists:collections,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'document_id' => ['nullable', 'uuid'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'effective_date' => ['nullable', 'date'],
            'file' => ['required', 'file', "max:{$maxKb}"],
        ]);

        if (! $access->canWriteCollection($request->user(), $validated['collection_id'])) {
            return ApiError::response($request, 'forbidden', 'Write permission denied for collection.', 403);
        }

        $file = $request->file('file');
        $fileHash = hash_file('sha256', $file->getRealPath());
        $duplicate = Document::query()
            ->where('collection_id', $validated['collection_id'])
            ->where('file_hash', $fileHash)
            ->first();

        $document = null;
        if (! empty($validated['document_id'])) {
            $document = Document::query()->find($validated['document_id']);
            if (! $document || $document->collection_id !== $validated['collection_id']) {
                return ApiError::response($request, 'not_found', 'Target document not found in collection.', 404);
            }
            $nextVersion = $document->latest_version + 1;
            $document->fill([
                'title' => $validated['title'] ?? $document->title,
                'tags' => $validated['tags'] ?? $document->tags,
                'effective_date' => $validated['effective_date'] ?? $document->effective_date,
                'latest_version' => $nextVersion,
                'file_hash' => $fileHash,
            ])->save();
        } else {
            $nextVersion = 1;
            $document = Document::query()->create([
                'collection_id' => $validated['collection_id'],
                'owner_id' => $request->user()->id,
                'title' => $validated['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'tags' => $validated['tags'] ?? [],
                'effective_date' => $validated['effective_date'] ?? null,
                'active_version' => 0,
                'latest_version' => 1,
                'file_hash' => $fileHash,
            ]);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $relPath = "{$document->collection_id}/{$document->id}/v{$nextVersion}.{$ext}";
        $absDir = rtrim((string) config('rag.uploads_dir'), '/\\').DIRECTORY_SEPARATOR.dirname($relPath);
        File::ensureDirectoryExists($absDir);
        $file->move($absDir, basename($relPath));

        $version = DocumentVersion::query()->create([
            'document_id' => $document->id,
            'version' => $nextVersion,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => File::size($absDir.DIRECTORY_SEPARATOR.basename($relPath)),
            'file_hash' => $fileHash,
            'storage_path' => $relPath,
            'status' => 'queued',
        ]);

        $job = IngestionJob::query()->create([
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'status' => 'queued',
        ]);

        $ragResp = $rag->enqueueIngest([
            'job_id' => $job->id,
            'document_id' => $document->id,
            'collection_id' => $document->collection_id,
            'title' => $document->title,
            'version' => $nextVersion,
            'filename' => $version->filename,
            'storage_path' => $relPath,
            'tags' => $document->tags ?? [],
            'effective_ts' => $document->effective_date?->timestamp,
        ]);

        if (! $ragResp->successful()) {
            $job->update(['status' => 'failed', 'error_message' => 'Failed to enqueue ingestion job on rag-core.']);
            return ApiError::response($request, 'upstream_error', 'Failed to enqueue ingestion job.', 502);
        }

        $audit->record($request, 'document.upload', 'document', $document->id, [
            'version' => $nextVersion,
            'filename' => $version->filename,
            'duplicate_of' => $duplicate?->id,
        ]);

        return response()->json([
            'document' => $document->fresh(),
            'version' => $version,
            'ingestion_job' => $job,
            'duplicate_warning' => $duplicate !== null && $duplicate->id !== $document->id
                ? ['existing_document_id' => $duplicate->id, 'title' => $duplicate->title]
                : null,
        ], 202);
    }

    public function show(Request $request, string $id, AccessResolver $access): JsonResponse
    {
        $doc = Document::query()->with(['versions', 'ingestionJobs'])->find($id);
        if (! $doc || $access->readableCollectionIds($request->user(), [$doc->collection_id]) === []) {
            return ApiError::response($request, 'not_found', 'Document not found.', 404);
        }

        return response()->json($doc);
    }

    public function update(Request $request, string $id, AccessResolver $access, AuditLogger $audit): JsonResponse
    {
        $doc = Document::query()->find($id);
        if (! $doc || ! $access->canWriteCollection($request->user(), $doc->collection_id)) {
            return ApiError::response($request, 'forbidden', 'Document not found or write denied.', 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'effective_date' => ['nullable', 'date'],
        ]);

        $doc->fill($validated)->save();
        $audit->record($request, 'document.update', 'document', $doc->id, $validated);

        return response()->json($doc);
    }

    public function destroy(
        Request $request,
        string $id,
        AccessResolver $access,
        RagCoreClient $rag,
        AuditLogger $audit,
    ): JsonResponse {
        $doc = Document::query()->find($id);
        if (! $doc || ! $access->canWriteCollection($request->user(), $doc->collection_id)) {
            return ApiError::response($request, 'forbidden', 'Document not found or write denied.', 403);
        }

        $rag->deleteDocument($doc->id)->throw();
        $doc->delete();
        $audit->record($request, 'document.delete', 'document', $id);

        return response()->json(['deleted' => true]);
    }

    public function reindex(
        Request $request,
        string $id,
        AccessResolver $access,
        RagCoreClient $rag,
        AuditLogger $audit,
    ): JsonResponse {
        $doc = Document::query()->find($id);
        if (! $doc || ! $access->canWriteCollection($request->user(), $doc->collection_id)) {
            return ApiError::response($request, 'forbidden', 'Document not found or write denied.', 403);
        }

        $version = $doc->versions()->first();
        if (! $version) {
            return ApiError::response($request, 'not_found', 'No document version available to reindex.', 404);
        }

        $job = IngestionJob::query()->create([
            'document_id' => $doc->id,
            'document_version_id' => $version->id,
            'status' => 'queued',
        ]);

        $rag->enqueueIngest([
            'job_id' => $job->id,
            'document_id' => $doc->id,
            'collection_id' => $doc->collection_id,
            'title' => $doc->title,
            'version' => $version->version,
            'filename' => $version->filename,
            'storage_path' => $version->storage_path,
            'tags' => $doc->tags ?? [],
            'effective_ts' => $doc->effective_date?->timestamp,
        ])->throw();

        $audit->record($request, 'document.reindex', 'document', $doc->id, ['job_id' => $job->id]);

        return response()->json(['ingestion_job' => $job], 202);
    }

    public function chunks(Request $request, string $id, AccessResolver $access, RagCoreClient $rag): JsonResponse
    {
        $doc = Document::query()->find($id);
        if (! $doc || $access->readableCollectionIds($request->user(), [$doc->collection_id]) === []) {
            return ApiError::response($request, 'not_found', 'Document not found.', 404);
        }

        $version = $request->filled('version') ? (int) $request->integer('version') : null;
        $resp = $rag->getDocumentChunks($doc->id, $version);
        if (! $resp->successful()) {
            return ApiError::response($request, 'upstream_error', 'Unable to fetch chunks from rag-core.', 502);
        }

        return response()->json($resp->json());
    }
}
