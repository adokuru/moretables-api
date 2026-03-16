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
