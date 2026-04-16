<?php

use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

it('generates fake reviews for a specific restaurant', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $restaurant = Restaurant::factory()->create([
        'slug' => 'test-restaurant',
    ]);

    $this->artisan('app:generate-fake-reviews', [
        'restaurant' => 'test-restaurant',
        '--count' => 6,
    ])->assertSuccessful();

    expect(RestaurantReview::query()->where('restaurant_id', $restaurant->id)->count())->toBe(6);

    $customerCount = User::query()
        ->whereHas('roleAssignments.role', fn ($query) => $query->where('name', Role::Customer))
        ->count();

    expect($customerCount)->toBe(6);
});

it('generates fake reviews for random active restaurants', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    Restaurant::factory()->count(3)->create();

    $this->artisan('app:generate-fake-reviews', [
        '--restaurants' => 2,
        '--count' => 4,
    ])->assertSuccessful();

    expect(RestaurantReview::query()->count())->toBe(8);
});
