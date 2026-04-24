<?php

declare(strict_types=1);

use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\GuestReservationLifecycleMailNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

it('renders the reservation confirmed email with restaurant details and actions', function (): void {
    Storage::fake('public');

    config(['app.url' => 'https://moretables.test']);

    $restaurant = Restaurant::factory()->create([
        'name' => 'Pepp & Dolores',
        'phone' => '(513) 419-1820',
        'address_line_1' => '1501 Vine Street',
        'address_line_2' => null,
        'city' => 'Cincinnati',
        'state' => 'OH',
        'country' => 'USA',
        'timezone' => 'America/New_York',
        'menu_link' => 'https://pepp.example.com/menu',
        'latitude' => 39.1080000,
        'longitude' => -84.5150000,
    ]);

    $restaurant
        ->addMedia(UploadedFile::fake()->image('restaurant.png'))
        ->toMediaCollection('featured');

    $guestContact = GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'first_name' => 'Urenna',
        'last_name' => 'Anyadike',
    ]);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'guest_contact_id' => $guestContact->id,
        'starts_at' => Carbon::parse('2026-04-14 00:00:00', 'UTC'),
        'party_size' => 2,
        'reservation_reference' => '378493',
    ]);

    $notification = new GuestReservationLifecycleMailNotification($reservation, $guestContact, 'created');
    $mailMessage = $notification->toMail((object) []);
    $html = (string) $mailMessage->render();
    $text = trim(view($mailMessage->view['text'], $mailMessage->data())->render());

    expect($mailMessage->data()['menuUrl'])->toBe('https://pepp.example.com/menu')
        ->and($mailMessage->data()['directionsUrl'])->toBe('https://www.google.com/maps/search/?api=1&query=39.1080000%2C-84.5150000')
        ->and($html)->toContain('Reservation confirmed')
        ->and($html)->toContain('Thanks for using MoreTables')
        ->and($html)->toContain('Pepp &amp; Dolores')
        ->and($html)->toContain('Table for 2 on Monday, April 13, 2026 at 8:00 pm')
        ->and($html)->toContain('Name:')
        ->and($html)->toContain('Urenna Anyadike')
        ->and($html)->toContain('Confirmation #:')
        ->and($html)->toContain('378493')
        ->and($html)->toContain('See Menu')
        ->and($html)->toContain('Get Directions')
        ->and($html)->toContain('1501 Vine Street')
        ->and($html)->toContain('(513) 419-1820')
        ->and($text)->toContain('Reservation confirmed')
        ->and($text)->toContain('See Menu: https://pepp.example.com/menu')
        ->and($text)->toContain('Get Directions: https://www.google.com/maps/search/?api=1&query=39.1080000%2C-84.5150000');
});

it('renders the reservation canceled email with the canceled copy and trimmed details', function (): void {
    Storage::fake('public');

    config(['app.url' => 'https://moretables.test']);

    $restaurant = Restaurant::factory()->create([
        'name' => 'Pepp & Dolores',
        'phone' => '(513) 419-1820',
        'address_line_1' => '1501 Vine Street',
        'address_line_2' => null,
        'city' => 'Cincinnati',
        'state' => 'OH',
        'country' => 'USA',
        'timezone' => 'America/New_York',
        'menu_link' => 'https://pepp.example.com/menu',
        'latitude' => 39.1080000,
        'longitude' => -84.5150000,
    ]);

    $restaurant
        ->addMedia(UploadedFile::fake()->image('restaurant-cancelled.png'))
        ->toMediaCollection('featured');

    $guestContact = GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'first_name' => 'Urenna',
        'last_name' => 'Anyadike',
    ]);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'guest_contact_id' => $guestContact->id,
        'starts_at' => Carbon::parse('2026-04-16 00:45:00', 'UTC'),
        'party_size' => 2,
        'reservation_reference' => '378493',
    ]);

    $notification = new GuestReservationLifecycleMailNotification($reservation, $guestContact, 'cancelled');
    $mailMessage = $notification->toMail((object) []);
    $html = (string) $mailMessage->render();
    $text = trim(view($mailMessage->view['text'], $mailMessage->data())->render());

    expect($html)->toContain('Reservation canceled')
        ->and($html)->toContain('successfully canceled your reservation at')
        ->and($html)->toContain('Pepp &amp; Dolores')
        ->and($html)->toContain('Table for 2 on Wednesday, April 15, 2026 at 8:45 pm')
        ->and($html)->toContain('See Menu')
        ->and($html)->toContain('Get Directions')
        ->and($html)->not->toContain('1501 Vine Street')
        ->and($html)->not->toContain('(513) 419-1820')
        ->and($text)->toContain('Reservation canceled')
        ->and($text)->toContain("You've successfully canceled your reservation at")
        ->and($text)->not->toContain('1501 Vine Street')
        ->and($text)->not->toContain('(513) 419-1820')
        ->and($text)->toContain('See Menu: https://pepp.example.com/menu')
        ->and($text)->toContain('Get Directions: https://www.google.com/maps/search/?api=1&query=39.1080000%2C-84.5150000');
});

it('renders the reservation changed email with the updated copy and full details', function (): void {
    Storage::fake('public');

    config(['app.url' => 'https://moretables.test']);

    $restaurant = Restaurant::factory()->create([
        'name' => 'Pepp & Dolores',
        'phone' => '(513) 419-1820',
        'address_line_1' => '1501 Vine Street',
        'address_line_2' => null,
        'city' => 'Cincinnati',
        'state' => 'OH',
        'country' => 'USA',
        'timezone' => 'America/New_York',
        'menu_link' => 'https://pepp.example.com/menu',
        'latitude' => 39.1080000,
        'longitude' => -84.5150000,
    ]);

    $restaurant
        ->addMedia(UploadedFile::fake()->image('restaurant-updated.png'))
        ->toMediaCollection('featured');

    $guestContact = GuestContact::factory()->create([
        'restaurant_id' => $restaurant->id,
        'first_name' => 'Urenna',
        'last_name' => 'Anyadike',
    ]);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'guest_contact_id' => $guestContact->id,
        'starts_at' => Carbon::parse('2026-04-16 00:45:00', 'UTC'),
        'party_size' => 2,
        'reservation_reference' => '378493',
    ]);

    $notification = new GuestReservationLifecycleMailNotification($reservation, $guestContact, 'updated');
    $mailMessage = $notification->toMail((object) []);
    $html = (string) $mailMessage->render();
    $text = trim(view($mailMessage->view['text'], $mailMessage->data())->render());

    expect($html)->toContain('Reservation changed')
        ->and($html)->toContain('Here are the new details:')
        ->and($html)->toContain('Pepp &amp; Dolores')
        ->and($html)->toContain('Table for 2 on Wednesday, April 15, 2026 at 8:45 pm')
        ->and($html)->toContain('1501 Vine Street')
        ->and($html)->toContain('(513) 419-1820')
        ->and($html)->toContain('See Menu')
        ->and($html)->toContain('Get Directions')
        ->and($text)->toContain('Reservation changed')
        ->and($text)->toContain('Here are the new details:')
        ->and($text)->toContain('1501 Vine Street')
        ->and($text)->toContain('(513) 419-1820')
        ->and($text)->toContain('See Menu: https://pepp.example.com/menu')
        ->and($text)->toContain('Get Directions: https://www.google.com/maps/search/?api=1&query=39.1080000%2C-84.5150000');
});

it('renders the added diner email with the guest-added copy and trimmed details', function (): void {
    Storage::fake('public');

    config(['app.url' => 'https://moretables.test']);

    $restaurant = Restaurant::factory()->create([
        'name' => 'Pepp & Dolores',
        'phone' => '(513) 419-1820',
        'address_line_1' => '1501 Vine Street',
        'address_line_2' => null,
        'city' => 'Cincinnati',
        'state' => 'OH',
        'country' => 'USA',
        'timezone' => 'America/New_York',
        'menu_link' => 'https://pepp.example.com/menu',
        'latitude' => 39.1080000,
        'longitude' => -84.5150000,
    ]);

    $restaurant
        ->addMedia(UploadedFile::fake()->image('restaurant-guest-added.png'))
        ->toMediaCollection('featured');

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'starts_at' => Carbon::parse('2026-04-16 00:45:00', 'UTC'),
        'party_size' => 2,
        'reservation_reference' => '378493',
    ]);

    $reservationGuest = ReservationGuest::query()->create([
        'reservation_id' => $reservation->id,
        'restaurant_id' => $restaurant->id,
        'attendee_name' => 'Urenna Anyadike',
        'email_address' => 'urenna@example.com',
        'email_normalized' => 'urenna@example.com',
        'phone_number' => '+15134191820',
    ]);

    $notification = new GuestReservationLifecycleMailNotification($reservation, $reservationGuest, 'guest_added');
    $mailMessage = $notification->toMail((object) []);
    $html = (string) $mailMessage->render();
    $text = trim(view($mailMessage->view['text'], $mailMessage->data())->render());

    expect($html)->toContain('You have been added to the below reservation')
        ->and($html)->toContain('Here are the details')
        ->and($html)->toContain('Pepp &amp; Dolores')
        ->and($html)->toContain('Table for 2 on Wednesday, April 15, 2026 at 8:45 pm')
        ->and($html)->toContain('Urenna Anyadike')
        ->and($html)->toContain('Confirmation #:')
        ->and($html)->toContain('378493')
        ->and($html)->toContain('See Menu')
        ->and($html)->toContain('Get Directions')
        ->and($html)->not->toContain('1501 Vine Street')
        ->and($html)->not->toContain('(513) 419-1820')
        ->and($text)->toContain('You have been added to the below reservation')
        ->and($text)->toContain('Here are the details')
        ->and($text)->toContain('See Menu: https://pepp.example.com/menu')
        ->and($text)->toContain('Get Directions: https://www.google.com/maps/search/?api=1&query=39.1080000%2C-84.5150000')
        ->and($text)->not->toContain('1501 Vine Street')
        ->and($text)->not->toContain('(513) 419-1820');
});

it('renders the upcoming reservation reminder email with the reminder copy and trimmed details', function (): void {
    Storage::fake('public');

    config(['app.url' => 'https://moretables.test']);

    $restaurant = Restaurant::factory()->create([
        'name' => 'Pepp & Dolores',
        'phone' => '(513) 419-1820',
        'address_line_1' => '1501 Vine Street',
        'address_line_2' => null,
        'city' => 'Cincinnati',
        'state' => 'OH',
        'country' => 'USA',
        'timezone' => 'America/New_York',
        'menu_link' => 'https://pepp.example.com/menu',
        'latitude' => 39.1080000,
        'longitude' => -84.5150000,
    ]);

    $restaurant
        ->addMedia(UploadedFile::fake()->image('restaurant-upcoming.png'))
        ->toMediaCollection('featured');

    $user = User::factory()->create([
        'first_name' => 'Urenna',
        'last_name' => 'Anyadike',
        'email' => 'urenna@example.com',
    ]);

    $reservation = Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $user->id,
        'starts_at' => Carbon::parse('2026-04-16 00:45:00', 'UTC'),
        'party_size' => 2,
        'reservation_reference' => '378493',
    ]);

    $notification = new GuestReservationLifecycleMailNotification($reservation, $user, 'upcoming_reminder', 3);
    $mailMessage = $notification->toMail((object) []);
    $html = (string) $mailMessage->render();
    $text = trim(view($mailMessage->view['text'], $mailMessage->data())->render());

    expect($html)->toContain('Your reservation is coming up at Pepp &amp; Dolores in 3 days')
        ->and($html)->toContain('Here are the details')
        ->and($html)->toContain('Table for 2 on Wednesday, April 15, 2026 at 8:45 pm')
        ->and($html)->toContain('Urenna Anyadike')
        ->and($html)->toContain('See Menu')
        ->and($html)->toContain('Get Directions')
        ->and($html)->not->toContain('1501 Vine Street')
        ->and($html)->not->toContain('(513) 419-1820')
        ->and($text)->toContain('Your reservation is coming up at Pepp & Dolores in 3 days')
        ->and($text)->toContain('Here are the details')
        ->and($text)->toContain('See Menu: https://pepp.example.com/menu')
        ->and($text)->toContain('Get Directions: https://www.google.com/maps/search/?api=1&query=39.1080000%2C-84.5150000')
        ->and($text)->not->toContain('1501 Vine Street')
        ->and($text)->not->toContain('(513) 419-1820');
});
