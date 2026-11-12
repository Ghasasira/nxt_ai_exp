<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoldenSet extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'description', 'created_by'];

    public function cases(): HasMany
    {
        return $this->hasMany(GoldenCase::class)->orderBy('created_at');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(EvalRun::class)->orderByDesc('created_at');
    }
}
