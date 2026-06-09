<?php

namespace App\Services;

use App\Models\CuisineOption;
use App\Models\Restaurant;
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
    public function search(string $query, int $limit = 5, ?int $userId = null): array
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
            'restaurants' => $this->searchRestaurants($searchTerm, $limit, $userId),
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
            ->publiclyListed()
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
    protected function searchRestaurants(string $searchTerm, int $limit, ?int $userId = null): Collection
    {
        $likePattern = $this->likePattern($searchTerm);
        $startsWithPattern = $this->startsWithPattern($searchTerm);

        return Restaurant::query()
            ->with(['cuisines', 'media'])
            ->publiclyListed()
            ->when($userId !== null, function (Builder $query) use ($userId): void {
                $query->withExists([
                    'savedEntries as has_saved' => fn ($subQuery) => $subQuery->where('user_id', $userId),
                ]);
            })
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

        return CuisineOption::query()
            ->select('cuisine_options.name')
            ->selectRaw('COUNT(DISTINCT cuisine_option_restaurant.restaurant_id) as restaurant_count')
            ->join('cuisine_option_restaurant', 'cuisine_option_restaurant.cuisine_option_id', '=', 'cuisine_options.id')
            ->join('restaurants', 'restaurants.id', '=', 'cuisine_option_restaurant.restaurant_id')
            ->whereIn('restaurants.id', Restaurant::query()->publiclyListed()->select('id'))
            ->where('cuisine_options.name', 'like', $likePattern)
            ->groupBy('cuisine_options.id', 'cuisine_options.name')
            ->orderByRaw('case when cuisine_options.name like ? then 0 else 1 end', [$startsWithPattern])
            ->orderBy('cuisine_options.name')
            ->limit($limit)
            ->get()
            ->map(function (CuisineOption $cuisineOption): array {
                return [
                    'name' => $cuisineOption->name,
                    'restaurant_count' => (int) $cuisineOption->getAttribute('restaurant_count'),
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
