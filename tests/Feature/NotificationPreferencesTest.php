<?php

use App\Models\Reservation;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\ExpoPushChannel;
use App\Notifications\OwnerReservationLifecycleNotification;
use App\Notifications\ReservationLifecycleNotification;
use App\Notifications\WaitlistAvailabilityNotification;
use App\Notifications\WaitlistOfferExpiredNotification;
use App\Notifications\WaitlistTableNoLongerAvailableNotification;
use App\UserAuthMethod;
use App\UserStatus;

it('returns notification preferences in the profile response', function () {
    $user = User::factory()->create([
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
    ]);

    $token = $user->createToken('profile')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/auth/profile');

    $response->assertOk()
        ->assertJsonStructure([
            'user' => [
                'notification_preferences' => [
                    'dining_rating_emails',
                    'marketing_emails',
                    'sms_alerts',
                    'push_notifications',
                ],
            ],
        ])
        ->assertJsonPath('user.notification_preferences.dining_rating_emails', true)
        ->assertJsonPath('user.notification_preferences.marketing_emails', true)
        ->assertJsonPath('user.notification_preferences.sms_alerts', true)
        ->assertJsonPath('user.notification_preferences.push_notifications', true);
});

it('updates notification preferences via the profile endpoint', function () {
    $user = User::factory()->create([
        'auth_method' => UserAuthMethod::Passwordless,
        'status' => UserStatus::Active,
    ]);

    $token = $user->createToken('profile')->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/v1/auth/profile', [
        'notify_marketing_emails' => false,
        'notify_push_notifications' => false,
    ]);

    $response->assertOk()
        ->assertJsonPath('user.notification_preferences.marketing_emails', false)
        ->assertJsonPath('user.notification_preferences.push_notifications', false)
        ->assertJsonPath('user.notification_preferences.dining_rating_emails', true)
        ->assertJsonPath('user.notification_preferences.sms_alerts', true);

    $user->refresh();

    expect($user->notify_marketing_emails)->toBeFalse()
        ->and($user->notify_push_notifications)->toBeFalse()
        ->and($user->notify_dining_rating_emails)->toBeTrue()
        ->and($user->notify_sms_alerts)->toBeTrue();
});

it('defaults all notification preferences to enabled on new users', function () {
    $user = User::factory()->create()->fresh();

    expect($user->notify_dining_rating_emails)->toBeTrue()
        ->and($user->notify_marketing_emails)->toBeTrue()
        ->and($user->notify_sms_alerts)->toBeTrue()
        ->and($user->notify_push_notifications)->toBeTrue();
});

it('includes push channel when push notifications are enabled', function () {
    $user = User::factory()->create([
        'notify_push_notifications' => true,
    ]);

    $reservation = Reservation::factory()->create([
        'user_id' => $user->id,
    ]);

    $notification = new ReservationLifecycleNotification($reservation, 'confirmed');
    $channels = $notification->via($user);

    expect($channels)->toContain('mail')
        ->and($channels)->toContain(ExpoPushChannel::class);
});

it('excludes push channel when push notifications are disabled', function () {
    $user = User::factory()->create([
        'notify_push_notifications' => false,
    ]);

    $reservation = Reservation::factory()->create([
        'user_id' => $user->id,
    ]);

    $notification = new ReservationLifecycleNotification($reservation, 'confirmed');
    $channels = $notification->via($user);

    expect($channels)->toContain('mail')
        ->and($channels)->not->toContain(ExpoPushChannel::class);
});

it('stores and pushes owner reservation lifecycle notifications when enabled', function () {
    $owner = User::factory()->create([
        'notify_push_notifications' => true,
    ]);
    $reservation = Reservation::factory()->create([
        'party_size' => 4,
    ])->load(['restaurant', 'table', 'user', 'guestContact']);

    $notification = new OwnerReservationLifecycleNotification($reservation, 'cancelled');

    expect($notification->via($owner))->toContain('mail', 'database', ExpoPushChannel::class)
        ->and($notification->toArray($owner))->toMatchArray([
            'type' => 'reservation_lifecycle',
            'title' => 'Reservation cancelled',
            'action' => 'cancelled',
            'reservation_id' => $reservation->id,
            'restaurant_id' => $reservation->restaurant_id,
            'party_size' => 4,
        ])
        ->and($notification->toExpoPush($owner)->data)->toMatchArray([
            'reservation_id' => $reservation->id,
            'restaurant_id' => $reservation->restaurant_id,
            'action' => 'cancelled',
        ]);
});

it('keeps owner reservation push disabled when the owner opts out', function () {
    $owner = User::factory()->create([
        'notify_push_notifications' => false,
    ]);
    $reservation = Reservation::factory()->create();

    $channels = (new OwnerReservationLifecycleNotification($reservation, 'created'))->via($owner);

    expect($channels)->toContain('mail', 'database')
        ->and($channels)->not->toContain(ExpoPushChannel::class);
});

it('excludes push channel on waitlist availability notification when disabled', function () {
    $user = User::factory()->create([
        'notify_push_notifications' => false,
    ]);

    $entry = WaitlistEntry::factory()->create(['user_id' => $user->id]);

    $notification = new WaitlistAvailabilityNotification($entry);
    $channels = $notification->via($user);

    expect($channels)->toContain('mail')
        ->and($channels)->not->toContain(ExpoPushChannel::class);
});

it('excludes push channel on waitlist offer expired notification when disabled', function () {
    $user = User::factory()->create([
        'notify_push_notifications' => false,
    ]);

    $entry = WaitlistEntry::factory()->create(['user_id' => $user->id]);

    $notification = new WaitlistOfferExpiredNotification($entry);
    $channels = $notification->via($user);

    expect($channels)->toContain('mail')
        ->and($channels)->not->toContain(ExpoPushChannel::class);
});

it('excludes push channel on waitlist table no longer available notification when disabled', function () {
    $user = User::factory()->create([
        'notify_push_notifications' => false,
    ]);

    $entry = WaitlistEntry::factory()->create(['user_id' => $user->id]);

    $notification = new WaitlistTableNoLongerAvailableNotification($entry);
    $channels = $notification->via($user);

    expect($channels)->toContain('mail')
        ->and($channels)->not->toContain(ExpoPushChannel::class);
});
