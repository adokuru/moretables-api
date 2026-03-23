<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RoleAndPermissionSeeder;

it('creates the bootstrap admin accounts with global admin roles', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(AdminUserSeeder::class);

    $superAdmin = User::query()->where('email', 'superadmin@moretables.test')->first();
    $businessAdmin = User::query()->where('email', 'businessadmin@moretables.test')->first();

    expect($superAdmin)->not->toBeNull();
    expect($businessAdmin)->not->toBeNull();
    expect($superAdmin?->hasRole(Role::SuperAdmin))->toBeTrue();
    expect($businessAdmin?->hasRole(Role::BusinessAdmin))->toBeTrue();
    expect($superAdmin?->requiresAdminLogin())->toBeTrue();
    expect($businessAdmin?->requiresAdminLogin())->toBeTrue();
});
