<?php

use App\Models\Role;
use App\Models\User;
use App\Services\RewardProgramService;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('includes default redemption tiers in the reward program payload', function () {
    $service = app(RewardProgramService::class);
    $program = $service->activeProgram();
    $payload = $service->programPayload($program);

    expect($payload['redemption_tiers'])->toHaveCount(4)
        ->and($payload['redemption_tiers'][0])->toMatchArray(['points' => 1000, 'credit_value' => 3000, 'credit_currency' => 'NGN'])
        ->and($payload['redemption_tiers'][1])->toMatchArray(['points' => 2500, 'credit_value' => 5000, 'credit_currency' => 'NGN'])
        ->and($payload['redemption_tiers'][2])->toMatchArray(['points' => 5000, 'credit_value' => 10000, 'credit_currency' => 'NGN'])
        ->and($payload['redemption_tiers'][3])->toMatchArray(['points' => 10000, 'credit_value' => 30000, 'credit_currency' => 'NGN']);
});

it('returns redemption tiers on the customer reward status endpoint', function () {
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $response = $this->getJson('/api/v1/me/rewards/status');

    $response->assertOk()
        ->assertJsonPath('rewards.program.redemption_tiers.0.points', 1000)
        ->assertJsonPath('rewards.program.redemption_tiers.0.credit_value', 3000)
        ->assertJsonPath('rewards.program.redemption_tiers.3.points', 10000)
        ->assertJsonPath('rewards.program.redemption_tiers.3.credit_value', 30000);
});

it('allows admins to update the redemption tiers', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    Sanctum::actingAs($admin);

    $response = $this->patchJson('/api/v1/admin/reward-program', [
        'redemption_tiers' => [
            ['points' => 500, 'credit_value' => 1500, 'credit_currency' => 'NGN'],
            ['points' => 2000, 'credit_value' => 4000, 'credit_currency' => 'NGN'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('reward_program.redemption_tiers.0.points', 500)
        ->assertJsonPath('reward_program.redemption_tiers.0.credit_value', 1500)
        ->assertJsonPath('reward_program.redemption_tiers.1.points', 2000)
        ->assertJsonPath('reward_program.redemption_tiers.1.credit_value', 4000);
});

it('validates redemption tier fields', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $response = $this->patchJson('/api/v1/admin/reward-program', [
        'redemption_tiers' => [
            ['points' => 0, 'credit_value' => -100],
        ],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'redemption_tiers.0.points',
            'redemption_tiers.0.credit_value',
        ]);
});
