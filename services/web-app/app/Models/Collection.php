<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Collection extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'allow_cloud_generation',
    ];

    protected function casts(): array
    {
        return [
            'allow_cloud_generation' => 'boolean',
        ];
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(CollectionAccess::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
