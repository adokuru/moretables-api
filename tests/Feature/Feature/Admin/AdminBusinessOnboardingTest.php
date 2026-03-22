<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('allows business admins to onboard a business with multiple restaurants', function () {
    Storage::fake('public');
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $response = $this->post('/api/v1/admin/organizations/onboard', adminBusinessOnboardingPayload(), [
        'Accept' => 'application/json',
    ]);

    $response->assertCreated()
        ->assertJsonPath('organization.business_email', 'contact@chicken-republic.test')
        ->assertJsonPath('owner.email', 'owner@chicken-republic.test')
        ->assertJsonCount(3, 'restaurants');

    $organization = Organization::query()
        ->where('business_email', 'contact@chicken-republic.test')
        ->firstOrFail();

    $owner = User::query()->where('email', 'owner@chicken-republic.test')->firstOrFail();

    Notification::assertSentTo($owner, ResetPassword::class);
    $this->assertDatabaseHas('password_reset_tokens', [
        'email' => $owner->email,
    ]);

    expect($owner->hasRole(Role::OrganizationOwner, organization: $organization))->toBeTrue();

    $restaurants = Restaurant::query()
        ->where('organization_id', $organization->id)
        ->orderBy('name')
        ->get();

    expect($restaurants)->toHaveCount(3);

    foreach ($restaurants as $restaurant) {
        expect($owner->hasRole(Role::RestaurantManager, restaurant: $restaurant))->toBeTrue();
        expect($restaurant->diningAreas()->count())->toBe(1);
        expect($restaurant->diningAreas()->first()?->name)->toBe('Main Dining');
        expect($restaurant->tables()->count())->toBe($restaurant->number_of_tables);
        expect((int) $restaurant->tables()->sum('max_capacity'))->toBe($restaurant->total_seating_capacity);
    }

    $linkRestaurant = Restaurant::query()->where('name', 'Chicken Republic Ikeja')->firstOrFail();
    expect($linkRestaurant->menu_source)->toBe('link');
    expect($linkRestaurant->menu_link)->toBe('https://ikeja.chicken-republic.test/menu');
    expect($linkRestaurant->getMedia('featured'))->toHaveCount(1);
    expect($linkRestaurant->getMedia('gallery'))->toHaveCount(2);
    expect($linkRestaurant->policy?->booking_window_days)->toBe(7);
    expect($linkRestaurant->policy?->reservation_duration_minutes)->toBe(60);

    $manualRestaurant = Restaurant::query()->where('name', 'Chicken Republic Victoria Island')->firstOrFail();
    expect($manualRestaurant->menu_source)->toBe('manual');
    expect($manualRestaurant->menuItems()->count())->toBe(2);
    expect($manualRestaurant->menuItems()->orderBy('sort_order')->first()?->section_name)->toBe('Main Menu');

    $pdfRestaurant = Restaurant::query()->where('name', 'Chicken Republic Abuja')->firstOrFail();
    expect($pdfRestaurant->menu_source)->toBe('pdf');
    expect($pdfRestaurant->getMedia('menu_documents'))->toHaveCount(1);
});

it('validates onboarding request counts and menu payloads', function (Closure $mutate, string $errorField) {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $payload = adminBusinessOnboardingPayload();
    $payload = $mutate($payload);

    $response = $this->post('/api/v1/admin/organizations/onboard', $payload, [
        'Accept' => 'application/json',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);
})->with([
    'restaurant count mismatch' => [
        fn (array $payload): array => tap($payload, function (array &$payload): void {
            $payload['restaurants_count'] = 2;
        }),
        'restaurants_count',
    ],
    'missing link menu url' => [
        fn (array $payload): array => tap($payload, function (array &$payload): void {
            unset($payload['restaurants'][0]['menu']['link']);
        }),
        'restaurants.0.menu.link',
    ],
    'missing manual menu items' => [
        fn (array $payload): array => tap($payload, function (array &$payload): void {
            $payload['restaurants'][1]['menu']['items'] = [];
        }),
        'restaurants.1.menu.items',
    ],
    'missing pdf file' => [
        fn (array $payload): array => tap($payload, function (array &$payload): void {
            unset($payload['restaurants'][2]['menu']['pdf']);
        }),
        'restaurants.2.menu.pdf',
    ],
    'seating lower than tables' => [
        fn (array $payload): array => tap($payload, function (array &$payload): void {
            $payload['restaurants'][0]['total_seating_capacity'] = 2;
            $payload['restaurants'][0]['number_of_tables'] = 3;
        }),
        'restaurants.0.total_seating_capacity',
    ],
]);

it('forbids non-admin users from onboarding businesses', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/admin/organizations/onboard', [
        'business_name' => 'Blocked Business',
        'business_phone' => '+2348000000100',
        'owner_name' => 'Blocked Owner',
        'owner_phone' => '+2348000000101',
        'owner_email' => 'blocked-owner@example.com',
        'business_email' => 'blocked@example.com',
        'business_website' => 'https://blocked.example.com',
        'business_city' => 'Lagos',
        'business_state' => 'Lagos',
        'business_country' => 'Nigeria',
        'restaurants_count' => 0,
        'restaurants' => [],
    ]);

    $response->assertForbidden();
});

it('keeps existing admin and merchant restaurant surfaces working with onboarding fields', function () {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $organizationResponse = $this->postJson('/api/v1/admin/organizations', [
        'name' => 'Surface Org',
        'slug' => 'surface-org',
        'business_phone' => '+2348000000200',
        'business_email' => 'surface@example.com',
        'website' => 'https://surface.example.com',
        'billing_email' => 'billing@surface.example.com',
        'tax_id' => 'VAT-2000',
        'registration_number' => 'RC-2000',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
    ]);

    $organizationResponse->assertCreated()
        ->assertJsonPath('organization.business_email', 'surface@example.com');

    $restaurantResponse = $this->post('/api/v1/admin/restaurants', [
        'organization_id' => $organizationResponse->json('organization.id'),
        'name' => 'Surface Restaurant',
        'slug' => 'surface-restaurant',
        'website' => 'https://surface-restaurant.example.com',
        'instagram_handle' => '@surface_restaurant',
        'average_price_range' => '$$$',
        'dining_style' => 'Fine Dining',
        'dress_code' => 'Smart Casual',
        'menu_source' => 'pdf',
        'payment_options' => ['Paystack', 'Visa'],
        'accessibility_features' => ['Wheelchair Accessible'],
        'total_seating_capacity' => 50,
        'number_of_tables' => 12,
        'menu_document' => UploadedFile::fake()->create('surface-menu.pdf', 100, 'application/pdf'),
        'featured_image' => UploadedFile::fake()->image('surface-logo.png'),
    ], ['Accept' => 'application/json']);

    $restaurantResponse->assertCreated()
        ->assertJsonPath('restaurant.menu_source', 'pdf')
        ->assertJsonPath('restaurant.website', 'https://surface-restaurant.example.com')
        ->assertJsonCount(1, 'restaurant.menu_documents');

    $organization = Organization::query()->findOrFail($organizationResponse->json('organization.id'));
    $restaurant = Restaurant::query()->findOrFail($restaurantResponse->json('restaurant.id'));

    $manager = User::factory()->create();
    assignScopedRole($manager, Role::RestaurantManager, $organization, $restaurant);

    Sanctum::actingAs($manager);

    $updateResponse = $this->patch('/api/v1/merchant/restaurants/'.$restaurant->id, [
        'website' => 'https://merchant-update.example.com',
        'instagram_handle' => '@merchant_update',
        'average_price_range' => '$$',
        'dining_style' => 'Casual Dining',
        'dress_code' => 'Casual',
        'menu_source' => 'link',
        'menu_link' => 'https://merchant-update.example.com/menu',
        'payment_options' => ['Visa', 'Mastercard'],
        'accessibility_features' => ['Vegetarian Options', 'Wheelchair Accessible'],
        'total_seating_capacity' => 60,
        'number_of_tables' => 15,
        'cuisines' => ['Contemporary'],
        'hours' => restaurantHoursPayload(),
        'policy' => [
            'booking_window_days' => 14,
            'reservation_duration_minutes' => 90,
        ],
    ], ['Accept' => 'application/json']);

    $updateResponse->assertOk()
        ->assertJsonPath('restaurant.website', 'https://merchant-update.example.com')
        ->assertJsonPath('restaurant.menu_source', 'link')
        ->assertJsonPath('restaurant.menu_link', 'https://merchant-update.example.com/menu')
        ->assertJsonPath('restaurant.policy.booking_window_days', 14)
        ->assertJsonPath('restaurant.policy.reservation_duration_minutes', 90)
        ->assertJsonFragment(['Contemporary']);
});

function adminBusinessOnboardingPayload(): array
{
    return [
        'business_name' => 'Chicken Republic Limited',
        'business_phone' => '+2348000000000',
        'owner_name' => 'Ada Okafor',
        'owner_phone' => '+2348000000001',
        'owner_email' => 'owner@chicken-republic.test',
        'business_email' => 'contact@chicken-republic.test',
        'business_website' => 'https://chicken-republic.test',
        'billing_email' => 'billing@chicken-republic.test',
        'tax_id' => 'VAT-1000',
        'registration_number' => 'RC-1000',
        'business_city' => 'Lagos',
        'business_state' => 'Lagos',
        'business_country' => 'Nigeria',
        'restaurants_count' => 3,
        'restaurants' => [
            [
                'name' => 'Chicken Republic Ikeja',
                'phone' => '+2348000000002',
                'description' => 'The flagship Ikeja branch.',
                'website' => 'https://ikeja.chicken-republic.test',
                'instagram_handle' => '@chickenrep_ikeja',
                'cuisine_type' => 'Nigerian',
                'average_price_range' => '$$',
                'dining_style' => 'Casual Dining',
                'dress_code' => 'Casual',
                'country' => 'Nigeria',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'address_line_1' => '12 Allen Avenue',
                'latitude' => 6.6018,
                'longitude' => 3.3515,
                'hours' => restaurantHoursPayload(),
                'accessibility_features' => ['Wheelchair Accessible', 'Vegetarian Options'],
                'payment_options' => ['Paystack', 'Visa'],
                'restaurant_logo' => UploadedFile::fake()->image('ikeja-logo.png'),
                'restaurant_photos' => [
                    UploadedFile::fake()->image('ikeja-room.png'),
                    UploadedFile::fake()->image('ikeja-terrace.png'),
                ],
                'total_seating_capacity' => 12,
                'number_of_tables' => 3,
                'booking_window_days' => 7,
                'reservation_duration_minutes' => 60,
                'menu' => [
                    'mode' => 'link',
                    'link' => 'https://ikeja.chicken-republic.test/menu',
                ],
            ],
            [
                'name' => 'Chicken Republic Victoria Island',
                'phone' => '+2348000000003',
                'description' => 'The waterfront location.',
                'website' => 'https://vi.chicken-republic.test',
                'instagram_handle' => '@chickenrep_vi',
                'cuisine_type' => 'African',
                'average_price_range' => '$$$',
                'dining_style' => 'Fine Dining',
                'dress_code' => 'Smart Casual',
                'country' => 'Nigeria',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'address_line_1' => '88 Admiralty Way',
                'hours' => restaurantHoursPayload(),
                'accessibility_features' => ['Step-free access (no stairs)'],
                'payment_options' => ['Visa', 'Mastercard'],
                'restaurant_logo' => UploadedFile::fake()->image('vi-logo.png'),
                'restaurant_photos' => [
                    UploadedFile::fake()->image('vi-room.png'),
                ],
                'total_seating_capacity' => 8,
                'number_of_tables' => 2,
                'booking_window_days' => 14,
                'reservation_duration_minutes' => 90,
                'menu' => [
                    'mode' => 'manual',
                    'name' => 'Main Menu',
                    'currency' => 'NGN',
                    'items' => [
                        [
                            'name' => 'Jollof Rice',
                            'price' => 8500,
                            'description' => 'Signature smoky jollof.',
                        ],
                        [
                            'name' => 'Grilled Chicken',
                            'price' => 12000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chicken Republic Abuja',
                'phone' => '+2348000000004',
                'description' => 'The Abuja city centre branch.',
                'website' => 'https://abuja.chicken-republic.test',
                'instagram_handle' => '@chickenrep_abuja',
                'cuisine_type' => 'International',
                'average_price_range' => '$$',
                'dining_style' => 'Family Style',
                'dress_code' => 'Business Casual',
                'country' => 'Nigeria',
                'city' => 'Abuja',
                'state' => 'FCT',
                'address_line_1' => '10 Central Business District',
                'hours' => restaurantHoursPayload(),
                'accessibility_features' => ['Wheelchair Accessible'],
                'payment_options' => ['Paystack'],
                'restaurant_logo' => UploadedFile::fake()->image('abuja-logo.png'),
                'total_seating_capacity' => 10,
                'number_of_tables' => 4,
                'booking_window_days' => 10,
                'reservation_duration_minutes' => 75,
                'menu' => [
                    'mode' => 'pdf',
                    'pdf' => UploadedFile::fake()->create('abuja-menu.pdf', 120, 'application/pdf'),
                ],
            ],
        ],
    ];
}

function restaurantHoursPayload(): array
{
    return [
        ['day_of_week' => 0, 'opens_at' => '10:00', 'closes_at' => '22:00', 'is_closed' => false],
        ['day_of_week' => 1, 'opens_at' => '10:00', 'closes_at' => '22:00', 'is_closed' => false],
        ['day_of_week' => 2, 'opens_at' => '10:00', 'closes_at' => '22:00', 'is_closed' => false],
        ['day_of_week' => 3, 'opens_at' => '10:00', 'closes_at' => '22:00', 'is_closed' => false],
        ['day_of_week' => 4, 'opens_at' => '10:00', 'closes_at' => '22:00', 'is_closed' => false],
        ['day_of_week' => 5, 'opens_at' => '11:00', 'closes_at' => '23:00', 'is_closed' => false],
        ['day_of_week' => 6, 'opens_at' => '11:00', 'closes_at' => '23:00', 'is_closed' => false],
    ];
}
