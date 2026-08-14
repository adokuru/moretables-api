<?php

use App\BillingPlanSlug;
use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ExpoPushChannel;
use App\Notifications\RestaurantBroadcastNotification;
use App\ReservationStatus;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);
});

function createBroadcastMerchant(): array
{
    $data = createBookableRestaurant();
    activateMerchantBilling($data['restaurant']);
    // Automated Email Campaigns (this whole broadcast endpoint) is Premium-only
    // (docs/PLAN_PERMISSIONS.md) — this file's existing tests predate that gate and
    // expect broadcasting to just work, so default to Premium. The plan-gating tests
    // further down explicitly downgrade instead.
    setRestaurantBillingPlan($data['restaurant'], BillingPlanSlug::Premium);

    $merchant = User::factory()->create();
    assignScopedRole($merchant, Role::Operations, $data['organization'], $data['restaurant']);

    return [...$data, 'merchant' => $merchant];
}

it('broadcasts to all users with active or completed reservations', function () {
    Notification::fake();

    $data = createBroadcastMerchant();

    $bookedGuest = User::factory()->create();
    $completedGuest = User::factory()->create();
    $cancelledGuest = User::factory()->create();
    $otherRestaurantGuest = User::factory()->create();

    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'user_id' => $bookedGuest->id,
    ]);
    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'user_id' => $completedGuest->id,
        'status' => ReservationStatus::Completed,
    ]);
    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'user_id' => $cancelledGuest->id,
        'status' => ReservationStatus::Cancelled,
    ]);

    $otherRestaurant = createBookableRestaurant();
    Reservation::factory()->create([
        'restaurant_id' => $otherRestaurant['restaurant']->id,
        'restaurant_table_id' => $otherRestaurant['table']->id,
        'user_id' => $otherRestaurantGuest->id,
    ]);

    Sanctum::actingAs($data['merchant']);

    $response = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/broadcasts', [
        'title' => 'Weekend special',
        'message' => 'Join us this weekend for a special tasting menu.',
        'audience' => 'all',
    ]);

    $response->assertAccepted()
        ->assertJsonPath('recipients_count', 2);

    $bookedGuest->refresh();

    Notification::assertSentTo($bookedGuest, RestaurantBroadcastNotification::class, function ($notification, $channels) use ($bookedGuest) {
        return in_array('database', $notification->via($bookedGuest), true)
            && in_array(ExpoPushChannel::class, $notification->via($bookedGuest), true);
    });
    Notification::assertSentTo($completedGuest, RestaurantBroadcastNotification::class);
    Notification::assertNotSentTo($cancelledGuest, RestaurantBroadcastNotification::class);
    Notification::assertNotSentTo($otherRestaurantGuest, RestaurantBroadcastNotification::class);
});

it('broadcasts only to users linked to the selected guest contacts', function () {
    Notification::fake();

    $data = createBroadcastMerchant();

    $selectedUser = User::factory()->create();
    $unselectedUser = User::factory()->create();

    $selectedContact = GuestContact::factory()->create(['restaurant_id' => $data['restaurant']->id]);
    $unselectedContact = GuestContact::factory()->create(['restaurant_id' => $data['restaurant']->id]);

    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'user_id' => $selectedUser->id,
        'guest_contact_id' => $selectedContact->id,
    ]);
    Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'user_id' => $unselectedUser->id,
        'guest_contact_id' => $unselectedContact->id,
    ]);

    Sanctum::actingAs($data['merchant']);

    $response = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/broadcasts', [
        'title' => 'VIP invite',
        'message' => 'You are invited to our chef\'s table evening.',
        'audience' => 'selected',
        'guest_contact_ids' => [$selectedContact->id],
    ]);

    $response->assertAccepted()
        ->assertJsonPath('recipients_count', 1);

    Notification::assertSentTo($selectedUser, RestaurantBroadcastNotification::class);
    Notification::assertNotSentTo($unselectedUser, RestaurantBroadcastNotification::class);
});

it('rejects guest contacts that belong to another restaurant', function () {
    Notification::fake();

    $data = createBroadcastMerchant();
    $otherRestaurant = createBookableRestaurant();

    $foreignContact = GuestContact::factory()->create(['restaurant_id' => $otherRestaurant['restaurant']->id]);

    Sanctum::actingAs($data['merchant']);

    $response = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/broadcasts', [
        'title' => 'Hello',
        'message' => 'Test message.',
        'audience' => 'selected',
        'guest_contact_ids' => [$foreignContact->id],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['guest_contact_ids.0']);

    Notification::assertNothingSent();
});

it('requires guest contact ids when the audience is selected', function () {
    $data = createBroadcastMerchant();

    Sanctum::actingAs($data['merchant']);

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/broadcasts', [
        'title' => 'Hello',
        'message' => 'Test message.',
        'audience' => 'selected',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['guest_contact_ids']);
});

it('allows a staff member with only messaging.manage to broadcast to all guests', function () {
    Notification::fake();
    $data = createBroadcastMerchant();
    $staff = User::factory()->create();
    grantAccessConfigPermissions($staff, $data['restaurant'], ['messaging.manage']);
    Sanctum::actingAs($staff);

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/broadcasts', [
        'title' => 'Hello',
        'message' => 'Test message.',
        'audience' => 'all',
    ])->assertAccepted();
});

it('forbids a staff member with only messaging.manage from broadcasting to selected guests', function () {
    $data = createBroadcastMerchant();
    $selectedContact = GuestContact::factory()->create(['restaurant_id' => $data['restaurant']->id]);
    $staff = User::factory()->create();
    grantAccessConfigPermissions($staff, $data['restaurant'], ['messaging.manage']);
    Sanctum::actingAs($staff);

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/broadcasts', [
        'title' => 'Hello',
        'message' => 'Test message.',
        'audience' => 'selected',
        'guest_contact_ids' => [$selectedContact->id],
    ])->assertForbidden();
});

it('allows a staff member with only communications.manage to broadcast to selected guests', function () {
    Notification::fake();
    $data = createBroadcastMerchant();
    $selectedContact = GuestContact::factory()->create(['restaurant_id' => $data['restaurant']->id]);
    $staff = User::factory()->create();
    grantAccessConfigPermissions($staff, $data['restaurant'], ['communications.manage']);
    Sanctum::actingAs($staff);

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/broadcasts', [
        'title' => 'Hello',
        'message' => 'Test message.',
        'audience' => 'selected',
        'guest_contact_ids' => [$selectedContact->id],
    ])->assertAccepted();
});

it('forbids a staff member with only communications.manage from broadcasting to all guests', function () {
    $data = createBroadcastMerchant();
    $staff = User::factory()->create();
    grantAccessConfigPermissions($staff, $data['restaurant'], ['communications.manage']);
    Sanctum::actingAs($staff);

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/broadcasts', [
        'title' => 'Hello',
        'message' => 'Test message.',
        'audience' => 'all',
    ])->assertForbidden();
});

it('forbids users without reservation management permission', function () {
    $data = createBroadcastMerchant();

    $outsider = User::factory()->create();
    Sanctum::actingAs($outsider);

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/broadcasts', [
        'title' => 'Hello',
        'message' => 'Test message.',
        'audience' => 'all',
    ])->assertForbidden();
});

// Plan-tier gating — Automated Email Campaigns (both the Guest Email and Broadcast
// Messaging tabs, i.e. this whole endpoint regardless of audience) is Premium-only
// (docs/PLAN_PERMISSIONS.md). createBroadcastMerchant() defaults to Premium; these
// tests explicitly downgrade instead.

it('rejects broadcasting to all guests for a restaurant below Premium', function () {
    Notification::fake();
    $data = createBroadcastMerchant();
    setRestaurantBillingPlan($data['restaurant'], BillingPlanSlug::Core);
    Sanctum::actingAs($data['merchant']);

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/broadcasts', [
        'title' => 'Hello',
        'message' => 'Test message.',
        'audience' => 'all',
    ])->assertForbidden()
        ->assertJsonPath('message', 'Upgrade to Premium to send automated email campaigns to guests.');

    Notification::assertNothingSent();
});

it('rejects broadcasting to selected guests for a restaurant below Premium', function () {
    Notification::fake();
    $data = createBroadcastMerchant();
    $selectedContact = GuestContact::factory()->create(['restaurant_id' => $data['restaurant']->id]);
    setRestaurantBillingPlan($data['restaurant'], BillingPlanSlug::Foundation);
    Sanctum::actingAs($data['merchant']);

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/broadcasts', [
        'title' => 'Hello',
        'message' => 'Test message.',
        'audience' => 'selected',
        'guest_contact_ids' => [$selectedContact->id],
    ])->assertForbidden()
        ->assertJsonPath('message', 'Upgrade to Premium to send automated email campaigns to guests.');

    Notification::assertNothingSent();
});
