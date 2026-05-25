<?php

use App\Models\GuestContact;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\ReservationStatus;
use App\UserStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

// ─────────────────────────────────────────────────────────────────────────────
//  Helpers
// ─────────────────────────────────────────────────────────────────────────────

function guestbookSetup(): array
{
    $organization = Organization::factory()->create();
    $restaurant   = Restaurant::factory()->create(['organization_id' => $organization->id]);
    $manager      = User::factory()->create(['status' => UserStatus::Active]);

    assignScopedRole($manager, Role::RestaurantManager, restaurant: $restaurant);

    return compact('organization', 'restaurant', 'manager');
}

// ─────────────────────────────────────────────────────────────────────────────
//  List guests
// ─────────────────────────────────────────────────────────────────────────────

it('returns 401 when listing guests unauthenticated', function () {
    ['restaurant' => $restaurant] = guestbookSetup();

    $this->getJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook")
        ->assertUnauthorized();
});

it('returns 403 when user lacks reservations.view permission', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant] = guestbookSetup();

    $stranger = User::factory()->create(['status' => UserStatus::Active]);
    Sanctum::actingAs($stranger);

    $this->getJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook")
        ->assertForbidden();
});

it('lists only permanent guests with total count', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    // 3 permanent guests, 2 temporary — only permanent should appear
    GuestContact::factory()->count(3)->create(['restaurant_id' => $restaurant->id, 'is_temporary' => false]);
    GuestContact::factory()->count(2)->create(['restaurant_id' => $restaurant->id, 'is_temporary' => true]);

    Sanctum::actingAs($manager);

    $response = $this->getJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook");

    $response->assertOk()
        ->assertJsonPath('total_guests', 3)
        ->assertJsonCount(3, 'data');
});

it('searches guests by name, phone and email', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'first_name'    => 'Yousuf',
        'last_name'     => 'Adamu',
        'phone'         => '+2348011111111',
        'email'         => 'yousuf@example.com',
        'is_temporary'  => false,
    ]);
    GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'first_name'    => 'Kosi',
        'last_name'     => 'Usman',
        'phone'         => '+2348022222222',
        'email'         => 'kosi@example.com',
        'is_temporary'  => false,
    ]);

    Sanctum::actingAs($manager);

    $this->getJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook?search_term=Yousuf")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.first_name', 'Yousuf');

    $this->getJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook?search_term=+2348022222222")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.first_name', 'Kosi');
});

// ─────────────────────────────────────────────────────────────────────────────
//  Create guest
// ─────────────────────────────────────────────────────────────────────────────

it('creates a permanent guest manually', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    Sanctum::actingAs($manager);

    $response = $this->postJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook", [
        'first_name'       => 'Abayomi',
        'last_name'        => 'Thompson',
        'phone'            => '+2348033333333',
        'email'            => 'abayomi@example.com',
        'marketing_opt_in' => true,
        'birthday'         => '1990-12-31',
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Guest created successfully.')
        ->assertJsonPath('guest.first_name', 'Abayomi')
        ->assertJsonPath('guest.marketing_opt_in', true)
        ->assertJsonPath('guest.birthday', '1990-12-31');

    $this->assertDatabaseHas('guest_contacts', [
        'restaurant_id' => $restaurant->id,
        'phone'         => '+2348033333333',
        'is_temporary'  => false,
    ]);
});

it('requires first_name and phone when creating a guest', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    Sanctum::actingAs($manager);

    $this->postJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['first_name', 'phone']);
});

// ─────────────────────────────────────────────────────────────────────────────
//  Show personal details
// ─────────────────────────────────────────────────────────────────────────────

it('returns guest personal details', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    $guest = GuestContact::factory()->create([
        'restaurant_id'      => $restaurant->id,
        'first_name'         => 'Mazi',
        'last_name'          => 'Okeke',
        'phone'              => '+2348044444444',
        'email'              => 'mazi@example.com',
        'marketing_opt_in'   => false,
        'birthday'           => '1985-07-15',
        'wedding_anniversary' => '2010-01-12',
        'is_temporary'       => false,
    ]);

    Sanctum::actingAs($manager);

    $this->getJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook/{$guest->id}")
        ->assertOk()
        ->assertJsonPath('guest.first_name', 'Mazi')
        ->assertJsonPath('guest.last_name', 'Okeke')
        ->assertJsonPath('guest.marketing_opt_in', false)
        ->assertJsonPath('guest.birthday', '1985-07-15')
        ->assertJsonPath('guest.wedding_anniversary', '2010-01-12');
});

it('returns 404 when guest belongs to a different restaurant', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    $otherRestaurant = Restaurant::factory()->create();
    $guest = GuestContact::factory()->create([
        'restaurant_id' => $otherRestaurant->id,
        'is_temporary'  => false,
    ]);

    Sanctum::actingAs($manager);

    $this->getJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook/{$guest->id}")
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
//  Update personal details
// ─────────────────────────────────────────────────────────────────────────────

it('updates guest personal details and notable dates', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    $guest = GuestContact::factory()->create([
        'restaurant_id'    => $restaurant->id,
        'marketing_opt_in' => false,
        'birthday'         => null,
        'is_temporary'     => false,
    ]);

    Sanctum::actingAs($manager);

    $this->patchJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook/{$guest->id}", [
        'marketing_opt_in'    => true,
        'birthday'            => '1992-12-31',
        'wedding_anniversary' => '2015-01-12',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Guest updated successfully.')
        ->assertJsonPath('guest.marketing_opt_in', true)
        ->assertJsonPath('guest.birthday', '1992-12-31')
        ->assertJsonPath('guest.wedding_anniversary', '2015-01-12');

    expect($guest->fresh()->marketing_opt_in)->toBeTrue()
        ->and($guest->fresh()->birthday->toDateString())->toBe('1992-12-31');
});

// ─────────────────────────────────────────────────────────────────────────────
//  Preferences
// ─────────────────────────────────────────────────────────────────────────────

it('returns guest preferences', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    $guest = GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'preferences'   => ['seating_preference' => 'window', 'dietary_restrictions' => ['vegetarian']],
        'is_temporary'  => false,
    ]);

    Sanctum::actingAs($manager);

    $this->getJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook/{$guest->id}/preferences")
        ->assertOk()
        ->assertJsonPath('preferences.seating_preference', 'window')
        ->assertJsonPath('preferences.dietary_restrictions.0', 'vegetarian');
});

it('updates guest preferences', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    $guest = GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'preferences'   => [],
        'is_temporary'  => false,
    ]);

    Sanctum::actingAs($manager);

    $this->patchJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook/{$guest->id}/preferences", [
        'seating_preference'       => 'booth',
        'dietary_restrictions'     => ['gluten-free'],
        'allergies'                => ['nuts'],
        'communication_preference' => 'email',
        'special_notes'            => 'Prefers quiet corner',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Preferences updated successfully.')
        ->assertJsonPath('preferences.seating_preference', 'booth')
        ->assertJsonPath('preferences.dietary_restrictions.0', 'gluten-free')
        ->assertJsonPath('preferences.allergies.0', 'nuts')
        ->assertJsonPath('preferences.communication_preference', 'email');

    expect($guest->fresh()->preferences['seating_preference'])->toBe('booth');
});

it('merges preferences rather than replacing them', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    $guest = GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'preferences'   => ['seating_preference' => 'window', 'special_notes' => 'Existing note'],
        'is_temporary'  => false,
    ]);

    Sanctum::actingAs($manager);

    $this->patchJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook/{$guest->id}/preferences", [
        'seating_preference' => 'booth',
    ])
        ->assertOk();

    $prefs = $guest->fresh()->preferences;
    expect($prefs['seating_preference'])->toBe('booth')
        ->and($prefs['special_notes'])->toBe('Existing note'); // preserved
});

// ─────────────────────────────────────────────────────────────────────────────
//  History
// ─────────────────────────────────────────────────────────────────────────────

it('returns guest visit history with stats', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    $guest = GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_temporary'  => false,
    ]);

    Reservation::factory()->count(2)->create([
        'restaurant_id'    => $restaurant->id,
        'guest_contact_id' => $guest->id,
        'status'           => ReservationStatus::Completed,
        'party_size'       => 3,
        'completed_at'     => now()->subDays(5),
    ]);

    Reservation::factory()->create([
        'restaurant_id'    => $restaurant->id,
        'guest_contact_id' => $guest->id,
        'status'           => ReservationStatus::Booked,
        'party_size'       => 2,
    ]);

    Sanctum::actingAs($manager);

    $response = $this->getJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook/{$guest->id}/history");

    $response->assertOk()
        ->assertJsonPath('stats.total_visits', 2)
        ->assertJsonPath('stats.total_covers', 6)
        ->assertJsonCount(3, 'data'); // all reservations paginated
});

// ─────────────────────────────────────────────────────────────────────────────
//  Delete
// ─────────────────────────────────────────────────────────────────────────────

it('deletes a guest from the guestbook', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'manager' => $manager] = guestbookSetup();

    $guest = GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_temporary'  => false,
    ]);

    Sanctum::actingAs($manager);

    $this->deleteJson("/api/v1/merchant/restaurants/{$restaurant->id}/guestbook/{$guest->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Guest removed from guestbook.');

    $this->assertDatabaseMissing('guest_contacts', ['id' => $guest->id]);
});

// ─────────────────────────────────────────────────────────────────────────────
//  Auto-enrolment
// ─────────────────────────────────────────────────────────────────────────────

it('auto-enrolls a guest when a merchant creates a reservation with new guest_contact', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant, 'table' => $table] = createBookableRestaurant();

    $manager = User::factory()->create(['status' => UserStatus::Active]);
    assignScopedRole($manager, Role::RestaurantManager, restaurant: $restaurant);

    Sanctum::actingAs($manager);

    $this->postJson("/api/v1/merchant/restaurants/{$restaurant->id}/reservations", [
        'source'     => 'staff',
        'party_size' => 2,
        'starts_at'  => now()->addDay()->setHour(12)->setMinute(0)->toIso8601String(),
        'guest_contact' => [
            'first_name' => 'Juliet',
            'last_name'  => 'Ibazebo',
            'phone'      => '+2348055555555',
            'email'      => 'juliet@example.com',
        ],
    ])->assertCreated();

    $this->assertDatabaseHas('guest_contacts', [
        'restaurant_id' => $restaurant->id,
        'phone'         => '+2348055555555',
        'is_temporary'  => false,
    ]);
});

it('reuses an existing guest when the same phone number books again', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant] = createBookableRestaurant();

    $manager = User::factory()->create(['status' => UserStatus::Active]);
    assignScopedRole($manager, Role::RestaurantManager, restaurant: $restaurant);

    // Pre-existing permanent guest with the same phone
    GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'phone'         => '+2348055555555',
        'is_temporary'  => false,
    ]);

    Sanctum::actingAs($manager);

    $this->postJson("/api/v1/merchant/restaurants/{$restaurant->id}/reservations", [
        'source'     => 'staff',
        'party_size' => 2,
        'starts_at'  => now()->addDay()->setHour(13)->setMinute(0)->toIso8601String(),
        'guest_contact' => [
            'first_name' => 'Juliet',
            'phone'      => '+2348055555555',
        ],
    ])->assertCreated();

    // Still only one guest contact for this phone
    expect(
        GuestContact::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('phone', '+2348055555555')
            ->count()
    )->toBe(1);
});

it('auto-enrolls a customer app user as a guest on their first reservation', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant] = createBookableRestaurant();

    $customer = User::factory()->create([
        'first_name' => 'Kazeem',
        'last_name'  => 'Ayuba',
        'email'      => 'kazeem@example.com',
        'phone'      => '+2348066666666',
        'status'     => UserStatus::Active,
    ]);
    assignScopedRole($customer, Role::Customer);

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/customer/reservations", [
        'restaurant_id' => $restaurant->id,
        'party_size'    => 2,
        'starts_at'     => now()->addDay()->setHour(14)->setMinute(0)->toIso8601String(),
    ])->assertCreated();

    $this->assertDatabaseHas('guest_contacts', [
        'restaurant_id' => $restaurant->id,
        'first_name'    => 'Kazeem',
        'email'         => 'kazeem@example.com',
        'is_temporary'  => false,
    ]);
});

it('does not duplicate guest when same customer books the same restaurant again', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    ['restaurant' => $restaurant] = createBookableRestaurant();

    $customer = User::factory()->create([
        'email'  => 'kazeem@example.com',
        'phone'  => '+2348066666666',
        'status' => UserStatus::Active,
    ]);
    assignScopedRole($customer, Role::Customer);

    // Existing guest contact from a previous booking
    GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'email'         => 'kazeem@example.com',
        'phone'         => '+2348066666666',
        'is_temporary'  => false,
    ]);

    Sanctum::actingAs($customer);

    $this->postJson("/api/v1/customer/reservations", [
        'restaurant_id' => $restaurant->id,
        'party_size'    => 2,
        'starts_at'     => now()->addDay()->setHour(15)->setMinute(0)->toIso8601String(),
    ])->assertCreated();

    expect(
        GuestContact::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('email', 'kazeem@example.com')
            ->count()
    )->toBe(1);
});
