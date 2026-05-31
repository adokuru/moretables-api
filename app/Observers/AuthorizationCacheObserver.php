<?php

namespace App\Observers;

use App\Models\UserRole;
use App\Services\PerformanceCacheService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class AuthorizationCacheObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly PerformanceCacheService $performanceCache) {}

    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        $this->performanceCache->invalidateAuthorization(
            $model instanceof UserRole ? (int) $model->user_id : null,
        );
    }
}
