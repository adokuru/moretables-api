<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->admins() as $admin) {
            $user = User::query()->firstOrCreate(
                ['email' => $admin['email']],
                [
                    'name' => $admin['first_name'].' '.$admin['last_name'],
                    'first_name' => $admin['first_name'],
                    'last_name' => $admin['last_name'],
                    'phone' => $admin['phone'],
                    'password' => $admin['password'],
                    'status' => UserStatus::Active,
                    'auth_method' => UserAuthMethod::Password,
                    'email_verified_at' => now(),
                    'last_active_at' => now(),
                ],
            );

            $roleId = Role::query()->where('name', $admin['role'])->value('id');

            if (! $roleId) {
                continue;
            }

            UserRole::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                    'organization_id' => null,
                    'restaurant_id' => null,
                ],
                [
                    'scope_type' => null,
                    'assigned_by' => $user->id,
                ],
            );
        }
    }

    /**
     * @return list<array{first_name: string, last_name: string, email: string, phone: string, password: string, role: string}>
     */
    protected function admins(): array
    {
        return [
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'email' => 'superadmin@moretables.test',
                'phone' => '+2348010000002',
                'password' => 'Password123!',
                'role' => Role::SuperAdmin,
            ],
            [
                'first_name' => 'Business',
                'last_name' => 'Admin',
                'email' => 'businessadmin@moretables.test',
                'phone' => '+2348010000003',
                'password' => 'Password123!',
                'role' => Role::BusinessAdmin,
            ],
        ];
    }
}
