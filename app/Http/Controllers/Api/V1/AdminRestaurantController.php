<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteRestaurantOwnerRequest;
use App\Http\Requests\Admin\StoreAdminRestaurantRequest;
use App\Http\Requests\Admin\UpdateAdminRestaurantRequest;
use App\Http\Requests\Admin\UpdateRestaurantStatusRequest;
use App\Http\Resources\RestaurantDetailResource;
use App\Http\Resources\UserResource;
use App\Models\Restaurant;
use App\Models\RestaurantPolicy;
use App\Models\Role;
use App\Models\User;
use App\Services\MediaLibraryService;
use App\Services\ScopedRoleAssignmentService;
use App\UserAuthMethod;
use App\UserStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Admin Restaurants', weight: 52)]
class AdminRestaurantController extends Controller
{
    public function __construct(
        protected MediaLibraryService $mediaLibraryService,
        protected ScopedRoleAssignmentService $scopedRoleAssignmentService,
    ) {}

    public function index(): JsonResponse
    {
        abort_unless(request()->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $restaurants = Restaurant::query()
            ->with(['organization', 'policy', 'cuisines', 'media'])
            ->paginate(20);

        return response()->json(RestaurantDetailResource::collection($restaurants));
    }

    public function store(StoreAdminRestaurantRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $validated = $request->validated();
        $restaurant = Restaurant::query()->create([
            ...collect($validated)->except([
                'featured_image',
                'featured_image_alt_text',
                'gallery_images',
                'gallery_image_alt_texts',
                'menu_document',
            ])->toArray(),
            'slug' => $validated['slug'] ?? str($validated['name'])->slug()->toString(),
        ]);

        RestaurantPolicy::query()->firstOrCreate(['restaurant_id' => $restaurant->id]);
        $this->mediaLibraryService->syncUploadedMedia($restaurant, $validated);
        $this->mediaLibraryService->syncMenuDocument($restaurant, $validated['menu_document'] ?? null);

        return response()->json([
            'message' => 'Restaurant created successfully.',
            'restaurant' => RestaurantDetailResource::make($restaurant->load([
                'organization',
                'policy',
                'cuisines',
                'media',
                'hours',
                'menuItems.media',
                'diningAreas.tables',
            ])),
        ], 201);
    }

    public function show(Restaurant $restaurant): RestaurantDetailResource
    {
        abort_unless(request()->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        return RestaurantDetailResource::make($restaurant->load([
            'organization',
            'policy',
            'cuisines',
            'media',
            'hours',
            'diningAreas.tables',
            'menuItems.media',
        ]));
    }

    public function update(UpdateAdminRestaurantRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $validated = $request->validated();
        if (array_key_exists('name', $validated) && empty($validated['slug'])) {
            $validated['slug'] = str($validated['name'])->slug()->toString();
        }

        $restaurant->update(
            collect($validated)->except([
                'featured_image',
                'featured_image_alt_text',
                'gallery_images',
                'gallery_image_alt_texts',
                'menu_document',
            ])->toArray(),
        );

        $this->mediaLibraryService->syncUploadedMedia($restaurant, $validated);
        $this->mediaLibraryService->syncMenuDocument($restaurant, $validated['menu_document'] ?? null);

        return response()->json([
            'message' => 'Restaurant updated successfully.',
            'restaurant' => RestaurantDetailResource::make($restaurant->refresh()->load([
                'organization',
                'policy',
                'cuisines',
                'media',
                'hours',
                'menuItems.media',
                'diningAreas.tables',
            ])),
        ]);
    }

    public function inviteOwner(InviteRestaurantOwnerRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $validated = $request->validated();

        $owner = User::query()->create([
            'name' => $validated['first_name'].' '.$validated['last_name'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
            'status' => UserStatus::Active,
            'auth_method' => UserAuthMethod::Password,
            'email_verified_at' => now(),
            'last_active_at' => now(),
        ]);

        $this->scopedRoleAssignmentService->assignOrganizationOwner($owner, $restaurant->organization, $request->user()->id);
        $this->scopedRoleAssignmentService->assignRestaurantManager($owner, $restaurant, $request->user()->id);

        return response()->json([
            'message' => 'Restaurant owner invited successfully.',
            'user' => UserResource::make($owner->load('roles')),
        ], 201);
    }

    public function updateStatus(UpdateRestaurantStatusRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $restaurant->update(['status' => $request->validated('status')]);

        return response()->json([
            'message' => 'Restaurant status updated successfully.',
            'restaurant' => RestaurantDetailResource::make($restaurant->refresh()->load(['organization', 'policy'])),
        ]);
    }
}
