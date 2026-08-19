<?php

use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OwnerReservationLifecycleNotification;
use App\Notifications\ReservationLifecycleNotification;
use App\ReservationStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('services.whatsapp.base_url', 'https://graph.test');
    config()->set('services.whatsapp.api_version', 'v21.0');
    config()->set('services.whatsapp.phone_number_id', '123456');
    config()->set('services.whatsapp.token', 'test-token');
    config()->set('services.whatsapp.app_secret', 'test-app-secret');
    config()->set('services.whatsapp.webhook_verify_token', 'verify-me');
});

function postSignedWebhook(array $payload, ?string $secret = 'test-app-secret')
{
    $body = json_encode($payload);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($secret !== null) {
        $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], $headers, $body);
}

function cancelButtonTapPayload(int $reservationId, string $from): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '1',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'messages' => [[
                        'from' => $from,
                        'id' => 'wamid.test',
                        'type' => 'button',
                        'button' => [
                            'text' => 'Cancel reservation',
                            'payload' => "cancel_reservation:{$reservationId}",
                        ],
                    ]],
                ],
            ]],
        ]],
    ];
}

function cancellableReservation(array $attributes = []): Reservation
{
    $data = createBookableRestaurant();
    $contact = GuestContact::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'phone' => '+2348012345678',
    ]);

    return Reservation::factory()->create(array_merge([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'user_id' => null,
        'guest_contact_id' => $contact->id,
        'status' => ReservationStatus::Booked,
        'party_size' => 2,
        'starts_at' => now()->addDays(3)->setTime(19, 0),
        'ends_at' => now()->addDays(3)->setTime(21, 0),
    ], $attributes));
}

it('completes the Meta verification handshake with the correct verify token', function (): void {
    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')
        ->assertOk()
        ->assertSeeText('12345');
});

it('rejects the verification handshake with a wrong verify token', function (): void {
    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')
        ->assertForbidden();
});

it('rejects webhook payloads with a missing or invalid signature', function (): void {
    $reservation = cancellableReservation();

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348012345678'), null)->assertForbidden();
    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348012345678'), 'wrong-secret')->assertForbidden();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Booked);
});

it('rejects all webhook payloads when no app secret is configured', function (): void {
    config()->set('services.whatsapp.app_secret', '');

    $reservation = cancellableReservation();

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348012345678'), '')->assertForbidden();
});

it('cancels the reservation when the primary booker taps the cancel button', function (): void {
    Notification::fake();
    Http::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $reservation = cancellableReservation();
    $owner = User::factory()->create();
    assignScopedRole($owner, Role::OrganizationOwner, $reservation->restaurant->organization);

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348012345678'))->assertOk();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($reservation->canceled_at)->not->toBeNull()
        ->and($reservation->canceled_by_user_id)->toBeNull();

    Notification::assertSentTo(
        $reservation->guestContact,
        ReservationLifecycleNotification::class,
        fn ($notification): bool => $notification->toArray((object) [])['action'] === 'cancelled',
    );

    Notification::assertSentTo(
        $owner,
        OwnerReservationLifecycleNotification::class,
        fn (OwnerReservationLifecycleNotification $notification): bool => $notification->toMail($owner)->subject === 'Reservation cancelled - '.$reservation->restaurant->name,
    );
});

it('matches local Nigerian booker phone numbers to Meta sender numbers', function (): void {
    Notification::fake();
    Http::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $reservation = cancellableReservation();
    $reservation->guestContact->forceFill(['phone' => '0801 234 5678'])->save();

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348012345678'))->assertOk();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
    Notification::assertSentTo($reservation->guestContact, ReservationLifecycleNotification::class);
});

it('refuses cancel taps from a phone that is not the primary booker and tells the sender', function (): void {
    Notification::fake();
    Http::fake();

    $reservation = cancellableReservation();

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2347000000000'))->assertOk();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Booked);
    Notification::assertNothingSent();

    Http::assertSent(function ($request): bool {
        return $request['type'] === 'text'
            && $request['to'] === '2347000000000'
            && str_contains($request['text']['body'], 'could not verify that cancellation request');
    });
});

it('accepts the guest contact phone when the reservation also has a user with a different phone', function (): void {
    Notification::fake();
    Http::fake();

    $reservation = cancellableReservation([
        'user_id' => User::factory()->create(['phone' => '+2348099998888'])->id,
    ]);

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348012345678'))->assertOk();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('accepts the user phone when the reservation also has a guest contact with a different phone', function (): void {
    Notification::fake();
    Http::fake();

    $reservation = cancellableReservation([
        'user_id' => User::factory()->create(['phone' => '+2348099998888'])->id,
    ]);

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348099998888'))->assertOk();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('falls back to the guest contact when the reservation user has no phone', function (): void {
    Notification::fake();
    Http::fake();

    $reservation = cancellableReservation([
        'user_id' => User::factory()->create(['phone' => null])->id,
    ]);

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348012345678'))->assertOk();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('confirms in chat after a successful cancellation', function (): void {
    Notification::fake();
    Http::fake();

    $reservation = cancellableReservation();

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348012345678'))->assertOk();

    Http::assertSent(function ($request) use ($reservation): bool {
        return $request['type'] === 'text'
            && $request['to'] === '2348012345678'
            && str_contains($request['text']['body'], 'has been cancelled')
            && str_contains($request['text']['body'], $reservation->reservation_reference);
    });
});

it('logs and replies generically when the reservation in the payload does not exist', function (): void {
    Notification::fake();
    Http::fake();
    Log::spy();

    postSignedWebhook(cancelButtonTapPayload(987654, '2348012345678'))->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []): bool => $message === 'WhatsApp cancel request rejected: reservation not found.'
            && $context['reservation_id'] === 987654
            && $context['from'] === '234******5678',
    )->once();

    Http::assertSent(function ($request): bool {
        return $request['type'] === 'text'
            && str_contains($request['text']['body'], 'could not verify that cancellation request');
    });
});

it('logs the masked numbers it compared when the sender is not the booker', function (): void {
    Notification::fake();
    Http::fake();
    Log::spy();

    $reservation = cancellableReservation();

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2347000000000'))->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []): bool => $message === 'WhatsApp cancel request rejected: sender is not the primary booker.'
            && $context['from'] === '234******0000'
            && $context['booker_candidates'] === ['234******5678'],
    )->once();
});

it('logs a button reply whose payload it cannot route', function (): void {
    Notification::fake();
    Http::fake();
    Log::spy();

    $payload = cancelButtonTapPayload(1, '2348012345678');
    $payload['entry'][0]['changes'][0]['value']['messages'][0]['button']['payload'] = 'Cancel reservation';

    postSignedWebhook($payload)->assertOk();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []): bool => $message === 'WhatsApp webhook ignored a button reply it could not route.'
            && $context['payload'] === 'Cancel reservation'
            && $context['has_sender'] === true,
    )->once();

    Http::assertNothingSent();
});

it('logs why it rejected a webhook when signature verification fails', function (): void {
    Log::spy();

    config()->set('services.whatsapp.app_secret', '');

    postSignedWebhook(cancelButtonTapPayload(1, '2348012345678'), '')->assertForbidden();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context = []): bool => $message === 'WhatsApp webhook rejected: signature verification failed.'
            && $context['has_app_secret'] === false
            && $context['has_signature_header'] === true,
    )->once();
});

it('replies with a text message instead of cancelling inside the cancellation cutoff', function (): void {
    Notification::fake();
    Http::fake();

    $reservation = cancellableReservation([
        'starts_at' => now()->addMinutes(30),
        'ends_at' => now()->addHours(2)->addMinutes(30),
    ]);

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348012345678'))->assertOk();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Booked);
    Notification::assertNothingSent();

    Http::assertSent(function ($request): bool {
        return $request['type'] === 'text'
            && $request['to'] === '2348012345678'
            && str_contains($request['text']['body'], 'can no longer be cancelled');
    });
});

it('tells the booker when the reservation is already cancelled', function (): void {
    Notification::fake();
    Http::fake();

    $reservation = cancellableReservation(['status' => ReservationStatus::Cancelled]);

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348012345678'))->assertOk();

    Notification::assertNothingSent();

    Http::assertSent(function ($request): bool {
        return $request['type'] === 'text'
            && str_contains($request['text']['body'], 'already been cancelled');
    });
});

it('authorizes registered users by their phone number', function (): void {
    Notification::fake();
    Http::fake();

    $data = createBookableRestaurant();
    $customer = User::factory()->create(['phone' => '+2348099998888']);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'user_id' => $customer->id,
        'status' => ReservationStatus::Confirmed,
        'party_size' => 2,
        'starts_at' => now()->addDays(3)->setTime(19, 0),
        'ends_at' => now()->addDays(3)->setTime(21, 0),
    ]);

    postSignedWebhook(cancelButtonTapPayload($reservation->id, '2348099998888'))->assertOk();

    expect($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
    Notification::assertSentTo($customer, ReservationLifecycleNotification::class);
});

it('ignores non-button webhook events', function (): void {
    Notification::fake();
    Http::fake();

    postSignedWebhook([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'changes' => [[
                'value' => [
                    'messages' => [[
                        'from' => '2348012345678',
                        'type' => 'text',
                        'text' => ['body' => 'cancel_reservation:1'],
                    ]],
                    'statuses' => [['status' => 'delivered']],
                ],
            ]],
        ]],
    ])->assertOk();

    Notification::assertNothingSent();
    Http::assertNothingSent();
});
