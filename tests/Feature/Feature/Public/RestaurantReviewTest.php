<?php

use App\Models\RestaurantReview;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function customerRestaurantReviewUrl(string $restaurantSlug, ?int $reviewId = null): string
{
    $url = "/api/v1/restaurants/{$restaurantSlug}/reviews";

    return $reviewId ? "{$url}/{$reviewId}" : $url;
}

it('stores category ratings when a customer submits a review', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $this->postJson(customerRestaurantReviewUrl($data['restaurant']->slug), [
        'food_rating' => 5,
        'service_rating' => 4,
        'ambience_rating' => 3,
        'value_rating' => 4,
        'body' => 'The food was good but servers got attitude',
    ])
        ->assertCreated()
        ->assertJsonPath('review.rating', 4)
        ->assertJsonPath('review.ratings.food', 5)
        ->assertJsonPath('review.ratings.service', 4)
        ->assertJsonPath('review.ratings.ambience', 3)
        ->assertJsonPath('review.ratings.value', 4);

    $this->assertDatabaseHas('restaurant_reviews', [
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'rating' => 4,
        'food_rating' => 5,
        'service_rating' => 4,
        'ambience_rating' => 3,
        'value_rating' => 4,
    ]);
});

it('updates category ratings on a customer review', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();
    $review = RestaurantReview::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'rating' => 3,
        'food_rating' => 3,
        'service_rating' => 3,
        'ambience_rating' => 3,
        'value_rating' => 3,
    ]);
    Sanctum::actingAs($customer);

    $this->patchJson(customerRestaurantReviewUrl($data['restaurant']->slug, $review->id), [
        'food_rating' => 5,
        'service_rating' => 4,
        'ambience_rating' => 5,
        'value_rating' => 4,
    ])
        ->assertOk()
        ->assertJsonPath('review.rating', 4.5)
        ->assertJsonPath('review.ratings.food', 5)
        ->assertJsonPath('review.ratings.service', 4)
        ->assertJsonPath('review.ratings.ambience', 5)
        ->assertJsonPath('review.ratings.value', 4);

    $this->assertDatabaseHas('restaurant_reviews', [
        'id' => $review->id,
        'rating' => 4.5,
        'food_rating' => 5,
        'service_rating' => 4,
        'ambience_rating' => 5,
        'value_rating' => 4,
    ]);
});
