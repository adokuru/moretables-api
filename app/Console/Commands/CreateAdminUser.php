<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\UserAuthMethod;
use App\UserStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('app:create-admin-user
    {email : The admin email address}
    {--role=super_admin : The admin role to assign}
    {--first-name=System : The admin first name}
    {--last-name=Admin : The admin last name}
    {--phone= : The admin phone number}
    {--password= : The admin password. If omitted, one will be generated}')]
#[Description('Create or update an admin user and assign an admin role')]
class CreateAdminUser extends Command
{
    public function handle(): int
    {
        $roleName = (string) $this->option('role');

        if (! in_array($roleName, Role::adminRoles(), true)) {
            $this->error('The role must be one of: '.implode(', ', Role::adminRoles()).'.');

            return self::FAILURE;
        }

        $this->call('db:seed', [
            '--class' => RoleAndPermissionSeeder::class,
            '--no-interaction' => true,
        ]);

        $firstName = (string) $this->option('first-name');
        $lastName = (string) $this->option('last-name');
        $password = (string) ($this->option('password') ?: Str::password(16));

        $user = User::query()->updateOrCreate(
            ['email' => (string) $this->argument('email')],
            [
                'name' => trim($firstName.' '.$lastName),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $this->option('phone') ?: null,
                'password' => $password,
                'status' => UserStatus::Active,
                'auth_method' => UserAuthMethod::Password,
                'email_verified_at' => now(),
                'last_active_at' => now(),
            ],
        );

        $roleId = Role::query()
            ->where('name', $roleName)
            ->value('id');

        UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'scope_type' => null,
            'organization_id' => null,
            'restaurant_id' => null,
        ], [
            'assigned_by' => $user->id,
        ]);

        $this->info('Admin user ready.');
        $this->line('Email: '.$user->email);
        $this->line('Role: '.$roleName);
        $this->line('Password: '.$password);

        return self::SUCCESS;
    }
}
