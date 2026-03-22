<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminReviewRequest;
use App\Http\Requests\Admin\UpdateAdminReviewRequest;
use App\Models\RestaurantReview;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Reviews', weight: 57)]
class AdminRestaurantReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $reviews = RestaurantReview::query()
            ->with(['restaurant.organization', 'user.roles'])
            ->when(
                $request->has('restaurant_id'),
                fn ($query) => $query->where('restaurant_id', $request->integer('restaurant_id')),
            )
            ->when(
                $request->has('user_id'),
                fn ($query) => $query->where('user_id', $request->integer('user_id')),
            )
            ->when(
                $request->has('rating'),
                fn ($query) => $query->where('rating', $request->integer('rating')),
            )
            ->when(
                filled($request->string('search')->toString()),
                fn ($query) => $query->where(function ($reviewQuery) use ($request): void {
                    $search = $request->string('search')->toString();

                    $reviewQuery
                        ->where('title', 'like', '%'.$search.'%')
                        ->orWhere('body', 'like', '%'.$search.'%');
                }),
            )
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $reviews->getCollection()->map(fn (RestaurantReview $review): array => $this->serializeReview($review))->values(),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
        ]);
    }

    public function store(StoreAdminReviewRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $review = RestaurantReview::query()->create($request->validated());
        $review->load(['restaurant.organization', 'user.roles']);

        return response()->json([
            'message' => 'Review created successfully.',
            'review' => $this->serializeReview($review),
        ], 201);
    }

    public function show(Request $request, RestaurantReview $review): JsonResponse
    {
        $this->ensureAdminAccess($request);

        return response()->json([
            'review' => $this->serializeReview($review->load(['restaurant.organization', 'user.roles'])),
        ]);
    }

    public function update(UpdateAdminReviewRequest $request, RestaurantReview $review): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $review->update($request->validated());
        $review->load(['restaurant.organization', 'user.roles']);

        return response()->json([
            'message' => 'Review updated successfully.',
            'review' => $this->serializeReview($review),
        ]);
    }

    public function destroy(Request $request, RestaurantReview $review): JsonResponse
    {
        $this->ensureAdminAccess($request);

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
            'restaurant' => [
                'id' => $review->restaurant?->id,
                'name' => $review->restaurant?->name,
                'organization_name' => $review->restaurant?->organization?->name,
            ],
            'reviewer' => [
                'id' => $review->user?->id,
                'name' => $review->user?->fullName(),
                'email' => $review->user?->email,
            ],
        ];
    }
}
