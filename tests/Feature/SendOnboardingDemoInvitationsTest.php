<?php

use App\Models\OnboardingRequest;
use App\Notifications\OnboardingDemoInvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['services.demo_booking.url' => 'https://cal.com/moretables/demo']);
});

it('emails every requester that has not been invited yet', function () {
    Notification::fake();

    $pending = OnboardingRequest::factory()->create(['email' => 'lead@bistro.ng', 'demo_invitation_sent_at' => null]);
    $alreadySent = OnboardingRequest::factory()->create(['email' => 'old@bistro.ng', 'demo_invitation_sent_at' => now()->subDay()]);

    $this->artisan('onboarding-requests:send-demo-invites --force')
        ->expectsOutputToContain('Queued 1 demo invitation(s).')
        ->assertSuccessful();

    Notification::assertSentOnDemand(
        OnboardingDemoInvitationNotification::class,
        fn ($notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'lead@bistro.ng',
    );
    Notification::assertSentOnDemandTimes(OnboardingDemoInvitationNotification::class, 1);

    expect($pending->refresh()->demo_invitation_sent_at)->not->toBeNull()
        ->and($alreadySent->refresh()->demo_invitation_sent_at->toDateString())->toBe(now()->subDay()->toDateString());
});

it('emails a repeat requester once and stamps all of their requests', function () {
    Notification::fake();

    $first = OnboardingRequest::factory()->create(['email' => 'lead@bistro.ng', 'demo_invitation_sent_at' => null]);
    $second = OnboardingRequest::factory()->create(['email' => 'LEAD@bistro.ng', 'demo_invitation_sent_at' => null]);

    $this->artisan('onboarding-requests:send-demo-invites --force')->assertSuccessful();

    Notification::assertSentOnDemandTimes(OnboardingDemoInvitationNotification::class, 1);
    expect($first->refresh()->demo_invitation_sent_at)->not->toBeNull()
        ->and($second->refresh()->demo_invitation_sent_at)->not->toBeNull();
});

it('does not send twice when run again', function () {
    Notification::fake();
    OnboardingRequest::factory()->create(['email' => 'lead@bistro.ng', 'demo_invitation_sent_at' => null]);

    $this->artisan('onboarding-requests:send-demo-invites --force')->assertSuccessful();
    $this->artisan('onboarding-requests:send-demo-invites --force')
        ->expectsOutputToContain('No onboarding requesters are awaiting a demo invitation.')
        ->assertSuccessful();

    Notification::assertSentOnDemandTimes(OnboardingDemoInvitationNotification::class, 1);
});

it('sends nothing on a dry run', function () {
    Notification::fake();
    $request = OnboardingRequest::factory()->create(['email' => 'lead@bistro.ng', 'demo_invitation_sent_at' => null]);

    $this->artisan('onboarding-requests:send-demo-invites --dry-run')
        ->expectsOutputToContain('lead@bistro.ng')
        ->expectsOutputToContain('Dry run: 1 requester(s) would be emailed.')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect($request->refresh()->demo_invitation_sent_at)->toBeNull();
});

it('sends nothing when the confirmation is declined', function () {
    Notification::fake();
    OnboardingRequest::factory()->create(['email' => 'lead@bistro.ng', 'demo_invitation_sent_at' => null]);

    $this->artisan('onboarding-requests:send-demo-invites')
        ->expectsConfirmation('Send the demo invitation to 1 requester(s)?', 'no')
        ->expectsOutputToContain('Aborted. Nothing was sent.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('skips requests without an email address', function () {
    Notification::fake();
    OnboardingRequest::factory()->create(['email' => '', 'demo_invitation_sent_at' => null]);

    $this->artisan('onboarding-requests:send-demo-invites --force')
        ->expectsOutputToContain('No onboarding requesters are awaiting a demo invitation.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('refuses to send when the booking link is not reachable by a recipient', function () {
    Notification::fake();
    config(['services.demo_booking.url' => null, 'app.frontend_urls.main' => null, 'app.url' => 'http://localhost:8000']);

    $request = OnboardingRequest::factory()->create(['email' => 'lead@bistro.ng', 'demo_invitation_sent_at' => null]);

    $this->artisan('onboarding-requests:send-demo-invites --force')
        ->expectsOutputToContain('Refusing to send')
        ->assertFailed();

    Notification::assertNothingSent();
    expect($request->refresh()->demo_invitation_sent_at)->toBeNull();
});

it('shows the booking link before sending', function () {
    Notification::fake();
    OnboardingRequest::factory()->create(['email' => 'lead@bistro.ng', 'demo_invitation_sent_at' => null]);

    $this->artisan('onboarding-requests:send-demo-invites --dry-run')
        ->expectsOutputToContain('Booking button links to: https://cal.com/moretables/demo')
        ->assertSuccessful();
});
