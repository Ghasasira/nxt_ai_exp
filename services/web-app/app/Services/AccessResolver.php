<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\CollectionAccess;
use App\Models\QueryLog;
use App\Models\Setting;
use App\Models\User;

class AccessResolver
{
    /**
     * Resolve collection UUIDs the user is permitted to read.
     *
     * @param  array<int, string>|null  $requestedIds
     * @return array<int, string>
     */
    public function readableCollectionIds(User $user, ?array $requestedIds = null): array
    {
        if ($user->isAdmin()) {
            $query = Collection::query();
            if ($requestedIds !== null && $requestedIds !== []) {
                $query->whereIn('id', $requestedIds);
            }

            return $query->pluck('id')->all();
        }

        $query = CollectionAccess::query()
            ->where('user_id', $user->id)
            ->where('can_read', true);

        if ($requestedIds !== null && $requestedIds !== []) {
            $query->whereIn('collection_id', $requestedIds);
        }

        return $query->pluck('collection_id')->all();
    }

    public function canWriteCollection(User $user, string $collectionId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if (! $user->isManagerOrAbove()) {
            return false;
        }

        return CollectionAccess::query()
            ->where('user_id', $user->id)
            ->where('collection_id', $collectionId)
            ->where('can_write', true)
            ->exists();
    }

    /**
     * Check whether cloud generation is allowed across all target collections AND within budget/quotas.
     *
     * @param  array<int, string>  $collectionIds
     */
    public function shouldUseCloudGeneration(User $user, array $collectionIds): bool
    {
        if ($collectionIds === []) {
            return false;
        }

        $hasRestricted = Collection::query()
            ->whereIn('id', $collectionIds)
            ->where('allow_cloud_generation', false)
            ->exists();

        if ($hasRestricted) {
            return false;
        }

        // Check per-user daily quota (FR-ADM-02 / FR-QA-06 retrieval-only fallback when over budget)
        if ($user->daily_query_quota !== null) {
            $todayCount = QueryLog::query()
                ->where('user_id', $user->id)
                ->where('created_at', '>=', now()->startOfDay())
                ->count();
            if ($todayCount >= $user->daily_query_quota) {
                return false;
            }
        }

        $capSetting = Setting::query()->find('monthly_spend_cap_usd');
        $monthlyCap = (float) ($capSetting?->value['amount'] ?? config('rag.monthly_spend_cap_usd'));
        if ($monthlyCap > 0) {
            $monthSpend = (float) QueryLog::query()
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('estimated_cost_usd');
            if ($monthSpend >= $monthlyCap) {
                return false;
            }
        }

        return true;
    }
}
