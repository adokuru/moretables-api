<?php

namespace App\Services;

use App\Models\CuisineOption;
use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CuisineOptionRestaurantSyncService
{
    public function __construct(private readonly PerformanceCacheService $performanceCache) {}

    /**
     * @param  array<int, mixed>  $names
     */
    public function syncFromNames(Restaurant $restaurant, array $names): void
    {
        $orderedDisplayNames = [];
        $seenKeys = [];

        foreach ($names as $raw) {
            if (! is_string($raw)) {
                continue;
            }

            $trimmed = trim($raw);

            if ($trimmed === '') {
                continue;
            }

            $key = mb_strtolower($trimmed);

            if (isset($seenKeys[$key])) {
                continue;
            }

            $seenKeys[$key] = true;
            $orderedDisplayNames[] = $trimmed;
        }

        if ($orderedDisplayNames === []) {
            $restaurant->cuisines()->sync([]);
            $this->performanceCache->invalidateRestaurant($restaurant->id);

            return;
        }

        DB::transaction(function () use ($restaurant, $orderedDisplayNames): void {
            $sync = [];

            foreach ($orderedDisplayNames as $index => $displayName) {
                $option = $this->findOrCreateCuisineOption($displayName);
                $sync[$option->id] = [
                    'is_primary' => $index === 0,
                ];
            }

            $restaurant->cuisines()->sync($sync);
        });

        $this->performanceCache->invalidateRestaurant($restaurant->id);
    }

    private function findOrCreateCuisineOption(string $displayName): CuisineOption
    {
        $normalized = mb_strtolower($displayName);

        $existing = CuisineOption::query()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $baseSlug = Str::slug($displayName);
        $slug = $this->uniqueCuisineSlug($baseSlug !== '' ? $baseSlug : 'cuisine');

        return CuisineOption::query()->create([
            'name' => $displayName,
            'slug' => $slug,
        ]);
    }

    private function uniqueCuisineSlug(string $baseSlug): string
    {
        $slug = $baseSlug !== '' ? $baseSlug : 'cuisine';
        $candidate = $slug;
        $suffix = 1;

        while (CuisineOption::query()->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
