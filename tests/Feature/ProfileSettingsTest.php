<?php

use App\Models\User;
use App\UserAuthMethod;
use App\UserStatus;

it('returns the authenticated user profile settings', function () {
    $user = User::factory()->create([
        'first_name' => 'Samuel',
        'last_name' => 'Adebayo',
        'bio' => 'Food explorer and brunch enthusiast.',
        'birthday' => '1992-08-14',
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
    ]);

    $token = $user->createToken('profile-settings')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/auth/profile');

    $response->assertOk()
        ->assertJsonPath('user.first_name', 'Samuel')
        ->assertJsonPath('user.last_name', 'Adebayo')
        ->assertJsonPath('user.bio', 'Food explorer and brunch enthusiast.')
        ->assertJsonPath('user.birthday', '1992-08-14');
});

it('updates the authenticated user profile settings', function () {
    $user = User::factory()->create([
        'first_name' => 'Samuel',
        'last_name' => 'Adebayo',
        'bio' => null,
        'birthday' => null,
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
    ]);

    $token = $user->createToken('profile-settings')->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/v1/auth/profile', [
        'first_name' => 'Ada',
        'last_name' => 'Okafor',
        'bio' => 'Tell us a bit about yourself',
        'birthday' => '1994-05-21',
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Profile updated successfully.')
        ->assertJsonPath('user.name', 'Ada Okafor')
        ->assertJsonPath('user.first_name', 'Ada')
        ->assertJsonPath('user.last_name', 'Okafor')
        ->assertJsonPath('user.bio', 'Tell us a bit about yourself')
        ->assertJsonPath('user.birthday', '1994-05-21');

    $user->refresh();

    expect($user->name)->toBe('Ada Okafor')
        ->and($user->first_name)->toBe('Ada')
        ->and($user->last_name)->toBe('Okafor')
        ->and($user->bio)->toBe('Tell us a bit about yourself')
        ->and(optional($user->birthday)?->toDateString())->toBe('1994-05-21');
});

it('validates birthday when updating profile settings', function () {
    $user = User::factory()->create([
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
    ]);

    $token = $user->createToken('profile-settings')->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/v1/auth/profile', [
        'birthday' => now()->addDay()->toDateString(),
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['birthday']);
});
