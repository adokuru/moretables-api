<?php

use App\AuthChallengeStatus;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\SocialIdentityVerifier;
use App\Services\VerifiedSocialIdentity;
use App\SocialAuthProvider;
use App\UserStatus;
use Illuminate\Support\Facades\Auth;

it('marks the account for deletion and revokes all access', function () {
    $user = User::factory()->create();
    $currentToken = $user->createToken('current-device')->plainTextToken;
    $otherToken = $user->createToken('other-device')->plainTextToken;
    $challenge = AuthChallenge::factory()->for($user)->create([
        'status' => AuthChallengeStatus::Pending,
    ]);

    $response = $this->withToken($currentToken)->deleteJson('/api/v1/auth/account');

    $response->assertOk()
        ->assertJsonPath('message', 'Account deletion requested successfully.');

    expect($user->refresh()->status)->toBe(UserStatus::PendingDeletion)
        ->and($user->tokens()->count())->toBe(0)
        ->and($challenge->refresh()->status)->toBe(AuthChallengeStatus::Cancelled);

    $this->assertDatabaseHas('users', ['id' => $user->id]);
    Auth::forgetGuards();
    $this->withToken($otherToken)->getJson('/api/v1/auth/profile')->assertUnauthorized();
});

it('requires authentication to request account deletion', function () {
    $this->deleteJson('/api/v1/auth/account')->assertUnauthorized();
});

it('blocks passwordless login for an account pending deletion', function () {
    $user = User::factory()->create([
        'email' => 'pending-deletion@example.com',
        'status' => UserStatus::PendingDeletion,
    ]);

    $this->postJson('/api/v1/auth/start', [
        'email' => $user->email,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'This account is pending deletion and can no longer be accessed.');
});

it('blocks staff and admin password login for an account pending deletion', function () {
    $user = User::factory()->create([
        'email' => 'pending-password-deletion@example.com',
        'password' => 'Secret123!',
        'status' => UserStatus::PendingDeletion,
    ]);

    $credentials = [
        'identifier' => $user->email,
        'password' => 'Secret123!',
    ];

    $this->postJson('/api/v1/auth/staff/login', $credentials)->assertUnprocessable();
    $this->postJson('/api/v1/admin/auth/login', $credentials)->assertUnprocessable();
});

it('blocks social login for an account pending deletion', function () {
    User::factory()->create([
        'email' => 'pending-social-deletion@example.com',
        'status' => UserStatus::PendingDeletion,
    ]);

    $this->mock(SocialIdentityVerifier::class, function (object $mock): void {
        $mock->shouldReceive('verify')
            ->once()
            ->andReturn(new VerifiedSocialIdentity(
                provider: SocialAuthProvider::Google,
                providerUserId: 'pending-social-user',
                email: 'pending-social-deletion@example.com',
                emailVerified: true,
            ));
    });

    $this->postJson('/api/v1/auth/google', [
        'id_token' => 'google-id-token',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.id_token.0', 'This account is pending deletion and can no longer be accessed.');
});

it('starts and confirms account restore for a pending deletion account', function () {
    $user = User::factory()->create([
        'email' => 'restore-me@example.com',
        'first_name' => 'Restore',
        'last_name' => 'Me',
        'status' => UserStatus::PendingDeletion,
    ]);

    $start = $this->postJson('/api/v1/auth/account/restore', [
        'email' => $user->email,
    ]);

    $start->assertCreated()
        ->assertJsonPath('message', 'Verification code sent.')
        ->assertJsonStructure(['challenge_token', 'expires_at']);

    $confirm = $this->postJson('/api/v1/auth/account/restore/verify', [
        'challenge_token' => $start->json('challenge_token'),
        'code' => '1234',
        'device_name' => 'restore-test',
    ]);

    $confirm->assertOk()
        ->assertJsonPath('message', 'Account restored successfully.')
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonStructure(['token', 'user']);

    expect($user->refresh()->status)->toBe(UserStatus::Active);

    $this->withToken($confirm->json('token'))
        ->getJson('/api/v1/auth/profile')
        ->assertOk();
});

it('rejects restore for accounts that are not pending deletion', function () {
    $user = User::factory()->create([
        'email' => 'still-active@example.com',
        'status' => UserStatus::Active,
    ]);

    $this->postJson('/api/v1/auth/account/restore', [
        'email' => $user->email,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'No account pending deletion was found for this email.');
});

it('resends the account restore verification code', function () {
    $user = User::factory()->create([
        'email' => 'restore-resend@example.com',
        'status' => UserStatus::PendingDeletion,
    ]);

    $start = $this->postJson('/api/v1/auth/account/restore', [
        'email' => $user->email,
    ])->assertCreated();

    $this->postJson('/api/v1/auth/account/restore/resend', [
        'challenge_token' => $start->json('challenge_token'),
    ])->assertOk()
        ->assertJsonPath('message', 'A new verification code has been sent.');
});
