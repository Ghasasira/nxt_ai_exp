<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasUuids;

    protected $fillable = [
        'collection_id',
        'owner_id',
        'title',
        'tags',
        'effective_date',
        'active_version',
        'latest_version',
        'file_hash',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'effective_date' => 'date',
            'active_version' => 'integer',
            'latest_version' => 'integer',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    public function ingestionJobs(): HasMany
    {
        return $this->hasMany(IngestionJob::class)->orderByDesc('created_at');
    }
}
