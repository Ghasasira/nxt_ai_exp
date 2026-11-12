<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Services\AccessResolver;
use App\Services\AuditLogger;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CollectionController extends Controller
{
    public function index(Request $request, AccessResolver $access): JsonResponse
    {
        $permitted = $access->readableCollectionIds($request->user());
        $collections = Collection::query()
            ->whereIn('id', $permitted)
            ->orderBy('name')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json($collections);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', 'unique:collections,slug'],
            'description' => ['nullable', 'string', 'max:1000'],
            'allow_cloud_generation' => ['boolean'],
        ]);

        $slug = $validated['slug'] ?? Str::slug($validated['name']).'-'.Str::lower(Str::random(4));
        $collection = Collection::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'allow_cloud_generation' => $validated['allow_cloud_generation'] ?? true,
        ]);

        $audit->record($request, 'collection.create', 'collection', $collection->id, ['name' => $collection->name]);

        return response()->json($collection, 201);
    }

    public function show(Request $request, string $id, AccessResolver $access): JsonResponse
    {
        $permitted = $access->readableCollectionIds($request->user(), [$id]);
        if ($permitted === []) {
            return ApiError::response($request, 'not_found', 'Collection not found or access denied.', 404);
        }

        return response()->json(Collection::query()->findOrFail($id));
    }

    public function update(Request $request, string $id, AccessResolver $access, AuditLogger $audit): JsonResponse
    {
        if (! $access->canWriteCollection($request->user(), $id)) {
            return ApiError::response($request, 'forbidden', 'Write permission denied for collection.', 403);
        }

        $collection = Collection::query()->find($id);
        if (! $collection) {
            return ApiError::response($request, 'not_found', 'Collection not found.', 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'allow_cloud_generation' => ['sometimes', 'boolean'],
        ]);

        $collection->fill($validated)->save();
        $audit->record($request, 'collection.update', 'collection', $collection->id, $validated);

        return response()->json($collection);
    }

    public function destroy(Request $request, string $id, AccessResolver $access, AuditLogger $audit): JsonResponse
    {
        if (! $access->canWriteCollection($request->user(), $id)) {
            return ApiError::response($request, 'forbidden', 'Write permission denied for collection.', 403);
        }

        $collection = Collection::query()->find($id);
        if (! $collection) {
            return ApiError::response($request, 'not_found', 'Collection not found.', 404);
        }

        $collection->delete();
        $audit->record($request, 'collection.delete', 'collection', $id);

        return response()->json(['deleted' => true]);
    }
}
