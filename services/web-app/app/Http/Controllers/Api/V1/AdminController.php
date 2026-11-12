<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\QueryLog;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function listApiKeys(Request $request): JsonResponse
    {
        $keys = ApiKey::query()
            ->with('user:id,name,email,role')
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 25));

        return response()->json($keys);
    }

    public function createApiKey(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:chat,search,documents,admin,*'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $plainToken = 'nxt_'.Str::random(40);
        $key = ApiKey::query()->create([
            'user_id' => $validated['user_id'] ?? $request->user()->id,
            'name' => $validated['name'],
            'key_prefix' => substr($plainToken, 0, 12),
            'key_hash' => hash('sha256', $plainToken),
            'scopes' => $validated['scopes'],
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        $audit->record($request, 'api_key.create', 'api_key', $key->id, ['name' => $key->name, 'scopes' => $key->scopes]);

        return response()->json([
            'api_key' => $key,
            'plaintext_token' => $plainToken,
        ], 201);
    }

    public function revokeApiKey(Request $request, string $id, AuditLogger $audit): JsonResponse
    {
        $key = ApiKey::query()->find($id);
        if (! $key) {
            return ApiError::response($request, 'not_found', 'API key not found.', 404);
        }

        $key->forceFill(['revoked_at' => now()])->save();
        $audit->record($request, 'api_key.revoke', 'api_key', $key->id);

        return response()->json(['revoked' => true]);
    }

    public function usage(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->integer('days', 30)));
        $since = now()->subDays($days)->startOfDay();

        $summary = QueryLog::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as total_queries')
            ->selectRaw('SUM(input_tokens) as total_input_tokens')
            ->selectRaw('SUM(output_tokens) as total_output_tokens')
            ->selectRaw('SUM(estimated_cost_usd) as total_cost_usd')
            ->selectRaw('AVG(latency_ms) as avg_latency_ms')
            ->first();

        $byUser = QueryLog::query()
            ->where('created_at', '>=', $since)
            ->select('user_id')
            ->selectRaw('COUNT(*) as queries, SUM(estimated_cost_usd) as cost_usd')
            ->groupBy('user_id')
            ->get();

        return response()->json([
            'window_days' => $days,
            'summary' => $summary,
            'by_user' => $byUser,
        ]);
    }

    public function getSettings(): JsonResponse
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

        return response()->json([
            'rag_runtime' => $runtime,
            'monthly_spend_cap_usd' => $cap['amount'],
        ]);
    }

    public function updateSettings(Request $request, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'model_id' => ['sometimes', 'string', 'max:100'],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'thinking_budget' => ['sometimes', 'integer', 'min:0', 'max:8192'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'similarity_threshold' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'reranker_enabled' => ['sometimes', 'boolean'],
            'monthly_spend_cap_usd' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $current = Setting::query()->find('rag_runtime')?->value ?? [];
        $runtimeUpdate = array_merge($current, collect($validated)->except('monthly_spend_cap_usd')->all());

        Setting::query()->updateOrCreate(
            ['key' => 'rag_runtime'],
            ['value' => $runtimeUpdate, 'updated_by' => $request->user()->id]
        );

        if (array_key_exists('monthly_spend_cap_usd', $validated)) {
            Setting::query()->updateOrCreate(
                ['key' => 'monthly_spend_cap_usd'],
                ['value' => ['amount' => (float) $validated['monthly_spend_cap_usd']], 'updated_by' => $request->user()->id]
            );
        }

        // Snapshot into prompt_versions for rollback history (FR-ADM-01)
        DB::table('prompt_versions')->update(['is_active' => false]);
        DB::table('prompt_versions')->insert([
            'version_label' => 'v-'.now()->format('YmdHis').'-'.Str::lower(Str::random(4)),
            'system_prompt' => 'default',
            'model_id' => $runtimeUpdate['model_id'] ?? config('rag.default_model'),
            'temperature' => (float) ($runtimeUpdate['temperature'] ?? 0.0),
            'thinking_budget' => (int) ($runtimeUpdate['thinking_budget'] ?? 0),
            'top_k' => (int) ($runtimeUpdate['top_k'] ?? config('rag.default_top_k')),
            'similarity_threshold' => (float) ($runtimeUpdate['similarity_threshold'] ?? config('rag.default_threshold')),
            'reranker_enabled' => (bool) ($runtimeUpdate['reranker_enabled'] ?? false),
            'created_by' => $request->user()->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $audit->record($request, 'settings.update', 'setting', 'rag_runtime', $validated);

        return $this->getSettings();
    }
}
