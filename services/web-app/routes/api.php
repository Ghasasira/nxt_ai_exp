<?php

use App\Http\Controllers\Api\InternalCallbackController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\IngestionJobController;
use Illuminate\Support\Facades\Route;

// Signed internal callbacks from rag-core / ingest-worker
Route::post('/internal/ingestion-callback', [InternalCallbackController::class, 'ingestionStatus']);

Route::prefix('v1')->group(function (): void {
    // Unauthenticated liveness & readiness (SRS §5.2)
    Route::get('/health', [HealthController::class, 'health']);
    Route::get('/ready', [HealthController::class, 'ready']);

    // Chat & Conversations (scope: chat)
    Route::middleware(['api.auth:chat', 'throttle:rag-api'])->group(function (): void {
        Route::post('/chat', [ChatController::class, 'chat']);
        Route::get('/conversations', [ChatController::class, 'conversations']);
        Route::get('/conversations/{id}', [ChatController::class, 'showConversation']);
        Route::delete('/conversations/{id}', [ChatController::class, 'deleteConversation']);
        Route::post('/feedback', [ChatController::class, 'feedback']);
    });

    // Retrieval-only search (scope: search)
    Route::middleware(['api.auth:search', 'throttle:rag-api'])->group(function (): void {
        Route::post('/search', [ChatController::class, 'search']);
    });

    // Collections, Documents & Ingestion Jobs (scope: documents)
    Route::middleware(['api.auth:documents', 'throttle:rag-api'])->group(function (): void {
        Route::get('/collections', [CollectionController::class, 'index']);
        Route::post('/collections', [CollectionController::class, 'store'])->middleware('idempotent');
        Route::get('/collections/{id}', [CollectionController::class, 'show']);
        Route::patch('/collections/{id}', [CollectionController::class, 'update']);
        Route::delete('/collections/{id}', [CollectionController::class, 'destroy']);

        Route::get('/documents', [DocumentController::class, 'index']);
        Route::post('/documents', [DocumentController::class, 'store'])->middleware('idempotent');
        Route::get('/documents/{id}', [DocumentController::class, 'show']);
        Route::patch('/documents/{id}', [DocumentController::class, 'update']);
        Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);
        Route::post('/documents/{id}/reindex', [DocumentController::class, 'reindex'])->middleware('idempotent');
        Route::get('/documents/{id}/chunks', [DocumentController::class, 'chunks']);

        Route::get('/ingestion-jobs', [IngestionJobController::class, 'index']);
        Route::get('/ingestion-jobs/{id}', [IngestionJobController::class, 'show']);
    });

    // Admin endpoints (scope: admin)
    Route::middleware(['api.auth:admin', 'throttle:rag-api'])->group(function (): void {
        Route::get('/api-keys', [AdminController::class, 'listApiKeys']);
        Route::post('/api-keys', [AdminController::class, 'createApiKey'])->middleware('idempotent');
        Route::delete('/api-keys/{id}', [AdminController::class, 'revokeApiKey']);
        Route::get('/usage', [AdminController::class, 'usage']);
        Route::get('/settings', [AdminController::class, 'getSettings']);
        Route::put('/settings', [AdminController::class, 'updateSettings']);
    });
});
