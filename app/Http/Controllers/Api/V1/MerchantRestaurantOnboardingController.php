<?php

namespace App\Http\Controllers\Api\V1;

use App\AuthChallengeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\SendRestaurantEmailVerificationRequest;
use App\Http\Requests\Merchant\StoreRestaurantOnboardingMealTypeRequest;
use App\Http\Requests\Merchant\UpdateRestaurantOnboardingContactRequest;
use App\Http\Requests\Merchant\UpdateRestaurantOnboardingDescriptionRequest;
use App\Http\Requests\Merchant\UpdateRestaurantOnboardingHoursRequest;
use App\Http\Requests\Merchant\UpdateRestaurantOnboardingInternalNotesRequest;
use App\Http\Requests\Merchant\UpdateRestaurantOnboardingMealTypeRequest;
use App\Http\Requests\Merchant\UpdateRestaurantOnboardingStatusRequest;
use App\Http\Requests\Merchant\UploadRestaurantOnboardingPhotoRequest;
use App\Http\Requests\Merchant\VerifyRestaurantEmailRequest;
use App\Http\Resources\MediaAssetResource;
use App\Models\Restaurant;
use App\Models\RestaurantMealType;
use App\Services\AuthChallengeService;
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
        protected AuthChallengeService $authChallengeService,
    ) {}

    public function updateContactCuisinePrice(UpdateRestaurantOnboardingContactRequest $request, Restaurant $restaurant): JsonResponse
    {
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

        $onboardingStatus = $this->onboardingService->syncStatus($restaurant);

        $restaurant->load(['cuisines', 'socialHandles']);

        return response()->json([
            'message' => 'Contact, cuisine and price updated successfully.',
            'onboarding_status' => $onboardingStatus,
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
        $galleryMedia = $this->mediaLibraryService->addUploadedFileToCollection(
            $restaurant,
            $request->file('photo'),
            'gallery',
        );

        $featuredMedia = $this->mediaLibraryService->featureMedia($restaurant, $galleryMedia);
        $onboardingStatus = $this->onboardingService->syncStatus($restaurant);

        return response()->json([
            'message' => 'Profile photo uploaded successfully.',
            'onboarding_status' => $onboardingStatus,
            'featured_image' => MediaAssetResource::make($featuredMedia),
            'gallery_image' => MediaAssetResource::make($galleryMedia),
        ], 201);
    }

    public function updateDescription(UpdateRestaurantOnboardingDescriptionRequest $request, Restaurant $restaurant): JsonResponse
    {
        $restaurant->update($request->validated());
        $onboardingStatus = $this->onboardingService->syncStatus($restaurant);

        return response()->json([
            'message' => 'Description updated successfully.',
            'onboarding_status' => $onboardingStatus,
            'description' => $restaurant->description,
        ]);
    }

    public function updateInternalNotes(UpdateRestaurantOnboardingInternalNotesRequest $request, Restaurant $restaurant): JsonResponse
    {
        $restaurant->update([
            'internal_notes' => $request->boolean('skip') ? null : $request->validated('internal_notes'),
        ]);
        $onboardingStatus = $this->onboardingService->syncStatus($restaurant);

        return response()->json([
            'message' => $request->boolean('skip')
                ? 'Internal notes skipped successfully.'
                : 'Internal notes updated successfully.',
            'onboarding_status' => $onboardingStatus,
            'internal_notes' => $restaurant->internal_notes,
        ]);
    }

    public function updateBusinessHours(UpdateRestaurantOnboardingHoursRequest $request, Restaurant $restaurant): JsonResponse
    {
        $this->onboardingService->replaceMealSchedules($restaurant, $request->validated('schedules'));
        $onboardingStatus = $this->onboardingService->syncStatus($restaurant);

        $restaurant->load(['mealTypes.schedules']);

        return response()->json([
            'message' => 'Business hours updated successfully.',
            'onboarding_status' => $onboardingStatus,
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
        $validated = $request->validated();

        if (! isset($validated['sort_order'])) {
            $validated['sort_order'] = (int) $restaurant->mealTypes()->max('sort_order') + 1;
        }

        $mealType = $restaurant->mealTypes()->create($validated);
        $onboardingStatus = $this->onboardingService->syncStatus($restaurant);

        return response()->json([
            'message' => 'Meal type created successfully.',
            'onboarding_status' => $onboardingStatus,
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
        abort_unless($this->onboardingService->mealTypeBelongsToRestaurant($mealType, $restaurant), 404);

        $mealType->update($request->validated());
        $onboardingStatus = $this->onboardingService->syncStatus($restaurant);

        return response()->json([
            'message' => 'Meal type updated successfully.',
            'onboarding_status' => $onboardingStatus,
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
        $onboardingStatus = $this->onboardingService->syncStatus($restaurant);

        return response()->json([
            'message' => 'Meal type deleted successfully.',
            'onboarding_status' => $onboardingStatus,
        ]);
    }

    public function sendEmailVerificationCode(SendRestaurantEmailVerificationRequest $request, Restaurant $restaurant): JsonResponse
    {
        $challenge = $this->authChallengeService->createForEmail(
            $request->user(),
            AuthChallengeType::RestaurantEmailVerification,
            $request->validated('email'),
        );

        return response()->json([
            'message' => 'A verification code has been sent to '.$request->validated('email').'. It expires in 10 minutes.',
            'challenge_token' => $challenge->challenge_token,
        ], 201);
    }

    public function verifyEmail(VerifyRestaurantEmailRequest $request, Restaurant $restaurant): JsonResponse
    {
        $challenge = $this->authChallengeService->verify(
            $request->validated('challenge_token'),
            $request->validated('code'),
            AuthChallengeType::RestaurantEmailVerification,
        );

        abort_unless((int) $challenge->user_id === (int) $request->user()->id, 403);

        $email = $challenge->meta['email'];

        $restaurant->update([
            'email' => $email,
            'contact_email_verified_at' => now(),
        ]);

        $restaurant->refresh();
        $onboardingStatus = $this->onboardingService->syncStatus($restaurant);

        return response()->json([
            'message' => 'Email address verified successfully.',
            'onboarding_status' => $onboardingStatus,
            'email' => $restaurant->email,
            'contact_email_verified_at' => $restaurant->contact_email_verified_at->toIso8601String(),
        ]);
    }

    public function showData(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        $restaurant->loadMissing(['cuisines', 'socialHandles', 'media']);

        $featuredMedia = $restaurant->media->firstWhere('collection_name', 'featured');

        return response()->json([
            'email' => $restaurant->email,
            'email_verified' => $restaurant->contact_email_verified_at !== null,
            'phone' => $restaurant->phone,
            'website' => $restaurant->website,
            'average_price_range' => $restaurant->average_price_range,
            'description' => $restaurant->description,
            'cuisines' => $restaurant->cuisines->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'is_primary' => (bool) ($c->pivot->is_primary ?? false),
            ])->values(),
            'social_handles' => $restaurant->socialHandles->map(fn ($h) => [
                'platform' => $h->platform,
                'handle' => $h->handle,
            ])->values(),
            'featured_image' => $featuredMedia ? [
                'original_url' => $featuredMedia->getFullUrl(),
                'thumb_url' => $featuredMedia->hasGeneratedConversion('thumb')
                    ? $featuredMedia->getUrl('thumb')
                    : null,
            ] : null,
        ]);
    }

    public function showStatus(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        return response()->json($this->onboardingService->getStatus($restaurant));
    }

    public function updateStatus(UpdateRestaurantOnboardingStatusRequest $request, Restaurant $restaurant): JsonResponse
    {
        $status = $this->onboardingService->updateStatus($restaurant, $request->validated());

        return response()->json([
            'message' => 'Onboarding status updated.',
            ...$status,
        ]);
    }
}
