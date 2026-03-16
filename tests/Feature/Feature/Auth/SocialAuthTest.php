<?php

use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialIdentityVerifier;
use App\Services\VerifiedSocialIdentity;
use App\SocialAuthProvider;
use App\UserAuthMethod;
use Database\Seeders\RoleAndPermissionSeeder;

it('creates a customer account from a google identity token', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->mock(SocialIdentityVerifier::class, function (object $mock): void {
        $mock->shouldReceive('verify')
            ->once()
            ->withArgs(fn (SocialAuthProvider $provider, string $idToken): bool => $provider === SocialAuthProvider::Google && $idToken === 'google-id-token')
            ->andReturn(new VerifiedSocialIdentity(
                provider: SocialAuthProvider::Google,
                providerUserId: 'google-user-123',
                email: 'google-customer@example.com',
                emailVerified: true,
                firstName: 'Ada',
                lastName: 'Okafor',
            ));
    });

    $response = $this->postJson('/api/v1/auth/google', [
        'id_token' => 'google-id-token',
        'device_name' => 'expo-ios',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.email', 'google-customer@example.com')
        ->assertJsonPath('user.first_name', 'Ada')
        ->assertJsonPath('user.auth_method', UserAuthMethod::Social->value)
        ->assertJsonStructure(['token', 'token_type', 'user']);

    $user = User::query()->where('email', 'google-customer@example.com')->firstOrFail();

    expect($user->hasRole(Role::Customer))->toBeTrue();

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => SocialAuthProvider::Google->value,
        'provider_user_id' => 'google-user-123',
    ]);
});

it('links apple sign in to an existing customer account by email', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $customer = User::factory()->create([
        'email' => 'existing-customer@example.com',
        'auth_method' => UserAuthMethod::Password,
    ]);

    $this->mock(SocialIdentityVerifier::class, function (object $mock): void {
        $mock->shouldReceive('verify')
            ->once()
            ->andReturn(new VerifiedSocialIdentity(
                provider: SocialAuthProvider::Apple,
                providerUserId: 'apple-user-456',
                email: 'existing-customer@example.com',
                emailVerified: true,
                firstName: 'Chioma',
                lastName: 'Adebayo',
            ));
    });

    $response = $this->postJson('/api/v1/auth/apple', [
        'id_token' => 'apple-id-token',
        'device_name' => 'expo-ios',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.email', 'existing-customer@example.com')
        ->assertJsonPath('user.auth_method', UserAuthMethod::Password->value);

    expect(User::query()->where('email', 'existing-customer@example.com')->count())->toBe(1);

    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $customer->id,
        'provider' => SocialAuthProvider::Apple->value,
        'provider_user_id' => 'apple-user-456',
    ]);
});

it('signs in an existing apple social account even when the email is not present anymore', function () {
    $customer = User::factory()->create([
        'email' => 'apple-linked@example.com',
        'auth_method' => UserAuthMethod::Social,
    ]);

    SocialAccount::factory()->create([
        'user_id' => $customer->id,
        'provider' => SocialAuthProvider::Apple,
        'provider_user_id' => 'apple-user-789',
        'provider_email' => 'apple-linked@example.com',
    ]);

    $this->mock(SocialIdentityVerifier::class, function (object $mock): void {
        $mock->shouldReceive('verify')
            ->once()
            ->andReturn(new VerifiedSocialIdentity(
                provider: SocialAuthProvider::Apple,
                providerUserId: 'apple-user-789',
                email: null,
                emailVerified: false,
                firstName: 'Tobi',
                lastName: 'Ajayi',
            ));
    });

    $response = $this->postJson('/api/v1/auth/apple', [
        'id_token' => 'apple-returning-token',
        'device_name' => 'expo-ios',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.email', 'apple-linked@example.com');
});

it('rejects first time social sign in when the provider token has no verified email', function () {
    $this->mock(SocialIdentityVerifier::class, function (object $mock): void {
        $mock->shouldReceive('verify')
            ->once()
            ->andReturn(new VerifiedSocialIdentity(
                provider: SocialAuthProvider::Apple,
                providerUserId: 'apple-user-999',
                email: null,
                emailVerified: false,
            ));
    });

    $response = $this->postJson('/api/v1/auth/apple', [
        'id_token' => 'apple-first-login-no-email',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['id_token']);
});
