<?php

use App\Events\ReservationUpdated;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use App\Notifications\GuestReservationLifecycleMailNotification;
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
        'party_size' => 3,
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

    $guestRows = ReservationGuest::query()
        ->where('reservation_id', $reservation->id)
        ->orderBy('id')
        ->get();

    expect($guestRows)->toHaveCount(2);
    expect($guestRows[0]->attendee_name)->toBe('Updated Guest');
    expect($guestRows[0]->email_address)->toBe('updated.guest@example.com');
    expect($guestRows[0]->phone_number)->toBe('+2348000000001');
    expect($guestRows[1]->attendee_name)->toBe('New Guest');
    expect($guestRows[1]->email_address)->toBe('new.guest@example.com');

    $metadata = $reservation->metadata;
    expect($metadata === null || ! is_array($metadata) || ! array_key_exists('guests', $metadata))->toBeTrue();
});

it('emails a newly added diner when a customer adds a guest to a reservation', function () {
    Notification::fake();

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

    $this->postJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'Added Diner',
                'email_address' => 'added.diner@example.com',
                'phone_number' => '+2348000000003',
            ],
        ],
    ])->assertOk()
        ->assertJsonCount(1, 'reservation.guests');

    Notification::assertSentOnDemand(GuestReservationLifecycleMailNotification::class, function ($notification, $channels, $notifiable): bool {
        return ($notifiable->routes['mail'] ?? null) === 'added.diner@example.com'
            && $notification->toArray((object) [])['action'] === 'guest_added';
    });
});

it('removes a single guest via delete endpoint', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 4,
        'starts_at' => now()->addDays(2)->setTime(19, 0),
        'ends_at' => now()->addDays(2)->setTime(21, 0),
    ]);

    Sanctum::actingAs($customer);

    $this->putJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'Keep',
                'email_address' => 'keep@example.com',
            ],
            [
                'attendee_name' => 'Remove',
                'email_address' => 'remove@example.com',
            ],
        ],
    ])->assertOk()
        ->assertJsonCount(2, 'reservation.guests');

    $toRemove = ReservationGuest::query()
        ->where('reservation_id', $reservation->id)
        ->where('email_address', 'remove@example.com')
        ->firstOrFail();

    $this->deleteJson('/api/v1/reservations/'.$reservation->id.'/guests/'.$toRemove->id)
        ->assertOk()
        ->assertJsonCount(1, 'reservation.guests')
        ->assertJsonPath('reservation.guests.0.email_address', 'keep@example.com');

    expect(ReservationGuest::query()->where('reservation_id', $reservation->id)->count())->toBe(1);
});

it('returns 404 when removing a guest that belongs to another reservation', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();
    $other = User::factory()->create();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 3,
        'starts_at' => now()->addDays(2)->setTime(19, 0),
        'ends_at' => now()->addDays(2)->setTime(21, 0),
    ]);

    $otherReservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $other->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 2,
        'starts_at' => now()->addDays(2)->setTime(20, 0),
        'ends_at' => now()->addDays(2)->setTime(22, 0),
    ]);

    $othersGuest = ReservationGuest::query()->create([
        'reservation_id' => $otherReservation->id,
        'restaurant_id' => $data['restaurant']->id,
        'attendee_name' => 'Not Yours',
        'email_address' => 'nope@example.com',
        'email_normalized' => 'nope@example.com',
    ]);

    Sanctum::actingAs($customer);

    $this->deleteJson('/api/v1/reservations/'.$reservation->id.'/guests/'.$othersGuest->id)
        ->assertNotFound();
});

it('clears all additional guests when put guests is an empty array', function () {
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
                'attendee_name' => 'Solo',
                'email_address' => 'solo@example.com',
            ],
        ],
    ])->assertOk();

    $this->putJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [],
    ])->assertOk()
        ->assertJsonCount(0, 'reservation.guests');

    expect(ReservationGuest::query()->where('reservation_id', $reservation->id)->count())->toBe(0);
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

it('allows up to party size minus one additional guest entries', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 5,
        'starts_at' => now()->addDays(2)->setTime(19, 0),
        'ends_at' => now()->addDays(2)->setTime(21, 0),
    ]);

    Sanctum::actingAs($customer);

    $guests = collect(range(1, 4))->map(fn (int $i) => [
        'attendee_name' => "Guest {$i}",
        'email_address' => "guest{$i}@example.com",
    ])->all();

    $this->putJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => $guests,
    ])->assertOk()
        ->assertJsonCount(4, 'reservation.guests');
});

it('rejects guest updates when guest count exceeds additional guest cap', function () {
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

    // party_size 3 => at most 2 additional guest entries; 3 is over the cap
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

it('merges guests when adding in separate requests without losing prior guests', function () {
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

    $this->postJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'First Add',
                'email_address' => 'first@example.com',
            ],
        ],
    ])->assertOk()
        ->assertJsonCount(1, 'reservation.guests');

    $this->postJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'Second Add',
                'email_address' => 'second@example.com',
            ],
        ],
    ])->assertOk()
        ->assertJsonCount(2, 'reservation.guests');

    $this->getJson('/api/v1/reservations/'.$reservation->id)
        ->assertOk()
        ->assertJsonCount(2, 'data.guests')
        ->assertJsonPath('data.guests.0.attendee_name', 'First Add')
        ->assertJsonPath('data.guests.1.attendee_name', 'Second Add');
});

it('updates an existing guest when adding with the same email', function () {
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

    $this->postJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'Original Name',
                'email_address' => 'same@example.com',
            ],
        ],
    ])->assertOk();

    $this->postJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'Updated Name',
                'email_address' => 'same@example.com',
                'phone_number' => '+10000000000',
            ],
        ],
    ])->assertOk()
        ->assertJsonCount(1, 'reservation.guests')
        ->assertJsonPath('reservation.guests.0.attendee_name', 'Updated Name')
        ->assertJsonPath('reservation.guests.0.phone_number', '+10000000000');
});

it('rejects add-guest when merged count would exceed party size', function () {
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

    $this->postJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'Guest A',
                'email_address' => 'a@example.com',
            ],
        ],
    ])->assertOk();

    $response = $this->postJson('/api/v1/reservations/'.$reservation->id.'/guests', [
        'guests' => [
            [
                'attendee_name' => 'Guest B',
                'email_address' => 'b@example.com',
            ],
            [
                'attendee_name' => 'Guest C',
                'email_address' => 'c@example.com',
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
