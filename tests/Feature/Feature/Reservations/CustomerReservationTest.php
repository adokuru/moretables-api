<?php

use App\Events\ReservationUpdated;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationLifecycleNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

it('creates a reservation for an active customer and assigns a table', function () {
    Notification::fake();
    Event::fake([ReservationUpdated::class]);

    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/reservations', [
        'restaurant_id' => $data['restaurant']->id,
        'starts_at' => now()->addDay()->setTime(18, 0)->toDateTimeString(),
        'party_size' => 2,
        'notes' => 'Window seat please',
    ]);

    $response->assertCreated()
        ->assertJsonPath('reservation.party_size', 2)
        ->assertJsonPath('reservation.restaurant_id', $data['restaurant']->id);

    $reservation = Reservation::query()->firstOrFail();

    expect($reservation->restaurant_table_id)->not->toBeNull();
    Notification::assertSentTo($customer, ReservationLifecycleNotification::class);
    Event::assertDispatched(ReservationUpdated::class);
});

it('lets a customer cancel their reservation before the cutoff window', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'starts_at' => now()->addDays(2)->setTime(18, 0),
        'ends_at' => now()->addDays(2)->setTime(20, 0),
    ]);

    Sanctum::actingAs($customer);

    $response = $this->deleteJson('/api/v1/reservations/'.$reservation->id);

    $response->assertOk()
        ->assertJsonPath('reservation.status', 'cancelled');
});

it('updates guests on a customer reservation through a dedicated endpoint', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'starts_at' => now()->addDays(2)->setTime(19, 0),
        'ends_at' => now()->addDays(2)->setTime(21, 0),
        'metadata' => [
            'guests' => [
                [
                    'first_name' => 'Initial',
                    'last_name' => 'Guest',
                ],
            ],
        ],
    ]);

    Sanctum::actingAs($customer);

    $response = $this->putJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'Updated Guest',
                'email_address' => 'updated.guest@example.com',
                'phone_number' => '+2348000000001',
            ],
            [
                'attendee_name' => 'New Guest',
                'email_address' => 'new.guest@example.com',
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('reservation.guests.0.attendee_name', 'Updated Guest')
        ->assertJsonPath('reservation.guests.1.attendee_name', 'New Guest');

    $reservation->refresh();

    expect($reservation->metadata)->toMatchArray([
        'guests' => [
            [
                'attendee_name' => 'Updated Guest',
                'email_address' => 'updated.guest@example.com',
                'phone_number' => '+2348000000001',
            ],
            [
                'attendee_name' => 'New Guest',
                'email_address' => 'new.guest@example.com',
            ],
        ],
    ]);
});

it('returns all saved guests when fetching a reservation by id', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 3,
        'starts_at' => now()->addDays(2)->setTime(19, 0),
        'ends_at' => now()->addDays(2)->setTime(21, 0),
    ]);

    Sanctum::actingAs($customer);

    $this->putJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'Guest One',
                'email_address' => 'one@example.com',
            ],
            [
                'attendee_name' => 'Guest Two',
                'email_address' => 'two@example.com',
            ],
        ],
    ])->assertOk();

    $this->getJson('/api/v1/reservations/'.$reservation->id)
        ->assertOk()
        ->assertJsonCount(2, 'data.guests')
        ->assertJsonPath('data.guests.0.attendee_name', 'Guest One')
        ->assertJsonPath('data.guests.1.attendee_name', 'Guest Two');
});

it('normalizes metadata guests stored as a single object into a one-element array', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 2,
        'starts_at' => now()->addDays(2)->setTime(19, 0),
        'ends_at' => now()->addDays(2)->setTime(21, 0),
        'metadata' => [
            'guests' => [
                'attendee_name' => 'Solo Object',
                'email_address' => 'solo.object@example.com',
            ],
        ],
    ]);

    Sanctum::actingAs($customer);

    $this->getJson('/api/v1/reservations/'.$reservation->id)
        ->assertOk()
        ->assertJsonCount(1, 'data.guests')
        ->assertJsonPath('data.guests.0.attendee_name', 'Solo Object')
        ->assertJsonPath('data.guests.0.email_address', 'solo.object@example.com');
});

it('rejects guest updates when guest count exceeds party size', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 2,
        'starts_at' => now()->addDays(2)->setTime(19, 0),
        'ends_at' => now()->addDays(2)->setTime(21, 0),
    ]);

    Sanctum::actingAs($customer);

    $response = $this->putJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'First Guest',
                'email_address' => 'first.guest@example.com',
            ],
            [
                'attendee_name' => 'Second Guest',
                'email_address' => 'second.guest@example.com',
            ],
            [
                'attendee_name' => 'Third Guest',
                'email_address' => 'third.guest@example.com',
            ],
        ],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['guests']);
});

it('validates guest payload using attendee fields', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 2,
        'starts_at' => now()->addDays(2)->setTime(19, 0),
        'ends_at' => now()->addDays(2)->setTime(21, 0),
    ]);

    Sanctum::actingAs($customer);

    $response = $this->putJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'Missing Email',
            ],
        ],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['guests.0.email_address']);
});
