<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreRestaurantReviewRequest;
use App\Http\Requests\Customer\UpdateRestaurantReviewRequest;
use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\RestaurantStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

#[Group('Restaurant Reviews', weight: 6)]
class RestaurantReviewController extends Controller
{
    /**
     * List public reviews and summary stats for a restaurant.
     */
    public function index(Restaurant $restaurant): JsonResponse
    {
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);

        $reviews = $restaurant->reviews()
            ->with('user:id,name,first_name,last_name')
            ->latest()
            ->paginate(15);

        $summary = $restaurant->reviews()
            ->selectRaw('count(*) as reviews_count, avg(rating) as average_rating')
            ->first();

        return response()->json([
            'data' => $reviews->getCollection()->map(fn (RestaurantReview $review): array => $this->serializeReview($review))->values(),
            'summary' => [
                'reviews_count' => (int) ($summary?->reviews_count ?? 0),
                'average_rating' => $summary?->average_rating !== null ? round((float) $summary->average_rating, 2) : null,
            ],
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
        ]);
    }

    /**
     * Submit a review for a restaurant. Each customer can create one review per restaurant.
     */
    public function store(StoreRestaurantReviewRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);

        if ($restaurant->reviews()->where('user_id', $request->user()->id)->exists()) {
            throw ValidationException::withMessages([
                'rating' => ['You have already reviewed this restaurant.'],
            ]);
        }

        $review = $restaurant->reviews()->create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        $review->load('user:id,name,first_name,last_name');

        return response()->json([
            'message' => 'Review submitted successfully.',
            'review' => $this->serializeReview($review),
        ], 201);
    }

    /**
     * Update the authenticated customer's review for a restaurant.
     */
    public function update(UpdateRestaurantReviewRequest $request, Restaurant $restaurant, RestaurantReview $review): JsonResponse
    {
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);
        abort_unless($review->restaurant_id === $restaurant->id && $review->user_id === $request->user()->id, 404);

        $review->update($request->validated());
        $review->load('user:id,name,first_name,last_name');

        return response()->json([
            'message' => 'Review updated successfully.',
            'review' => $this->serializeReview($review),
        ]);
    }

    /**
     * Delete the authenticated customer's review for a restaurant.
     */
    public function destroy(Request $request, Restaurant $restaurant, RestaurantReview $review): JsonResponse
    {
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);
        abort_unless($review->restaurant_id === $restaurant->id && $review->user_id === $request->user()->id, 404);

        $review->delete();

        return response()->json([
            'message' => 'Review deleted successfully.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeReview(RestaurantReview $review): array
    {
        return [
            'id' => $review->id,
            'rating' => $review->rating,
            'title' => $review->title,
            'body' => $review->body,
            'visited_at' => $review->visited_at?->toDateString(),
            'created_at' => $review->created_at?->toIso8601String(),
            'reviewer' => [
                'id' => $review->user?->id,
                'name' => $review->user?->fullName(),
            ],
        ];
    }
}
