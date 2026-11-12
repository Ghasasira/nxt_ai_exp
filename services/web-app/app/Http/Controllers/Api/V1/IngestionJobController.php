<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\IngestionJob;
use App\Services\AccessResolver;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngestionJobController extends Controller
{
    public function index(Request $request, AccessResolver $access): JsonResponse
    {
        $permitted = $access->readableCollectionIds($request->user());
        $jobs = IngestionJob::query()
            ->whereHas('document', fn ($q) => $q->whereIn('collection_id', $permitted))
            ->with(['document:id,title,collection_id', 'documentVersion:id,version,filename'])
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json($jobs);
    }

    public function show(Request $request, string $id, AccessResolver $access): JsonResponse
    {
        $job = IngestionJob::query()
            ->with(['document', 'documentVersion'])
            ->find($id);

        if (! $job || $access->readableCollectionIds($request->user(), [$job->document->collection_id]) === []) {
            return ApiError::response($request, 'not_found', 'Ingestion job not found.', 404);
        }

        return response()->json($job);
    }
}
