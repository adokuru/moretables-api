<?php

use App\Events\WaitlistEntryUpdated;
use App\Models\ExpoPushToken;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\ExpoPushChannel;
use App\Notifications\WaitlistAvailabilityNotification;
use App\Notifications\WaitlistOfferExpiredNotification;
use App\WaitlistStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

it('allows a customer to accept a notified waitlist offer and creates a reservation', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'status' => WaitlistStatus::Notified,
        'party_size' => 2,
        'preferred_starts_at' => now()->addDay()->setTime(19, 0),
        'expires_at' => now()->addMinutes(20),
    ]);

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/waitlist-entries/'.$entry->id.'/accept');

    $response->assertOk()
        ->assertJsonPath('reservation.source', 'waitlist');

    $entry->refresh();

    expect($entry->status)->toBe(WaitlistStatus::Accepted)
        ->and($entry->reservation_id)->not->toBeNull();
});

it('notifies the customer when they try to accept after the waitlist offer expired', function () {
    Notification::fake();

    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'status' => WaitlistStatus::Notified,
        'party_size' => 2,
        'preferred_starts_at' => now()->addDay()->setTime(19, 0),
        'expires_at' => now()->subMinute(),
    ]);

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/waitlist-entries/'.$entry->id.'/accept');

    $response->assertUnprocessable();

    Notification::assertSentTo($customer, WaitlistOfferExpiredNotification::class);
});

it('allows a customer to decline a notified waitlist offer', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'status' => WaitlistStatus::Notified,
        'expires_at' => now()->addMinutes(20),
    ]);

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/waitlist-entries/'.$entry->id.'/decline');

    $response->assertOk()
        ->assertJsonPath('waitlist_entry.status', 'declined');
});

it('broadcasts waitlist updates to both restaurant and customer channels', function () {
    $data = createBookableRestaurant();
    $customer = User::factory()->create();
    $entry = WaitlistEntry::factory()->make([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'status' => WaitlistStatus::Notified,
    ]);

    $event = new WaitlistEntryUpdated($entry, 'notified');
    $channels = collect($event->broadcastOn())->map(fn ($channel) => $channel->name)->all();

    expect($channels)->toContain(
        'private-restaurant.'.$data['restaurant']->id,
        'private-App.Models.User.'.$customer->id,
    );
});

it('sends expo push notifications for waitlist availability when the customer has expo tokens', function () {
    Http::fake();
    config()->set('services.expo.push_url', 'https://expo.test/push');

    $data = createBookableRestaurant();
    $customer = User::factory()->create();

    ExpoPushToken::factory()->create([
        'user_id' => $customer->id,
        'expo_token' => 'ExponentPushToken[waitlist-customer-token]',
    ]);

    $entry = WaitlistEntry::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'status' => WaitlistStatus::Notified,
        'expires_at' => now()->addMinutes(15),
    ]);

    $entry->load('restaurant');

    app(ExpoPushChannel::class)->send($customer, new WaitlistAvailabilityNotification($entry));

    Http::assertSent(fn ($request) => $request->url() === 'https://expo.test/push'
        && $request['0']['to'] === 'ExponentPushToken[waitlist-customer-token]'
        && $request['0']['data']['type'] === 'waitlist_availability');
});
