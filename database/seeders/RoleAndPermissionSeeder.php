<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'restaurants.view',
            'restaurants.manage',
            'reservations.view',
            'reservations.manage',
            'waitlist.manage',
            'tables.manage',
            'staff.manage',
            'audit_logs.view',
            'organizations.manage',
            'roles.manage',
            'docs.view',
        ];

        foreach ($permissions as $permissionName) {
            Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['description' => str_replace('.', ' ', $permissionName)],
            );
        }

        $rolePermissions = [
            Role::Customer => ['restaurants.view', 'reservations.view'],
            Role::OrganizationOwner => [
                'restaurants.view',
                'restaurants.manage',
                'reservations.view',
                'reservations.manage',
                'waitlist.manage',
                'tables.manage',
                'staff.manage',
                'audit_logs.view',
            ],
            Role::PrincipalAdmin => [
                'restaurants.view',
                'restaurants.manage',
                'reservations.view',
                'reservations.manage',
                'waitlist.manage',
                'tables.manage',
                'staff.manage',
                'audit_logs.view',
            ],
            Role::Operations => [
                'restaurants.view',
                'reservations.view',
                'reservations.manage',
                'waitlist.manage',
                'tables.manage',
            ],
            Role::AnalyticsReporting => [
                'restaurants.view',
                'reservations.view',
                'audit_logs.view',
            ],
            Role::MarketingGrowth => [
                'restaurants.view',
                'restaurants.manage',
            ],
            Role::GuestRelations => [
                'restaurants.view',
                'reservations.view',
            ],
            Role::RestaurantManager => [
                'restaurants.view',
                'restaurants.manage',
                'reservations.view',
                'reservations.manage',
                'waitlist.manage',
                'tables.manage',
                'audit_logs.view',
            ],
            Role::RestaurantStaff => [
                'restaurants.view',
                'reservations.view',
                'reservations.manage',
                'waitlist.manage',
                'tables.manage',
            ],
            Role::BusinessAdmin => [
                'restaurants.view',
                'restaurants.manage',
                'organizations.manage',
                'roles.manage',
                'audit_logs.view',
                'docs.view',
            ],
            Role::DevAdmin => [
                'organizations.manage',
                'roles.manage',
                'audit_logs.view',
                'docs.view',
            ],
            Role::SuperAdmin => $permissions,
        ];

        foreach ($rolePermissions as $roleName => $rolePermissionNames) {
            $role = Role::query()->firstOrCreate(
                ['name' => $roleName],
                ['description' => str_replace('_', ' ', $roleName)],
            );

            $permissionIds = Permission::query()
                ->whereIn('name', $rolePermissionNames)
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);
        }
    }
}
