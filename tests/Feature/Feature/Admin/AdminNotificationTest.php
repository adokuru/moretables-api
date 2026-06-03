<?php

use App\Models\OnboardingRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OnboardingRequestSubmittedNotification;
use App\UserAuthMethod;
use App\UserStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('returns admin notifications for the authenticated admin', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create([
        'auth_method' => UserAuthMethod::Password,
        'status' => UserStatus::Active,
    ]);
    assignScopedRole($admin, Role::BusinessAdmin);

    $onboardingRequest = OnboardingRequest::factory()->create();
    $admin->notify(new OnboardingRequestSubmittedNotification($onboardingRequest));

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/notifications');

    $response->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.type', 'OnboardingRequestSubmittedNotification')
        ->assertJsonPath('notifications.0.is_read', false)
        ->assertJsonStructure([
            'notifications' => [
                '*' => ['id', 'type', 'data', 'read_at', 'is_read', 'created_at'],
            ],
            'unread_count',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
});

it('marks a single admin notification as read', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    $onboardingRequest = OnboardingRequest::factory()->create();
    $admin->notify(new OnboardingRequestSubmittedNotification($onboardingRequest));

    $notification = $admin->notifications()->first();

    Sanctum::actingAs($admin);

    $response = $this->patchJson('/api/v1/admin/notifications/'.$notification->id.'/read');

    $response->assertOk()
        ->assertJsonPath('message', 'Notification marked as read.')
        ->assertJsonPath('notification.is_read', true);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('marks all admin notifications as read', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::DevAdmin);

    $onboardingRequest = OnboardingRequest::factory()->create();
    $admin->notify(new OnboardingRequestSubmittedNotification($onboardingRequest));
    $admin->notify(new OnboardingRequestSubmittedNotification($onboardingRequest));

    Sanctum::actingAs($admin);

    $response = $this->patchJson('/api/v1/admin/notifications/read-all');

    $response->assertOk()
        ->assertJsonPath('message', 'All notifications marked as read.');

    expect($admin->unreadNotifications()->count())->toBe(0);
});

it('forbids non admins from admin notification routes', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $customer = User::factory()->create();
    assignScopedRole($customer, Role::Customer);

    Sanctum::actingAs($customer);

    $this->getJson('/api/v1/admin/notifications')->assertForbidden();
    $this->patchJson('/api/v1/admin/notifications/read-all')->assertForbidden();
});

it('prevents marking another admin notification as read', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $otherAdmin = User::factory()->create();
    assignScopedRole($otherAdmin, Role::SuperAdmin);

    $onboardingRequest = OnboardingRequest::factory()->create();
    $otherAdmin->notify(new OnboardingRequestSubmittedNotification($onboardingRequest));

    $notification = $otherAdmin->notifications()->first();

    Sanctum::actingAs($admin);

    $this->patchJson('/api/v1/admin/notifications/'.$notification->id.'/read')->assertForbidden();
});
