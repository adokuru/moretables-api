<?php

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
