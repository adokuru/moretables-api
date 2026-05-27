<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Models\RestaurantMealSchedule;
use App\Models\RestaurantMealType;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantPolicy;
use App\Models\RestaurantReview;
use App\Models\Role;
use App\Models\SavedRestaurant;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('includes grouped menu items in the public restaurant detail response', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
    ]);

    RestaurantPolicy::factory()->create([
        'restaurant_id' => $restaurant->id,
    ]);

    foreach (range(0, 6) as $day) {
        RestaurantHour::factory()->create([
            'restaurant_id' => $restaurant->id,
            'day_of_week' => $day,
        ]);
    }

    RestaurantMenuItem::factory()->create([
        'restaurant_id' => $restaurant->id,
        'section_name' => 'Starters',
        'item_name' => 'Pepper Prawns',
        'description' => 'Grilled prawns with citrus butter.',
        'price' => 13500,
        'sort_order' => 0,
    ]);

    RestaurantMenuItem::factory()->create([
        'restaurant_id' => $restaurant->id,
        'section_name' => 'Starters',
        'item_name' => 'Corn Ribs',
        'description' => 'Smoky corn ribs with suya mayo.',
        'price' => 6200,
        'sort_order' => 1,
    ]);

    RestaurantMenuItem::factory()->create([
        'restaurant_id' => $restaurant->id,
        'section_name' => 'Mains',
        'item_name' => 'Lobster Rice',
        'description' => 'Butter lobster over fragrant rice.',
        'price' => 28500,
        'sort_order' => 2,
    ]);

    $restaurant
        ->addMedia(UploadedFile::fake()->image('restaurant-cover.png'))
        ->withCustomProperties(['alt_text' => 'Restaurant cover image'])
        ->toMediaCollection('featured');

    $restaurant
        ->addMedia(UploadedFile::fake()->image('restaurant-gallery.png'))
        ->withCustomProperties(['alt_text' => 'Restaurant gallery image'])
        ->toMediaCollection('gallery');

    $restaurant->menuItems()->firstOrFail()
        ->addMedia(UploadedFile::fake()->image('menu-item-cover.png'))
        ->withCustomProperties(['alt_text' => 'Menu item cover image'])
        ->toMediaCollection('featured');

    RestaurantReview::factory()->create([
        'restaurant_id' => $restaurant->id,
        'rating' => 5,
        'food_rating' => 5,
        'service_rating' => 4,
        'ambience_rating' => 4,
        'value_rating' => 4,
    ]);

    RestaurantReview::factory()->create([
        'restaurant_id' => $restaurant->id,
        'rating' => 4,
        'food_rating' => 3,
        'service_rating' => 5,
        'ambience_rating' => 4,
        'value_rating' => 5,
    ]);

    $response = $this->getJson('/api/v1/restaurants/'.$restaurant->slug);

    $response->assertOk()
        ->assertJsonPath('data.featured_image.featured', true)
        ->assertJsonPath('data.gallery_images.0.featured', false)
        ->assertJsonPath('data.menus.0.section', 'Starters')
        ->assertJsonPath('data.menus.0.items.0.name', 'Pepper Prawns')
        ->assertJsonPath('data.menus.0.items.0.featured_image.featured', true)
        ->assertJsonPath('data.menus.1.section', 'Mains')
        ->assertJsonPath('data.menus.1.items.0.price', 28500)
        ->assertJsonPath('data.discovery_metrics.bookings_count', 0)
        ->assertJsonPath('data.discovery_metrics.views_count', 0)
        ->assertJsonPath('data.discovery_metrics.saves_count', 0)
        ->assertJsonPath('data.discovery_metrics.list_adds_count', 0)
        ->assertJsonPath('data.discovery_metrics.reviews_count', 2)
        ->assertJsonPath('data.discovery_metrics.average_rating', 4.5)
        ->assertJsonPath('data.discovery_metrics.ratings_breakdown', [
            '5' => 1,
            '4' => 1,
            '3' => 0,
            '2' => 0,
            '1' => 0,
        ])
        ->assertJsonPath('data.summary.reviews_count', 2)
        ->assertJsonPath('data.summary.average_rating', 4.5)
        ->assertJsonPath('data.summary.ratings_breakdown', [
            '5' => 1,
            '4' => 1,
            '3' => 0,
            '2' => 0,
            '1' => 0,
        ])
        ->assertJsonPath('data.summary.category_breakdown.0', [
            'key' => 'food',
            'label' => 'Food',
            'average_rating' => 4,
        ])
        ->assertJsonPath('data.summary.category_breakdown.1', [
            'key' => 'service',
            'label' => 'Service',
            'average_rating' => 4.5,
        ])
        ->assertJsonPath('data.summary.category_breakdown.2', [
            'key' => 'ambience',
            'label' => 'Ambience',
            'average_rating' => 4,
        ])
        ->assertJsonPath('data.summary.category_breakdown.3', [
            'key' => 'value',
            'label' => 'Value',
            'average_rating' => 4.5,
        ])
        ->assertJsonPath('data.discovery_metrics.category_breakdown.0.key', 'food');
});

it('includes has_saved in the public restaurant detail response for authenticated users', function () {
    $restaurant = Restaurant::factory()->create();
    $user = User::factory()->create();

    SavedRestaurant::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
    ]);

    $response = $this->withToken($user->createToken('restaurant-detail')->plainTextToken)
        ->getJson('/api/v1/restaurants/'.$restaurant->slug);

    $response->assertOk()
        ->assertJsonPath('data.id', $restaurant->id)
        ->assertJsonPath('data.has_saved', true);
});

it('includes preferred meal schedules in the public restaurant detail response', function () {
    $restaurant = Restaurant::factory()->create();
    $mealType = RestaurantMealType::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Dinner',
        'sort_order' => 1,
    ]);

    RestaurantMealSchedule::create([
        'restaurant_id' => $restaurant->id,
        'restaurant_meal_type_id' => $mealType->id,
        'day_of_week' => 5,
        'opens_at' => '18:00',
        'closes_at' => '22:00',
    ]);

    $response = $this->getJson('/api/v1/restaurants/'.$restaurant->slug);

    $response->assertOk()
        ->assertJsonPath('data.meal_types.0.name', 'Dinner')
        ->assertJsonPath('data.meal_types.0.schedules.0.day_of_week', 5)
        ->assertJsonPath('data.meal_types.0.schedules.0.opens_at', '18:00')
        ->assertJsonPath('data.meal_types.0.schedules.0.closes_at', '22:00')
        ->assertJsonPath('data.meal_schedules.0.restaurant_meal_type_id', $mealType->id)
        ->assertJsonPath('data.meal_schedules.0.opens_at', '18:00');
});

it('only includes internal notes for users who can access the restaurant', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'internal_notes' => trim(str_repeat('Only restaurant staff should see this operational note. ', 3)),
    ]);
    $customer = User::factory()->create();
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::MarketingGrowth, $organization, $restaurant);

    $this->getJson('/api/v1/restaurants/'.$restaurant->slug)
        ->assertOk()
        ->assertJsonMissingPath('data.internal_notes');

    $this->withToken($customer->createToken('restaurant-detail')->plainTextToken)
        ->getJson('/api/v1/restaurants/'.$restaurant->slug)
        ->assertOk()
        ->assertJsonMissingPath('data.internal_notes');

    $this->withToken($staff->createToken('restaurant-detail')->plainTextToken)
        ->getJson('/api/v1/restaurants/'.$restaurant->slug)
        ->assertOk()
        ->assertJsonPath('data.internal_notes', $restaurant->internal_notes);
});
