<?php

use App\Models\Role;
use App\Models\User;
use App\UserAuthMethod;
use App\UserStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('returns an unauthorized response when profile settings are requested without a token', function () {
    $response = $this->getJson('/api/v1/auth/profile');

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
});

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

it('uploads the authenticated user profile picture', function () {
    Storage::fake('public');

    $user = User::factory()->create([
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
    ]);

    $token = $user->createToken('profile-settings')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/auth/profile-picture', [
        'profile_picture' => UploadedFile::fake()->image('avatar.png'),
        'alt_text' => 'Profile avatar',
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Profile picture uploaded successfully.')
        ->assertJsonPath('profile_picture.collection', 'profile_picture')
        ->assertJsonPath('profile_picture.alt_text', 'Profile avatar')
        ->assertJsonPath('user.profile_picture.collection', 'profile_picture');
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

it('returns the authenticated merchant profile settings', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $merchant = User::factory()->create([
        'first_name' => 'Merchant',
        'last_name' => 'Manager',
        'bio' => 'Runs daily restaurant operations.',
        'birthday' => '1990-02-03',
    ]);

    assignScopedRole($merchant, Role::Operations, $data['organization'], $data['restaurant']);

    $token = $merchant->createToken('merchant-profile')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/auth/staff/profile');

    $response->assertOk()
        ->assertJsonPath('user.first_name', 'Merchant')
        ->assertJsonPath('user.bio', 'Runs daily restaurant operations.')
        ->assertJsonPath('user.birthday', '1990-02-03');
});

it('updates the authenticated admin profile settings', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create([
        'first_name' => 'Admin',
        'last_name' => 'User',
        'bio' => null,
        'birthday' => null,
    ]);

    assignScopedRole($admin, Role::SuperAdmin);

    $token = $admin->createToken('admin-profile')->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/v1/admin/auth/profile', [
        'first_name' => 'Chief',
        'last_name' => 'Admin',
        'bio' => 'Oversees platform administration.',
        'birthday' => '1988-11-19',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.name', 'Chief Admin')
        ->assertJsonPath('user.first_name', 'Chief')
        ->assertJsonPath('user.last_name', 'Admin')
        ->assertJsonPath('user.bio', 'Oversees platform administration.')
        ->assertJsonPath('user.birthday', '1988-11-19');
});
