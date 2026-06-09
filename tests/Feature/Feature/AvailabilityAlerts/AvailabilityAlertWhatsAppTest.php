<?php

use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\AvailabilityAlertNotification;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\ExpoPushChannel;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.whatsapp.base_url', 'https://graph.test');
    config()->set('services.whatsapp.api_version', 'v21.0');
    config()->set('services.whatsapp.phone_number_id', '123456');
    config()->set('services.whatsapp.token', 'test-token');
    config()->set('services.whatsapp.availability_alert_template', 'table_availability_alert');
});

it('includes the WhatsApp channel only when the alert and user opt in', function () {
    $data = createBookableRestaurant();

    $alert = WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'whatsapp_updates' => true,
    ])->load('restaurant');

    $optedIn = User::factory()->make(['notify_sms_alerts' => true, 'notify_push_notifications' => true]);
    $optedOut = User::factory()->make(['notify_sms_alerts' => false, 'notify_push_notifications' => true]);

    $notification = new AvailabilityAlertNotification($alert);

    expect($notification->via($optedIn))->toContain(WhatsAppChannel::class)
        ->and($notification->via($optedIn))->toContain(ExpoPushChannel::class)
        ->and($notification->via($optedOut))->not->toContain(WhatsAppChannel::class);
});

it('sends a WhatsApp template message to the customer phone', function () {
    Http::fake();

    $data = createBookableRestaurant();
    $customer = User::factory()->create(['phone' => '+2348012345678', 'notify_sms_alerts' => true]);

    $alert = WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'whatsapp_updates' => true,
    ])->load('restaurant');

    app(WhatsAppChannel::class)->send($customer, new AvailabilityAlertNotification($alert));

    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.test/v21.0/123456/messages'
            && $request['to'] === '2348012345678'
            && $request['type'] === 'template'
            && $request['template']['name'] === 'table_availability_alert';
    });
});

it('does not send WhatsApp when credentials are missing', function () {
    Http::fake();
    config()->set('services.whatsapp.token', '');

    $data = createBookableRestaurant();
    $customer = User::factory()->create(['phone' => '+2348012345678', 'notify_sms_alerts' => true]);

    $alert = WaitlistEntry::factory()->availabilityAlert()->create([
        'restaurant_id' => $data['restaurant']->id,
        'user_id' => $customer->id,
        'whatsapp_updates' => true,
    ])->load('restaurant');

    app(WhatsAppChannel::class)->send($customer, new AvailabilityAlertNotification($alert));

    Http::assertNothingSent();
});
