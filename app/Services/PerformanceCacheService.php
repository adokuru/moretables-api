<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PerformanceCacheService
{
    public function flexible(string $key, string $ttl, Closure $callback): mixed
    {
        return Cache::flexible($key, config("performance.cache.ttls.{$ttl}"), $callback);
    }

    public function key(string $group, string ...$parts): string
    {
        return implode(':', [
            'performance',
            config('performance.cache.namespace'),
            $group,
            ...$parts,
        ]);
    }

    public function versionedKey(string $group, string ...$parts): string
    {
        return $this->key($group, $this->version($group), ...$parts);
    }

    public function restaurantKey(string $group, int $restaurantId, string ...$parts): string
    {
        $versionGroup = "{$group}:restaurant:{$restaurantId}";

        return $this->key($group, $this->version($versionGroup), (string) $restaurantId, ...$parts);
    }

    public function billingEligibilityKey(int $restaurantId): string
    {
        return $this->key('billing-eligibility', (string) $restaurantId);
    }

    public function authorizationKey(int $userId, string $ability, ?int $restaurantId = null): string
    {
        return $this->key(
            'authorization',
            $this->version('authorization'),
            $this->version("authorization:user:{$userId}"),
            (string) $userId,
            $ability,
            (string) ($restaurantId ?? 'global'),
        );
    }

    public function invalidateRestaurant(int $restaurantId): void
    {
        $this->bump('public-fragments');
        $this->bump("public-fragments:restaurant:{$restaurantId}");
        $this->bump("review-summaries:restaurant:{$restaurantId}");
        $this->bump("merchant-overview:restaurant:{$restaurantId}");
        $this->bump('discovery');
    }

    public function invalidateCuisines(): void
    {
        $this->bump('public-fragments');
        $this->bump('cuisine-options');
        $this->bump('discovery');
    }

    public function invalidateBillingEligibility(int $restaurantId): void
    {
        Cache::forget($this->billingEligibilityKey($restaurantId));
    }

    public function invalidateAuthorization(?int $userId = null): void
    {
        $this->bump($userId ? "authorization:user:{$userId}" : 'authorization');
    }

    public function bump(string $group): void
    {
        Cache::forever($this->versionKey($group), (string) Str::uuid());
    }

    private function version(string $group): string
    {
        return (string) Cache::get($this->versionKey($group), 'initial');
    }

    private function versionKey(string $group): string
    {
        return $this->key('versions', $group);
    }
}
