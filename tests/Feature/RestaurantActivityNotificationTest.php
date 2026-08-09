<?php

use App\Events\ReservationUpdated;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use App\Notifications\RestaurantActivityNotification;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('notifies restaurant staff (not just the owner) when a reservation is created', function (): void {
    Notification::fake();

    $organization = Organization::factory()->create();
    $restaurant = createListedRestaurant(['organization_id' => $organization->id]);

    $owner = User::factory()->create();
    assignScopedRole($owner, Role::OrganizationOwner, $organization);

    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $organization, $restaurant);

    $reservation = Reservation::factory()->for($restaurant)->create();

    event(new ReservationUpdated($reservation, 'created'));

    Notification::assertSentTo($owner, RestaurantActivityNotification::class);
    Notification::assertSentTo($staff, RestaurantActivityNotification::class);
});

it('does not notify staff from a different restaurant', function (): void {
    Notification::fake();

    $restaurant = createListedRestaurant();
    $otherRestaurant = createListedRestaurant();

    $outsider = User::factory()->create();
    assignScopedRole($outsider, Role::Operations, $otherRestaurant->organization, $otherRestaurant);

    $reservation = Reservation::factory()->for($restaurant)->create();

    event(new ReservationUpdated($reservation, 'created'));

    Notification::assertNotSentTo($outsider, RestaurantActivityNotification::class);
});

it('skips noisy service-stage actions but notifies on table_assigned/cancelled/no_show', function (): void {
    Notification::fake();

    $restaurant = createListedRestaurant();
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $restaurant->organization, $restaurant);
    $reservation = Reservation::factory()->for($restaurant)->create();

    event(new ReservationUpdated($reservation, 'seated'));
    event(new ReservationUpdated($reservation, 'appetizer'));
    Notification::assertNotSentTo($staff, RestaurantActivityNotification::class);

    event(new ReservationUpdated($reservation, 'table_assigned'));
    event(new ReservationUpdated($reservation, 'cancelled'));
    event(new ReservationUpdated($reservation, 'no_show'));
    Notification::assertSentToTimes($staff, RestaurantActivityNotification::class, 3);
});

it('names the table and the staff member who assigned it in the table_assigned notification', function (): void {
    Notification::fake();

    $restaurant = createListedRestaurant();
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $restaurant->organization, $restaurant);

    $assigner = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $table = RestaurantTable::factory()->for($restaurant)->create(['name' => '4']);
    $reservation = Reservation::factory()->for($restaurant)->create([
        'user_id' => User::factory()->create(['first_name' => 'Test', 'last_name' => 'Customer']),
        'restaurant_table_id' => $table->id,
        'starts_at' => now()->addDay()->setTime(18, 0),
    ]);

    event(new ReservationUpdated($reservation, 'table_assigned', $assigner));

    Notification::assertSentTo($staff, RestaurantActivityNotification::class, function ($notification) use ($staff, $reservation, $restaurant) {
        $data = $notification->toArray($staff);
        $when = $reservation->starts_at->copy()->timezone($restaurant->timezone)->format('D, M j \a\t g:i A');

        expect($data['message'])->toBe("Test Customer ({$when}) was assigned to Table 4 by Jane Doe.");

        return true;
    });
});
