<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\RestaurantStatus;
use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AdminBusinessOnboardingService
{
    public function __construct(
        protected MediaLibraryService $mediaLibraryService,
        protected ScopedRoleAssignmentService $scopedRoleAssignmentService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{organization: Organization, owner: User, restaurants: Collection<int, Restaurant>}
     */
    public function onboard(array $payload, User $admin): array
    {
        return DB::transaction(function () use ($payload, $admin): array {
            $organization = Organization::query()->create($this->organizationAttributes($payload));

            $owner = $this->createOwner($payload);

            $this->scopedRoleAssignmentService->assignOrganizationOwner($owner, $organization, $admin->id);

            DB::afterCommit(function () use ($owner): void {
                Password::sendResetLink(['email' => $owner->email]);
            });

            $restaurants = collect();

            foreach ($payload['restaurants'] as $restaurantPayload) {
                $restaurant = $organization->restaurants()->create($this->restaurantAttributes($restaurantPayload));

                $this->scopedRoleAssignmentService->assignRestaurantManager($owner, $restaurant, $admin->id);
                $this->createRestaurantPolicy($restaurant, $restaurantPayload);
                $this->createRestaurantCuisine($restaurant, $restaurantPayload);
                $this->createRestaurantHours($restaurant, $restaurantPayload);
                $this->createRestaurantTables($restaurant, $restaurantPayload);
                $this->createRestaurantMenu($restaurant, $restaurantPayload);
                $this->attachRestaurantMedia($restaurant, $restaurantPayload);

                $restaurants->push($restaurant->load([
                    'organization',
                    'policy',
                    'cuisines',
                    'media',
                    'hours',
                    'menuItems.media',
                    'diningAreas.tables',
                ]));
            }

            return [
                'organization' => $organization->refresh()->loadCount('restaurants'),
                'owner' => $owner->refresh()->load('roles'),
                'restaurants' => $restaurants,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function organizationAttributes(array $payload): array
    {
        return [
            'name' => $payload['business_name'],
            'slug' => $payload['business_slug'] ?? $this->generateUniqueSlug(Organization::class, $payload['business_name']),
            'primary_contact_name' => $payload['owner_name'],
            'primary_contact_email' => $payload['owner_email'],
            'primary_contact_phone' => $payload['owner_phone'],
            'business_phone' => $payload['business_phone'],
            'business_email' => $payload['business_email'],
            'website' => $payload['business_website'],
            'billing_email' => $payload['billing_email'] ?? null,
            'tax_id' => $payload['tax_id'] ?? null,
            'registration_number' => $payload['registration_number'] ?? null,
            'city' => $payload['business_city'],
            'state' => $payload['business_state'],
            'country' => $payload['business_country'],
            'status' => 'active',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function createOwner(array $payload): User
    {
        $nameParts = $this->splitName($payload['owner_name']);

        return User::query()->create([
            'name' => $payload['owner_name'],
            'first_name' => $nameParts['first_name'],
            'last_name' => $nameParts['last_name'],
            'email' => $payload['owner_email'],
            'phone' => $payload['owner_phone'],
            'password' => Str::password(40),
            'status' => UserStatus::Active,
            'auth_method' => UserAuthMethod::Password,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $restaurantPayload
     * @return array<string, mixed>
     */
    protected function restaurantAttributes(array $restaurantPayload): array
    {
        $menuMode = data_get($restaurantPayload, 'menu.mode');

        return [
            'name' => $restaurantPayload['name'],
            'slug' => $restaurantPayload['slug'] ?? $this->generateUniqueSlug(Restaurant::class, $restaurantPayload['name']),
            'status' => $this->normalizeRestaurantStatus($restaurantPayload['status'] ?? RestaurantStatus::Active),
            'email' => $restaurantPayload['email'] ?? null,
            'phone' => $restaurantPayload['phone'],
            'city' => $restaurantPayload['city'],
            'state' => $restaurantPayload['state'] ?? null,
            'country' => $restaurantPayload['country'],
            'address_line_1' => $restaurantPayload['address_line_1'],
            'address_line_2' => $restaurantPayload['address_line_2'] ?? null,
            'latitude' => $restaurantPayload['latitude'] ?? null,
            'longitude' => $restaurantPayload['longitude'] ?? null,
            'description' => $restaurantPayload['description'] ?? null,
            'website' => $restaurantPayload['website'] ?? null,
            'instagram_handle' => $restaurantPayload['instagram_handle'] ?? null,
            'average_price_range' => $restaurantPayload['average_price_range'],
            'dining_style' => $restaurantPayload['dining_style'],
            'dress_code' => $restaurantPayload['dress_code'],
            'total_seating_capacity' => $restaurantPayload['total_seating_capacity'],
            'number_of_tables' => $restaurantPayload['number_of_tables'],
            'menu_source' => $menuMode,
            'menu_link' => $menuMode === 'link' ? data_get($restaurantPayload, 'menu.link') : null,
            'payment_options' => array_values(array_unique($restaurantPayload['payment_options'] ?? [])),
            'accessibility_features' => array_values(array_unique($restaurantPayload['accessibility_features'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $restaurantPayload
     */
    protected function createRestaurantPolicy(Restaurant $restaurant, array $restaurantPayload): void
    {
        $restaurant->policy()->create([
            'reservation_duration_minutes' => $restaurantPayload['reservation_duration_minutes'],
            'booking_window_days' => $restaurantPayload['booking_window_days'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $restaurantPayload
     */
    protected function createRestaurantCuisine(Restaurant $restaurant, array $restaurantPayload): void
    {
        $restaurant->cuisines()->create([
            'name' => $restaurantPayload['cuisine_type'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $restaurantPayload
     */
    protected function createRestaurantHours(Restaurant $restaurant, array $restaurantPayload): void
    {
        foreach ($restaurantPayload['hours'] as $hour) {
            $restaurant->hours()->create([
                'day_of_week' => $hour['day_of_week'],
                'opens_at' => $hour['opens_at'] ?? null,
                'closes_at' => $hour['closes_at'] ?? null,
                'is_closed' => $hour['is_closed'] ?? false,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $restaurantPayload
     */
    protected function createRestaurantTables(Restaurant $restaurant, array $restaurantPayload): void
    {
        $diningArea = $restaurant->diningAreas()->create([
            'name' => 'Main Dining',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $numberOfTables = (int) $restaurantPayload['number_of_tables'];
        $totalSeatingCapacity = (int) $restaurantPayload['total_seating_capacity'];
        $baseCapacity = intdiv($totalSeatingCapacity, $numberOfTables);
        $remainder = $totalSeatingCapacity % $numberOfTables;

        foreach (range(1, $numberOfTables) as $tableNumber) {
            $additionalSeat = $tableNumber <= $remainder ? 1 : 0;
            $maxCapacity = $baseCapacity + $additionalSeat;

            $restaurant->tables()->create([
                'dining_area_id' => $diningArea->id,
                'name' => 'Table '.$tableNumber,
                'min_capacity' => 1,
                'max_capacity' => $maxCapacity,
                'is_active' => true,
                'sort_order' => $tableNumber - 1,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $restaurantPayload
     */
    protected function createRestaurantMenu(Restaurant $restaurant, array $restaurantPayload): void
    {
        if (data_get($restaurantPayload, 'menu.mode') !== 'manual') {
            return;
        }

        foreach (data_get($restaurantPayload, 'menu.items', []) as $index => $item) {
            $restaurant->menuItems()->create([
                'section_name' => data_get($restaurantPayload, 'menu.name'),
                'item_name' => $item['name'],
                'description' => $item['description'] ?? null,
                'price' => $item['price'],
                'currency' => strtoupper((string) data_get($restaurantPayload, 'menu.currency', 'NGN')),
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $restaurantPayload
     */
    protected function attachRestaurantMedia(Restaurant $restaurant, array $restaurantPayload): void
    {
        $this->mediaLibraryService->syncUploadedMedia($restaurant, [
            'featured_image' => $restaurantPayload['restaurant_logo'] ?? null,
            'gallery_images' => $restaurantPayload['restaurant_photos'] ?? [],
            'gallery_image_alt_texts' => [],
        ]);

        $this->mediaLibraryService->syncMenuDocument(
            $restaurant,
            data_get($restaurantPayload, 'menu.pdf'),
        );
    }

    /**
     * @return array{first_name: string, last_name: ?string}
     */
    protected function splitName(string $name): array
    {
        $parts = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->values();

        return [
            'first_name' => $parts->first() ?? $name,
            'last_name' => $parts->slice(1)->implode(' ') ?: null,
        ];
    }

    protected function generateUniqueSlug(string $modelClass, string $value): string
    {
        $baseSlug = Str::slug($value);
        $baseSlug = $baseSlug !== '' ? $baseSlug : Str::lower(Str::random(8));
        $slug = $baseSlug;
        $counter = 2;

        while ($modelClass::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    protected function normalizeRestaurantStatus(RestaurantStatus|string $status): string
    {
        return $status instanceof RestaurantStatus ? $status->value : $status;
    }
}
