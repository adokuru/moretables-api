<?php

use App\Models\AuthChallenge;
use App\Models\User;
use App\Notifications\AuthChallengeCodeNotification;
use App\UserStatus;
use Illuminate\Support\Facades\Notification;

it('starts guest onboarding, verifies otp, and completes the profile', function () {
    Notification::fake();

    $startResponse = $this->postJson('/api/v1/guest/start', [
        'email' => 'guest@example.com',
    ]);

    $startResponse->assertCreated()
        ->assertJsonStructure(['message', 'challenge_token', 'expires_at']);

    $user = User::query()->where('email', 'guest@example.com')->firstOrFail();

    expect($user->status)->toBe(UserStatus::PendingEmailVerification);
    Notification::assertSentTo($user, AuthChallengeCodeNotification::class);

    $challenge = AuthChallenge::query()->where('user_id', $user->id)->firstOrFail();

    $verifyResponse = $this->postJson('/api/v1/guest/verify-otp', [
        'challenge_token' => $challenge->challenge_token,
        'code' => '123456',
        'device_name' => 'test-device',
    ]);

    $verifyResponse->assertOk()
        ->assertJsonStructure(['message', 'token', 'token_type', 'user']);

    $user->refresh();
    expect($user->status)->toBe(UserStatus::PendingProfileCompletion);

    $completeResponse = $this->withToken($verifyResponse->json('token'))->postJson('/api/v1/guest/complete-profile', [
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'phone' => '+2348012345678',
    ]);

    $completeResponse->assertOk()
        ->assertJsonPath('user.first_name', 'Ada')
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
