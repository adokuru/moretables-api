<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreRestaurantReviewRequest;
use App\Http\Requests\Customer\UpdateRestaurantReviewRequest;
use App\Http\Resources\PublicRestaurantReviewResource;
use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\RestaurantStatus;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

#[Group('Restaurant Reviews', weight: 6)]
class RestaurantReviewController extends Controller
{
    /**
     * List public reviews and summary stats for a restaurant.
     */
    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 15, example: 15)]
    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);

        $reviews = $restaurant->reviews()
            ->with('user:id,name,first_name,last_name')
            ->latest()
            ->paginate($this->perPage($request, 15, 50))
            ->appends($request->query());

        $summary = $restaurant->reviews()
            ->selectRaw('count(*) as reviews_count, avg(rating) as average_rating')
            ->first();
        $ratingsBreakdown = $restaurant->reviews()
            ->selectRaw('rating, count(*) as aggregate')
            ->groupBy('rating')
            ->pluck('aggregate', 'rating');

        return response()->json([
            'data' => PublicRestaurantReviewResource::collection($reviews->getCollection())->resolve($request),
            'summary' => [
                'reviews_count' => (int) ($summary?->reviews_count ?? 0),
                'average_rating' => $summary?->average_rating !== null ? round((float) $summary->average_rating, 2) : null,
                'ratings_breakdown' => collect(range(5, 1))
                    ->mapWithKeys(fn (int $rating): array => [(string) $rating => (int) ($ratingsBreakdown[$rating] ?? 0)])
                    ->all(),
            ],
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

        $payload = $request->safe()->except('review_images');
        $payload['review_images'] = $this->storeReviewImages($request->file('review_images', []));

        $review = $restaurant->reviews()->create([
            ...$payload,
            'user_id' => $request->user()->id,
        ]);

        $review->load('user:id,name,first_name,last_name');

        return response()->json([
            'message' => 'Review submitted successfully.',
            'review' => PublicRestaurantReviewResource::make($review)->resolve(),
        ], 201);
    }

    /**
     * Update the authenticated customer's review for a restaurant.
     */
    public function update(UpdateRestaurantReviewRequest $request, Restaurant $restaurant, RestaurantReview $review): JsonResponse
    {
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);
        abort_unless($review->restaurant_id === $restaurant->id && $review->user_id === $request->user()->id, 404);

        $payload = $request->safe()->except('review_images');

        if ($request->hasFile('review_images') || $request->has('review_images')) {
            $this->deleteReviewImages($review->review_images ?? []);
            $payload['review_images'] = $this->storeReviewImages($request->file('review_images', []));
        }

        $review->update($payload);
        $review->load('user:id,name,first_name,last_name');

        return response()->json([
            'message' => 'Review updated successfully.',
            'review' => PublicRestaurantReviewResource::make($review)->resolve(),
        ]);
    }

    /**
     * Delete the authenticated customer's review for a restaurant.
     */
    public function destroy(Request $request, Restaurant $restaurant, RestaurantReview $review): JsonResponse
    {
        abort_unless($restaurant->status === RestaurantStatus::Active, 404);
        abort_unless($review->restaurant_id === $restaurant->id && $review->user_id === $request->user()->id, 404);

        $this->deleteReviewImages($review->review_images ?? []);
        $review->delete();

        return response()->json([
            'message' => 'Review deleted successfully.',
        ]);
    }

    /**
     * @param  array<int, UploadedFile>  $reviewImages
     * @return array<int, string>
     */
    private function storeReviewImages(array $reviewImages): array
    {
        $storedImages = [];

        foreach ($reviewImages as $reviewImage) {
            $storedImages[] = $reviewImage->store('reviews', 'public');
        }

        return $storedImages;
    }

    /**
     * @param  array<int, string>  $reviewImages
     */
    private function deleteReviewImages(array $reviewImages): void
    {
        foreach ($reviewImages as $reviewImage) {
            Storage::disk('public')->delete($reviewImage);
        }
    }
}
