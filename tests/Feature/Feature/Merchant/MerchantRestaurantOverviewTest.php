<?php

use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use App\ReservationStatus;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);
});

it('returns overview data for a restaurant with reservations', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $data['organization'], $data['restaurant']);

    // Add two more staff users so total_users = 3 (staff + the one created above)
    $extraStaff1 = User::factory()->create();
    $extraStaff2 = User::factory()->create();
    assignScopedRole($extraStaff1, Role::GuestRelations, $data['organization'], $data['restaurant']);
    assignScopedRole($extraStaff2, Role::Operations, $data['organization'], $data['restaurant']);

    foreach (range(1, 3) as $i) {
        Reservation::factory()->create([
            'restaurant_id' => $data['restaurant']->id,
            'restaurant_table_id' => $data['table']->id,
            'user_id' => User::factory()->create()->id,
            'party_size' => 2,
            'starts_at' => now()->addDay()->setTime(18, 0),
            'ends_at' => now()->addDay()->setTime(20, 0),
            'status' => ReservationStatus::Booked,
        ]);
    }

    Sanctum::actingAs($staff);

    $response = $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/overview');

    $response->assertOk()
        ->assertJsonStructure([
            'quick_view' => [
                'total_reservations' => ['value', 'this_month', 'previous_month', 'change_percentage', 'trend'],
                'total_guests' => ['value', 'this_month', 'previous_month', 'change_percentage', 'trend'],
                'total_servers' => ['value'],
                'total_users' => ['value'],
            ],
            'calendar_overview' => [
                'week_start',
                'week_end',
                'days' => [
                    '*' => ['date', 'day_of_week', 'day_label', 'is_today', 'reservations_count', 'total_covers', 'average_covers_historical'],
                ],
            ],
        ]);

    expect($response->json('quick_view.total_reservations.value'))->toBe(3);
    expect($response->json('quick_view.total_guests.value'))->toBe(6);
    expect($response->json('quick_view.total_servers.value'))->toBe(3);
    expect($response->json('quick_view.total_users.value'))->toBe(3);
    expect($response->json('calendar_overview.days'))->toHaveCount(7);
});

it('excludes cancelled reservations from calendar covers', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $data['organization'], $data['restaurant']);

    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 4,
        'starts_at' => now()->setTime(18, 0),
        'ends_at' => now()->setTime(20, 0),
        'status' => ReservationStatus::Cancelled,
    ]);

    Sanctum::actingAs($staff);

    $response = $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/overview');

    $response->assertOk();

    $today = now()->toDateString();
    $todayDay = collect($response->json('calendar_overview.days'))->firstWhere('date', $today);

    expect($todayDay['total_covers'])->toBeNull();
    expect($todayDay['reservations_count'])->toBeNull();
});

it('forbids access without any permission', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/overview');

    $response->assertForbidden();
});

it('grants access on restaurants.view alone, since this is an /admin-only page', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $user = User::factory()->create();
    grantAccessConfigPermissions($user, $data['restaurant'], ['restaurants.view']);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/overview');

    $response->assertOk();
});

it('forbids access on reservations.view alone, since that is a /dashboard-scoped permission', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $user = User::factory()->create();
    grantAccessConfigPermissions($user, $data['restaurant'], ['reservations.view']);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/overview');

    $response->assertForbidden();
});

it('returns 7 days in the calendar overview always', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($staff);

    $response = $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/overview');

    $response->assertOk();
    expect($response->json('calendar_overview.days'))->toHaveCount(7);

    $weekdays = collect($response->json('calendar_overview.days'))->pluck('day_of_week');
    expect($weekdays->first())->toBe('Sunday');
    expect($weekdays->last())->toBe('Saturday');
});
