<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantAvailabilityPeriod;
use App\Models\RestaurantAvailabilitySchedule;
use App\Models\RestaurantHour;
use App\Models\RestaurantReview;
use App\Models\RestaurantTable;
use App\Models\SavedRestaurant;
use App\Models\User;
use App\TableType;

it('filters restaurants by coordinates using latitude and longitude', function () {
    $organization = Organization::factory()->create();

    $nearRestaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Near Restaurant',
        'slug' => 'near-restaurant',
        'is_featured' => true,
        'latitude' => 6.4500000,
        'longitude' => 3.4500000,
    ]);

    Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Far Restaurant',
        'slug' => 'far-restaurant',
        'latitude' => 9.0765000,
        'longitude' => 7.3986000,
    ]);

    $response = $this->getJson('/api/v1/restaurants?latitude=6.4500000&longitude=3.4500000&radius_km=15');

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $nearRestaurant->id)
        ->assertJsonPath('0.is_featured', true)
        ->assertJsonPath('0.latitude', 6.45)
        ->assertJsonPath('0.longitude', 3.45);
});

it('sorts restaurants by the requested mode', function () {
    $organization = Organization::factory()->create();

    $featured = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Featured Restaurant',
        'slug' => 'featured-restaurant',
        'is_featured' => true,
        'latitude' => 6.4500,
        'longitude' => 3.4500,
        'created_at' => now()->subDays(3),
    ]);
    $newest = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Newest Restaurant',
        'slug' => 'newest-restaurant',
        'latitude' => 6.4550,
        'longitude' => 3.4550,
        'created_at' => now(),
    ]);
    $rated = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Rated Restaurant',
        'slug' => 'rated-restaurant',
        'latitude' => 6.4600,
        'longitude' => 3.4600,
        'created_at' => now()->subDay(),
    ]);
    RestaurantReview::factory()->for($rated)->create(['rating' => 5]);
    RestaurantReview::factory()->for($newest)->create(['rating' => 3]);

    $base = '/api/v1/restaurants?latitude=6.4500&longitude=3.4500&radius_km=25&per_page=10&sort=';

    $this->getJson($base.'featured')->assertOk()->assertJsonPath('0.id', $featured->id);
    $this->getJson($base.'distance')->assertOk()->assertJsonPath('0.id', $featured->id);
    $this->getJson($base.'newest')->assertOk()->assertJsonPath('0.id', $newest->id);
    $this->getJson($base.'rating')->assertOk()->assertJsonPath('0.id', $rated->id);
});

it('filters restaurants by canonical price and seating values', function () {
    $matching = createListedRestaurant([
        'name' => 'Matching Restaurant',
        'slug' => 'matching-restaurant',
        'average_price_range' => '10k and under',
    ]);
    RestaurantTable::factory()->for($matching)->create([
        'dining_area_id' => null,
        'table_type' => TableType::Bar,
    ]);

    $other = createListedRestaurant([
        'name' => 'Other Restaurant',
        'slug' => 'other-restaurant',
        'average_price_range' => '20k and under',
    ]);
    RestaurantTable::factory()->for($other)->create([
        'dining_area_id' => null,
        'table_type' => TableType::Regular,
    ]);

    $this->getJson('/api/v1/restaurants?price=10k%20and%20under&seating=bar')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $matching->id);
});

it('validates restaurant sort, price, and seating filters', function () {
    $this->getJson('/api/v1/restaurants?sort=nearby&price=cheap&seating=sofa')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sort', 'price', 'seating']);

    $this->getJson('/api/v1/restaurants?sort=distance')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude', 'longitude']);
});

it('includes has_saved for authenticated users on restaurant listings', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    $savedRestaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Saved Restaurant',
        'slug' => 'saved-restaurant',
        'city' => 'Lagos',
    ]);

    $unsavedRestaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Unsaved Restaurant',
        'slug' => 'unsaved-restaurant',
        'city' => 'Lagos',
    ]);

    SavedRestaurant::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $savedRestaurant->id,
    ]);

    $response = $this->withToken($user->createToken('restaurant-list')->plainTextToken)
        ->getJson('/api/v1/restaurants?city=Lagos');

    $response->assertOk()
        ->assertJsonFragment([
            'id' => $savedRestaurant->id,
            'has_saved' => true,
        ])
        ->assertJsonFragment([
            'id' => $unsavedRestaurant->id,
            'has_saved' => false,
        ]);
});

it('uses meal schedules to summarize hours on restaurant listings', function () {
    $restaurant = createListedRestaurant([
        'slug' => 'scheduled-restaurant',
        'city' => 'Lagos',
    ]);
    RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();
    RestaurantHour::factory()->create([
        'restaurant_id' => $restaurant->id,
        'day_of_week' => 4,
        'opens_at' => '09:00',
        'closes_at' => '10:00',
    ]);
    $breakfast = RestaurantAvailabilityPeriod::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Breakfast',
    ]);
    $dinner = RestaurantAvailabilityPeriod::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Dinner',
    ]);

    RestaurantAvailabilitySchedule::create([
        'restaurant_id' => $restaurant->id,
        'restaurant_meal_type_id' => $breakfast->id,
        'day_of_week' => 4,
        'opens_at' => '06:00',
        'closes_at' => '09:30',
    ]);
    RestaurantAvailabilitySchedule::create([
        'restaurant_id' => $restaurant->id,
        'restaurant_meal_type_id' => $dinner->id,
        'day_of_week' => 4,
        'opens_at' => '18:00',
        'closes_at' => '22:00',
    ]);

    $response = $this->getJson('/api/v1/restaurants?city=Lagos');

    $response->assertOk()
        ->assertJsonPath('0.id', $restaurant->id)
        ->assertJsonCount(7, '0.hours')
        ->assertJsonPath('0.hours.4.day_of_week', 4)
        ->assertJsonPath('0.hours.4.opens_at', '06:00')
        ->assertJsonPath('0.hours.4.closes_at', '22:00')
        ->assertJsonPath('0.hours.4.is_closed', false);
});

it('excludes active restaurants without an active merchant subscription from public listings', function () {
    $organization = Organization::factory()->create();

    $paidRestaurant = createListedRestaurant([
        'organization_id' => $organization->id,
        'name' => 'Paid Restaurant',
        'slug' => 'paid-restaurant',
        'city' => 'Lagos',
    ]);

    Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Unpaid Restaurant',
        'slug' => 'unpaid-restaurant',
        'city' => 'Lagos',
    ]);

    $response = $this->getJson('/api/v1/restaurants?city=Lagos');

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $paidRestaurant->id);
});
