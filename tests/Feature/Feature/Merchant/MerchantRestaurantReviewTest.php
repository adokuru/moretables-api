<?php

use App\Models\RestaurantReview;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

function merchantReviewUrl(int $restaurantId, string $suffix = ''): string
{
    return "/api/v1/merchant/restaurants/{$restaurantId}/reviews{$suffix}";
}

it('returns aggregate review metrics for a merchant restaurant', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $data['organization'], $data['restaurant']);

    RestaurantReview::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'rating' => 4,
        'food_rating' => 5,
        'service_rating' => 4,
        'ambience_rating' => 4,
        'value_rating' => 3,
        'created_at' => '2025-09-01 12:00:00',
    ]);
    RestaurantReview::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'rating' => 5,
        'food_rating' => 4,
        'service_rating' => 5,
        'ambience_rating' => 5,
        'value_rating' => 4,
        'created_at' => '2025-09-10 12:00:00',
    ]);
    RestaurantReview::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'rating' => 3,
        'food_rating' => 3,
        'service_rating' => 2,
        'ambience_rating' => 3,
        'value_rating' => 5,
        'created_at' => '2025-08-01 12:00:00',
    ]);

    Sanctum::actingAs($staff);

    $this->getJson(merchantReviewUrl($data['restaurant']->id, '/aggregate').'?date_from=2025-09-01&date_to=2025-09-30')
        ->assertOk()
        ->assertJsonPath('summary.overall_rating', 4.5)
        ->assertJsonPath('summary.total_customer_reviews', 2)
        ->assertJsonPath('summary.ratings_breakdown.5', 1)
        ->assertJsonPath('summary.ratings_breakdown.4', 1)
        ->assertJsonPath('category_breakdown.0.key', 'food')
        ->assertJsonPath('category_breakdown.0.average_rating', 4.5)
        ->assertJsonPath('category_breakdown.1.key', 'service')
        ->assertJsonPath('category_breakdown.1.average_rating', 4.5)
        ->assertJsonPath('period.date_from', '2025-09-01')
        ->assertJsonPath('period.date_to', '2025-09-30');
});

it('lists detailed reviews for a merchant restaurant', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $staff = User::factory()->create();
    $diner = User::factory()->create([
        'first_name' => 'Samuel',
        'last_name' => 'Taiwo',
        'email' => 'samuel@example.com',
    ]);
    assignScopedRole($staff, Role::Operations, $data['organization'], $data['restaurant']);

    RestaurantReview::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $diner->id,
        'rating' => 4,
        'food_rating' => 5,
        'service_rating' => 4,
        'ambience_rating' => 4,
        'value_rating' => 3,
        'body' => 'The food was good but servers got attitude',
        'created_at' => '2026-01-21 12:00:00',
    ]);
    RestaurantReview::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'rating' => 2,
        'body' => 'This should be filtered out',
    ]);

    Sanctum::actingAs($staff);

    $this->getJson(merchantReviewUrl($data['restaurant']->id).'?rating=4&search=Samuel')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.diner.name', 'Samuel Taiwo')
        ->assertJsonPath('data.0.diner.email', 'samuel@example.com')
        ->assertJsonPath('data.0.review_date', '2026-01-21')
        ->assertJsonPath('data.0.rating', 4)
        ->assertJsonPath('data.0.ratings.food', 5)
        ->assertJsonPath('data.0.commentary', 'The food was good but servers got attitude')
        ->assertJsonPath('meta.total', 1);
});

it('forbids users without restaurant access from merchant review endpoints', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(merchantReviewUrl($data['restaurant']->id))->assertForbidden();
    $this->getJson(merchantReviewUrl($data['restaurant']->id, '/aggregate'))->assertForbidden();
});

it('requires authentication for merchant review endpoints', function () {
    $data = createBookableRestaurant();

    $this->getJson(merchantReviewUrl($data['restaurant']->id))->assertUnauthorized();
    $this->getJson(merchantReviewUrl($data['restaurant']->id, '/aggregate'))->assertUnauthorized();
});
