<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantMenuItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('persists link menu on restaurant update', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'menu_source' => 'manual',
        'menu_link' => null,
    ]);

    RestaurantMenuItem::factory()->create([
        'restaurant_id' => $restaurant->id,
        'section_name' => 'Old',
        'item_name' => 'Old Item',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->patchJson('/api/v1/admin/restaurants/'.$restaurant->id, [
        'menu_source' => 'link',
        'menu_link' => 'https://example.com/menu',
        'menu' => [
            'mode' => 'link',
            'link' => 'https://example.com/menu',
        ],
    ]);

    $response->assertOk();

    $restaurant->refresh();

    expect($restaurant->menu_source)->toBe('link')
        ->and($restaurant->menu_link)->toBe('https://example.com/menu')
        ->and($restaurant->menuItems()->count())->toBe(0);
});

it('persists manual categorized menu on restaurant update', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'menu_source' => 'link',
        'menu_link' => 'https://old.example.com/menu',
    ]);

    $existingItem = RestaurantMenuItem::factory()->create([
        'restaurant_id' => $restaurant->id,
        'section_name' => 'Breakfast',
        'item_name' => 'Old Jollof',
        'price' => 1000,
        'currency' => 'NGN',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->patchJson('/api/v1/admin/restaurants/'.$restaurant->id, [
        'menu_source' => 'manual',
        'menu' => [
            'mode' => 'manual',
            'currency' => 'ngn',
            'categories' => [
                [
                    'name' => 'Breakfast',
                    'items' => [
                        [
                            'id' => $existingItem->id,
                            'name' => 'Smokey Jollof',
                            'description' => 'Smoky and rich',
                            'price' => '25000',
                            'is_featured' => 1,
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertOk();

    $restaurant->refresh();
    $updatedItem = $restaurant->menuItems()->first();

    expect($restaurant->menu_source)->toBe('manual')
        ->and($restaurant->menu_link)->toBeNull()
        ->and($restaurant->menuItems()->count())->toBe(1)
        ->and($updatedItem?->item_name)->toBe('Smokey Jollof')
        ->and((float) $updatedItem?->price)->toBe(25000.0)
        ->and($updatedItem?->is_featured)->toBeTrue();
});

it('accepts multipart restaurant update via post method spoofing', function (): void {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'menu_source' => 'pdf',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->post('/api/v1/admin/restaurants/'.$restaurant->id, [
        '_method' => 'PATCH',
        'name' => 'Updated Via Post',
        'menu_source' => 'link',
        'menu_link' => 'https://example.com/new-menu',
        'menu' => [
            'mode' => 'link',
            'link' => 'https://example.com/new-menu',
        ],
        'restaurant_logo' => UploadedFile::fake()->image('logo.png'),
    ]);

    $response->assertOk();

    $restaurant->refresh();

    expect($restaurant->name)->toBe('Updated Via Post')
        ->and($restaurant->menu_source)->toBe('link')
        ->and($restaurant->menu_link)->toBe('https://example.com/new-menu')
        ->and($restaurant->getMedia('featured')->count())->toBe(1);
});

it('creates restaurant with frontend field aliases and manual menu', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();

    Sanctum::actingAs($admin);

    $response = $this->post('/api/v1/admin/restaurants', [
        'organization_id' => $organization->id,
        'name' => 'Frontend Alias Bistro',
        'email' => 'hello@alias.example.com',
        'phone' => '+2348000000999',
        'cuisine_type' => 'Italian',
        'booking_window_days' => 45,
        'reservation_duration_minutes' => 120,
        'menu_source' => 'manual',
        'menu' => [
            'mode' => 'manual',
            'currency' => 'ngn',
            'categories' => [
                [
                    'name' => 'Mains',
                    'items' => [
                        [
                            'name' => 'Pasta',
                            'description' => 'Fresh pasta',
                            'price' => '8500',
                            'is_featured' => 0,
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $response->assertCreated();

    $restaurant = Restaurant::query()->where('name', 'Frontend Alias Bistro')->first();

    expect($restaurant)->not->toBeNull()
        ->and($restaurant->menu_source)->toBe('manual')
        ->and($restaurant->menuItems()->count())->toBe(1)
        ->and($restaurant->policy?->booking_window_days)->toBe(45)
        ->and($restaurant->policy?->reservation_duration_minutes)->toBe(120)
        ->and($restaurant->cuisines()->pluck('name')->all())->toContain('Italian');
});
