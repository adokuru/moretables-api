<?php

use App\Models\Reservation;
use App\Models\RewardPointTransaction;
use App\Models\User;
use App\Services\ReservationService;
use App\UserStatus;
use Laravel\Sanctum\Sanctum;

it('accepts occasion, accept_points and subscribe_to_promotions when booking a reservation', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/reservations', [
        'restaurant_id' => $data['restaurant']->id,
        'starts_at' => now()->addDay()->setTime(18, 0)->toDateTimeString(),
        'party_size' => 2,
        'notes' => 'Quiet corner please',
        'occasion' => 'Birthday',
        'accept_points' => true,
        'subscribe_to_promotions' => true,
    ]);

    $response->assertCreated()
        ->assertJsonPath('reservation.notes', 'Quiet corner please')
        ->assertJsonPath('reservation.occasion', 'Birthday')
        ->assertJsonPath('reservation.accept_points', true)
        ->assertJsonPath('reservation.subscribe_to_promotions', true);

    $this->assertDatabaseHas('reservations', [
        'user_id' => $customer->id,
        'occasion' => 'Birthday',
        'accept_points' => true,
        'subscribe_to_promotions' => true,
    ]);
});

it('defaults accept_points and subscribe_to_promotions to false when not provided', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/reservations', [
        'restaurant_id' => $data['restaurant']->id,
        'starts_at' => now()->addDay()->setTime(18, 0)->toDateTimeString(),
        'party_size' => 2,
    ]);

    $response->assertCreated()
        ->assertJsonPath('reservation.accept_points', false)
        ->assertJsonPath('reservation.subscribe_to_promotions', false)
        ->assertJsonPath('reservation.occasion', null);
});

it('allows updating occasion and subscribe_to_promotions on an existing reservation', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'starts_at' => now()->addDays(3)->setTime(18, 0),
        'ends_at' => now()->addDays(3)->setTime(20, 0),
    ]);

    Sanctum::actingAs($customer);

    $response = $this->patchJson('/api/v1/reservations/'.$reservation->id, [
        'occasion' => 'Anniversary',
        'subscribe_to_promotions' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('reservation.occasion', 'Anniversary')
        ->assertJsonPath('reservation.subscribe_to_promotions', true);
});

it('awards 100 points to a user on reservation completion when accept_points is true and restaurant rewards are enabled', function () {
    $data = createBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['rewards_enabled' => true, 'reservation_reward_points' => 100]);

    $customer = User::factory()->create(['status' => UserStatus::Active]);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'accept_points' => true,
    ]);

    app(ReservationService::class)->completeReservation($reservation, $customer);

    expect(
        RewardPointTransaction::query()
            ->where('user_id', $customer->id)
            ->where('reference_type', Reservation::class)
            ->where('reference_id', $reservation->id)
            ->where('points', 100)
            ->exists()
    )->toBeTrue();
});

it('does not award points when accept_points is false', function () {
    $data = createBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['rewards_enabled' => true, 'reservation_reward_points' => 100]);

    $customer = User::factory()->create(['status' => UserStatus::Active]);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'accept_points' => false,
    ]);

    app(ReservationService::class)->completeReservation($reservation, $customer);

    expect(
        RewardPointTransaction::query()->where('user_id', $customer->id)->exists()
    )->toBeFalse();
});

it('does not award points when restaurant rewards are disabled', function () {
    $data = createBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['rewards_enabled' => false]);

    $customer = User::factory()->create(['status' => UserStatus::Active]);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'accept_points' => true,
    ]);

    app(ReservationService::class)->completeReservation($reservation, $customer);

    expect(
        RewardPointTransaction::query()->where('user_id', $customer->id)->exists()
    )->toBeFalse();
});

it('awards a custom points amount set by the restaurant on reservation completion', function () {
    $data = createBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['rewards_enabled' => true, 'reservation_reward_points' => 250]);

    $customer = User::factory()->create(['status' => UserStatus::Active]);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => $data['table']->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'accept_points' => true,
    ]);

    app(ReservationService::class)->completeReservation($reservation, $customer);

    expect(
        RewardPointTransaction::query()
            ->where('user_id', $customer->id)
            ->where('points', 250)
            ->exists()
    )->toBeTrue();
});

it('does not award points for guest reservations without a user account', function () {
    $data = createBookableRestaurant();
    $restaurant = $data['restaurant'];
    $restaurant->update(['rewards_enabled' => true]);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => null,
        'restaurant_table_id' => $data['table']->id,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(),
        'accept_points' => true,
    ]);

    $actor = User::factory()->create();

    app(ReservationService::class)->completeReservation($reservation, $actor);

    expect(RewardPointTransaction::query()->count())->toBe(0);
});
