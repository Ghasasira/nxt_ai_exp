<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromptVersion extends Model
{
    protected $fillable = [
        'version_label',
        'system_prompt',
        'model_id',
        'temperature',
        'thinking_budget',
        'top_k',
        'similarity_threshold',
        'reranker_enabled',
        'created_by',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'temperature' => 'float',
            'thinking_budget' => 'integer',
            'top_k' => 'integer',
            'similarity_threshold' => 'float',
            'reranker_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
