<?php

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('sends password reset without the password.reset web route', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'reset-test@example.com']);

    $status = Password::sendResetLink(['email' => $user->email]);

    expect($status)->toBe(Password::RESET_LINK_SENT);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $built = $notification->toMail($user);

        return str_contains((string) $built->actionUrl, 'token=')
            && str_contains((string) $built->actionUrl, 'email=');
    });
});

it('uses the main frontend url for customer password resets', function (): void {
    config([
        'app.frontend_urls.main' => 'https://app.example.com',
        'app.frontend_paths.password_reset.main' => '/password/reset',
    ]);

    Notification::fake();

    $user = User::factory()->create(['email' => 'custom-url@example.com']);

    Password::sendResetLink(['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $built = $notification->toMail($user);

        return str_starts_with((string) $built->actionUrl, 'https://app.example.com/password/reset?');
    });
});

it('uses the admin frontend url for admin accounts', function (): void {
    config(['app.frontend_urls.admin' => 'https://admin.moretables.com']);

    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create(['email' => 'admin-reset@example.com']);
    assignScopedRole($admin, Role::BusinessAdmin);

    Password::sendResetLink(['email' => $admin->email]);

    Notification::assertSentTo($admin, ResetPassword::class, function (ResetPassword $notification) use ($admin): bool {
        $built = $notification->toMail($admin);

        return str_starts_with((string) $built->actionUrl, 'https://admin.moretables.com/change-password/')
            && str_contains((string) $built->actionUrl, 'email=admin-reset%40example.com')
            && ! str_contains((string) $built->actionUrl, 'token=');
    });
});

it('uses the restaurant frontend url for organization owners', function (): void {
    config(['app.frontend_urls.restaurant' => 'https://restaurant.moretables.com']);

    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $organization = Organization::factory()->create();
    $owner = User::factory()->create(['email' => 'owner-reset@example.com']);
    assignScopedRole($owner, Role::OrganizationOwner, $organization);

    Password::sendResetLink(['email' => $owner->email]);

    Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $notification) use ($owner): bool {
        $built = $notification->toMail($owner);

        return str_starts_with((string) $built->actionUrl, 'https://restaurant.moretables.com/auth/change-password/')
            && str_contains((string) $built->actionUrl, 'email=owner-reset%40example.com')
            && ! str_contains((string) $built->actionUrl, 'token=');
    });
});

it('returns a native deep link for staff when the request comes from the app', function (): void {
    config([
        'app.frontend_urls.restaurant' => 'https://restaurant.moretables.com',
        'app.mobile_schemes.foh' => 'moretables-foh',
    ]);

    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $organization = Organization::factory()->create();
    $owner = User::factory()->create(['email' => 'app-owner@example.com']);
    assignScopedRole($owner, Role::OrganizationOwner, $organization);

    app()->instance('password_reset.client', 'app');

    Password::sendResetLink(['email' => $owner->email]);

    Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $notification) use ($owner): bool {
        $url = (string) $notification->toMail($owner)->actionUrl;

        return str_starts_with($url, 'moretables-foh://reset-password?')
            && str_contains($url, 'token=')
            && str_contains($url, 'email=app-owner%40example.com');
    });
});

it('keeps the web url for customers even when the request comes from the app', function (): void {
    config(['app.frontend_urls.main' => 'https://www.moretables.com']);

    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $customer = User::factory()->create(['email' => 'app-customer@example.com']);

    app()->instance('password_reset.client', 'app');

    Password::sendResetLink(['email' => $customer->email]);

    Notification::assertSentTo($customer, ResetPassword::class, function (ResetPassword $notification) use ($customer): bool {
        return str_starts_with((string) $notification->toMail($customer)->actionUrl, 'https://www.moretables.com/reset-password?');
    });
});

it('does not route customers to the restaurant or admin frontends', function (): void {
    config([
        'app.frontend_urls.main' => 'https://www.moretables.com',
        'app.frontend_urls.restaurant' => 'https://restaurant.moretables.com',
        'app.frontend_urls.admin' => 'https://admin.moretables.com',
    ]);

    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $customer = User::factory()->create(['email' => 'customer-reset@example.com']);

    Password::sendResetLink(['email' => $customer->email]);

    Notification::assertSentTo($customer, ResetPassword::class, function (ResetPassword $notification) use ($customer): bool {
        $built = $notification->toMail($customer);
        $url = (string) $built->actionUrl;

        return str_starts_with($url, 'https://www.moretables.com/reset-password?')
            && str_contains($url, 'token=')
            && str_contains($url, 'email=customer-reset%40example.com');
    });
});

it('honors a custom password reset path override per frontend', function (): void {
    config([
        'app.frontend_urls.admin' => 'https://admin.moretables.com',
        'app.frontend_paths.password_reset.admin' => '/auth/reset/{token}',
    ]);

    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create(['email' => 'admin-custom-path@example.com']);
    assignScopedRole($admin, Role::BusinessAdmin);

    Password::sendResetLink(['email' => $admin->email]);

    Notification::assertSentTo($admin, ResetPassword::class, function (ResetPassword $notification) use ($admin): bool {
        $built = $notification->toMail($admin);
        $url = (string) $built->actionUrl;

        return str_starts_with($url, 'https://admin.moretables.com/auth/reset/')
            && ! str_contains($url, '{token}')
            && str_contains($url, 'email=admin-custom-path%40example.com');
    });
});
