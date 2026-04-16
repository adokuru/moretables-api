<?php

use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\Models\RestaurantView;
use App\Models\SavedRestaurant;
use App\Models\User;
use App\Models\UserRestaurantList;
use App\Models\UserRestaurantListItem;
use App\ReservationStatus;
use App\RestaurantStatus;
use Laravel\Sanctum\Sanctum;

it('returns discovery sections for top booked viewed saved rated new featured timeofday and lineup restaurants', function () {
    $organization = Organization::factory()->create();

    $bookedChampion = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Booked Champion',
        'slug' => 'booked-champion',
        'created_at' => now()->subDays(6),
        'updated_at' => now()->subDays(6),
    ]);

    $viewedChampion = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Viewed Champion',
        'slug' => 'viewed-champion',
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(5),
    ]);

    $savedChampion = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Saved Champion',
        'slug' => 'saved-champion',
        'created_at' => now()->subDays(4),
        'updated_at' => now()->subDays(4),
    ]);

    $ratedChampion = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Rated Champion',
        'slug' => 'rated-champion',
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);

    $featuredChampion = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Featured Champion',
        'slug' => 'featured-champion',
        'is_featured' => true,
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDay(),
    ]);

    $newChampion = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'New Champion',
        'slug' => 'new-champion',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Reservation::factory()->count(5)->create([
        'restaurant_id' => $bookedChampion->id,
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Completed,
        'starts_at' => now()->subDays(3)->setTime(19, 0),
        'ends_at' => now()->subDays(3)->setTime(21, 0),
    ]);

    Reservation::factory()->count(2)->create([
        'restaurant_id' => $viewedChampion->id,
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Confirmed,
        'starts_at' => now()->subDays(2)->setTime(19, 0),
        'ends_at' => now()->subDays(2)->setTime(21, 0),
    ]);

    RestaurantView::factory()->count(7)->create([
        'restaurant_id' => $viewedChampion->id,
    ]);

    RestaurantView::factory()->count(3)->create([
        'restaurant_id' => $bookedChampion->id,
    ]);

    $savedUsers = User::factory()->count(6)->create();

    foreach ($savedUsers as $savedUser) {
        SavedRestaurant::factory()->create([
            'user_id' => $savedUser->id,
            'restaurant_id' => $savedChampion->id,
        ]);
    }

    $listOwner = User::factory()->create();
    $restaurantList = UserRestaurantList::factory()->create([
        'user_id' => $listOwner->id,
        'name' => 'Top picks',
    ]);

    UserRestaurantListItem::factory()->create([
        'user_restaurant_list_id' => $restaurantList->id,
        'restaurant_id' => $savedChampion->id,
        'sort_order' => 0,
    ]);

    $reviewers = User::factory()->count(3)->create();

    RestaurantReview::factory()->create([
        'restaurant_id' => $ratedChampion->id,
        'user_id' => $reviewers[0]->id,
        'rating' => 5,
    ]);
    RestaurantReview::factory()->create([
        'restaurant_id' => $ratedChampion->id,
        'user_id' => $reviewers[1]->id,
        'rating' => 5,
    ]);
    RestaurantReview::factory()->create([
        'restaurant_id' => $ratedChampion->id,
        'user_id' => $reviewers[2]->id,
        'rating' => 4,
    ]);

    $response = $this->getJson('/api/v1/restaurants/discovery?limit=3');

    $response->assertOk()
        ->assertJsonPath('sections.top_booked.restaurants.0.id', $bookedChampion->id)
        ->assertJsonPath('sections.top_booked.restaurants.0.discovery_metrics.bookings_count', 5)
        ->assertJsonPath('sections.top_viewed.restaurants.0.id', $viewedChampion->id)
        ->assertJsonPath('sections.top_viewed.restaurants.0.discovery_metrics.views_count', 7)
        ->assertJsonPath('sections.top_saved.restaurants.0.id', $savedChampion->id)
        ->assertJsonPath('sections.top_saved.restaurants.0.discovery_metrics.saves_count', 6)
        ->assertJsonPath('sections.top_saved.restaurants.0.discovery_metrics.list_adds_count', 1)
        ->assertJsonPath('sections.highly_rated.restaurants.0.id', $ratedChampion->id)
        ->assertJsonPath('sections.highly_rated.restaurants.0.discovery_metrics.average_rating', 4.67)
        ->assertJsonPath('sections.new_on_moretables.restaurants.0.id', $newChampion->id)
        ->assertJsonPath('sections.featured.restaurants.0.id', $featuredChampion->id)
        ->assertJsonPath('sections.featured.label', 'Featured')
        ->assertJsonPath('sections.timeofday.restaurants.0.id', $bookedChampion->id)
        ->assertJsonStructure([
            'sections' => [
                'timeofday' => ['label', 'restaurants'],
                'moretable_lineup' => ['label', 'restaurants'],
            ],
        ]);

    $sectionResponse = $this->getJson('/api/v1/restaurants/discovery/top-saved?per_page=2');

    $sectionResponse->assertOk()
        ->assertJsonPath('section', 'top_saved')
        ->assertJsonPath('label', 'Top Saved')
        ->assertJsonPath('data.0.id', $savedChampion->id)
        ->assertJsonPath('meta.per_page', 2);

    $lineupSectionResponse = $this->getJson('/api/v1/restaurants/discovery/moretable-lineup?per_page=2');

    $lineupSectionResponse->assertOk()
        ->assertJsonPath('section', 'moretable_lineup')
        ->assertJsonPath('label', 'The Moretable Lineup')
        ->assertJsonPath('meta.per_page', 2);
});

it('records restaurant views for discovery metrics', function () {
    $restaurant = Restaurant::factory()->create();

    $response = $this->postJson('/api/v1/restaurants/'.$restaurant->slug.'/views', [
        'platform' => 'ios',
        'session_id' => 'session-123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Restaurant view recorded successfully.');

    $this->assertDatabaseHas('restaurant_views', [
        'restaurant_id' => $restaurant->id,
        'platform' => 'ios',
        'session_id' => 'session-123',
    ]);
});

it('returns random public reviews with rating customer name restaurant name and notes ordered by highest rating first', function () {
    $organization = Organization::factory()->create();
    $activeRestaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Ocean Basket',
        'status' => RestaurantStatus::Active,
    ]);
    $anotherActiveRestaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'The Grill House',
        'status' => RestaurantStatus::Active,
    ]);
    $inactiveRestaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Hidden Spot',
        'status' => RestaurantStatus::Suspended,
    ]);

    $customer = User::factory()->create([
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'name' => 'Ada Okafor',
    ]);

    RestaurantReview::factory()->create([
        'restaurant_id' => $activeRestaurant->id,
        'user_id' => $customer->id,
        'rating' => 5,
        'body' => null,
    ]);

    RestaurantReview::factory()->create([
        'restaurant_id' => $anotherActiveRestaurant->id,
        'user_id' => User::factory()->create()->id,
        'rating' => 3,
    ]);

    RestaurantReview::factory()->create([
        'restaurant_id' => $inactiveRestaurant->id,
        'user_id' => User::factory()->create()->id,
        'rating' => 2,
    ]);

    $response = $this->getJson('/api/v1/reviews/random?limit=2');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.rating', 5)
        ->assertJsonPath('data.0.notes', null)
        ->assertJsonPath('data.0.customer_name', 'Ada Okafor')
        ->assertJsonPath('data.0.restaurant_name', 'Ocean Basket')
        ->assertJsonPath('data.1.rating', 3);
});

it('lets authenticated users save restaurants and manage custom restaurant lists', function () {
    $customer = User::factory()->create();
    $restaurant = Restaurant::factory()->create();

    Sanctum::actingAs($customer);

    $saveResponse = $this->postJson('/api/v1/restaurants/'.$restaurant->slug.'/save');

    $saveResponse->assertCreated()
        ->assertJsonPath('message', 'Restaurant saved successfully.')
        ->assertJsonPath('saved_restaurant.restaurant_id', $restaurant->id);

    $duplicateSaveResponse = $this->postJson('/api/v1/restaurants/'.$restaurant->slug.'/save');

    $duplicateSaveResponse->assertOk()
        ->assertJsonPath('message', 'Restaurant was already saved.');

    $savedIndexResponse = $this->getJson('/api/v1/me/saved-restaurants');

    $savedIndexResponse->assertOk()
        ->assertJsonPath('data.0.id', $restaurant->id)
        ->assertJsonPath('meta.total', 1);

    $createListResponse = $this->postJson('/api/v1/me/restaurant-lists', [
        'name' => 'Weekend Plans',
        'description' => 'Places to check out this weekend.',
        'is_private' => true,
    ]);

    $createListResponse->assertCreated()
        ->assertJsonPath('list.name', 'Weekend Plans')
        ->assertJsonPath('list.is_private', true);

    $listId = $createListResponse->json('list.id');

    $addRestaurantResponse = $this->postJson('/api/v1/me/restaurant-lists/'.$listId.'/restaurants', [
        'restaurant_id' => $restaurant->id,
    ]);

    $addRestaurantResponse->assertCreated()
        ->assertJsonPath('list.restaurants_count', 1)
        ->assertJsonPath('list.restaurants.0.id', $restaurant->id);

    $listIndexResponse = $this->getJson('/api/v1/me/restaurant-lists');

    $listIndexResponse->assertOk()
        ->assertJsonPath('data.0.name', 'Weekend Plans')
        ->assertJsonPath('data.0.restaurants.0.id', $restaurant->id);

    $removeRestaurantResponse = $this->deleteJson('/api/v1/me/restaurant-lists/'.$listId.'/restaurants/'.$restaurant->slug);

    $removeRestaurantResponse->assertOk()
        ->assertJsonPath('list.restaurants_count', 0);

    $unsaveResponse = $this->deleteJson('/api/v1/restaurants/'.$restaurant->slug.'/save');

    $unsaveResponse->assertOk()
        ->assertJsonPath('message', 'Restaurant removed from saved items successfully.');
});

it('lets authenticated users create update and list restaurant reviews', function () {
    $customer = User::factory()->create([
        'name' => 'Ada Okafor',
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
    ]);
    $restaurant = Restaurant::factory()->create();

    Sanctum::actingAs($customer);

    $createResponse = $this->postJson('/api/v1/restaurants/'.$restaurant->slug.'/reviews', [
        'rating' => 5,
        'title' => 'Excellent dinner',
        'body' => 'Loved the tasting menu and service.',
        'visited_at' => now()->subDay()->toDateString(),
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('message', 'Review submitted successfully.')
        ->assertJsonPath('review.rating', 5)
        ->assertJsonPath('review.reviewer.name', 'Ada Okafor');

    $duplicateResponse = $this->postJson('/api/v1/restaurants/'.$restaurant->slug.'/reviews', [
        'rating' => 4,
    ]);

    $duplicateResponse->assertUnprocessable()
        ->assertJsonValidationErrors(['rating']);

    $reviewId = $createResponse->json('review.id');

    $updateResponse = $this->patchJson('/api/v1/restaurants/'.$restaurant->slug.'/reviews/'.$reviewId, [
        'rating' => 4,
        'body' => 'Still great, but dessert was the highlight.',
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('review.rating', 4)
        ->assertJsonPath('review.notes', 'Still great, but dessert was the highlight.');

    $indexResponse = $this->getJson('/api/v1/restaurants/'.$restaurant->slug.'/reviews');

    $indexResponse->assertOk()
        ->assertJsonStructure(['data', 'summary', 'links', 'meta'])
        ->assertJsonPath('summary.reviews_count', 1)
        ->assertJsonPath('summary.average_rating', 4)
        ->assertJsonPath('summary.ratings_breakdown.4', 1)
        ->assertJsonPath('links.prev', null)
        ->assertJsonPath('data.0.reviewer.name', 'Ada Okafor')
        ->assertJsonPath('data.0.reviewer.initials', 'AO');
});
