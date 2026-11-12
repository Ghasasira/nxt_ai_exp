<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    public function record(
        Request $request,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
    ): void {
        $user = $request->user();
        $apiKeyId = $request->attributes->get('api_key_id');

        AuditLog::query()->create([
            'actor_user_id' => $user?->id,
            'api_key_id' => $apiKeyId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}
