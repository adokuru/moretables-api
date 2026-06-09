<?php

use App\Models\Reservation;
use App\Models\RewardPointTransaction;
use App\Models\User;
use App\RewardPointTransactionType;
use App\Services\RewardProgramService;
use Laravel\Sanctum\Sanctum;

it('returns reservation and restaurant details on earn transactions', function () {
    $service = app(RewardProgramService::class);
    $customer = User::factory()->create();
    $reservation = Reservation::factory()->create([
        'user_id' => $customer->id,
    ]);

    $service->awardPoints($customer, [
        'points' => 500,
        'type' => RewardPointTransactionType::Earn,
        'description' => 'Points earned from completed reservation.',
        'reference_type' => Reservation::class,
        'reference_id' => $reservation->id,
        'metadata' => [
            'restaurant_id' => $reservation->restaurant_id,
            'restaurant_name' => $reservation->restaurant->name,
            'reservation_reference' => $reservation->reservation_reference,
            'reservation_starts_at' => $reservation->starts_at?->toIso8601String(),
        ],
    ]);

    Sanctum::actingAs($customer);

    $response = $this->getJson('/api/v1/me/rewards/transactions');

    $response->assertOk()
        ->assertJsonPath('data.0.type', 'earn')
        ->assertJsonPath('data.0.direction', 'credit')
        ->assertJsonPath('data.0.points', 500)
        ->assertJsonPath('data.0.source.type', 'reservation')
        ->assertJsonPath('data.0.source.reservation.reference', $reservation->reservation_reference)
        ->assertJsonPath('data.0.source.restaurant.id', $reservation->restaurant_id)
        ->assertJsonPath('data.0.source.restaurant.name', $reservation->restaurant->name)
        ->assertJsonPath('data.0.expires_at', fn ($value) => $value !== null);
});

it('returns redemption credit and consumed source details on redeem transactions', function () {
    $service = app(RewardProgramService::class);
    $customer = User::factory()->create();
    $reservation = Reservation::factory()->create([
        'user_id' => $customer->id,
    ]);

    $service->awardPoints($customer, [
        'points' => 1000,
        'type' => RewardPointTransactionType::Earn,
        'description' => 'Points earned from completed reservation.',
        'reference_type' => Reservation::class,
        'reference_id' => $reservation->id,
        'metadata' => [
            'restaurant_id' => $reservation->restaurant_id,
            'restaurant_name' => $reservation->restaurant->name,
            'reservation_reference' => $reservation->reservation_reference,
        ],
    ]);

    Sanctum::actingAs($customer);

    $this->postJson('/api/v1/me/rewards/redeem', [
        'points' => 1000,
    ])->assertCreated()
        ->assertJsonPath('transaction.type', 'redeem')
        ->assertJsonPath('transaction.direction', 'debit')
        ->assertJsonPath('transaction.points', -1000)
        ->assertJsonPath('transaction.credit.value', 3000)
        ->assertJsonPath('transaction.credit.currency', 'NGN')
        ->assertJsonPath('transaction.redemption.points_redeemed', 1000)
        ->assertJsonPath('transaction.redemption.credit_value', 3000)
        ->assertJsonPath('transaction.redemption.consumed_from.0.points', 1000)
        ->assertJsonPath('rewards.points', 0);
});

it('creates expire transactions for due earn lots', function () {
    $customer = User::factory()->create();
    $program = app(RewardProgramService::class)->activeProgram();

    RewardPointTransaction::factory()
        ->for($program, 'rewardProgram')
        ->for($customer, 'user')
        ->expiredEarnLot(750)
        ->create([
            'created_by' => $customer->id,
        ]);

    $this->artisan('app:expire-reward-points')->assertSuccessful();

    Sanctum::actingAs($customer);

    $response = $this->getJson('/api/v1/me/rewards/transactions');

    $response->assertOk()
        ->assertJsonPath('data.0.type', 'expire')
        ->assertJsonPath('data.0.direction', 'debit')
        ->assertJsonPath('data.0.points', -750)
        ->assertJsonPath('rewards.points', 0);
});
