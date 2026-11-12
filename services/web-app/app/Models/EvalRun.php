<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvalRun extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'golden_set_id',
        'prompt_version_id',
        'model_id',
        'similarity_threshold',
        'recall_at_5',
        'grounded_rate',
        'no_answer_accuracy',
        'avg_latency_ms',
        'results',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'similarity_threshold' => 'float',
            'recall_at_5' => 'float',
            'grounded_rate' => 'float',
            'no_answer_accuracy' => 'float',
            'avg_latency_ms' => 'integer',
            'results' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function goldenSet(): BelongsTo
    {
        return $this->belongsTo(GoldenSet::class);
    }
}
