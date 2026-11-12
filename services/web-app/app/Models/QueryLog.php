<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QueryLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'user_id',
        'api_key_id',
        'conversation_id',
        'query_text',
        'answer_text',
        'mode',
        'grounded',
        'model',
        'chunks_used',
        'input_tokens',
        'output_tokens',
        'estimated_cost_usd',
        'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'grounded' => 'boolean',
            'chunks_used' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost_usd' => 'float',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }
}
