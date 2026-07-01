<?php

use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);
});

it('accepts full_name, phone, occasion and notes when merchant creates a reservation', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($staff);

    $response = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations', [
        'starts_at' => now()->addDay()->setTime(19, 0)->toDateTimeString(),
        'party_size' => 2,
        'source' => 'walk_in',
        'notes' => 'Window seat please',
        'occasion' => 'Birthday',
        'guest_contact' => [
            'full_name' => 'Ada Okafor',
            'phone' => '+2348099999999',
            'email' => 'ada@example.com',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('reservation.notes', 'Window seat please')
        ->assertJsonPath('reservation.occasion', 'Birthday')
        ->assertJsonPath('reservation.guest_contact.first_name', 'Ada')
        ->assertJsonPath('reservation.guest_contact.last_name', 'Okafor');

    $this->assertDatabaseHas('reservations', [
        'occasion' => 'Birthday',
        'notes' => 'Window seat please',
    ]);

    $this->assertDatabaseHas('guest_contacts', [
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'phone' => '+2348099999999',
        'email' => 'ada@example.com',
    ]);
});

it('accepts first_name and last_name separately when merchant creates a reservation', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($staff);

    $response = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations', [
        'starts_at' => now()->addDay()->setTime(19, 0)->toDateTimeString(),
        'party_size' => 2,
        'source' => 'walk_in',
        'guest_contact' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '+2348011111111',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('reservation.guest_contact.first_name', 'John')
        ->assertJsonPath('reservation.guest_contact.last_name', 'Doe');
});

it('accepts a guest contact without phone when merchant creates a reservation', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($staff);

    $response = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations', [
        'starts_at' => now()->addDay()->setTime(19, 0)->toDateTimeString(),
        'party_size' => 2,
        'source' => 'walk_in',
        'guest_contact' => [
            'first_name' => 'Ada',
            'last_name' => 'Okafor',
            'email' => 'ada.no-phone@example.com',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('reservation.guest_contact.first_name', 'Ada')
        ->assertJsonPath('reservation.guest_contact.phone', null);

    $this->assertDatabaseHas('guest_contacts', [
        'first_name' => 'Ada',
        'email' => 'ada.no-phone@example.com',
        'phone' => null,
    ]);
});

it('accepts long-form notes when merchant creates a reservation', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    $specialRequest = trim(str_repeat('Long-form guest request with accessibility, allergy, seating, timing, and celebration details. ', 20));

    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($staff);

    $response = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations', [
        'starts_at' => now()->addDay()->setTime(19, 0)->toDateTimeString(),
        'party_size' => 2,
        'source' => 'walk_in',
        'notes' => $specialRequest,
        'guest_contact' => [
            'first_name' => 'Ada',
            'email' => 'ada.long-notes@example.com',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('reservation.notes', $specialRequest);

    $this->assertDatabaseHas('reservations', [
        'restaurant_id' => $data['restaurant']->id,
        'notes' => $specialRequest,
    ]);
});

it('splits a single-word full_name with no last name', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($staff);

    $response = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations', [
        'starts_at' => now()->addDay()->setTime(19, 0)->toDateTimeString(),
        'party_size' => 2,
        'source' => 'walk_in',
        'guest_contact' => [
            'full_name' => 'Chidi',
            'phone' => '+2348022222222',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('reservation.guest_contact.first_name', 'Chidi')
        ->assertJsonPath('reservation.guest_contact.last_name', null);
});

it('allows merchant to update occasion on an existing reservation', function () {
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);

    $staff = User::factory()->create();
    assignScopedRole($staff, Role::Operations, $data['organization'], $data['restaurant']);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'starts_at' => now()->addDays(2)->setTime(19, 0),
        'ends_at' => now()->addDays(2)->setTime(21, 0),
        'occasion' => null,
    ]);

    Sanctum::actingAs($staff);

    $response = $this->patchJson(
        '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations/'.$reservation->id,
        ['occasion' => 'Anniversary', 'notes' => 'Champagne on arrival']
    );

    $response->assertOk()
        ->assertJsonPath('reservation.occasion', 'Anniversary')
        ->assertJsonPath('reservation.notes', 'Champagne on arrival');
});
