<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Collection;
use App\Models\IngestionJob;
use App\Models\PromptVersion;
use App\Models\QueryLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RagCoreClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminWebController extends Controller
{
    public function settings(): Response
    {
        $runtime = Setting::query()->find('rag_runtime')?->value ?? [
            'model_id' => config('rag.default_model'),
            'temperature' => 0.0,
            'thinking_budget' => 0,
            'top_k' => config('rag.default_top_k'),
            'similarity_threshold' => config('rag.default_threshold'),
            'reranker_enabled' => false,
        ];
        $cap = Setting::query()->find('monthly_spend_cap_usd')?->value ?? [
            'amount' => config('rag.monthly_spend_cap_usd'),
        ];
        $retention = Setting::query()->find('query_log_retention')?->value ?? ['days' => 90];
        $versions = PromptVersion::query()->orderByDesc('created_at')->limit(15)->get();

        return Inertia::render('Admin/Settings', [
            'runtime' => $runtime,
            'monthlySpendCapUsd' => $cap['amount'],
            'retentionDays' => $retention['days'] ?? 90,
            'promptVersions' => $versions,
        ]);
    }

    public function updateSettings(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'model_id' => ['required', 'string', 'max:100'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:1'],
            'thinking_budget' => ['required', 'integer', 'min:0', 'max:8192'],
            'top_k' => ['required', 'integer', 'min:1', 'max:50'],
            'similarity_threshold' => ['required', 'numeric', 'min:0', 'max:1'],
            'reranker_enabled' => ['required', 'boolean'],
            'monthly_spend_cap_usd' => ['required', 'numeric', 'min:0'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $runtime = [
            'model_id' => $validated['model_id'],
            'temperature' => (float) $validated['temperature'],
            'thinking_budget' => (int) $validated['thinking_budget'],
            'top_k' => (int) $validated['top_k'],
            'similarity_threshold' => (float) $validated['similarity_threshold'],
            'reranker_enabled' => (bool) $validated['reranker_enabled'],
        ];

        Setting::query()->updateOrCreate(['key' => 'rag_runtime'], ['value' => $runtime, 'updated_by' => $request->user()->id]);
        Setting::query()->updateOrCreate(
            ['key' => 'monthly_spend_cap_usd'],
            ['value' => ['amount' => (float) $validated['monthly_spend_cap_usd']], 'updated_by' => $request->user()->id]
        );
        Setting::query()->updateOrCreate(
            ['key' => 'query_log_retention'],
            ['value' => ['days' => (int) $validated['retention_days']], 'updated_by' => $request->user()->id]
        );

        PromptVersion::query()->update(['is_active' => false]);
        PromptVersion::query()->create([
            'version_label' => 'v-'.now()->format('YmdHis').'-'.Str::lower(Str::random(4)),
            'system_prompt' => 'default',
            ...$runtime,
            'created_by' => $request->user()->id,
            'is_active' => true,
        ]);

        $audit->record($request, 'settings.update', 'setting', 'rag_runtime', $validated);

        return back()->with('success', 'Settings saved and version snapshot created.');
    }

    public function rollbackPromptVersion(Request $request, int $id, AuditLogger $audit): RedirectResponse
    {
        $pv = PromptVersion::query()->findOrFail($id);
        $runtime = [
            'model_id' => $pv->model_id,
            'temperature' => $pv->temperature,
            'thinking_budget' => $pv->thinking_budget,
            'top_k' => $pv->top_k,
            'similarity_threshold' => $pv->similarity_threshold,
            'reranker_enabled' => $pv->reranker_enabled,
        ];

        Setting::query()->updateOrCreate(['key' => 'rag_runtime'], ['value' => $runtime, 'updated_by' => $request->user()->id]);
        PromptVersion::query()->update(['is_active' => false]);
        $pv->update(['is_active' => true]);
        $audit->record($request, 'settings.rollback', 'prompt_version', (string) $pv->id);

        return back()->with('success', "Rolled back settings to {$pv->version_label}.");
    }

    public function purgeOldQueryText(Request $request, AuditLogger $audit): RedirectResponse
    {
        $days = (int) (Setting::query()->find('query_log_retention')?->value['days'] ?? 90);
        $cutoff = now()->subDays($days);
        $affected = QueryLog::query()
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('query_text')
            ->update(['query_text' => null, 'answer_text' => null]);

        $audit->record($request, 'query_logs.purge_text', 'query_log', null, ['days' => $days, 'affected' => $affected]);

        return back()->with('success', "Redacted query/answer text on {$affected} logs older than {$days} days.");
    }

    public function users(): Response
    {
        return Inertia::render('Admin/Users', [
            'users' => User::query()->with('collectionAccesses.collection:id,name')->orderBy('name')->get(),
            'collections' => Collection::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function storeUser(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10'],
            'role' => ['required', 'in:admin,manager,reader'],
            'daily_query_quota' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = User::query()->create($validated);
        $audit->record($request, 'user.create', 'user', (string) $user->id, ['role' => $user->role]);

        return back()->with('success', 'User created.');
    }

    public function updateUser(Request $request, int $id, AuditLogger $audit): RedirectResponse
    {
        $user = User::query()->findOrFail($id);
        $validated = $request->validate([
            'role' => ['required', 'in:admin,manager,reader'],
            'daily_query_quota' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);

        $user->update($validated);
        $audit->record($request, 'user.update', 'user', (string) $user->id, $validated);

        return back()->with('success', 'User updated.');
    }

    public function apiKeys(): Response
    {
        return Inertia::render('Admin/ApiKeys', [
            'apiKeys' => ApiKey::query()->with('user:id,name,email')->orderByDesc('created_at')->get(),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function storeApiKey(Request $request, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'user_id' => ['nullable', 'exists:users,id'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['in:chat,search,documents,admin,*'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $plain = 'nxt_'.Str::random(40);
        $key = ApiKey::query()->create([
            'user_id' => $validated['user_id'] ?? $request->user()->id,
            'name' => $validated['name'],
            'key_prefix' => substr($plain, 0, 12),
            'key_hash' => hash('sha256', $plain),
            'scopes' => $validated['scopes'],
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        $audit->record($request, 'api_key.create', 'api_key', $key->id, ['name' => $key->name]);

        return back()
            ->with('success', 'API key created. Copy it now — it will not be shown again.')
            ->with('plaintext_token', $plain);
    }

    public function revokeApiKey(Request $request, string $id, AuditLogger $audit): RedirectResponse
    {
        $key = ApiKey::query()->findOrFail($id);
        $key->update(['revoked_at' => now()]);
        $audit->record($request, 'api_key.revoke', 'api_key', $key->id);

        return back()->with('success', 'API key revoked.');
    }

    public function systemHealth(RagCoreClient $rag): Response
    {
        $dbOk = false;
        $redisOk = false;
        $ragStatus = ['ok' => false, 'detail' => null];

        try {
            DB::select('SELECT 1');
            $dbOk = true;
        } catch (Throwable) {
        }

        try {
            Redis::connection()->ping();
            $redisOk = true;
        } catch (Throwable) {
        }

        try {
            $resp = $rag->ready();
            $ragStatus = ['ok' => $resp->successful(), 'detail' => $resp->json()];
        } catch (Throwable $e) {
            $ragStatus = ['ok' => false, 'detail' => $e->getMessage()];
        }

        $diskPath = PHP_OS_FAMILY === 'Windows' ? base_path() : '/';
        $diskFree = @disk_free_space($diskPath) ?: 0;
        $diskTotal = @disk_total_space($diskPath) ?: 1;

        $cpuTempC = null;
        if (is_readable('/sys/class/thermal/thermal_zone0/temp')) {
            $raw = trim((string) @file_get_contents('/sys/class/thermal/thermal_zone0/temp'));
            if (is_numeric($raw)) {
                $cpuTempC = round(((float) $raw) / 1000.0, 1);
            }
        }

        $lastBackup = null;
        $backupStatusFile = (string) config('rag.backup_status_file');
        if ($backupStatusFile !== '' && is_readable($backupStatusFile)) {
            $decoded = json_decode((string) file_get_contents($backupStatusFile), true);
            if (is_array($decoded)) {
                $lastBackup = $decoded;
            }
        }

        return Inertia::render('Admin/SystemHealth', [
            'telemetry' => [
                'postgres_ok' => $dbOk,
                'redis_ok' => $redisOk,
                'rag_core' => $ragStatus,
                'queue_depth' => IngestionJob::query()->whereIn('status', ['queued', 'parsing', 'embedding'])->count(),
                'failed_jobs' => IngestionJob::query()->where('status', 'failed')->count(),
                'disk_used_gb' => round(($diskTotal - $diskFree) / 1_073_741_824, 2),
                'disk_total_gb' => round($diskTotal / 1_073_741_824, 2),
                'cpu_temp_c' => $cpuTempC,
                'last_backup' => $lastBackup,
            ],
        ]);
    }

    public function auditLog(Request $request): Response
    {
        $query = AuditLog::query();
        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->string('action')->toString().'%');
        }

        return Inertia::render('Admin/AuditLog', [
            'logs' => $query->orderByDesc('created_at')->paginate(30)->withQueryString(),
            'actionFilter' => $request->string('action')->toString(),
        ]);
    }
}
