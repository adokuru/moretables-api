<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantCuisine;
use App\RestaurantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RestaurantSearchService
{
    /**
     * @return array{
     *     locations: Collection<int, array{name: string, secondary_text: string, city: string, state: ?string, country: string}>,
     *     restaurants: Collection<int, Restaurant>,
     *     cuisines: Collection<int, array{name: string, restaurant_count: int}>
     * }
     */
    public function search(string $query, int $limit = 5): array
    {
        $searchTerm = trim($query);

        if ($searchTerm === '') {
            return [
                'locations' => collect(),
                'restaurants' => collect(),
                'cuisines' => collect(),
            ];
        }

        return [
            'locations' => $this->searchLocations($searchTerm, $limit),
            'restaurants' => $this->searchRestaurants($searchTerm, $limit),
            'cuisines' => $this->searchCuisines($searchTerm, $limit),
        ];
    }

    /**
     * @return Collection<int, array{name: string, secondary_text: string, city: string, state: ?string, country: string}>
     */
    protected function searchLocations(string $searchTerm, int $limit): Collection
    {
        $likePattern = $this->likePattern($searchTerm);
        $startsWithPattern = $this->startsWithPattern($searchTerm);

        return Restaurant::query()
            ->select(['city', 'state', 'country'])
            ->where('status', RestaurantStatus::Active->value)
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->where(function (Builder $query) use ($likePattern): void {
                $query->where('city', 'like', $likePattern)
                    ->orWhere('state', 'like', $likePattern)
                    ->orWhere('country', 'like', $likePattern);
            })
            ->distinct()
            ->orderByRaw('case when city like ? then 0 when state like ? then 1 when country like ? then 2 else 3 end', [
                $startsWithPattern,
                $startsWithPattern,
                $startsWithPattern,
            ])
            ->orderBy('city')
            ->orderBy('state')
            ->orderBy('country')
            ->limit($limit)
            ->get()
            ->map(function (Restaurant $restaurant): array {
                $secondaryParts = array_values(array_filter([$restaurant->state, $restaurant->country]));

                return [
                    'name' => $restaurant->city,
                    'secondary_text' => implode(', ', $secondaryParts),
                    'city' => $restaurant->city,
                    'state' => $restaurant->state,
                    'country' => $restaurant->country,
                ];
            });
    }

    /**
     * @return Collection<int, Restaurant>
     */
    protected function searchRestaurants(string $searchTerm, int $limit): Collection
    {
        $likePattern = $this->likePattern($searchTerm);
        $startsWithPattern = $this->startsWithPattern($searchTerm);

        return Restaurant::query()
            ->with(['cuisines', 'media'])
            ->where('status', RestaurantStatus::Active->value)
            ->where(function (Builder $query) use ($likePattern): void {
                $query->where('name', 'like', $likePattern)
                    ->orWhere('description', 'like', $likePattern)
                    ->orWhere('city', 'like', $likePattern)
                    ->orWhere('state', 'like', $likePattern)
                    ->orWhere('country', 'like', $likePattern)
                    ->orWhere('address_line_1', 'like', $likePattern)
                    ->orWhere('address_line_2', 'like', $likePattern);
            })
            ->orderByRaw('case when name like ? then 0 when name like ? then 1 else 2 end', [
                $startsWithPattern,
                $likePattern,
            ])
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, array{name: string, restaurant_count: int}>
     */
    protected function searchCuisines(string $searchTerm, int $limit): Collection
    {
        $likePattern = $this->likePattern($searchTerm);
        $startsWithPattern = $this->startsWithPattern($searchTerm);

        return RestaurantCuisine::query()
            ->select('restaurant_cuisines.name')
            ->selectRaw('COUNT(DISTINCT restaurant_cuisines.restaurant_id) as restaurant_count')
            ->join('restaurants', 'restaurants.id', '=', 'restaurant_cuisines.restaurant_id')
            ->where('restaurants.status', RestaurantStatus::Active->value)
            ->where('restaurant_cuisines.name', 'like', $likePattern)
            ->groupBy('restaurant_cuisines.name')
            ->orderByRaw('case when restaurant_cuisines.name like ? then 0 else 1 end', [$startsWithPattern])
            ->orderBy('restaurant_cuisines.name')
            ->limit($limit)
            ->get()
            ->map(function (RestaurantCuisine $restaurantCuisine): array {
                return [
                    'name' => $restaurantCuisine->name,
                    'restaurant_count' => (int) $restaurantCuisine->getAttribute('restaurant_count'),
                ];
            });
    }

    protected function likePattern(string $searchTerm): string
    {
        return '%'.$this->escapeLike($searchTerm).'%';
    }

    protected function startsWithPattern(string $searchTerm): string
    {
        return $this->escapeLike($searchTerm).'%';
    }

    protected function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
