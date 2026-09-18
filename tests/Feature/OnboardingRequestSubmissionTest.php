<?php

use App\Models\OnboardingRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OnboardingAcknowledgementNotification;
use App\Notifications\OnboardingDemoInvitationNotification;
use App\Notifications\OnboardingRequestSubmittedNotification;
use App\OnboardingContactReason;
use App\OnboardingJobTitle;
use App\OnboardingLocationCount;
use App\UserStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

it('submits an onboarding request with all fields', function () {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $businessAdmin = User::factory()->create();
    assignScopedRole($businessAdmin, Role::BusinessAdmin);

    $suspendedAdmin = User::factory()->create([
        'status' => UserStatus::Suspended,
    ]);
    assignScopedRole($suspendedAdmin, Role::SuperAdmin);

    $customer = User::factory()->create();
    assignScopedRole($customer, Role::Customer);

    $response = $this->postJson('/api/v1/onboarding-requests', [
        'first_name' => 'Chidi',
        'last_name' => 'Okeke',
        'email' => 'chidi@bistro.ng',
        'phone' => '+2348011223344',
        'restaurant_name' => 'Chidi\'s Bistro',
        'job_title' => OnboardingJobTitle::Owner->value,
        'location_count' => OnboardingLocationCount::One->value,
        'contact_reason' => OnboardingContactReason::BookADemo->value,
    ]);

    $response->assertCreated()
        ->assertJsonPath('message', 'Onboarding request submitted successfully.')
        ->assertJsonPath('onboarding_request.first_name', 'Chidi')
        ->assertJsonPath('onboarding_request.last_name', 'Okeke')
        ->assertJsonPath('onboarding_request.full_name', 'Chidi Okeke')
        ->assertJsonPath('onboarding_request.restaurant_name', 'Chidi\'s Bistro')
        ->assertJsonPath('onboarding_request.job_title', 'owner')
        ->assertJsonPath('onboarding_request.location_count', '1')
        ->assertJsonPath('onboarding_request.contact_reason', 'book_a_demo')
        ->assertJsonPath('onboarding_request.status', 'pending');

    expect(OnboardingRequest::query()->where('email', 'chidi@bistro.ng')->exists())->toBeTrue();

    Notification::assertSentTo(
        $businessAdmin,
        OnboardingRequestSubmittedNotification::class,
        function (OnboardingRequestSubmittedNotification $notification, array $channels): bool {
            $databasePayload = $notification->toArray(new stdClass);
            $mailHtml = (string) $notification->toMail(new stdClass)->render();

            return $channels === ['mail', 'database']
                && $databasePayload['type'] === 'onboarding_request_submitted'
                && $databasePayload['restaurant_name'] === 'Chidi\'s Bistro'
                && $databasePayload['email'] === 'chidi@bistro.ng'
                && str_contains($mailHtml, 'Reason: Book a demo')
                && ! str_contains($mailHtml, 'Reason: book_a_demo');
        },
    );
    Notification::assertNotSentTo($suspendedAdmin, OnboardingRequestSubmittedNotification::class);
    Notification::assertNotSentTo($customer, OnboardingRequestSubmittedNotification::class);
    Notification::assertSentOnDemand(
        OnboardingRequestSubmittedNotification::class,
        fn (OnboardingRequestSubmittedNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'sales@moretables.com',
    );
});

it('validates required fields on onboarding request submission', function () {
    $response = $this->postJson('/api/v1/onboarding-requests', []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'first_name',
            'last_name',
            'email',
            'phone',
            'restaurant_name',
            'job_title',
            'location_count',
            'contact_reason',
        ]);
});

it('rejects invalid enum values for job title, location count and contact reason', function () {
    $response = $this->postJson('/api/v1/onboarding-requests', [
        'first_name' => 'Chidi',
        'last_name' => 'Okeke',
        'email' => 'chidi@bistro.ng',
        'phone' => '+2348011223344',
        'restaurant_name' => 'Chidi\'s Bistro',
        'job_title' => 'invalid_title',
        'location_count' => 'invalid_count',
        'contact_reason' => 'invalid_reason',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['job_title', 'location_count', 'contact_reason']);
});

it('accepts optional address and notes fields', function () {
    $response = $this->postJson('/api/v1/onboarding-requests', [
        'first_name' => 'Ada',
        'last_name' => 'Nwosu',
        'email' => 'ada@restaurant.ng',
        'phone' => '+2348099887766',
        'restaurant_name' => 'Ada\'s Kitchen',
        'job_title' => OnboardingJobTitle::GeneralManager->value,
        'location_count' => OnboardingLocationCount::TwoToFive->value,
        'contact_reason' => OnboardingContactReason::Support->value,
        'address' => '10 Victoria Island, Lagos',
        'notes' => 'Looking forward to partnering with you.',
    ]);

    $response->assertCreated()
        ->assertJsonPath('onboarding_request.address', '10 Victoria Island, Lagos')
        ->assertJsonPath('onboarding_request.notes', 'Looking forward to partnering with you.');
});

it('emails the requester a demo booking link', function () {
    Notification::fake();
    config(['services.demo_booking.url' => 'https://cal.com/moretables/demo']);

    $this->postJson('/api/v1/onboarding-requests', [
        'first_name' => 'Chidi',
        'last_name' => 'Okeke',
        'email' => 'chidi@bistro.ng',
        'phone' => '+2348011223344',
        'restaurant_name' => 'Chidi\'s Bistro',
        'job_title' => OnboardingJobTitle::Owner->value,
        'location_count' => OnboardingLocationCount::One->value,
        'contact_reason' => OnboardingContactReason::BookADemo->value,
    ])->assertCreated();

    Notification::assertSentOnDemand(
        OnboardingDemoInvitationNotification::class,
        function (OnboardingDemoInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
            $html = (string) $notification->toMail(new stdClass)->render();

            $copy = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            expect($copy)->toContain(
                'Thanks for requesting a MoreTables demo.',
                'We’d love to show you how MoreTables can help Chidi\'s Bistro simplify reservations, optimize your tables, reduce no-shows, and turn more diners into regulars.',
                'In a 30-45 minute demo, we’ll cover:',
                'The Diner Experience — see how your guests can easily discover your restaurant, check availability, book a table, and manage their reservation from start to finish.',
                'Reservations — real-time availability, booking management & waitlists',
                'Guest Management — guest profiles, preferences & dining history',
                'No-Show Protection — reminders, reservation holds & smarter cancellation management',
                'Guest Loyalty — rewards and tools to encourage repeat visits',
                'Analytics & Reporting — actionable insights into bookings, covers, revenue, guest behavior & restaurant performance',
                'Pick a time that works for you, and we’ll take care of the rest.',
                'See you soon',
            )->not->toContain('20 minutes', 'No slides', 'Talk soon');

            return $notifiable->routes['mail'] === 'chidi@bistro.ng'
                && $channels === ['mail']
                && str_contains($html, 'Hi Chidi,')
                && str_contains($html, 'https://cal.com/moretables/demo')
                && str_contains($html, 'Book a demo')
                && str_contains($html, 'background-color:#1a1a1a;padding:14px 28px;');
        },
    );

    expect(OnboardingRequest::query()->where('email', 'chidi@bistro.ng')->value('demo_invitation_sent_at'))->not->toBeNull();
});

it('falls back to the frontend booking path when no calendar url is configured', function () {
    config(['services.demo_booking.url' => null, 'app.frontend_urls.main' => 'https://www.moretables.com']);

    $request = OnboardingRequest::factory()->create(['email' => 'lead@bistro.ng']);
    $html = (string) (new OnboardingDemoInvitationNotification($request))->toMail(new stdClass)->render();

    expect($html)->toContain('https://www.moretables.com/book-a-demo');
});

it('replies with the reason-specific acknowledgement instead of the demo pitch', function (string $reason, string $subject, array $expectedCopy) {
    Notification::fake();

    $this->postJson('/api/v1/onboarding-requests', [
        'first_name' => 'Chidi',
        'last_name' => 'Okeke',
        'email' => 'chidi@bistro.ng',
        'phone' => '+2348011223344',
        'restaurant_name' => 'Chidi\'s Bistro',
        'job_title' => OnboardingJobTitle::Owner->value,
        'location_count' => OnboardingLocationCount::One->value,
        'contact_reason' => $reason,
    ])->assertCreated();

    Notification::assertSentOnDemandTimes(OnboardingDemoInvitationNotification::class, 0);

    Notification::assertSentOnDemand(
        OnboardingAcknowledgementNotification::class,
        function (OnboardingAcknowledgementNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($subject, $expectedCopy): bool {
            $mail = $notification->toMail(new stdClass);
            $copy = html_entity_decode(strip_tags((string) $mail->render()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            expect($copy)->toContain(...$expectedCopy)
                ->not->toContain('Book a demo', 'In a 30-45 minute demo');

            return $notifiable->routes['mail'] === 'chidi@bistro.ng'
                && $channels === ['mail']
                && $mail->subject === $subject
                && str_contains($copy, 'Hi Chidi,');
        },
    );

    expect(OnboardingRequest::query()->where('email', 'chidi@bistro.ng')->value('demo_invitation_sent_at'))->toBeNull();
})->with([
    'pricing' => [
        OnboardingContactReason::Pricing->value,
        'Thanks for your MoreTables pricing enquiry',
        [
            'Thanks for reaching out to MoreTables!',
            'We’ve received your pricing enquiry. A member of our Sales Team will be in touch shortly to share more information about our plans, pricing, and the solutions available for Chidi\'s Bistro.',
            'We look forward to helping you find the right fit for your restaurant.',
        ],
    ],
    'support' => [
        OnboardingContactReason::Support->value,
        'We’ve received your MoreTables support request',
        [
            'Thanks for reaching out to MoreTables!',
            'We’ve received your support request, and a member of our Support Team will be in touch shortly to assist you.',
            'We’re here to make sure you get the help you need.',
        ],
    ],
    'restaurant onboarding falls back to support' => [
        OnboardingContactReason::RestaurantOnboarding->value,
        'We’ve received your MoreTables support request',
        [
            'Thanks for reaching out to MoreTables!',
            'We’ve received your support request, and a member of our Support Team will be in touch shortly to assist you.',
            'We’re here to make sure you get the help you need.',
        ],
    ],
    'general inquiry falls back to support' => [
        OnboardingContactReason::GeneralInquiry->value,
        'We’ve received your MoreTables support request',
        [
            'Thanks for reaching out to MoreTables!',
            'We’ve received your support request, and a member of our Support Team will be in touch shortly to assist you.',
            'We’re here to make sure you get the help you need.',
        ],
    ],
    'partnership' => [
        OnboardingContactReason::Partnership->value,
        'Thanks for your interest in partnering with MoreTables',
        [
            'Thanks for your interest in partnering with MoreTables!',
            'We’ve received your partnership enquiry. A member of our Sales Team will be in touch shortly to learn more about your proposal and explore how we can work together.',
            'We look forward to connecting and exploring what’s possible.',
        ],
    ],
    'other' => [
        OnboardingContactReason::Other->value,
        'Thanks for reaching out to MoreTables',
        [
            'Thanks for reaching out to MoreTables!',
            'We’ve received your enquiry and will make sure it gets to the right team. A member of our team will be in touch shortly to learn more about how we can help.',
            'We look forward to connecting.',
        ],
    ],
]);

it('never sends the demo acknowledgement to a demo request', function () {
    Notification::fake();

    $this->postJson('/api/v1/onboarding-requests', [
        'first_name' => 'Chidi',
        'last_name' => 'Okeke',
        'email' => 'chidi@bistro.ng',
        'phone' => '+2348011223344',
        'restaurant_name' => 'Chidi\'s Bistro',
        'job_title' => OnboardingJobTitle::Owner->value,
        'location_count' => OnboardingLocationCount::One->value,
        'contact_reason' => OnboardingContactReason::BookADemo->value,
    ])->assertCreated();

    Notification::assertSentOnDemandTimes(OnboardingAcknowledgementNotification::class, 0);
    Notification::assertSentOnDemand(OnboardingDemoInvitationNotification::class);
});
