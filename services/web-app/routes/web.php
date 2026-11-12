<?php

use App\Http\Controllers\Web\AdminWebController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\WorkspaceController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => auth()->check() ? redirect()->route('chat') : redirect()->route('login'));
Route::get('/privacy', fn () => Inertia::render('Privacy'))->name('privacy');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
    Route::post('/login/2fa-cancel', [AuthController::class, 'cancelTwoFactor'])->name('login.2fa.cancel');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/profile', [AuthController::class, 'profile'])->name('profile');
    Route::put('/profile/password', [AuthController::class, 'updatePassword'])->name('profile.password');
    Route::post('/profile/2fa/setup', [AuthController::class, 'setupTwoFactor'])->name('profile.2fa.setup');
    Route::post('/profile/2fa/confirm', [AuthController::class, 'confirmTwoFactor'])->name('profile.2fa.confirm');
    Route::delete('/profile/2fa', [AuthController::class, 'disableTwoFactor'])->name('profile.2fa.disable');

    // Reader and above
    Route::get('/chat', [WorkspaceController::class, 'chat'])->name('chat');

    // Knowledge Manager and Admin
    Route::middleware('role:admin,manager')->group(function (): void {
        Route::get('/dashboard', [WorkspaceController::class, 'dashboard'])->name('dashboard');
        Route::get('/documents', [WorkspaceController::class, 'documents'])->name('documents.index');
        Route::get('/documents/{id}', [WorkspaceController::class, 'documentShow'])->name('documents.show');
        Route::get('/collections', [WorkspaceController::class, 'collections'])->name('collections.index');
        Route::post('/collections/{id}/access', [WorkspaceController::class, 'updateCollectionAccess'])->name('collections.access');
        Route::get('/ingestion', [WorkspaceController::class, 'ingestionQueue'])->name('ingestion.index');
        Route::post('/ingestion/{id}/retry', [WorkspaceController::class, 'retryIngestion'])->name('ingestion.retry');
        Route::get('/playground', [WorkspaceController::class, 'playground'])->name('playground');
        Route::get('/query-logs', [WorkspaceController::class, 'queryLogs'])->name('query-logs.index');
        Route::get('/evaluation', [WorkspaceController::class, 'evaluation'])->name('evaluation.index');
        Route::post('/evaluation/sets', [WorkspaceController::class, 'storeGoldenSet'])->name('evaluation.sets.store');
        Route::post('/evaluation/cases', [WorkspaceController::class, 'storeGoldenCase'])->name('evaluation.cases.store');
        Route::post('/evaluation/sets/{id}/run', [WorkspaceController::class, 'runEvaluation'])->name('evaluation.run');
    });

    // Admin only
    Route::middleware('role:admin')->prefix('admin')->group(function (): void {
        Route::get('/settings', [AdminWebController::class, 'settings'])->name('admin.settings');
        Route::put('/settings', [AdminWebController::class, 'updateSettings'])->name('admin.settings.update');
        Route::post('/settings/rollback/{id}', [AdminWebController::class, 'rollbackPromptVersion'])->name('admin.settings.rollback');
        Route::post('/settings/purge-logs', [AdminWebController::class, 'purgeOldQueryText'])->name('admin.settings.purge');

        Route::get('/users', [AdminWebController::class, 'users'])->name('admin.users');
        Route::post('/users', [AdminWebController::class, 'storeUser'])->name('admin.users.store');
        Route::patch('/users/{id}', [AdminWebController::class, 'updateUser'])->name('admin.users.update');

        Route::get('/api-keys', [AdminWebController::class, 'apiKeys'])->name('admin.api-keys');
        Route::post('/api-keys', [AdminWebController::class, 'storeApiKey'])->name('admin.api-keys.store');
        Route::delete('/api-keys/{id}', [AdminWebController::class, 'revokeApiKey'])->name('admin.api-keys.revoke');

        Route::get('/health', [AdminWebController::class, 'systemHealth'])->name('admin.health');
        Route::get('/audit-log', [AdminWebController::class, 'auditLog'])->name('admin.audit');
    });
});
