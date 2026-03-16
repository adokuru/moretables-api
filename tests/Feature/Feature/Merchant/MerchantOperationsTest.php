<?php

use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

it('allows restaurant managers to manage floor resources and walk-in reservations', function () {
    $data = createBookableRestaurant();
    $manager = User::factory()->create();
    assignScopedRole($manager, Role::RestaurantManager, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($manager);

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
        'max_capacity' => 4,
    ]);

    $tableResponse->assertCreated()
        ->assertJsonPath('table.name', 'VIP-1');

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

it('allows restaurant managers to notify waitlist guests', function () {
    Notification::fake();

    $data = createBookableRestaurant();
    $manager = User::factory()->create();
    $customer = User::factory()->create();
    assignScopedRole($manager, Role::RestaurantManager, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($manager);

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
});
