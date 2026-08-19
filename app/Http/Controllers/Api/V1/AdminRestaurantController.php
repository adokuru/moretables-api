<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteRestaurantOwnerRequest;
use App\Http\Requests\Admin\StoreAdminRestaurantRequest;
use App\Http\Requests\Admin\UpdateAdminRestaurantRequest;
use App\Http\Requests\Admin\UpdateRestaurantStatusRequest;
use App\Http\Resources\AdminRestaurantDetailResource;
use App\Http\Resources\RestaurantDetailResource;
use App\Http\Resources\UserResource;
use App\Jobs\ProcessRestaurantMediaUploads;
use App\Models\Restaurant;
use App\Models\RestaurantPolicy;
use App\Models\Role;
use App\Models\User;
use App\Services\CuisineOptionRestaurantSyncService;
use App\Services\MediaLibraryService;
use App\Services\RestaurantMenuSyncService;
use App\Services\RestaurantReviewSummaryService;
use App\Services\RestaurantUploadStagingService;
use App\Services\ScopedRoleAssignmentService;
use App\UserAuthMethod;
use App\UserStatus;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

#[Group('Admin Restaurants', weight: 52)]
class AdminRestaurantController extends Controller
{
    public function __construct(
        protected MediaLibraryService $mediaLibraryService,
        protected ScopedRoleAssignmentService $scopedRoleAssignmentService,
        protected CuisineOptionRestaurantSyncService $cuisineOptionRestaurantSyncService,
        protected RestaurantMenuSyncService $restaurantMenuSyncService,
        protected RestaurantReviewSummaryService $restaurantReviewSummaryService,
        protected RestaurantUploadStagingService $restaurantUploadStagingService,
    ) {}

    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 20)]
    #[Response(200, type: 'array{data: list<RestaurantDetailResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, path: string, per_page: int, to: int|null, total: int}}')]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $restaurants = Restaurant::query()
            ->with([
                'organization',
                'policy',
                'cuisines',
                'media',
                'activeBillingSubscription.plan',
                'organization.activeBillingSubscription.plan',
                'organization.activeBillingSubscription.plan',
                'latestBillingSubscription.plan',
            ])
            ->when(
                filled($request->string('search')->toString()),
                fn ($query) => $query->where(function ($restaurantQuery) use ($request): void {
                    $search = $request->string('search')->toString();

                    $restaurantQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('city', 'like', '%'.$search.'%');
                }),
            )
            ->when(
                filled($request->string('status')->toString()),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when(
                $request->has('organization_id'),
                fn ($query) => $query->where('organization_id', $request->integer('organization_id')),
            )
            ->when(
                $request->has('is_featured'),
                fn ($query) => $query->where('is_featured', $request->boolean('is_featured')),
            )
            ->latest()
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return response()->json([
            'data' => RestaurantDetailResource::collection($restaurants->getCollection())->resolve($request),
            'links' => [
                'first' => $restaurants->url(1),
                'last' => $restaurants->url($restaurants->lastPage()),
                'prev' => $restaurants->previousPageUrl(),
                'next' => $restaurants->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $restaurants->currentPage(),
                'from' => $restaurants->firstItem(),
                'last_page' => $restaurants->lastPage(),
                'path' => $restaurants->path(),
                'per_page' => $restaurants->perPage(),
                'to' => $restaurants->lastItem(),
                'total' => $restaurants->total(),
            ],
        ]);
    }

    public function store(StoreAdminRestaurantRequest $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $startedAt = microtime(true);
        $validated = $request->validatedWithMenuUploads();
        $deferUploads = $this->restaurantUploadStagingService->hasDeferredUploads($validated);

        $restaurant = Restaurant::query()->create([
            ...collect($validated)->except($this->restaurantPersistExcludedFields())->toArray(),
            'slug' => $validated['slug'] ?? str($validated['name'])->slug()->toString(),
        ]);

        RestaurantPolicy::query()->firstOrCreate(['restaurant_id' => $restaurant->id]);
        $this->syncRestaurantFeatures($restaurant, $validated);

        $mediaProcessing = null;

        if ($deferUploads) {
            if (config('queue.default') === 'sync') {
                $this->mediaLibraryService->syncUploadedMedia($restaurant, $validated);
                $this->restaurantMenuSyncService->sync($restaurant, $validated);
            } else {
                $stagedPayload = $this->restaurantUploadStagingService->stage($validated);
                $job = ProcessRestaurantMediaUploads::dispatch($restaurant->id, $stagedPayload);
                $mediaProcessing = [
                    'status' => 'processing',
                    'job_id' => $job->id ?? null,
                ];
            }
        } else {
            $this->mediaLibraryService->syncUploadedMedia($restaurant, $validated);
            $this->restaurantMenuSyncService->sync($restaurant, $validated);
        }

        $this->logAdminAudit(
            $request,
            'restaurant.created',
            $restaurant,
            newValues: ['name' => $restaurant->name, 'slug' => $restaurant->slug],
        );

        Log::info('admin.restaurant.store.completed', [
            'restaurant_id' => $restaurant->id,
            'deferred_uploads' => $deferUploads,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $response = [
            'message' => 'Restaurant created successfully.',
            'id' => $restaurant->id,
            'restaurant' => RestaurantDetailResource::make($restaurant->load([
                'organization',
                'policy',
                'cuisines',
                'media',
                'hours',
            ])),
        ];

        if ($mediaProcessing !== null) {
            $response['media_processing'] = $mediaProcessing;
        }

        return response()->json($response, 201);
    }

    public function show(Request $request, Restaurant $restaurant): AdminRestaurantDetailResource
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        return AdminRestaurantDetailResource::make($this->prepareRestaurantForAdminDetail($restaurant));
    }

    public function update(UpdateAdminRestaurantRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $validated = $request->validatedWithMenuUploads();

        if (array_key_exists('name', $validated) && empty($validated['slug'])) {
            $validated['slug'] = str($validated['name'])->slug()->toString();
        }

        $oldValues = $restaurant->only(['name', 'slug', 'status', 'is_featured']);
        $restaurant->update(
            collect($validated)->except($this->restaurantPersistExcludedFields())->toArray(),
        );

        $this->syncRestaurantFeatures($restaurant, $validated);
        $this->mediaLibraryService->syncUploadedMedia($restaurant, $validated);
        $this->restaurantMenuSyncService->sync($restaurant, $validated);

        $this->logAdminAudit(
            $request,
            'restaurant.updated',
            $restaurant,
            oldValues: $oldValues,
            newValues: $restaurant->only(array_keys($oldValues)),
        );

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
                'galleryCategories',
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
        $this->scopedRoleAssignmentService->assignRestaurantPrincipalAdmin($owner, $restaurant, $request->user()->id);

        $this->logAdminAudit(
            $request,
            'restaurant.owner_invited',
            $restaurant,
            newValues: ['owner_email' => $owner->email],
        );

        return response()->json([
            'message' => 'Restaurant owner invited successfully.',
            'user' => UserResource::make($owner->load('roles')),
        ], 201);
    }

    public function updateStatus(UpdateRestaurantStatusRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $oldStatus = $restaurant->status?->value;
        $restaurant->update(['status' => $request->validated('status')]);

        $this->logAdminAudit(
            $request,
            'restaurant.status_updated',
            $restaurant,
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => $restaurant->status?->value],
        );

        return response()->json([
            'message' => 'Restaurant status updated successfully.',
            'restaurant' => RestaurantDetailResource::make($restaurant->refresh()->load(['organization', 'policy'])),
        ]);
    }

    public function destroy(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);

        $oldValues = $restaurant->only(['id', 'name', 'slug']);

        $this->logAdminAudit($request, 'restaurant.deleted', $restaurant, oldValues: $oldValues);

        $restaurant->delete();

        return response()->json([
            'message' => 'Restaurant deleted successfully.',
        ]);
    }

    private function prepareRestaurantForAdminDetail(Restaurant $restaurant): Restaurant
    {
        $restaurant = Restaurant::query()
            ->withCount([
                'reservations as bookings_count',
                'views as views_count',
                'savedEntries as saves_count',
                'listItems as list_adds_count',
                'reviews as reviews_count',
                'waitlistEntries as waitlist_entries_count',
                'guestContacts as guest_contacts_count',
            ])
            ->withAvg('reviews as average_rating', 'rating')
            ->whereKey($restaurant->getKey())
            ->firstOrFail();

        $reviewSummary = $this->restaurantReviewSummaryService->summarize($restaurant->reviews(), $restaurant->id);

        $restaurant->setAttribute('ratings_breakdown', (object) $reviewSummary['ratings_breakdown']);
        $restaurant->setAttribute('category_breakdown', $reviewSummary['category_breakdown']);

        return $restaurant->load([
            'organization',
            'policy',
            'cuisines',
            'media',
            'hours',
            'availabilityPeriods.schedules',
            'availabilitySchedules',
            'specialDays.shifts',
            'menuItems.media',
            'diningAreas.tables',
            'galleryCategories',
            'tableCombinations',
            'socialHandles',
            'guestCommunicationSetting',
            'rewardRules',
            'accessConfigs',
            'activeBillingSubscription.plan',
            'organization.activeBillingSubscription.plan',
            'latestBillingSubscription.plan',
            'defaultPaymentMethod',
            'userRoles' => fn ($query) => $query
                ->with(['user.roles', 'role.permissions', 'accessConfig', 'assignedBy'])
                ->whereHas('role', fn ($roleQuery) => $roleQuery->whereIn('name', Role::allRestaurantStaffRoles())),
        ]);
    }

    /**
     * @return list<string>
     */
    private function restaurantPersistExcludedFields(): array
    {
        return [
            'featured_image',
            'featured_image_alt_text',
            'gallery_images',
            'gallery_image_alt_texts',
            'gallery_category_ids',
            'menu',
            'menu_document',
            'menu_source',
            'menu_link',
            'cuisines',
            'cuisine_type',
            'restaurant_logo',
            'restaurant_photos',
            'hours',
            'policy',
            'booking_window_days',
            'reservation_duration_minutes',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncRestaurantFeatures(Restaurant $restaurant, array $validated): void
    {
        if (array_key_exists('cuisines', $validated) && is_array($validated['cuisines'])) {
            $this->cuisineOptionRestaurantSyncService->syncFromNames(
                $restaurant,
                $validated['cuisines'],
            );
        }

        if (array_key_exists('hours', $validated) && is_array($validated['hours'])) {
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

        if (array_key_exists('policy', $validated) && is_array($validated['policy'])) {
            $restaurant->policy()->updateOrCreate(['restaurant_id' => $restaurant->id], $validated['policy']);
        }
    }
}
