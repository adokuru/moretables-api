<?php

use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\GuestReservationLifecycleMailNotification;
use App\Notifications\GuestWaitlistTableAvailableMailNotification;
use App\Notifications\WaitlistAvailabilityNotification;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);
});

it('allows operations staff to manage floor resources and walk-in reservations', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($operations);

    $diningAreaResponse = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/dining-areas', [
        'name' => 'VIP Room',
        'description' => 'Quiet area',
    ]);

    $diningAreaResponse->assertCreated()
        ->assertJsonPath('dining_area.name', 'VIP Room');

    $tableResponse = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables', [
        'dining_area_id' => $diningAreaResponse->json('dining_area.id'),
        'name' => 'VIP-1',
        'min_capacity' => 1,
        'max_capacity' => 10,
        'table_type' => 'booth',
        'shape' => 'rectangle',
        'x_position' => 128,
        'y_position' => 96,
        'width' => 160,
        'height' => 96,
        'rotation' => 90,
        'color' => '#AABBCC',
    ]);

    $tableResponse->assertCreated()
        ->assertJsonPath('table.name', 'VIP-1')
        ->assertJsonPath('table.table_type', 'booth')
        ->assertJsonPath('table.shape', 'rectangle')
        ->assertJsonPath('table.x_position', 128)
        ->assertJsonPath('table.y_position', 96)
        ->assertJsonPath('table.width', 160)
        ->assertJsonPath('table.height', 96)
        ->assertJsonPath('table.rotation', 90)
        ->assertJsonPath('table.color', '#AABBCC');

    $this->patchJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables/'.$tableResponse->json('table.id'), [
        'shape' => 'round',
        'x_position' => 240,
        'y_position' => 160,
        'width' => 112,
        'height' => 112,
        'rotation' => 0,
        'color' => '#D9D9D9',
    ])->assertOk()
        ->assertJsonPath('table.shape', 'round')
        ->assertJsonPath('table.x_position', 240)
        ->assertJsonPath('table.y_position', 160)
        ->assertJsonPath('table.width', 112)
        ->assertJsonPath('table.height', 112)
        ->assertJsonPath('table.rotation', 0)
        ->assertJsonPath('table.color', '#D9D9D9');

    $reservationResponse = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations', [
        'starts_at' => now()->addDay()->setTime(19, 0)->toDateTimeString(),
        'party_size' => 2,
        'source' => 'walk_in',
        'guest_contact' => [
            'first_name' => 'Walk',
            'last_name' => 'In',
            'phone' => '+2348099999999',
        ],
    ]);

    $reservationResponse->assertCreated()
        ->assertJsonPath('reservation.source', 'walk_in');

    expect(Reservation::query()->count())->toBe(1);
});

it('validates floor table layout and type values', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($operations);

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables', [
        'name' => 'Invalid Layout',
        'max_capacity' => 4,
        'table_type' => 'sofa',
        'shape' => 'triangle',
        'x_position' => -1,
        'width' => 0,
        'rotation' => 360,
        'color' => 'green',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'table_type',
            'shape',
            'x_position',
            'width',
            'rotation',
            'color',
        ]);
});

it('defaults new tables to one minimum seat and ten maximum seats', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($operations);

    $response = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables', [
        'name' => 'Default Capacity',
    ]);

    $response->assertCreated()
        ->assertJsonPath('table.min_capacity', 1)
        ->assertJsonPath('table.max_capacity', 10);
});

it('defaults synced layout tables to one minimum seat and ten maximum seats', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($operations);

    $diningAreaResponse = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/dining-areas', [
        'name' => 'Main Floor',
    ]);

    $diningAreaResponse->assertCreated();

    $response = $this->putJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/dining-areas/'.$diningAreaResponse->json('dining_area.id').'/layout', [
        'tables' => [
            [
                'layout_type' => 'square-2-tb',
                'x_position' => 0,
                'y_position' => 0,
                'table_label' => 'A1',
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('dining_area.tables.0.min_capacity', 1)
        ->assertJsonPath('dining_area.tables.0.max_capacity', 10);
});

it('allows restaurant managers to configure guest communication messaging', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $manager = User::factory()->create();
    assignScopedRole($manager, Role::PrincipalAdmin, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($manager);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/guest-communication')
        ->assertOk()
        ->assertJsonPath('guest_communication.automated_messaging.enabled', false)
        ->assertJsonPath('guest_communication.reservation_messaging.enabled', false);

    $this->patchJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/guest-communication/automated-messaging', [
        'enabled' => true,
    ])->assertOk()
        ->assertJsonPath('guest_communication.automated_messaging.enabled', true)
        ->assertJsonPath('guest_communication.reservation_messaging.enabled', false);

    $this->patchJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/guest-communication/reservation-messaging', [
        'enabled' => true,
    ])->assertOk()
        ->assertJsonPath('guest_communication.automated_messaging.enabled', true)
        ->assertJsonPath('guest_communication.reservation_messaging.enabled', true);

    $this->patchJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/guest-communication/reservation-messaging', [
        'enabled' => 'yes',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('enabled');
});

it('emails a guest when operations staff creates a walk-in reservation with guest email', function () {
    Notification::fake();
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($operations);

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations', [
        'starts_at' => now()->addDay()->setTime(19, 0)->toDateTimeString(),
        'party_size' => 2,
        'source' => 'walk_in',
        'guest_contact' => [
            'first_name' => 'Walk',
            'last_name' => 'In',
            'email' => 'guest.walkin@example.com',
            'phone' => '+2348099999999',
        ],
    ])->assertCreated();

    Notification::assertSentOnDemand(GuestReservationLifecycleMailNotification::class, function ($notification, $channels, $notifiable): bool {
        return ($notifiable->routes['mail'] ?? null) === 'guest.walkin@example.com';
    });
});

it('allows operations staff to notify waitlist guests', function () {
    Notification::fake();

    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    $customer = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($operations);

    $waitlistResponse = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/waitlist-entries', [
        'user_id' => $customer->id,
        'preferred_starts_at' => now()->addDay()->setTime(20, 0)->toDateTimeString(),
        'party_size' => 2,
    ]);

    $waitlistResponse->assertCreated();

    $entry = WaitlistEntry::query()->firstOrFail();

    $notifyResponse = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/waitlist-entries/'.$entry->id.'/notify', [
        'expires_in_minutes' => 20,
    ]);

    $notifyResponse->assertOk()
        ->assertJsonPath('waitlist_entry.status', 'notified');

    Notification::assertSentTo($customer, WaitlistAvailabilityNotification::class);
});

it('emails guest when operations staff notifies guest-only waitlist with email', function () {
    Notification::fake();

    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $operations = User::factory()->create();
    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($operations);

    $waitlistResponse = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/waitlist-entries', [
        'preferred_starts_at' => now()->addDay()->setTime(20, 0)->toDateTimeString(),
        'party_size' => 2,
        'guest_contact' => [
            'first_name' => 'Guest',
            'last_name' => 'User',
            'email' => 'waitlist.guest@example.com',
            'phone' => '+15550001111',
        ],
    ]);

    $waitlistResponse->assertCreated();

    $entry = WaitlistEntry::query()->firstOrFail();

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/waitlist-entries/'.$entry->id.'/notify', [
        'expires_in_minutes' => 20,
    ])->assertOk();

    Notification::assertSentOnDemand(GuestWaitlistTableAvailableMailNotification::class, function ($notification, $channels, $notifiable): bool {
        return ($notifiable->routes['mail'] ?? null) === 'waitlist.guest@example.com';
    });
});
