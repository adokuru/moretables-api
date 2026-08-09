<?php

use App\Events\WaitlistEntryUpdated;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\RestaurantActivityNotification;
use App\WaitlistStatus;
use App\WaitlistType;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
});

function makeAvailabilityAlert(Restaurant $restaurant, array $overrides = []): WaitlistEntry
{
    return WaitlistEntry::factory()->for($restaurant)->create(array_merge([
        'type' => WaitlistType::AvailabilityAlert,
        'status' => WaitlistStatus::Waiting,
        'user_id' => User::factory()->create(['first_name' => 'Test', 'last_name' => 'Customer']),
    ], $overrides));
}

it('notifies restaurant staff when a Notify Me alert is created, with a route to the notice-me page', function (): void {
    Notification::fake();

    $restaurant = createListedRestaurant();
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $restaurant->organization, $restaurant);

    $alert = makeAvailabilityAlert($restaurant);

    event(new WaitlistEntryUpdated($alert, 'created'));

    Notification::assertSentTo($staff, RestaurantActivityNotification::class, function ($notification) use ($staff) {
        $data = $notification->toArray($staff);

        expect($data['type'])->toBe('availability_alert.created')
            ->and($data['route'])->toBe('/dashboard/notice-me')
            ->and($data['message'])->toContain('Test Customer');

        return true;
    });
});

it('notifies restaurant staff when a Notify Me alert is fulfilled', function (): void {
    Notification::fake();

    $restaurant = createListedRestaurant();
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $restaurant->organization, $restaurant);

    $alert = makeAvailabilityAlert($restaurant);

    event(new WaitlistEntryUpdated($alert, 'notified'));

    Notification::assertSentTo($staff, RestaurantActivityNotification::class, function ($notification) use ($staff) {
        $data = $notification->toArray($staff);

        expect($data['type'])->toBe('availability_alert.notified');

        return true;
    });
});

it('does not notify staff for a regular walk-in waitlist entry (not an availability alert)', function (): void {
    Notification::fake();

    $restaurant = createListedRestaurant();
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $restaurant->organization, $restaurant);

    $entry = WaitlistEntry::factory()->for($restaurant)->create([
        'type' => WaitlistType::Seating,
        'status' => WaitlistStatus::Waiting,
    ]);

    event(new WaitlistEntryUpdated($entry, 'created'));
    event(new WaitlistEntryUpdated($entry, 'notified'));

    Notification::assertNotSentTo($staff, RestaurantActivityNotification::class);
});

it('does not notify staff for availability-alert actions outside the curated set', function (): void {
    Notification::fake();

    $restaurant = createListedRestaurant();
    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $restaurant->organization, $restaurant);

    $alert = makeAvailabilityAlert($restaurant, ['status' => WaitlistStatus::Cancelled]);

    event(new WaitlistEntryUpdated($alert, 'cancelled'));

    Notification::assertNotSentTo($staff, RestaurantActivityNotification::class);
});
