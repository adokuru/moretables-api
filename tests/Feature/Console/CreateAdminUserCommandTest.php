<?php

use App\Models\Role;
use App\Models\User;

it('creates the first admin user from the command', function () {
    $this->artisan('app:create-admin-user', [
        'email' => 'admin@moretables.test',
        '--role' => Role::SuperAdmin,
        '--first-name' => 'First',
        '--last-name' => 'Admin',
        '--phone' => '+2348000000001',
        '--password' => 'Secret123!',
    ])
        ->expectsOutput('Admin user ready.')
        ->expectsOutput('Email: admin@moretables.test')
        ->expectsOutput('Role: super_admin')
        ->expectsOutput('Password: Secret123!')
        ->assertSuccessful();

    $user = User::query()->where('email', 'admin@moretables.test')->firstOrFail();

    expect($user->first_name)->toBe('First');
    expect($user->status->value)->toBe('active');
    expect($user->requiresAdminLogin())->toBeTrue();
    expect($user->hasRole(Role::SuperAdmin))->toBeTrue();
});

it('rejects non admin roles on the create admin command', function () {
    $this->artisan('app:create-admin-user', [
        'email' => 'admin@moretables.test',
        '--role' => Role::Customer,
    ])
        ->expectsOutput('The role must be one of: business_admin, dev_admin, super_admin.')
        ->assertFailed();
});
