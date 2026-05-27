<?php

use App\Models\Reservation;
use App\Models\User;
use App\Notifications\ReservationLifecycleNotification;
use App\UserAuthMethod;
use App\UserStatus;

it('returns an empty notifications list for a new user', function () {
    $user = User::factory()->create([
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
    ]);

    $token = $user->createToken('notifications')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/auth/notifications');

    $response->assertOk()
        ->assertJsonPath('notifications', [])
        ->assertJsonPath('unread_count', 0)
        ->assertJsonStructure(['notifications', 'unread_count', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
});

it('returns notifications for the authenticated user', function () {
    $user = User::factory()->create([
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
    ]);

    $reservation = Reservation::factory()->create(['user_id' => $user->id]);
    $user->notify(new ReservationLifecycleNotification($reservation, 'confirmed'));

    $token = $user->createToken('notifications')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/auth/notifications');

    $response->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.type', 'ReservationLifecycleNotification')
        ->assertJsonPath('notifications.0.is_read', false)
        ->assertJsonStructure([
            'notifications' => [
                '*' => ['id', 'type', 'data', 'read_at', 'is_read', 'created_at'],
            ],
        ]);
});

it('marks a single notification as read', function () {
    $user = User::factory()->create([
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
    ]);

    $reservation = Reservation::factory()->create(['user_id' => $user->id]);
    $user->notify(new ReservationLifecycleNotification($reservation, 'confirmed'));

    $notification = $user->notifications()->first();
    $token = $user->createToken('notifications')->plainTextToken;

    $response = $this->withToken($token)->patchJson("/api/v1/auth/notifications/{$notification->id}/read");

    $response->assertOk()
        ->assertJsonPath('message', 'Notification marked as read.')
        ->assertJsonPath('notification.is_read', true);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('marks all notifications as read', function () {
    $user = User::factory()->create([
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
    ]);

    $reservation = Reservation::factory()->create(['user_id' => $user->id]);
    $user->notify(new ReservationLifecycleNotification($reservation, 'confirmed'));
    $user->notify(new ReservationLifecycleNotification($reservation, 'cancelled'));

    $token = $user->createToken('notifications')->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/v1/auth/notifications/read-all');

    $response->assertOk()
        ->assertJsonPath('message', 'All notifications marked as read.');

    expect($user->unreadNotifications()->count())->toBe(0);
});

it('prevents marking another user\'s notification as read', function () {
    $user = User::factory()->create([
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
    ]);

    $otherUser = User::factory()->create();
    $reservation = Reservation::factory()->create(['user_id' => $otherUser->id]);
    $otherUser->notify(new ReservationLifecycleNotification($reservation, 'confirmed'));

    $notification = $otherUser->notifications()->first();
    $token = $user->createToken('notifications')->plainTextToken;

    $response = $this->withToken($token)->patchJson("/api/v1/auth/notifications/{$notification->id}/read");

    $response->assertForbidden();
});

it('returns 401 when fetching notifications without a token', function () {
    $response = $this->getJson('/api/v1/auth/notifications');

    $response->assertUnauthorized();
});
