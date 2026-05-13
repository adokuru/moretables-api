<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\MerchantRestaurantReviewAggregateRequest;
use App\Http\Requests\Merchant\MerchantRestaurantReviewIndexRequest;
use App\Http\Resources\MerchantRestaurantReviewResource;
use App\Models\Restaurant;
use App\Models\RestaurantReview;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;

#[Group('Merchant Reviews', weight: 42)]
class MerchantRestaurantReviewController extends Controller
{
    /**
     * Review summary for the merchant aggregate reviews screen.
     */
    #[QueryParameter('date_from', type: 'string', example: '2025-08-20')]
    #[QueryParameter('date_to', type: 'string', example: '2025-09-25')]
    public function aggregate(MerchantRestaurantReviewAggregateRequest $request, Restaurant $restaurant): JsonResponse
    {
        $baseQuery = $this->reviewQuery($restaurant, $request->validated());

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

        return response()->json([
            'summary' => [
                'overall_rating' => $this->roundedRating($summary?->average_rating),
                'total_customer_reviews' => (int) ($summary?->reviews_count ?? 0),
                'ratings_breakdown' => collect(range(5, 1))
                    ->mapWithKeys(fn (int $rating): array => [(string) $rating => (int) ($ratingsBreakdown[$rating] ?? 0)])
                    ->all(),
            ],
            'category_breakdown' => [
                $this->categoryBreakdownItem('food', 'Food', $categoryAverages?->average_food_rating),
                $this->categoryBreakdownItem('service', 'Service', $categoryAverages?->average_service_rating),
                $this->categoryBreakdownItem('ambience', 'Ambience', $categoryAverages?->average_ambience_rating),
                $this->categoryBreakdownItem('value', 'Value', $categoryAverages?->average_value_rating),
            ],
            'period' => [
                'date_from' => $request->validated('date_from'),
                'date_to' => $request->validated('date_to'),
            ],
        ]);
    }

    /**
     * Detailed merchant reviews table.
     */
    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 20)]
    #[QueryParameter('rating', type: 'integer', example: 4)]
    #[QueryParameter('search', type: 'string', example: 'good food')]
    #[QueryParameter('date_from', type: 'string', example: '2025-08-20')]
    #[QueryParameter('date_to', type: 'string', example: '2025-09-25')]
    #[QueryParameter('sort', type: 'string', example: 'latest')]
    public function index(MerchantRestaurantReviewIndexRequest $request, Restaurant $restaurant): JsonResponse
    {
        $validated = $request->validated();
        $reviews = $this->reviewQuery($restaurant, $validated)
            ->with('user:id,name,first_name,last_name,email')
            ->when(
                array_key_exists('rating', $validated),
                fn (Builder $query): Builder => $query->where('rating', (int) $validated['rating']),
            )
            ->when(
                filled($validated['search'] ?? null),
                function (Builder $query) use ($validated): void {
                    $search = $validated['search'];

                    $query->where(function (Builder $reviewQuery) use ($search): void {
                        $reviewQuery
                            ->where('title', 'like', '%'.$search.'%')
                            ->orWhere('body', 'like', '%'.$search.'%')
                            ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                                $userQuery
                                    ->where('name', 'like', '%'.$search.'%')
                                    ->orWhere('first_name', 'like', '%'.$search.'%')
                                    ->orWhere('last_name', 'like', '%'.$search.'%')
                                    ->orWhere('email', 'like', '%'.$search.'%');
                            });
                    });
                },
            )
            ->when(($validated['sort'] ?? 'latest') === 'oldest', fn (Builder $query): Builder => $query->oldest())
            ->when(($validated['sort'] ?? 'latest') === 'rating_high', fn (Builder $query): Builder => $query->orderByDesc('rating')->latest())
            ->when(($validated['sort'] ?? 'latest') === 'rating_low', fn (Builder $query): Builder => $query->orderBy('rating')->latest())
            ->when(! in_array($validated['sort'] ?? 'latest', ['oldest', 'rating_high', 'rating_low'], true), fn (Builder $query): Builder => $query->latest())
            ->paginate($this->perPage($request, 20, 100))
            ->appends($request->query());

        return response()->json([
            'data' => MerchantRestaurantReviewResource::collection($reviews->getCollection())->resolve($request),
            'links' => [
                'first' => $reviews->url(1),
                'last' => $reviews->url($reviews->lastPage()),
                'prev' => $reviews->previousPageUrl(),
                'next' => $reviews->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'from' => $reviews->firstItem(),
                'last_page' => $reviews->lastPage(),
                'path' => $reviews->path(),
                'per_page' => $reviews->perPage(),
                'to' => $reviews->lastItem(),
                'total' => $reviews->total(),
            ],
        ]);
    }

    /**
     * @param  array{date_from?: string, date_to?: string}  $filters
     */
    private function reviewQuery(Restaurant $restaurant, array $filters): HasMany
    {
        return $restaurant->reviews()
            ->when(
                filled($filters['date_from'] ?? null),
                fn ($query) => $query->whereDate('created_at', '>=', $filters['date_from']),
            )
            ->when(
                filled($filters['date_to'] ?? null),
                fn ($query) => $query->whereDate('created_at', '<=', $filters['date_to']),
            );
    }

    private function roundedRating(mixed $rating): ?float
    {
        return $rating !== null ? round((float) $rating, 2) : null;
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
}
