<?php

use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RewardPointTransaction;
use App\Models\User;
use App\RewardPointTransactionType;
use App\Services\RewardProgramService;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

it('returns earn transactions with source, credit direction, and expiry', function () {
    $customer = User::factory()->create();
    $restaurant = Restaurant::factory()->create(['name' => 'The Oak Table']);
    $reservation = Reservation::factory()->create([
        'user_id' => $customer->id,
        'restaurant_id' => $restaurant->id,
    ]);

    $service = app(RewardProgramService::class);
    $service->awardPoints(
        user: $customer,
        points: 500,
        type: RewardPointTransactionType::Earn,
        description: 'Points for completed reservation',
        reference: $reservation,
        metadata: [
            'restaurant_id' => $restaurant->id,
            'restaurant_name' => $restaurant->name,
            'reservation_reference' => $reservation->reference,
            'reservation_starts_at' => $reservation->starts_at?->toIso8601String(),
        ],
    );

    Sanctum::actingAs($customer);

    $response = $this->getJson('/api/v1/me/rewards/transactions');

    $response->assertOk()
        ->assertJsonPath('transactions.data.0.type', 'earn')
        ->assertJsonPath('transactions.data.0.direction', 'credit')
        ->assertJsonPath('transactions.data.0.points', 500)
        ->assertJsonPath('transactions.data.0.source.restaurant.name', 'The Oak Table')
        ->assertJsonPath('transactions.data.0.source.reservation.id', $reservation->id)
        ->assertJsonPath('transactions.data.0.points_remaining', 500)
        ->assertJsonPath('transactions.data.0.credit', null);

    expect($response->json('transactions.data.0.expires_at'))->not->toBeNull();
});

it('returns redeem transactions with credit and consumed lots', function () {
    $customer = User::factory()->create();
    $service = app(RewardProgramService::class);

    $service->awardPoints(
        user: $customer,
        points: 5000,
        type: RewardPointTransactionType::Earn,
        description: 'Signup bonus',
    );

    $service->redeemPoints($customer, 1000);

    Sanctum::actingAs($customer);

    $response = $this->getJson('/api/v1/me/rewards/transactions');

    $response->assertOk();

    $redeem = collect($response->json('transactions.data'))
        ->firstWhere('type', 'redeem');

    expect($redeem)->not->toBeNull()
        ->and($redeem['direction'])->toBe('debit')
        ->and($redeem['points'])->toBe(-1000)
        ->and($redeem['credit']['value'])->toBe(3000)
        ->and($redeem['credit']['currency'])->toBe('NGN')
        ->and($redeem['redemption']['points_redeemed'])->toBe(1000)
        ->and($redeem['redemption']['consumed_from'])->toHaveCount(1);
});

it('redeems points via the customer endpoint', function () {
    $customer = User::factory()->create();
    $service = app(RewardProgramService::class);

    $service->awardPoints(
        user: $customer,
        points: 2500,
        type: RewardPointTransactionType::Earn,
        description: 'Earned points',
    );

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/me/rewards/redeem', [
        'points' => 2500,
    ]);

    $response->assertOk()
        ->assertJsonPath('transaction.type', 'redeem')
        ->assertJsonPath('transaction.direction', 'debit')
        ->assertJsonPath('transaction.credit.value', 5000)
        ->assertJsonPath('transaction.credit.currency', 'NGN')
        ->assertJsonPath('rewards.lifetime_points', 0);
});

it('creates expire transactions for lots older than one year', function () {
    $customer = User::factory()->create();
    $program = app(RewardProgramService::class)->activeProgram();

    RewardPointTransaction::factory()->create([
        'user_id' => $customer->id,
        'reward_program_id' => $program->id,
        'type' => RewardPointTransactionType::Earn,
        'points' => 800,
        'balance_after' => 800,
        'points_remaining' => 800,
        'expires_at' => now()->subDay(),
        'description' => 'Old earn lot',
    ]);

    Artisan::call('app:expire-reward-points');

    $expire = RewardPointTransaction::query()
        ->where('user_id', $customer->id)
        ->where('type', RewardPointTransactionType::Expire)
        ->first();

    expect($expire)->not->toBeNull()
        ->and($expire->points)->toBe(-800)
        ->and($expire->balance_after)->toBe(0);

    Sanctum::actingAs($customer);

    $response = $this->getJson('/api/v1/me/rewards/transactions');

    $response->assertOk()
        ->assertJsonPath('transactions.data.0.type', 'expire')
        ->assertJsonPath('transactions.data.0.direction', 'debit')
        ->assertJsonPath('transactions.data.0.points', -800);
});
