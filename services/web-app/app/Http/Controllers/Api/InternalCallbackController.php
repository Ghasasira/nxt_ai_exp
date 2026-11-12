<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\IngestionJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalCallbackController extends Controller
{
    public function ingestionStatus(Request $request): JsonResponse
    {
        $signature = $request->header('X-Callback-Signature', '');
        $rawBody = $request->getContent();
        $expected = 'sha256='.hash_hmac('sha256', $rawBody, (string) config('rag.callback_secret'));

        if (! hash_equals($expected, $signature)) {
            return response()->json(['code' => 'unauthorized', 'message' => 'Invalid callback signature.'], 401);
        }

        $data = json_decode($rawBody, true);
        if (! is_array($data) || empty($data['job_id']) || empty($data['status'])) {
            return response()->json(['code' => 'bad_request', 'message' => 'Malformed callback payload.'], 400);
        }

        $job = IngestionJob::query()->find($data['job_id']);
        if (! $job) {
            return response()->json(['code' => 'not_found', 'message' => 'Ingestion job not found.'], 404);
        }

        $status = (string) $data['status'];
        $updates = ['status' => $status];

        if (in_array($status, ['parsing', 'embedding'], true) && $job->started_at === null) {
            $updates['started_at'] = now();
            $updates['attempts'] = $job->attempts + 1;
        }

        if ($status === 'failed') {
            $updates['error_message'] = (string) ($data['error_message'] ?? 'Unknown ingestion failure.');
            $updates['finished_at'] = now();
        }

        if ($status === 'indexed') {
            $updates['finished_at'] = now();
            $updates['error_message'] = null;
        }

        $job->forceFill($updates)->save();

        /** @var DocumentVersion|null $version */
        $version = $job->documentVersion;
        if ($version) {
            $vUpdates = ['status' => $status];
            if (isset($data['chunk_count'])) {
                $vUpdates['chunk_count'] = (int) $data['chunk_count'];
            }
            if (isset($data['embedding_model'])) {
                $vUpdates['embedding_model'] = (string) $data['embedding_model'];
            }
            $version->forceFill($vUpdates)->save();
        }

        if ($status === 'indexed' && isset($data['version'])) {
            Document::query()
                ->where('id', $job->document_id)
                ->update(['active_version' => (int) $data['version']]);
        }

        return response()->json(['ok' => true]);
    }
}
