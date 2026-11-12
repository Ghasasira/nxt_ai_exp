<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoldenCase extends Model
{
    use HasUuids;

    protected $fillable = [
        'golden_set_id',
        'question',
        'expected_answer',
        'expected_document_ids',
        'collection_ids',
        'answerable',
    ];

    protected function casts(): array
    {
        return [
            'expected_document_ids' => 'array',
            'collection_ids' => 'array',
            'answerable' => 'boolean',
        ];
    }

    public function goldenSet(): BelongsTo
    {
        return $this->belongsTo(GoldenSet::class);
    }
}
