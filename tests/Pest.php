<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Models\RestaurantPolicy;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function assignScopedRole(
    User $user,
    string $roleName,
    ?Organization $organization = null,
    ?Restaurant $restaurant = null,
    ?User $assignedBy = null,
): void {
    $roleId = Role::query()->where('name', $roleName)->value('id');

    if (! $roleId) {
        return;
    }

    UserRole::query()->create([
        'user_id' => $user->id,
        'role_id' => $roleId,
        'scope_type' => $restaurant ? 'restaurant' : ($organization ? 'organization' : null),
        'organization_id' => $organization?->id,
        'restaurant_id' => $restaurant?->id,
        'assigned_by' => $assignedBy?->id ?? $user->id,
    ]);
}

/**
 * @return array{organization: Organization, restaurant: Restaurant, table: RestaurantTable}
 */
function createBookableRestaurant(): array
{
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

    $table = RestaurantTable::factory()->create([
        'restaurant_id' => $restaurant->id,
        'dining_area_id' => null,
        'max_capacity' => 4,
    ]);

    return compact('organization', 'restaurant', 'table');
}
