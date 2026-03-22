<?php

namespace App\Services;

use App\Models\Restaurant;
use App\ReservationStatus;
use App\RestaurantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RestaurantDiscoveryService
{
    /**
     * @var array<string, string>
     */
    public const SECTION_LABELS = [
        'top_booked' => 'Top Booked',
        'top_viewed' => 'Top Viewed',
        'top_saved' => 'Top Saved',
        'highly_rated' => 'Highly Rated',
        'new_on_moretables' => 'New on Moretables',
        'featured' => 'Featured',
    ];

    /**
     * @var list<string>
     */
    protected const BOOKING_STATUSES = [
        ReservationStatus::Booked->value,
        ReservationStatus::Confirmed->value,
        ReservationStatus::Arrived->value,
        ReservationStatus::Seated->value,
        ReservationStatus::Completed->value,
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, Collection<int, Restaurant>>
     */
    public function listSections(array $filters, int $limit): array
    {
        $sections = [];

        foreach (array_keys(self::SECTION_LABELS) as $section) {
            $sections[$section] = $this->sectionQuery($section, $filters)
                ->limit($limit)
                ->get();
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateSection(string $section, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->sectionQuery($section, $filters)->paginate($perPage);
    }

    public function normalizeSection(string $section): string
    {
        return Str::of($section)->lower()->replace('-', '_')->toString();
    }

    public function supportsSection(string $section): bool
    {
        return array_key_exists($this->normalizeSection($section), self::SECTION_LABELS);
    }

    public function sectionLabel(string $section): string
    {
        return self::SECTION_LABELS[$this->normalizeSection($section)];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function sectionQuery(string $section, array $filters): Builder
    {
        $normalizedSection = $this->normalizeSection($section);
        $query = $this->queryWithDiscoveryMetrics($filters);

        return match ($normalizedSection) {
            'top_booked' => $query
                ->orderByDesc('bookings_count')
                ->orderByDesc('views_count')
                ->orderByDesc('created_at'),
            'top_viewed' => $query
                ->orderByDesc('views_count')
                ->orderByDesc('bookings_count')
                ->orderByDesc('created_at'),
            'top_saved' => $query
                ->orderByDesc('saves_count')
                ->orderByDesc('list_adds_count')
                ->orderByDesc('created_at'),
            'highly_rated' => $query
                ->orderByDesc('average_rating')
                ->orderByDesc('reviews_count')
                ->orderByDesc('created_at'),
            'new_on_moretables' => $query->orderByDesc('created_at'),
            'featured' => $query
                ->where('is_featured', true)
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at'),
            default => abort(404),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function queryWithDiscoveryMetrics(array $filters): Builder
    {
        return $this->baseQuery($filters)
            ->withCount([
                'reservations as bookings_count' => fn (Builder $query) => $query
                    ->whereIn('status', self::BOOKING_STATUSES)
                    ->where('starts_at', '>=', now()->subDays(30)),
                'views as views_count' => fn (Builder $query) => $query
                    ->where('created_at', '>=', now()->subDays(30)),
                'savedEntries as saves_count',
                'listItems as list_adds_count',
                'reviews as reviews_count',
            ])
            ->withAvg('reviews as average_rating', 'rating');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters): Builder
    {
        $hasCoordinates = isset($filters['latitude'], $filters['longitude']);

        return Restaurant::query()
            ->with(['cuisines', 'media'])
            ->where('status', RestaurantStatus::Active->value)
            ->when(filled($filters['q'] ?? null), function (Builder $query) use ($filters): void {
                $searchTerm = (string) $filters['q'];

                $query->where(function (Builder $subQuery) use ($searchTerm): void {
                    $subQuery->where('name', 'like', '%'.$searchTerm.'%')
                        ->orWhere('description', 'like', '%'.$searchTerm.'%');
                });
            })
            ->when(filled($filters['city'] ?? null), function (Builder $query) use ($filters): void {
                $query->where('city', (string) $filters['city']);
            })
            ->when(filled($filters['country'] ?? null), function (Builder $query) use ($filters): void {
                $query->where('country', (string) $filters['country']);
            })
            ->when(filled($filters['cuisine'] ?? null), function (Builder $query) use ($filters): void {
                $query->whereHas('cuisines', function (Builder $subQuery) use ($filters): void {
                    $subQuery->where('name', 'like', '%'.(string) $filters['cuisine'].'%');
                });
            })
            ->when($hasCoordinates, function (Builder $query) use ($filters): void {
                $radiusKm = (float) ($filters['radius_km'] ?? 25);
                $bounds = $this->coordinateBounds(
                    latitude: (float) $filters['latitude'],
                    longitude: (float) $filters['longitude'],
                    radiusKm: $radiusKm,
                );

                $query->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->whereBetween('latitude', [$bounds['min_latitude'], $bounds['max_latitude']])
                    ->whereBetween('longitude', [$bounds['min_longitude'], $bounds['max_longitude']]);
            });
    }

    /**
     * @return array{min_latitude: float, max_latitude: float, min_longitude: float, max_longitude: float}
     */
    protected function coordinateBounds(float $latitude, float $longitude, float $radiusKm): array
    {
        $latitudeDelta = $radiusKm / 111.045;
        $longitudeFactor = max(abs(cos(deg2rad($latitude))), 0.01);
        $longitudeDelta = min($radiusKm / (111.045 * $longitudeFactor), 180.0);

        return [
            'min_latitude' => $latitude - $latitudeDelta,
            'max_latitude' => $latitude + $latitudeDelta,
            'min_longitude' => $longitude - $longitudeDelta,
            'max_longitude' => $longitude + $longitudeDelta,
        ];
    }
}
