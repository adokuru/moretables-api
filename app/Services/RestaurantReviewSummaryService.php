<?php

namespace App\Services;

use App\Models\RestaurantReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class RestaurantReviewSummaryService
{
    /**
     * @param  Builder<RestaurantReview>|Relation<RestaurantReview, *, *>  $reviewsQuery
     * @return array{
     *     reviews_count: int,
     *     average_rating: float|null,
     *     ratings_breakdown: array<string, int>,
     *     category_breakdown: list<array{key: string, label: string, average_rating: float|null}>
     * }
     */
    public function summarize(Builder|Relation $reviewsQuery): array
    {
        $baseQuery = $reviewsQuery instanceof Relation
            ? $reviewsQuery->getQuery()
            : $reviewsQuery;

        $summary = (clone $baseQuery)
            ->selectRaw('count(*) as reviews_count, avg(rating) as average_rating')
            ->first();

        $ratingsBreakdown = (clone $baseQuery)
            ->selectRaw('round(rating) as rating_bucket, count(*) as aggregate')
            ->groupBy('rating_bucket')
            ->get()
            ->mapWithKeys(fn (RestaurantReview $review): array => [
                (string) (int) round((float) $review->rating_bucket) => (int) $review->aggregate,
            ]);

        $categoryAverages = (clone $baseQuery)
            ->selectRaw('
                avg(food_rating) as average_food_rating,
                avg(service_rating) as average_service_rating,
                avg(ambience_rating) as average_ambience_rating,
                avg(value_rating) as average_value_rating
            ')
            ->first();

        return [
            'reviews_count' => (int) ($summary?->reviews_count ?? 0),
            'average_rating' => $this->roundedRating($summary?->average_rating),
            'ratings_breakdown' => $this->normalizeRatingsBreakdown($ratingsBreakdown),
            'category_breakdown' => $this->categoryBreakdown($categoryAverages),
        ];
    }

    /**
     * @param  Collection<string, int>|array<string, int>  $ratingsBreakdown
     * @return array<string, int>
     */
    public function normalizeRatingsBreakdown(Collection|array $ratingsBreakdown): array
    {
        $breakdown = $ratingsBreakdown instanceof Collection
            ? $ratingsBreakdown->all()
            : $ratingsBreakdown;

        return collect(range(5, 1))
            ->mapWithKeys(fn (int $rating): array => [(string) $rating => (int) ($breakdown[$rating] ?? 0)])
            ->all();
    }

    /**
     * @return list<array{key: string, label: string, average_rating: float|null}>
     */
    public function categoryBreakdown(?object $categoryAverages): array
    {
        return [
            $this->categoryBreakdownItem('food', 'Food', $categoryAverages?->average_food_rating ?? null),
            $this->categoryBreakdownItem('service', 'Service', $categoryAverages?->average_service_rating ?? null),
            $this->categoryBreakdownItem('ambience', 'Ambience', $categoryAverages?->average_ambience_rating ?? null),
            $this->categoryBreakdownItem('value', 'Value', $categoryAverages?->average_value_rating ?? null),
        ];
    }

    /**
     * @return array{key: string, label: string, average_rating: float|null}
     */
    private function categoryBreakdownItem(string $key, string $label, mixed $rating): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'average_rating' => $this->roundedRating($rating),
        ];
    }

    private function roundedRating(mixed $rating): ?float
    {
        return $rating !== null ? round((float) $rating, 2) : null;
    }
}
