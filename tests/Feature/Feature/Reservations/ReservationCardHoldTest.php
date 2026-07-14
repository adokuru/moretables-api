<?php

use App\Jobs\ChargeNoShowFeeJob;
use App\Models\Reservation;
use App\Models\ReservationCardHold;
use App\Models\Restaurant;
use App\Models\RestaurantCancellationPolicy;
use App\Models\User;
use App\Notifications\NoShowChargeNotification;
use App\ReservationCardHoldStatus;
use App\ReservationStatus;
use App\Services\ReservationCardHoldService;
use App\Services\ReservationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    config([
        'billing.providers.paystack.secret_key' => 'test-secret',
        'billing.providers.paystack.webhook_secret' => 'test-secret',
        'billing.card_hold.verification_amount' => 5000,
        'billing.card_hold.currency' => 'NGN',
    ]);
});

/**
 * @return array{restaurant: Restaurant, policy: RestaurantCancellationPolicy}
 */
function cardHoldRestaurant(int $holdAmount = 15000): array
{
    $data = createBookableRestaurant();

    $policy = RestaurantCancellationPolicy::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'days' => [0, 1, 2, 3, 4, 5, 6],
        'hold_charge_amount' => $holdAmount,
    ]);

    return ['restaurant' => $data['restaurant'], 'policy' => $policy];
}

it('captures a card before booking a card-hold slot', function (): void {
    ['restaurant' => $restaurant] = cardHoldRestaurant();
    $user = User::factory()->create(['email' => 'guest@example.com']);
    Sanctum::actingAs($user);

    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/hold', 'access_code' => 'ac_1'],
        ]),
    ]);

    $this->postJson("/api/v1/restaurants/{$restaurant->slug}/card-hold", [
        'starts_at' => now()->addDay()->setTime(18, 0)->toDateTimeString(),
        'party_size' => 2,
    ])
        ->assertCreated()
        ->assertJsonPath('authorization_url', 'https://checkout.paystack.com/hold')
        ->assertJsonPath('card_hold.amount', 15000)
        ->assertJsonPath('card_hold.status', 'pending');

    $this->assertDatabaseHas('reservation_card_holds', [
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'reservation_id' => null,
        'status' => 'pending',
    ]);

    Http::assertSent(fn ($request): bool => $request['amount'] === 5000);
});

it('rejects card capture when the slot has no card-hold policy', function (): void {
    $data = createBookableRestaurant();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/restaurants/{$data['restaurant']->slug}/card-hold", [
        'starts_at' => now()->addDay()->setTime(18, 0)->toDateTimeString(),
        'party_size' => 2,
    ])->assertUnprocessable();
});

it('saves the card authorization and refunds the verification charge on webhook', function (): void {
    ['restaurant' => $restaurant] = cardHoldRestaurant();
    $cardHold = ReservationCardHold::factory()->create([
        'restaurant_id' => $restaurant->id,
        'reference' => 'rch_test',
    ]);

    Http::fake([
        'https://api.paystack.co/refund' => Http::response(['status' => true, 'data' => []]),
    ]);

    $payload = json_encode([
        'event' => 'charge.success',
        'data' => [
            'reference' => 'rch_test',
            'status' => 'success',
            'authorization' => ['authorization_code' => 'AUTH_hold', 'brand' => 'visa', 'last4' => '4081'],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->withHeader('x-paystack-signature', hash_hmac('sha512', $payload, 'test-secret'))
        ->postJson('/api/v1/billing/paystack/webhook', json_decode($payload, true, 512, JSON_THROW_ON_ERROR))
        ->assertOk();

    $cardHold->refresh();
    expect($cardHold->status)->toBe(ReservationCardHoldStatus::Authorized)
        ->and($cardHold->authorization_code)->toBe('AUTH_hold')
        ->and($cardHold->last4)->toBe('4081')
        ->and($cardHold->metadata['verification_refunded_at'] ?? null)->not->toBeNull();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paystack.co/refund'
        && $request['transaction'] === 'rch_test');
});

it('rejects booking a card-hold slot without a verified card', function (): void {
    ['restaurant' => $restaurant] = cardHoldRestaurant();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/reservations', [
        'restaurant_id' => $restaurant->id,
        'starts_at' => now()->addDay()->setTime(18, 0)->toDateTimeString(),
        'party_size' => 2,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('card_hold_reference');
});

it('links the verified card to the reservation when booking a card-hold slot', function (): void {
    ['restaurant' => $restaurant] = cardHoldRestaurant();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $cardHold = ReservationCardHold::factory()->authorized()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'reservation_id' => null,
    ]);

    $response = $this->postJson('/api/v1/reservations', [
        'restaurant_id' => $restaurant->id,
        'starts_at' => now()->addDay()->setTime(18, 0)->toDateTimeString(),
        'party_size' => 2,
        'card_hold_reference' => $cardHold->reference,
    ])->assertCreated();

    $reservationId = $response->json('reservation.id');

    expect($cardHold->refresh()->reservation_id)->toBe($reservationId)
        ->and($cardHold->amount)->toBe(15000);
});

it('charges an authorized card and emails both parties on no-show', function (): void {
    Notification::fake();
    ['restaurant' => $restaurant] = cardHoldRestaurant();
    $user = User::factory()->create(['email' => 'guest@example.com']);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $user->id,
        'party_size' => 2,
    ]);
    ReservationCardHold::factory()->authorized()->create([
        'reservation_id' => $reservation->id,
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'amount' => 15000,
        'email' => 'guest@example.com',
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/charge_authorization' => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'reference' => 'rns_x'],
        ]),
    ]);

    app(ReservationCardHoldService::class)->chargeNoShow($reservation->load('cardHold'));

    $cardHold = $reservation->cardHold->refresh();
    expect($cardHold->status)->toBe(ReservationCardHoldStatus::Charged)
        ->and($cardHold->charged_amount)->toBe(15000);

    Notification::assertSentOnDemand(NoShowChargeNotification::class);
    Http::assertSent(fn ($request): bool => $request['amount'] === 15000
        && $request['authorization_code'] !== null);
});

it('dispatches the no-show charge job when a reservation is marked no-show', function (): void {
    Queue::fake();
    ['restaurant' => $restaurant] = cardHoldRestaurant();
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'status' => ReservationStatus::Confirmed,
    ]);

    app(ReservationService::class)->noShowReservation($reservation, User::factory()->create());

    Queue::assertPushed(ChargeNoShowFeeJob::class);
});
