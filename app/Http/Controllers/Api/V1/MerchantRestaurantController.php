<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\UpdateRestaurantRequest;
use App\Http\Resources\RestaurantDetailResource;
use App\Models\Restaurant;
use App\Models\Role;
use App\Services\AuditLogService;
use App\Services\CuisineOptionRestaurantSyncService;
use App\Services\MediaLibraryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Group('Merchant Restaurant Profile', weight: 30)]
class MerchantRestaurantController extends Controller
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected MediaLibraryService $mediaLibraryService,
        protected CuisineOptionRestaurantSyncService $cuisineOptionRestaurantSyncService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $accessRoles = Role::restaurantAccessRoles();

        $assignments = $user->roleAssignments()
            ->whereHas('role', fn ($q) => $q->whereIn('name', $accessRoles))
            ->get(['restaurant_id', 'organization_id']);

        $hasGlobalAccess = $assignments->contains(
            fn ($a) => $a->restaurant_id === null && $a->organization_id === null
        );

        $restaurants = Restaurant::query()
            ->with(['media', 'cuisines', 'organization'])
            ->when(! $hasGlobalAccess, function ($query) use ($assignments): void {
                $restaurantIds = $assignments->whereNotNull('restaurant_id')->pluck('restaurant_id');
                $orgIds = $assignments->whereNull('restaurant_id')->whereNotNull('organization_id')->pluck('organization_id');

                $query->where(function ($q) use ($restaurantIds, $orgIds): void {
                    $q->whereIn('id', $restaurantIds)
                        ->orWhereIn('organization_id', $orgIds);
                });
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'restaurants' => $restaurants->map(function (Restaurant $restaurant) use ($user) {
                /** @var ?Media $cover */
                $cover = $restaurant->media->firstWhere('collection_name', 'featured')
                    ?? $restaurant->media->where('collection_name', 'gallery')->sortBy('order_column')->first();

                return [
                    'id' => $restaurant->id,
                    'name' => $restaurant->name,
                    'organization_name' => $restaurant->organization?->name,
                    'slug' => $restaurant->slug,
                    'status' => $restaurant->status?->value,
                    'city' => $restaurant->city,
                    'state' => $restaurant->state,
                    'country' => $restaurant->country,
                    'timezone' => $restaurant->timezone ?: config('app.timezone'),
                    'permissions' => collect([
                        'restaurants.view',
                        'restaurants.manage',
                        'reservations.view',
                        'reservations.manage',
                        'waitlist.manage',
                        'tables.manage',
                        'staff.manage',
                        'audit_logs.view',
                    ])->filter(fn (string $permission): bool => $user->hasRestaurantPermission($permission, $restaurant))->values(),
                    'is_profile_published' => (bool) $restaurant->is_profile_published,
                    'onboarding_current_step' => $restaurant->onboarding_current_step,
                    'cuisines' => $restaurant->cuisines->pluck('name')->values(),
                    'cover_image' => $cover?->getAvailableUrl(['card']),
                ];
            })->values(),
        ]);
    }

    public function show(Restaurant $restaurant): RestaurantDetailResource
    {
        abort_unless(request()->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        return RestaurantDetailResource::make($restaurant->load([
            'cuisines',
            'media',
            'hours',
            'policy',
            'menuItems.media',
            'diningAreas.tables',
            'activeBillingSubscription.plan',
        ]));
    }

    public function update(UpdateRestaurantRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $validated = $request->validated();
        $oldValues = $restaurant->toArray();

        $restaurant->fill(collect($validated)->except([
            'cuisines',
            'hours',
            'policy',
            'featured_image',
            'featured_image_alt_text',
            'gallery_images',
            'gallery_image_alt_texts',
            'menu_document',
        ])->toArray());
        $restaurant->save();

        if (array_key_exists('cuisines', $validated)) {
            $this->cuisineOptionRestaurantSyncService->syncFromNames(
                $restaurant,
                $validated['cuisines'],
            );
        }

        if (array_key_exists('hours', $validated)) {
            foreach ($validated['hours'] as $hour) {
                $restaurant->hours()->updateOrCreate(
                    ['day_of_week' => $hour['day_of_week']],
                    [
                        'opens_at' => $hour['opens_at'] ?? null,
                        'closes_at' => $hour['closes_at'] ?? null,
                        'is_closed' => $hour['is_closed'] ?? false,
                    ],
                );
            }
        }

        if (array_key_exists('policy', $validated)) {
            $restaurant->policy()->updateOrCreate(['restaurant_id' => $restaurant->id], $validated['policy']);
        }

        $this->mediaLibraryService->syncUploadedMedia($restaurant, $validated);
        $this->mediaLibraryService->syncMenuDocument($restaurant, $validated['menu_document'] ?? null);

        $restaurant->load(['cuisines', 'media', 'hours', 'policy', 'menuItems.media', 'diningAreas.tables']);

        $this->auditLogService->log(
            action: 'restaurant.updated',
            actor: $request->user(),
            auditable: $restaurant,
            oldValues: $oldValues,
            newValues: $restaurant->toArray(),
            restaurant: $restaurant,
            organization: $restaurant->organization,
            description: 'Restaurant profile updated',
        );

        return response()->json([
            'message' => 'Restaurant updated successfully.',
            'restaurant' => RestaurantDetailResource::make($restaurant),
        ]);
    }
}
