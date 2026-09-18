<?php

use App\Models\AuthChallenge;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * The testing environment already forces every OTP to '1234', so these tests
 * deliberately configure a *different* demo code — otherwise they'd pass even
 * with the demo branch deleted.
 */
beforeEach(function () {
    Notification::fake();

    config([
        'auth.demo.code' => '5678',
        'auth.demo.customer.email' => 'Demo@MoreTables.com',
        'auth.demo.restaurant.email' => 'demo-restaurant@moretables.com',
        'auth.demo.restaurant.password' => 'MoreTablesDemo1!',
        'auth.demo.restaurant.name' => 'MoreTables Demo Kitchen',
        'auth.demo.emails' => ['Demo@MoreTables.com', 'demo-restaurant@moretables.com'],
    ]);
});

function startGuestLogin(string $email): string
{
    return test()->postJson('/api/v1/auth/start', ['email' => $email])
        ->assertCreated()
        ->json('challenge_token');
}

it('always accepts the fixed demo code for a configured demo account', function () {
    // Case-insensitively matched, so a reviewer typing lowercase still works.
    $token = startGuestLogin('demo@moretables.com');

    $this->postJson('/api/v1/auth/verify-otp', [
        'challenge_token' => $token,
        'code' => '5678',
    ])->assertOk()->assertJsonStructure(['token', 'user']);
});

it('does not give the demo code to any other account', function () {
    $token = startGuestLogin('guest@example.com');

    $this->postJson('/api/v1/auth/verify-otp', [
        'challenge_token' => $token,
        'code' => '5678',
    ])->assertStatus(422);
});

it('leaves demo challenges expiring, attempt-limited and single-use', function () {
    $token = startGuestLogin('demo@moretables.com');

    // A wrong code is still rejected and still counts against the attempt cap.
    $this->postJson('/api/v1/auth/verify-otp', [
        'challenge_token' => $token,
        'code' => '0000',
    ])->assertStatus(422);

    expect(AuthChallenge::query()->where('challenge_token', $token)->value('attempts'))->toBe(1);

    $this->postJson('/api/v1/auth/verify-otp', [
        'challenge_token' => $token,
        'code' => '5678',
    ])->assertOk();

    // Consumed: the same code cannot be replayed against the same challenge.
    $this->postJson('/api/v1/auth/verify-otp', [
        'challenge_token' => $token,
        'code' => '5678',
    ])->assertStatus(422);
});

it('provisions a customer demo that lands on Home, not profile completion', function () {
    $this->seed(Database\Seeders\RoleAndPermissionSeeder::class);
    $this->artisan('app:provision-demo')->assertSuccessful();

    $user = User::query()->where('email', 'Demo@MoreTables.com')->firstOrFail();

    expect($user->status->value)->toBe('active')
        ->and($user->first_name)->not->toBeEmpty()
        ->and($user->last_name)->not->toBeEmpty();
});

it('signs the restaurant demo all the way into the front-of-house app', function () {
    $this->seed(Database\Seeders\RoleAndPermissionSeeder::class);
    $this->seed(Database\Seeders\BillingPlanSeeder::class);
    $this->artisan('app:provision-demo')->assertSuccessful();

    // Step 1 of the tablet app's flow: identifier + password.
    $challengeToken = $this->postJson('/api/v1/auth/staff/login', [
        'identifier' => 'demo-restaurant@moretables.com',
        'password' => 'MoreTablesDemo1!',
    ])->assertOk()->json('challenge_token');

    // Step 2: the 2FA code, which the reviewer can never receive by email.
    $token = $this->postJson('/api/v1/auth/staff/verify-2fa', [
        'challenge_token' => $challengeToken,
        'code' => '5678',
    ])->assertOk()->json('token');

    // Front-of-house sits behind merchant.billing.active — a 402 here is the
    // paywall that would get the build rejected.
    $restaurant = Restaurant::query()->where('slug', 'moretables-demo-kitchen')->firstOrFail();

    $this->withToken($token)
        ->getJson("/api/v1/merchant/restaurants/{$restaurant->id}/front-of-house/reservations")
        ->assertOk();

    expect($restaurant->tables()->count())->toBeGreaterThan(0)
        ->and($restaurant->shifts()->where('is_active', true)->count())->toBe(7);
});

it('is safe to re-run before every review round', function () {
    $this->seed(Database\Seeders\RoleAndPermissionSeeder::class);
    $this->seed(Database\Seeders\BillingPlanSeeder::class);

    $this->artisan('app:provision-demo')->assertSuccessful();
    $this->artisan('app:provision-demo')->assertSuccessful();

    expect(Restaurant::query()->where('slug', 'moretables-demo-kitchen')->count())->toBe(1)
        ->and(User::query()->where('email', 'demo-restaurant@moretables.com')->count())->toBe(1)
        ->and(Restaurant::query()->where('slug', 'moretables-demo-kitchen')->firstOrFail()->tables()->count())->toBe(8);
});

it('does nothing at all when no demo accounts are configured', function () {
    config(['auth.demo.emails' => [], 'auth.demo.customer.email' => null, 'auth.demo.restaurant.email' => null]);

    $this->artisan('app:provision-demo')->assertSuccessful();

    expect(Restaurant::query()->where('slug', 'moretables-demo-kitchen')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'Demo@MoreTables.com')->exists())->toBeFalse();
});
