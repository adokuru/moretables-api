<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RoleAndPermissionSeeder;

it('creates the bootstrap admin accounts with global admin roles', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(AdminUserSeeder::class);

    $superAdmin = User::query()->where('email', 'info@moretables.com')->first();
    $businessAdmin = User::query()->where('email', 'business@moretables.com')->first();
    $secondarySuperAdmin = User::query()->where('email', 'alfadaji@gmail.com')->first();

    expect($superAdmin)->not->toBeNull();
    expect($businessAdmin)->not->toBeNull();
    expect($secondarySuperAdmin)->not->toBeNull();
    expect($superAdmin?->hasRole(Role::SuperAdmin))->toBeTrue();
    expect($businessAdmin?->hasRole(Role::BusinessAdmin))->toBeTrue();
    expect($secondarySuperAdmin?->hasRole(Role::SuperAdmin))->toBeTrue();
    expect($superAdmin?->requiresAdminLogin())->toBeTrue();
    expect($businessAdmin?->requiresAdminLogin())->toBeTrue();
    expect($secondarySuperAdmin?->requiresAdminLogin())->toBeTrue();
});
