<?php

use App\Models\RewardPointTransaction;
use App\Models\Role;
use App\Models\User;
use App\RewardPointTransactionType;
use App\Services\RewardProgramService;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('returns customer reward status and transaction history for the lifetime loyalty program', function () {
    $program = app(RewardProgramService::class)->activeProgram();
    $customer = User::factory()->create();

    RewardPointTransaction::factory()->create([
        'reward_program_id' => $program->id,
        'user_id' => $customer->id,
        'created_by' => $customer->id,
        'type' => RewardPointTransactionType::Earn,
        'points' => 1500,
        'balance_after' => 1500,
        'description' => 'Welcome points',
    ]);

    RewardPointTransaction::factory()->create([
        'reward_program_id' => $program->id,
        'user_id' => $customer->id,
        'created_by' => $customer->id,
        'type' => RewardPointTransactionType::Earn,
        'points' => 3500,
        'balance_after' => 5000,
        'description' => 'Dining points',
    ]);

    Sanctum::actingAs($customer);

    $statusResponse = $this->getJson('/api/v1/me/rewards/status');

    $statusResponse->assertOk()
        ->assertJsonPath('rewards.program.period_type', 'lifetime')
        ->assertJsonPath('rewards.program.levels.0.slug', 'bronze')
        ->assertJsonPath('rewards.program.levels.1.slug', 'silver')
        ->assertJsonPath('rewards.program.levels.2.slug', 'gold')
        ->assertJsonPath('rewards.program.levels.3.slug', 'platinum')
        ->assertJsonPath('rewards.points', 5000)
        ->assertJsonPath('rewards.current_level.slug', 'gold')
        ->assertJsonPath('rewards.next_level.slug', 'platinum')
        ->assertJsonPath('rewards.points_to_next_level', 5000);

    $transactionsResponse = $this->getJson('/api/v1/me/rewards/transactions');

    $transactionsResponse->assertOk()
        ->assertJsonPath('rewards.current_level.slug', 'gold')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.points', 3500)
        ->assertJsonPath('data.0.balance_after', 5000)
        ->assertJsonPath('data.0.level_after.slug', 'gold')
        ->assertJsonPath('data.1.points', 1500)
        ->assertJsonPath('data.1.level_after.slug', 'silver');
});

it('allows admins to award loyalty points and recalculates the customer tier', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    $customer = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/users/'.$customer->id.'/reward-points', [
        'points' => 1200,
        'type' => 'earn',
        'description' => 'Opening bonus',
        'metadata' => [
            'source' => 'manual_adjustment',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('transaction.type', 'earn')
        ->assertJsonPath('transaction.points', 1200)
        ->assertJsonPath('transaction.balance_after', 1200)
        ->assertJsonPath('rewards.points', 1200)
        ->assertJsonPath('rewards.current_level.slug', 'silver');

    $this->assertDatabaseHas('reward_point_transactions', [
        'user_id' => $customer->id,
        'created_by' => $admin->id,
        'points' => 1200,
        'balance_after' => 1200,
    ]);
});

it('allows admins to update the lifetime reward program levels', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    Sanctum::actingAs($admin);

    $response = $this->patchJson('/api/v1/admin/reward-program', [
        'name' => 'MoreTables VIP Rewards',
        'description' => 'Updated lifetime loyalty program.',
        'levels' => [
            [
                'name' => 'Bronze',
                'start_points' => 0,
                'end_points' => 1499,
            ],
            [
                'name' => 'Silver',
                'start_points' => 1500,
                'end_points' => 5499,
            ],
            [
                'name' => 'Gold',
                'start_points' => 5500,
                'end_points' => 10999,
            ],
            [
                'name' => 'Platinum',
                'start_points' => 11000,
                'end_points' => null,
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('reward_program.name', 'MoreTables VIP Rewards')
        ->assertJsonPath('reward_program.period_type', 'lifetime')
        ->assertJsonPath('reward_program.levels.0.end_points', 1499)
        ->assertJsonPath('reward_program.levels.3.start_points', 11000);
});

it('validates lifetime reward program updates and forbids non-admin management', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $customer = User::factory()->create();

    Sanctum::actingAs($customer);

    $forbiddenResponse = $this->getJson('/api/v1/admin/reward-program');
    $forbiddenResponse->assertForbidden();

    $forbiddenAwardResponse = $this->postJson('/api/v1/admin/users/'.$customer->id.'/reward-points', [
        'points' => 100,
    ]);
    $forbiddenAwardResponse->assertForbidden();

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $invalidResponse = $this->patchJson('/api/v1/admin/reward-program', [
        'period_value' => 30,
        'resets_points' => true,
        'levels' => [
            [
                'name' => 'Bronze',
                'start_points' => 0,
                'end_points' => 999,
            ],
            [
                'name' => 'Silver',
                'start_points' => 1200,
                'end_points' => 4999,
            ],
            [
                'name' => 'Gold',
                'start_points' => 5000,
                'end_points' => null,
            ],
            [
                'name' => 'Platinum',
                'start_points' => 10000,
                'end_points' => null,
            ],
        ],
    ]);

    $invalidResponse->assertUnprocessable()
        ->assertJsonValidationErrors([
            'period_value',
            'resets_points',
            'levels',
        ]);
});
