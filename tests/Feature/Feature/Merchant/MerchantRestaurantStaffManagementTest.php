<?php

use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use App\UserStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('allows principal admins to invite, manage, and remove restaurant staff', function () {
    Storage::fake('public');
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $principalAdmin = User::factory()->create();

    assignScopedRole($principalAdmin, Role::PrincipalAdmin, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($principalAdmin);

    $inviteResponse = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/staff', [
        'first_name' => 'Floor',
        'last_name' => 'Manager',
        'email' => 'floor.manager@example.com',
        'phone' => '+2348012345678',
        'role' => Role::Operations,
    ]);

    $inviteResponse->assertCreated()
        ->assertJsonPath('staff_member.role', Role::Operations)
        ->assertJsonPath('staff_member.user.email', 'floor.manager@example.com');

    $staffUser = User::query()->where('email', 'floor.manager@example.com')->firstOrFail();

    Notification::assertSentTo($staffUser, ResetPassword::class);
    $this->assertDatabaseHas('password_reset_tokens', [
        'email' => $staffUser->email,
    ]);

    $listResponse = $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/staff');

    $listResponse->assertOk()
        ->assertJsonCount(2, 'staff')
        ->assertJsonFragment([
            'role' => Role::PrincipalAdmin,
            'email' => $principalAdmin->email,
        ])
        ->assertJsonFragment([
            'role' => Role::Operations,
            'email' => 'floor.manager@example.com',
        ]);

    $updateResponse = $this->patchJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/staff/'.$staffUser->id, [
        'role' => Role::GuestRelations,
        'status' => UserStatus::Suspended->value,
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('staff_member.role', Role::GuestRelations)
        ->assertJsonPath('staff_member.user.status', UserStatus::Suspended->value);

    $deleteResponse = $this->deleteJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/staff/'.$staffUser->id);

    $deleteResponse->assertOk()
        ->assertJsonPath('message', 'Staff member removed from the restaurant successfully.');

    $this->assertDatabaseMissing('user_roles', [
        'user_id' => $staffUser->id,
        'restaurant_id' => $data['restaurant']->id,
    ]);
});

it('blocks suspended restaurant staff accounts from logging in', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $principalAdmin = User::factory()->create();
    $staffMember = User::factory()->create([
        'email' => 'staff.member@example.com',
        'password' => 'Secret123!',
    ]);

    assignScopedRole($principalAdmin, Role::PrincipalAdmin, $data['organization'], $data['restaurant']);
    assignScopedRole($staffMember, Role::GuestRelations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($principalAdmin);

    $this->patchJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/staff/'.$staffMember->id, [
        'status' => UserStatus::Suspended->value,
    ])->assertOk();

    $loginResponse = $this->postJson('/api/v1/auth/staff/login', [
        'identifier' => 'staff.member@example.com',
        'password' => 'Secret123!',
    ]);

    $loginResponse->assertUnprocessable()
        ->assertJsonValidationErrors('identifier');
});

it('forbids operations staff from managing restaurant staff', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $operations = User::factory()->create();

    assignScopedRole($operations, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($operations);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/staff')
        ->assertForbidden();

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/staff', [
        'first_name' => 'New',
        'last_name' => 'Staff',
        'email' => 'new.staff@example.com',
        'role' => Role::AnalyticsReporting,
    ])->assertForbidden();
});

it('lets guest relations and analytics staff view reservations but not mutate merchant resources', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $guestRelations = User::factory()->create();
    $analytics = User::factory()->create();

    assignScopedRole($guestRelations, Role::GuestRelations, $data['organization'], $data['restaurant']);
    assignScopedRole($analytics, Role::AnalyticsReporting, $data['organization'], $data['restaurant']);

    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
    ]);

    Sanctum::actingAs($guestRelations);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations')
        ->assertOk();

    $this->patchJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id, [
        'website' => 'https://blocked-update.example.com',
    ])->assertForbidden();

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations', [
        'starts_at' => now()->addDay()->setTime(19, 0)->toDateTimeString(),
        'party_size' => 2,
        'source' => 'walk_in',
        'guest_contact' => [
            'first_name' => 'Walk',
            'last_name' => 'In',
            'phone' => '+2348099999999',
        ],
    ])->assertForbidden();

    Sanctum::actingAs($analytics);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations')
        ->assertOk();

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations', [
        'starts_at' => now()->addDay()->setTime(19, 0)->toDateTimeString(),
        'party_size' => 2,
        'source' => 'walk_in',
        'guest_contact' => [
            'first_name' => 'Walk',
            'last_name' => 'In',
            'phone' => '+2348099999999',
        ],
    ])->assertForbidden();
});

it('lets marketing and growth staff manage profile resources but not reservations', function () {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $marketingLead = User::factory()->create();

    assignScopedRole($marketingLead, Role::MarketingGrowth, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($marketingLead);

    $this->patchJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id, [
        'website' => 'https://marketing-update.example.com',
    ])->assertOk()
        ->assertJsonPath('restaurant.website', 'https://marketing-update.example.com');

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/reservations', [
        'starts_at' => now()->addDay()->setTime(19, 0)->toDateTimeString(),
        'party_size' => 2,
        'source' => 'walk_in',
        'guest_contact' => [
            'first_name' => 'Walk',
            'last_name' => 'In',
            'phone' => '+2348099999999',
        ],
    ])->assertForbidden();
});
