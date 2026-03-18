<?php

use App\Models\AuthChallenge;
use App\Models\User;
use App\Notifications\AuthChallengeCodeNotification;
use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('starts guest onboarding, verifies otp, and completes the profile', function () {
    Notification::fake();

    $startResponse = $this->postJson('/api/v1/auth/start', [
        'email' => 'guest@example.com',
    ]);

    $startResponse->assertCreated()
        ->assertJsonStructure(['message', 'challenge_token', 'expires_at']);

    $user = User::query()->where('email', 'guest@example.com')->firstOrFail();

    expect($user->status)->toBe(UserStatus::PendingEmailVerification);
    Notification::assertSentTo($user, AuthChallengeCodeNotification::class);

    $challenge = AuthChallenge::query()->where('user_id', $user->id)->firstOrFail();

    $verifyResponse = $this->postJson('/api/v1/auth/verify-otp', [
        'challenge_token' => $challenge->challenge_token,
        'code' => '1234',
        'device_name' => 'test-device',
    ]);

    $verifyResponse->assertOk()
        ->assertJsonStructure(['message', 'token', 'token_type', 'user']);

    $user->refresh();
    expect($user->status)->toBe(UserStatus::PendingProfileCompletion);

    $completeResponse = $this->withToken($verifyResponse->json('token'))->postJson('/api/v1/auth/complete-profile', [
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'phone' => '+2348012345678',
    ]);

    $completeResponse->assertOk()
        ->assertJsonPath('user.first_name', 'Ada')
        ->assertJsonPath('user.status', UserStatus::Active->value);
});

it('lets an existing customer sign in again through the passwordless email otp flow', function () {
    Notification::fake();

    $customer = User::factory()->create([
        'email' => 'returning@example.com',
        'password' => null,
        'auth_method' => UserAuthMethod::Passwordless,
    ]);

    $startResponse = $this->postJson('/api/v1/auth/start', [
        'email' => 'returning@example.com',
    ]);

    $startResponse->assertCreated();

    Notification::assertSentTo($customer, AuthChallengeCodeNotification::class);

    $challenge = AuthChallenge::query()->where('user_id', $customer->id)->latest()->firstOrFail();

    $verifyResponse = $this->postJson('/api/v1/auth/verify-otp', [
        'challenge_token' => $challenge->challenge_token,
        'code' => '1234',
        'device_name' => 'returning-device',
    ]);

    $verifyResponse->assertOk()
        ->assertJsonPath('message', 'Login successful.')
        ->assertJsonPath('user.email', 'returning@example.com')
        ->assertJsonPath('user.status', UserStatus::Active->value);
});

it('prevents incomplete guest accounts from booking reservations', function () {
    $data = createBookableRestaurant();
    $user = User::factory()->pendingGuest()->create([
        'email' => 'incomplete@example.com',
    ]);

    $token = $user->createToken('guest-onboarding')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/reservations', [
        'restaurant_id' => $data['restaurant']->id,
        'starts_at' => now()->addDay()->setTime(18, 0)->toDateTimeString(),
        'party_size' => 2,
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['user']);
});

it('does not expose customer password auth endpoints', function () {
    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'customer@example.com',
        'password' => 'Secret123!',
    ])->assertNotFound();

    $this->postJson('/api/v1/auth/register', [
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'email' => 'customer@example.com',
        'phone' => '+2348012345678',
        'password' => 'Secret123!',
        'password_confirmation' => 'Secret123!',
    ])->assertNotFound();
});

it('does not allow customer accounts to request password reset links', function () {
    Password::spy();

    $customer = User::factory()->create([
        'email' => 'customer@example.com',
        'password' => null,
        'auth_method' => UserAuthMethod::Passwordless,
    ]);

    $response = $this->postJson('/api/v1/auth/password/forgot', [
        'email' => $customer->email,
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'If the account exists, a reset link has been sent.');

    Password::shouldNotHaveReceived('sendResetLink');
});
