<?php

use App\Models\EmailUnsubscribe;
use App\Models\GuestContact;
use App\Models\GuestSurvey;
use App\Models\GuestSurveyInvitation;
use App\Models\GuestSurveyResponse;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\GuestSurveyInvitationNotification;
use App\Notifications\ReservationLifecycleNotification;
use App\ReservationStatus;
use App\Services\ReservationService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->organization = Organization::factory()->create();
    $this->restaurant = Restaurant::factory()->for($this->organization)->create();
    activateMerchantBilling($this->restaurant);
    $this->owner = User::factory()->create();
    assignScopedRole($this->owner, Role::OrganizationOwner, $this->organization, $this->restaurant);
    Sanctum::actingAs($this->owner);
});

it('creates the post dining template with delivery settings', function (): void {
    $response = postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys", [
        'template_key' => 'post_dining',
        'title' => 'Post Dining Questions',
        'send_delay_minutes' => 120,
        'channels' => ['push', 'email', 'whatsapp'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('survey.status', 'draft')
        ->assertJsonPath('survey.settings.send_delay_minutes', 120)
        ->assertJsonPath('survey.settings.channels.2', 'whatsapp')
        ->assertJsonPath('survey.questions.0.id', 'food')
        ->assertJsonPath('survey.questions.3.type', 'nps');

    expect(GuestSurvey::first()->questions)->toHaveCount(5);
});

it('enforces nested restaurant ownership and published survey immutability', function (): void {
    $otherSurvey = GuestSurvey::factory()->create();

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/{$otherSurvey->id}")
        ->assertNotFound();

    $survey = GuestSurvey::factory()->for($this->restaurant)->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now()->subHours(4),
    ]);

    patchJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/{$survey->id}", [
        'title' => 'Changed',
    ])->assertUnprocessable();

    $this->deleteJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/{$survey->id}")
        ->assertUnprocessable();
});

it('publishes one immutable version at a time', function (): void {
    $old = GuestSurvey::factory()->for($this->restaurant)->create([
        'version' => 1,
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now()->subDay(),
    ]);
    $draft = GuestSurvey::factory()->for($this->restaurant)->create(['version' => 2]);

    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/{$draft->id}/publish")
        ->assertSuccessful()
        ->assertJsonPath('survey.status', 'published');

    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/{$draft->id}/publish")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('survey');

    expect($old->refresh()->status)->toBe('archived')
        ->and($draft->refresh()->published_at)->not->toBeNull();
});

it('assigns visits by publication chronology when drafts publish out of creation order', function (): void {
    Notification::fake();
    $firstDraft = GuestSurvey::factory()->for($this->restaurant)->create([
        'version' => 1,
        'send_delay_minutes' => 0,
    ]);
    $secondDraft = GuestSurvey::factory()->for($this->restaurant)->create([
        'version' => 2,
        'send_delay_minutes' => 0,
    ]);

    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/{$secondDraft->id}/publish")
        ->assertSuccessful();

    $this->travel(2)->hours();
    $guest = User::factory()->create();
    Reservation::factory()->for($this->restaurant)->for($guest)->create([
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Completed,
        'completed_at' => now()->subHour(),
    ]);

    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/{$firstDraft->id}/publish")
        ->assertSuccessful();
    $this->travel(1)->hour();

    Artisan::call('guest-surveys:send-due');

    expect(GuestSurveyInvitation::sole()->guest_survey_id)->toBe($secondDraft->id)
        ->and($firstDraft->refresh()->status)->toBe('published')
        ->and($secondDraft->refresh()->status)->toBe('archived');
});

it('uses monotonic publication sequence when out-of-order publishes share a timestamp', function (): void {
    Notification::fake();
    $firstDraft = GuestSurvey::factory()->for($this->restaurant)->create([
        'version' => 1,
        'send_delay_minutes' => 0,
    ]);
    $secondDraft = GuestSurvey::factory()->for($this->restaurant)->create([
        'version' => 2,
        'send_delay_minutes' => 0,
    ]);
    $this->travelTo(now()->startOfSecond());

    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/{$secondDraft->id}/publish")
        ->assertSuccessful();
    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/{$firstDraft->id}/publish")
        ->assertSuccessful();

    expect($secondDraft->refresh()->publication_sequence)->toBe(1)
        ->and($firstDraft->refresh()->publication_sequence)->toBe(2)
        ->and($secondDraft->published_at->equalTo($firstDraft->published_at))->toBeTrue();

    $this->travel(1)->hour();
    $guest = User::factory()->create();
    Reservation::factory()->for($this->restaurant)->for($guest)->create([
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Completed,
        'completed_at' => now(),
    ]);

    Artisan::call('guest-surveys:send-due');

    expect(GuestSurveyInvitation::sole()->guest_survey_id)->toBe($firstDraft->id);
});

it('queues one hashed expiring invitation for the primary booker', function (): void {
    Notification::fake();
    $survey = GuestSurvey::factory()->for($this->restaurant)->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now()->subHours(4),
        'send_delay_minutes' => 120,
    ]);
    $guest = User::factory()->create();
    $reservation = Reservation::factory()->for($this->restaurant)->for($guest)->create([
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Completed,
        'completed_at' => now()->subHours(3),
        'starts_at' => now()->subHours(5),
        'ends_at' => now()->subHours(3),
    ]);

    Artisan::call('guest-surveys:send-due');
    Artisan::call('guest-surveys:send-due');

    $invitation = GuestSurveyInvitation::sole();
    expect($invitation->guest_survey_id)->toBe($survey->id)
        ->and($invitation->reservation_id)->toBe($reservation->id)
        ->and($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->expires_at->isFuture())->toBeTrue();
    Notification::assertSentToTimes($guest, GuestSurveyInvitationNotification::class, 1);
});

it('does not send a new survey to visits completed before it was published', function (): void {
    Notification::fake();
    GuestSurvey::factory()->for($this->restaurant)->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now(),
        'send_delay_minutes' => 0,
    ]);
    $guest = User::factory()->create();
    Reservation::factory()->for($this->restaurant)->for($guest)->create([
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Completed,
        'completed_at' => now()->subMinute(),
    ]);

    Artisan::call('guest-surveys:send-due');

    expect(GuestSurveyInvitation::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('delivers the frozen archived version that was active when the visit completed', function (): void {
    Notification::fake();
    $oldSurvey = GuestSurvey::factory()->for($this->restaurant)->create([
        'version' => 1,
        'status' => 'archived',
        'publication_sequence' => 1,
        'published_at' => now()->subHours(5),
        'send_delay_minutes' => 120,
    ]);
    GuestSurvey::factory()->for($this->restaurant)->create([
        'version' => 2,
        'status' => 'published',
        'publication_sequence' => 2,
        'published_at' => now()->subHour(),
    ]);
    $guest = User::factory()->create();
    Reservation::factory()->for($this->restaurant)->for($guest)->create([
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Completed,
        'completed_at' => now()->subHours(3),
    ]);

    Artisan::call('guest-surveys:send-due');

    expect(GuestSurveyInvitation::sole()->guest_survey_id)->toBe($oldSurvey->id);
    Notification::assertSentToTimes($guest, GuestSurveyInvitationNotification::class, 1);
});

it('reclaims a stale delivery with the same deterministic encrypted token', function (): void {
    Notification::fake();
    $survey = GuestSurvey::factory()->for($this->restaurant)->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now()->subHours(4),
        'send_delay_minutes' => 120,
    ]);
    $guest = User::factory()->create();
    $reservation = Reservation::factory()->for($this->restaurant)->for($guest)->create([
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Completed,
        'completed_at' => now()->subHours(3),
    ]);
    $token = hash_hmac('sha256', "guest-survey:{$survey->id}:reservation:{$reservation->id}", (string) config('app.key'));
    $invitation = GuestSurveyInvitation::factory()->for($survey, 'survey')->for($reservation)->create([
        'token_hash' => hash('sha256', $token),
        'delivery_claimed_at' => now()->subMinutes(11),
        'sent_at' => null,
    ]);

    Artisan::call('guest-surveys:send-due');

    expect($invitation->refresh()->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->sent_at)->not->toBeNull();
    Notification::assertSentTo($guest, function (GuestSurveyInvitationNotification $notification) use ($token): bool {
        return (new ReflectionProperty($notification, 'token'))->getValue($notification) === $token
            && $notification instanceof ShouldBeEncrypted;
    });
});

it('does not claim push-only delivery without an Expo token', function (): void {
    Notification::fake();
    $survey = GuestSurvey::factory()->for($this->restaurant)->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now()->subHours(2),
        'send_delay_minutes' => 0,
        'channels' => ['push'],
    ]);
    $guest = User::factory()->create([
        'notify_dining_rating_emails' => false,
        'notify_push_notifications' => true,
    ]);
    Reservation::factory()->for($this->restaurant)->for($guest)->create([
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Completed,
        'completed_at' => now()->subHour(),
    ]);

    Artisan::call('guest-surveys:send-due');

    expect(GuestSurveyInvitation::query()->count())->toBe(0)
        ->and(GuestSurveyInvitationNotification::deliveryChannels($survey, $guest))->toBe([]);
    Notification::assertNothingSent();
});

it('rejects invalid single choice options and deletes only drafts', function (): void {
    postJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys", [
        'title' => 'Bad choices',
        'questions' => [[
            'id' => 'choice',
            'type' => 'single_choice',
            'prompt' => 'Choose',
            'required' => true,
            'options' => ['Same', 'Same'],
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors('questions.0.options');

    $draft = GuestSurvey::factory()->for($this->restaurant)->create();
    $this->deleteJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/{$draft->id}")
        ->assertSuccessful();
    expect(GuestSurvey::query()->find($draft->id))->toBeNull();
});

it('exports survey responses as csv with one column per question', function (): void {
    $survey = GuestSurvey::factory()->for($this->restaurant)->create([
        'questions' => [
            ['id' => 'food', 'type' => 'rating', 'prompt' => 'How would you rate your meal?', 'required' => true, 'options' => []],
            ['id' => 'clean', 'type' => 'yes_no', 'prompt' => 'Was the restaurant clean?', 'required' => true, 'options' => []],
        ],
    ]);
    $guest = GuestContact::factory()->for($this->restaurant)->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
    $reservation = Reservation::factory()->for($this->restaurant)->for($guest, 'guestContact')->create([
        'user_id' => null,
        'starts_at' => '2026-01-05 19:00:00',
    ]);
    $invitation = GuestSurveyInvitation::factory()->for($survey, 'survey')->for($reservation)->create();
    GuestSurveyResponse::factory()->for($invitation, 'invitation')->create([
        'answers' => [
            ['question_id' => 'food', 'value' => 5],
            ['question_id' => 'clean', 'value' => true],
        ],
    ]);

    $response = $this->get("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/{$survey->id}/responses/export");

    $response->assertSuccessful();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');

    $lines = array_filter(explode("\n", trim($response->streamedContent())));
    $rows = array_map(fn (string $line): array => str_getcsv($line), $lines);

    expect($rows[0])->toBe(['Diner', 'Visit Date', 'How would you rate your meal?', 'Was the restaurant clean?', 'Submitted At']);
    expect($rows[1][0])->toBe('Ada Lovelace')
        ->and($rows[1][1])->toBe('2026-01-05')
        ->and($rows[1][2])->toBe('5')
        ->and($rows[1][3])->toBe('Yes');
});

it('denies survey management to users without restaurant permission', function (): void {
    Sanctum::actingAs(User::factory()->create());

    getJson("/api/v1/merchant/restaurants/{$this->restaurant->id}/guest-surveys/templates")
        ->assertForbidden();
});

it('suppresses the competing generic review request when a survey is published', function (): void {
    Notification::fake();
    GuestSurvey::factory()->for($this->restaurant)->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now(),
    ]);
    $guest = User::factory()->create();
    $reservation = Reservation::factory()->for($this->restaurant)->for($guest)->create([
        'restaurant_table_id' => null,
        'status' => ReservationStatus::Seated,
    ]);

    app(ReservationService::class)->completeReservation($reservation, $this->owner);

    Notification::assertNotSentTo($guest, ReservationLifecycleNotification::class);
});

it('loads and submits a survey by opaque token with type validation', function (): void {
    $token = str_repeat('a', 64);
    $survey = GuestSurvey::factory()->for($this->restaurant)->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now(),
        'questions' => [
            ['id' => 'nps', 'type' => 'nps', 'prompt' => 'Recommend us?', 'required' => true, 'options' => []],
            ['id' => 'clean', 'type' => 'yes_no', 'prompt' => 'Was it clean?', 'required' => true, 'options' => []],
        ],
    ]);
    $reservation = Reservation::factory()->for($this->restaurant)->create(['restaurant_table_id' => null]);
    $invitation = GuestSurveyInvitation::factory()->for($survey, 'survey')->for($reservation)->create([
        'token_hash' => hash('sha256', $token),
    ]);

    getJson("/api/v1/guest-surveys/{$token}")
        ->assertSuccessful()
        ->assertJsonPath('survey.questions.0.id', 'nps')
        ->assertJsonMissingPath('token_hash');

    postJson("/api/v1/guest-surveys/{$token}/responses", [
        'answers' => [
            ['question_id' => 'nps', 'value' => 11],
            ['question_id' => 'clean', 'value' => true],
        ],
    ])->assertUnprocessable();

    postJson("/api/v1/guest-surveys/{$token}/responses", [
        'answers' => [
            ['question_id' => 'nps', 'value' => 0],
            ['question_id' => 'clean', 'value' => false],
        ],
    ])->assertCreated();

    expect(GuestSurveyResponse::sole()->guest_survey_invitation_id)->toBe($invitation->id);

    postJson("/api/v1/guest-surveys/{$token}/responses", [
        'answers' => [
            ['question_id' => 'nps', 'value' => 10],
            ['question_id' => 'clean', 'value' => true],
        ],
    ])->assertUnprocessable();
});

it('rejects expired invitation tokens', function (): void {
    $token = str_repeat('b', 64);
    GuestSurveyInvitation::factory()->create([
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->subMinute(),
    ]);

    getJson("/api/v1/guest-surveys/{$token}")
        ->assertNotFound()
        ->assertJsonPath('message', 'Survey invitation not found or expired.');
});

it('rejects whitespace-only required comments and stores trimmed text', function (): void {
    $token = str_repeat('c', 64);
    $survey = GuestSurvey::factory()->for($this->restaurant)->create([
        'questions' => [[
            'id' => 'comments',
            'type' => 'long_text',
            'prompt' => 'Tell us more',
            'required' => true,
            'options' => [],
        ]],
    ]);
    $reservation = Reservation::factory()->for($this->restaurant)->create(['restaurant_table_id' => null]);
    GuestSurveyInvitation::factory()->for($survey, 'survey')->for($reservation)->create([
        'token_hash' => hash('sha256', $token),
    ]);

    postJson("/api/v1/guest-surveys/{$token}/responses", [
        'answers' => [['question_id' => 'comments', 'value' => '   ']],
    ])->assertUnprocessable();
    postJson("/api/v1/guest-surveys/{$token}/responses", [
        'answers' => [['question_id' => 'comments', 'value' => '  Great dinner  ']],
    ])->assertCreated();

    expect(GuestSurveyResponse::sole()->answers[0]['value'])->toBe('Great dinner');
});

it('validates rating bounds and single-choice answers', function (): void {
    $token = str_repeat('d', 64);
    $survey = GuestSurvey::factory()->for($this->restaurant)->create([
        'questions' => [
            ['id' => 'food', 'type' => 'rating', 'prompt' => 'Rate food', 'required' => true, 'options' => []],
            ['id' => 'return', 'type' => 'single_choice', 'prompt' => 'Return?', 'required' => true, 'options' => ['Yes', 'No']],
        ],
    ]);
    $reservation = Reservation::factory()->for($this->restaurant)->create(['restaurant_table_id' => null]);
    GuestSurveyInvitation::factory()->for($survey, 'survey')->for($reservation)->create(['token_hash' => hash('sha256', $token)]);

    postJson("/api/v1/guest-surveys/{$token}/responses", ['answers' => [
        ['question_id' => 'food', 'value' => 0],
        ['question_id' => 'return', 'value' => 'Maybe'],
    ]])->assertUnprocessable();

    postJson("/api/v1/guest-surveys/{$token}/responses", ['answers' => [
        ['question_id' => 'food', 'value' => 5],
        ['question_id' => 'return', 'value' => 'Yes'],
    ]])->assertCreated();
});

it('honors global email suppression for survey invitations', function (): void {
    config(['mail.default' => 'array']);
    $transport = Mail::mailer('array')->getSymfonyTransport();
    $transport->flush();
    EmailUnsubscribe::suppress('skip-survey@example.com');
    $guest = GuestContact::factory()->for($this->restaurant)->create(['email' => 'skip-survey@example.com']);
    $survey = GuestSurvey::factory()->for($this->restaurant)->create(['channels' => ['email']]);
    $reservation = Reservation::factory()->for($this->restaurant)->for($guest, 'guestContact')->create([
        'user_id' => null,
        'restaurant_table_id' => null,
    ]);
    $invitation = GuestSurveyInvitation::factory()->for($survey, 'survey')->for($reservation)->create();

    $guest->notifyNow(new GuestSurveyInvitationNotification($invitation->load('survey.restaurant'), str_repeat('e', 64)));

    expect($transport->messages())->toHaveCount(0);
});

it('sends survey invitation links through WhatsApp', function (): void {
    config()->set('services.whatsapp.base_url', 'https://graph.test');
    config()->set('services.whatsapp.api_version', 'v21.0');
    config()->set('services.whatsapp.phone_number_id', '123456');
    config()->set('services.whatsapp.token', 'test-token');
    config()->set('services.whatsapp.guest_survey_invitation_template', 'guest_survey_invitation');
    Http::fake();

    $token = str_repeat('f', 64);
    $guest = GuestContact::factory()->for($this->restaurant)->create(['phone' => '0801 234 5678']);
    $survey = GuestSurvey::factory()->for($this->restaurant)->create(['channels' => ['whatsapp']]);
    $reservation = Reservation::factory()->for($this->restaurant)->for($guest, 'guestContact')->create([
        'user_id' => null,
        'restaurant_table_id' => null,
    ]);
    $invitation = GuestSurveyInvitation::factory()->for($survey, 'survey')->for($reservation)->create();
    $notification = new GuestSurveyInvitationNotification($invitation->load('survey.restaurant'), $token);

    expect($notification->via($guest))->toBe([WhatsAppChannel::class])
        ->and($notification->toWhatsApp($guest)->templateName)->toBe('guest_survey_invitation')
        ->and($notification->toWhatsApp($guest)->bodyParameters)->toBe([$this->restaurant->name])
        ->and($notification->toWhatsApp($guest)->urlButtonSuffix)->toBe($token);

    app(WhatsAppChannel::class)->send($guest, $notification);

    Http::assertSent(function ($request) use ($token): bool {
        return $request->url() === 'https://graph.test/v21.0/123456/messages'
            && $request['to'] === '2348012345678'
            && $request['template']['name'] === 'guest_survey_invitation'
            && $request['template']['components'][0]['parameters'][0]['text'] === $this->restaurant->name
            && $request['template']['components'][1] === [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [['type' => 'text', 'text' => $token]],
            ];
    });
});

it('respects a registered user’s WhatsApp notification preference for surveys', function (): void {
    $survey = GuestSurvey::factory()->for($this->restaurant)->create(['channels' => ['whatsapp']]);
    $optedIn = User::factory()->make(['phone' => '+2348012345678', 'notify_sms_alerts' => true]);
    $optedOut = User::factory()->make(['phone' => '+2348012345678', 'notify_sms_alerts' => false]);

    expect(GuestSurveyInvitationNotification::deliveryChannels($survey, $optedIn))->toBe([WhatsAppChannel::class])
        ->and(GuestSurveyInvitationNotification::deliveryChannels($survey, $optedOut))->toBe([]);
});
