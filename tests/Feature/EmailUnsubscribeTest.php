<?php

declare(strict_types=1);

use App\Models\EmailUnsubscribe;
use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\AuthChallengeCodeNotification;
use App\Notifications\GuestReservationLifecycleMailNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

it('unsubscribes the recipient when the signed link is visited', function (): void {
    $user = User::factory()->create([
        'email' => 'Guest@Example.com',
        'notify_marketing_emails' => true,
    ]);

    $url = URL::signedRoute('email.unsubscribe', ['email' => 'Guest@Example.com']);

    $this->get($url)
        ->assertOk()
        ->assertSee('unsubscribed');

    expect(EmailUnsubscribe::isSuppressed('guest@example.com'))->toBeTrue()
        ->and($user->fresh()->notify_marketing_emails)->toBeFalse();
});

it('rejects an unsubscribe link with an invalid signature', function (): void {
    $this->get('/email/unsubscribe?email=guest@example.com&signature=tampered')
        ->assertForbidden();

    expect(EmailUnsubscribe::isSuppressed('guest@example.com'))->toBeFalse();
});

it('honors one-click unsubscribe via POST', function (): void {
    $url = URL::signedRoute('email.unsubscribe', ['email' => 'guest@example.com']);

    $this->post($url)
        ->assertOk()
        ->assertJson(['status' => 'unsubscribed']);

    expect(EmailUnsubscribe::isSuppressed('guest@example.com'))->toBeTrue();
});

it('puts the rewards and signed unsubscribe links in the footer', function (): void {
    $restaurant = Restaurant::factory()->create();
    $guestContact = GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'email' => 'guest@example.com',
    ]);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'guest_contact_id' => $guestContact->id,
        'starts_at' => Carbon::parse('2026-04-14 00:00:00', 'UTC'),
    ]);

    $data = (new GuestReservationLifecycleMailNotification($reservation, $guestContact, 'created'))
        ->toMail($guestContact)
        ->data();

    expect($data['footerLink1Url'])->toEndWith('/rewards')
        ->and($data['footerLink2Url'])->toContain('/email/unsubscribe')
        ->and($data['footerLink2Url'])->toContain('signature=');
});

it('skips sending mail to an unsubscribed recipient', function (): void {
    config(['mail.default' => 'array']);
    $transport = Mail::mailer('array')->getSymfonyTransport();
    $transport->flush();

    EmailUnsubscribe::suppress('skip@example.com');

    $restaurant = Restaurant::factory()->create();
    $guestContact = GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'email' => 'skip@example.com',
    ]);
    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'guest_contact_id' => $guestContact->id,
        'starts_at' => Carbon::parse('2026-04-14 00:00:00', 'UTC'),
    ]);

    Notification::route('mail', 'skip@example.com')
        ->notifyNow(new GuestReservationLifecycleMailNotification($reservation, $guestContact, 'created'));

    expect($transport->messages())->toHaveCount(0);

    Notification::route('mail', 'allowed@example.com')
        ->notifyNow(new GuestReservationLifecycleMailNotification($reservation, $guestContact, 'created'));

    expect($transport->messages())->toHaveCount(1);
});

it('still delivers security mail to an unsubscribed recipient', function (): void {
    config(['mail.default' => 'array']);
    $transport = Mail::mailer('array')->getSymfonyTransport();
    $transport->flush();

    EmailUnsubscribe::suppress('locked-out@example.com');

    Notification::route('mail', 'locked-out@example.com')
        ->notifyNow(new AuthChallengeCodeNotification('123456', 'log in'));

    expect($transport->messages())->toHaveCount(1);
});
