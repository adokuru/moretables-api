<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreRestaurantOnboardingMealTypeRequest;
use App\Http\Requests\Merchant\UpdateRestaurantOnboardingContactRequest;
use App\Http\Requests\Merchant\UpdateRestaurantOnboardingDescriptionRequest;
use App\Http\Requests\Merchant\UpdateRestaurantOnboardingHoursRequest;
use App\Http\Requests\Merchant\UpdateRestaurantOnboardingMealTypeRequest;
use App\Http\Requests\Merchant\UpdateRestaurantOnboardingStatusRequest;
use App\Http\Requests\Merchant\UploadRestaurantOnboardingPhotoRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\Restaurant;
use App\Models\RestaurantMealType;
use App\Services\MediaLibraryService;
use App\Services\RestaurantOnboardingService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant Onboarding', weight: 35)]
class MerchantRestaurantOnboardingController extends Controller
{
    public function __construct(
        protected RestaurantOnboardingService $onboardingService,
        protected MediaLibraryService $mediaLibraryService,
    ) {}

    public function updateContactCuisinePrice(UpdateRestaurantOnboardingContactRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $validated = $request->validated();

        $restaurant->fill(collect($validated)->only(['email', 'phone', 'website', 'average_price_range'])->toArray());
        $restaurant->save();

        if (array_key_exists('primary_cuisine_option_id', $validated)) {
            $this->onboardingService->syncCuisines(
                $restaurant,
                $validated['primary_cuisine_option_id'],
                $validated['additional_cuisine_option_ids'] ?? [],
            );
        }

        if (array_key_exists('social_handles', $validated) && $validated['social_handles'] !== null) {
            $this->onboardingService->syncSocialHandles($restaurant, $validated['social_handles']);
        }

        $restaurant->load(['cuisines', 'socialHandles']);

        return response()->json([
            'message' => 'Contact, cuisine and price updated successfully.',
            'restaurant' => [
                'email' => $restaurant->email,
                'phone' => $restaurant->phone,
                'website' => $restaurant->website,
                'average_price_range' => $restaurant->average_price_range,
                'cuisines' => $restaurant->cuisines->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'slug' => $c->slug,
                    'is_primary' => (bool) $c->pivot->is_primary,
                ])->values(),
                'social_handles' => $restaurant->socialHandles->map(fn ($h) => [
                    'platform' => $h->platform,
                    'handle' => $h->handle,
                ])->values(),
            ],
        ]);
    }

    public function uploadProfilePhoto(UploadRestaurantOnboardingPhotoRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $galleryMedia = $this->mediaLibraryService->addUploadedFileToCollection(
            $restaurant,
            $request->file('photo'),
            'gallery',
        );

        $featuredMedia = $this->mediaLibraryService->featureMedia($restaurant, $galleryMedia);

        return response()->json([
            'message' => 'Profile photo uploaded successfully.',
            'featured_image' => MediaAssetResource::make($featuredMedia),
            'gallery_image' => MediaAssetResource::make($galleryMedia),
        ], 201);
    }

    public function updateDescription(UpdateRestaurantOnboardingDescriptionRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $restaurant->update($request->validated());

        return response()->json([
            'message' => 'Description updated successfully.',
            'description' => $restaurant->description,
        ]);
    }

    public function updateBusinessHours(UpdateRestaurantOnboardingHoursRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $this->onboardingService->replaceMealSchedules($restaurant, $request->validated('schedules'));

        $restaurant->load(['mealTypes.schedules']);

        return response()->json([
            'message' => 'Business hours updated successfully.',
            'meal_types' => $restaurant->mealTypes->map(fn ($type) => [
                'id' => $type->id,
                'name' => $type->name,
                'sort_order' => $type->sort_order,
                'schedules' => $type->schedules->map(fn ($s) => [
                    'id' => $s->id,
                    'day_of_week' => $s->day_of_week,
                    'opens_at' => $s->opens_at,
                    'closes_at' => $s->closes_at,
                ])->values(),
            ])->values(),
        ]);
    }

    public function indexMealTypes(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        $restaurant->load(['mealTypes.schedules']);

        return response()->json([
            'meal_types' => $restaurant->mealTypes->map(fn ($type) => [
                'id' => $type->id,
                'name' => $type->name,
                'sort_order' => $type->sort_order,
                'schedules' => $type->schedules->map(fn ($s) => [
                    'id' => $s->id,
                    'day_of_week' => $s->day_of_week,
                    'opens_at' => $s->opens_at,
                    'closes_at' => $s->closes_at,
                ])->values(),
            ])->values(),
        ]);
    }

    public function storeMealType(StoreRestaurantOnboardingMealTypeRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $validated = $request->validated();

        if (! isset($validated['sort_order'])) {
            $validated['sort_order'] = (int) $restaurant->mealTypes()->max('sort_order') + 1;
        }

        $mealType = $restaurant->mealTypes()->create($validated);

        return response()->json([
            'message' => 'Meal type created successfully.',
            'meal_type' => [
                'id' => $mealType->id,
                'name' => $mealType->name,
                'sort_order' => $mealType->sort_order,
                'schedules' => [],
            ],
        ], 201);
    }

    public function updateMealType(UpdateRestaurantOnboardingMealTypeRequest $request, Restaurant $restaurant, RestaurantMealType $mealType): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless($this->onboardingService->mealTypeBelongsToRestaurant($mealType, $restaurant), 404);

        $mealType->update($request->validated());

        return response()->json([
            'message' => 'Meal type updated successfully.',
            'meal_type' => [
                'id' => $mealType->id,
                'name' => $mealType->name,
                'sort_order' => $mealType->sort_order,
            ],
        ]);
    }

    public function destroyMealType(Restaurant $restaurant, RestaurantMealType $mealType): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);
        abort_unless($this->onboardingService->mealTypeBelongsToRestaurant($mealType, $restaurant), 404);

        if ($mealType->schedules()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a meal type that has schedules. Remove its schedules first or use PUT business-hours to replace all.',
            ], 422);
        }

        $mealType->delete();

        return response()->json(['message' => 'Meal type deleted successfully.']);
    }

    public function showStatus(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        return response()->json($this->onboardingService->getStatus($restaurant));
    }

    public function updateStatus(UpdateRestaurantOnboardingStatusRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $status = $this->onboardingService->updateStatus($restaurant, $request->validated());

        return response()->json([
            'message' => 'Onboarding status updated.',
            ...$status,
        ]);
    }
}
