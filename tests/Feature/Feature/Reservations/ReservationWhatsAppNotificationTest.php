<?php

use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\ReservationLifecycleNotification;
use App\Services\ReservationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('services.whatsapp.base_url', 'https://graph.test');
    config()->set('services.whatsapp.api_version', 'v21.0');
    config()->set('services.whatsapp.phone_number_id', '123456');
    config()->set('services.whatsapp.token', 'test-token');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function reservationForWhatsAppTests(array $attributes = []): Reservation
{
    $data = createBookableRestaurant();

    return Reservation::factory()->create(array_merge([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_table_id' => $data['table']->id,
        'party_size' => 4,
        'starts_at' => Carbon::parse('2026-06-10 18:00:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-06-10 20:00:00', 'UTC'),
    ], $attributes))->load('restaurant');
}

it('includes the WhatsApp channel for users only when they opt in and have a phone', function (): void {
    $reservation = reservationForWhatsAppTests();
    $notification = new ReservationLifecycleNotification($reservation, 'created');

    $optedIn = User::factory()->make(['phone' => '+2348012345678', 'notify_sms_alerts' => true]);
    $optedOut = User::factory()->make(['phone' => '+2348012345678', 'notify_sms_alerts' => false]);
    $noPhone = User::factory()->make(['phone' => null, 'notify_sms_alerts' => true]);

    expect($notification->via($optedIn))->toContain(WhatsAppChannel::class)
        ->and($notification->via($optedIn))->toContain('mail')
        ->and($notification->via($optedIn))->toContain('database')
        ->and($notification->via($optedOut))->not->toContain(WhatsAppChannel::class)
        ->and($notification->via($noPhone))->not->toContain(WhatsAppChannel::class);
});

it('includes the WhatsApp channel for guest contacts and reservation guests with a phone', function (): void {
    $reservation = reservationForWhatsAppTests();
    $notification = new ReservationLifecycleNotification($reservation, 'created');

    $contactWithPhone = GuestContact::factory()->make(['phone' => '+2348012345678', 'email' => 'guest@example.com']);
    $contactWithoutPhone = GuestContact::factory()->make(['phone' => null, 'email' => 'guest@example.com']);
    $contactInvalidEmail = GuestContact::factory()->make(['phone' => '+2348012345678', 'email' => 'not-an-email']);

    expect($notification->via($contactWithPhone))->toBe(['mail', WhatsAppChannel::class])
        ->and($notification->via($contactWithoutPhone))->toBe(['mail'])
        ->and($notification->via($contactInvalidEmail))->toBe([WhatsAppChannel::class]);

    $guest = new ReservationGuest([
        'attendee_name' => 'Added Diner',
        'email_address' => 'added@example.com',
        'phone_number' => '+2348000000001',
    ]);
    $guestWithoutContactDetails = new ReservationGuest(['attendee_name' => 'No Contact']);

    expect($notification->via($guest))->toBe(['mail', WhatsAppChannel::class])
        ->and($notification->via($guestWithoutContactDetails))->toBe([]);
});

it('builds the WhatsApp template message with the standard parameters', function (string $action, string $template): void {
    $reservation = reservationForWhatsAppTests([
        'reservation_reference' => 'MT-12345678',
    ]);

    $contact = GuestContact::factory()->make([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'phone' => '+2348012345678',
    ]);

    $message = (new ReservationLifecycleNotification($reservation, $action))->toWhatsApp($contact);

    expect($message)->not->toBeNull()
        ->and($message->templateName)->toBe($template)
        ->and($message->bodyParameters)->toBe([
            'John Doe',
            $reservation->restaurant->name,
            $reservation->starts_at->copy()->timezone($reservation->restaurant->timezone)->format('l, F j, Y \a\t g:i A'),
            '4',
            'MT-12345678',
        ]);
})->with([
    'created' => ['created', 'reservation_created'],
    'updated' => ['updated', 'reservation_updated'],
    'cancelled' => ['cancelled', 'reservation_cancelled'],
    'guest added' => ['guest_added', 'reservation_guest_added'],
]);

it('appends the time window parameter for upcoming reminders', function (): void {
    $reservation = reservationForWhatsAppTests();
    $contact = GuestContact::factory()->make(['first_name' => 'Jane', 'last_name' => null]);

    $message = (new ReservationLifecycleNotification($reservation, 'upcoming_reminder', 72))->toWhatsApp($contact);

    expect($message->templateName)->toBe('reservation_upcoming_reminder')
        ->and($message->bodyParameters)->toHaveCount(6)
        ->and($message->bodyParameters[0])->toBe('Jane')
        ->and($message->bodyParameters[5])->toBe('72 hours');
});

it('returns no WhatsApp message for unknown lifecycle actions', function (): void {
    $reservation = reservationForWhatsAppTests();
    $contact = GuestContact::factory()->make();

    expect((new ReservationLifecycleNotification($reservation, 'confirmed'))->toWhatsApp($contact))->toBeNull();
});

it('sends the WhatsApp template to the guest contact phone through the channel', function (): void {
    Http::fake();

    $reservation = reservationForWhatsAppTests(['reservation_reference' => 'MT-12345678']);
    $contact = GuestContact::factory()->create([
        'restaurant_id' => $reservation->restaurant_id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'phone' => '+234 (801) 234-5678',
    ]);

    app(WhatsAppChannel::class)->send($contact, new ReservationLifecycleNotification($reservation, 'created'));

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://graph.test/v21.0/123456/messages'
            && $request['to'] === '2348012345678'
            && $request['type'] === 'template'
            && $request['template']['name'] === 'reservation_created'
            && $request['template']['components'][0]['parameters'][4]['text'] === 'MT-12345678';
    });
});

it('sanitizes template parameters that Meta would reject', function (): void {
    Http::fake();

    $reservation = reservationForWhatsAppTests();
    $reservation->restaurant->forceFill(['name' => "The Grill\nHouse    Lagos"])->save();
    $reservation->load('restaurant');

    $contact = GuestContact::factory()->create([
        'restaurant_id' => $reservation->restaurant_id,
        'first_name' => '  ',
        'last_name' => null,
        'phone' => '+2348012345678',
    ]);

    app(WhatsAppChannel::class)->send($contact, new ReservationLifecycleNotification($reservation, 'created'));

    Http::assertSent(function ($request): bool {
        $parameters = $request['template']['components'][0]['parameters'];

        return $parameters[0]['text'] === 'Guest'
            && $parameters[1]['text'] === 'The Grill House Lagos';
    });
});

it('includes a dynamic URL button parameter when the templates support it', function (): void {
    config()->set('services.whatsapp.reservation_templates_have_url_button', true);

    $reservation = reservationForWhatsAppTests();
    $reservation->restaurant->forceFill(['slug' => 'the-grill-house'])->save();
    $reservation->load('restaurant');

    $contact = GuestContact::factory()->make(['phone' => '+2348012345678']);

    $created = (new ReservationLifecycleNotification($reservation, 'created'))->toWhatsApp($contact);
    $cancelled = (new ReservationLifecycleNotification($reservation, 'cancelled'))->toWhatsApp($contact);

    expect($created->urlButtonSuffix)->toBe("restaurants/the-grill-house/reservations?reservation_id={$reservation->id}")
        ->and($cancelled->urlButtonSuffix)->toBe('restaurants/the-grill-house');

    $buttonComponent = collect($created->toPayload('2348012345678')['template']['components'])
        ->firstWhere('type', 'button');

    expect($buttonComponent)->not->toBeNull()
        ->and($buttonComponent['sub_type'])->toBe('url')
        ->and($buttonComponent['index'])->toBe('0')
        ->and($buttonComponent['parameters'][0]['text'])->toBe("restaurants/the-grill-house/reservations?reservation_id={$reservation->id}");
});

it('omits the URL button component when templates do not support it', function (): void {
    config()->set('services.whatsapp.reservation_templates_have_url_button', false);

    $reservation = reservationForWhatsAppTests();
    $contact = GuestContact::factory()->make(['phone' => '+2348012345678']);

    $message = (new ReservationLifecycleNotification($reservation, 'created'))->toWhatsApp($contact);
    $components = $message->toPayload('2348012345678')['template']['components'];

    expect($message->urlButtonSuffix)->toBeNull()
        ->and(collect($components)->pluck('type')->all())->toBe(['body']);
});

it('attaches the cancel quick reply payload only for the primary booker', function (): void {
    config()->set('services.whatsapp.reservation_templates_have_cancel_button', true);

    $reservation = reservationForWhatsAppTests(['user_id' => null]);
    $booker = GuestContact::factory()->create([
        'restaurant_id' => $reservation->restaurant_id,
        'phone' => '+2348012345678',
    ]);
    $reservation->forceFill(['guest_contact_id' => $booker->id])->save();
    $reservation->refresh()->load('restaurant');

    $otherGuest = new ReservationGuest([
        'attendee_name' => 'Added Diner',
        'email_address' => 'added@example.com',
        'phone_number' => '+2348000000001',
    ]);

    $bookerMessage = (new ReservationLifecycleNotification($reservation, 'created'))->toWhatsApp($booker);
    $guestMessage = (new ReservationLifecycleNotification($reservation, 'created'))->toWhatsApp($otherGuest);
    $cancelledMessage = (new ReservationLifecycleNotification($reservation, 'cancelled'))->toWhatsApp($booker);

    expect($bookerMessage->quickReplyPayload)->toBe("cancel_reservation:{$reservation->id}")
        ->and($guestMessage->quickReplyPayload)->toBeNull()
        ->and($cancelledMessage->quickReplyPayload)->toBeNull();

    $buttonComponent = collect($bookerMessage->toPayload('2348012345678')['template']['components'])
        ->firstWhere('sub_type', 'quick_reply');

    expect($buttonComponent['index'])->toBe('0')
        ->and($buttonComponent['parameters'][0])->toBe(['type' => 'payload', 'payload' => "cancel_reservation:{$reservation->id}"]);
});

it('places the quick reply after the URL button when both are enabled', function (): void {
    config()->set('services.whatsapp.reservation_templates_have_url_button', true);
    config()->set('services.whatsapp.reservation_templates_have_cancel_button', true);

    $reservation = reservationForWhatsAppTests(['user_id' => null]);
    $reservation->restaurant->forceFill(['slug' => 'the-grill-house'])->save();
    $booker = GuestContact::factory()->create([
        'restaurant_id' => $reservation->restaurant_id,
        'phone' => '+2348012345678',
    ]);
    $reservation->forceFill(['guest_contact_id' => $booker->id])->save();
    $reservation->refresh()->load('restaurant');

    $message = (new ReservationLifecycleNotification($reservation, 'created'))->toWhatsApp($booker);
    $buttons = collect($message->toPayload('2348012345678')['template']['components'])
        ->where('type', 'button')
        ->values();

    expect($buttons->pluck('sub_type')->all())->toBe(['url', 'quick_reply'])
        ->and($buttons->pluck('index')->all())->toBe(['0', '1']);
});

it('omits the cancel quick reply when templates do not support it', function (): void {
    config()->set('services.whatsapp.reservation_templates_have_cancel_button', false);

    $reservation = reservationForWhatsAppTests(['user_id' => null]);
    $booker = GuestContact::factory()->create([
        'restaurant_id' => $reservation->restaurant_id,
        'phone' => '+2348012345678',
    ]);
    $reservation->forceFill(['guest_contact_id' => $booker->id])->save();
    $reservation->refresh()->load('restaurant');

    $message = (new ReservationLifecycleNotification($reservation, 'created'))->toWhatsApp($booker);

    expect($message->quickReplyPayload)->toBeNull();
});

it('does not send WhatsApp messages when credentials are missing', function (): void {
    Http::fake();
    config()->set('services.whatsapp.token', '');

    $reservation = reservationForWhatsAppTests();
    $contact = GuestContact::factory()->create([
        'restaurant_id' => $reservation->restaurant_id,
        'phone' => '+2348012345678',
    ]);

    app(WhatsAppChannel::class)->send($contact, new ReservationLifecycleNotification($reservation, 'created'));

    Http::assertNothingSent();
});

it('notifies the primary booker and additional guests without duplicates', function (): void {
    $reservation = reservationForWhatsAppTests(['user_id' => null]);
    $contact = GuestContact::factory()->create([
        'restaurant_id' => $reservation->restaurant_id,
        'email' => 'booker@example.com',
        'phone' => '+2348012345678',
    ]);
    $reservation->forceFill(['guest_contact_id' => $contact->id])->save();

    ReservationGuest::query()->create([
        'reservation_id' => $reservation->id,
        'restaurant_id' => $reservation->restaurant_id,
        'attendee_name' => 'Booker Duplicate',
        'email_address' => 'BOOKER@example.com',
        'email_normalized' => 'booker@example.com',
        'phone_number' => null,
    ]);
    ReservationGuest::query()->create([
        'reservation_id' => $reservation->id,
        'restaurant_id' => $reservation->restaurant_id,
        'attendee_name' => 'Phone Duplicate',
        'email_address' => 'other@example.com',
        'email_normalized' => 'other@example.com',
        'phone_number' => '+234 (801) 234-5678',
    ]);
    ReservationGuest::query()->create([
        'reservation_id' => $reservation->id,
        'restaurant_id' => $reservation->restaurant_id,
        'attendee_name' => 'Unique Guest',
        'email_address' => 'unique@example.com',
        'email_normalized' => 'unique@example.com',
        'phone_number' => '+2348000000002',
    ]);

    $participants = $reservation->refresh()->notifiableParticipants();

    expect($participants)->toHaveCount(2)
        ->and($participants->first())->toBeInstanceOf(GuestContact::class)
        ->and($participants->last()->attendee_name)->toBe('Unique Guest');
});

it('notifies guest contact and additional guests when a reservation is cancelled', function (): void {
    Notification::fake();

    $reservation = reservationForWhatsAppTests(['user_id' => null]);
    $contact = GuestContact::factory()->create([
        'restaurant_id' => $reservation->restaurant_id,
        'email' => 'booker@example.com',
        'phone' => '+2348012345678',
    ]);
    $reservation->forceFill(['guest_contact_id' => $contact->id])->save();

    ReservationGuest::query()->create([
        'reservation_id' => $reservation->id,
        'restaurant_id' => $reservation->restaurant_id,
        'attendee_name' => 'Added Diner',
        'email_address' => 'added@example.com',
        'email_normalized' => 'added@example.com',
        'phone_number' => '+2348000000003',
    ]);

    $actor = User::factory()->create();

    app(ReservationService::class)->cancelReservation($reservation->refresh(), $actor);

    Notification::assertSentTo($contact, ReservationLifecycleNotification::class, function ($notification, array $channels): bool {
        return in_array(WhatsAppChannel::class, $channels, true)
            && $notification->toArray((object) [])['action'] === 'cancelled';
    });

    Notification::assertSentTo(
        ReservationGuest::query()->where('email_normalized', 'added@example.com')->first(),
        ReservationLifecycleNotification::class,
    );
});
