<?php

use App\Jobs\DispatchAdminSurveyJob;
use App\Models\AdminSurveyDispatch;
use App\Models\GuestSurvey;
use App\Models\GuestSurveyInvitation;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GuestSurveyInvitationNotification;
use App\ReservationStatus;
use App\UserAuthMethod;
use App\UserStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->admin = User::factory()->create([
        'auth_method' => UserAuthMethod::Password,
        'status' => UserStatus::Active,
    ]);
    assignScopedRole($this->admin, Role::SuperAdmin);
    Sanctum::actingAs($this->admin);
});

it('lists surveys with pagination', function (): void {
    GuestSurvey::factory()->platform()->count(3)->create(['status' => 'draft']);
    GuestSurvey::factory()->count(2)->create(['status' => 'published', 'publication_sequence' => 1, 'published_at' => now()]);

    getJson('/api/v1/admin/surveys')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'scope', 'title', 'status', 'questions']], 'meta']);
});

it('filters surveys by scope', function (): void {
    GuestSurvey::factory()->platform()->count(2)->create();
    GuestSurvey::factory()->count(3)->create();

    getJson('/api/v1/admin/surveys?scope=platform')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters surveys by status', function (): void {
    GuestSurvey::factory()->platform()->count(2)->create(['status' => 'draft']);
    GuestSurvey::factory()->platform()->create(['status' => 'published', 'publication_sequence' => 1, 'published_at' => now()]);

    getJson('/api/v1/admin/surveys?status=draft')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('creates a platform-scoped survey', function (): void {
    postJson('/api/v1/admin/surveys', [
        'scope' => 'platform',
        'title' => 'Customer Satisfaction',
        'description' => 'Tell us how we are doing.',
        'channels' => ['email', 'push'],
        'questions' => [
            ['id' => 'rating', 'type' => 'rating', 'prompt' => 'Overall rating?', 'required' => true, 'options' => []],
        ],
    ])->assertCreated()
        ->assertJsonPath('survey.scope', 'platform')
        ->assertJsonPath('survey.status', 'draft')
        ->assertJsonPath('survey.title', 'Customer Satisfaction');

    expect(GuestSurvey::where('scope', 'platform')->count())->toBe(1);
});

it('creates a restaurant-scoped survey via admin', function (): void {
    $org = Organization::factory()->create();
    $restaurant = Restaurant::factory()->for($org)->create();

    postJson('/api/v1/admin/surveys', [
        'scope' => 'restaurant',
        'restaurant_id' => $restaurant->id,
        'title' => 'Post Dining',
        'channels' => ['email'],
        'questions' => [
            ['id' => 'food', 'type' => 'rating', 'prompt' => 'Rate the food.', 'required' => true, 'options' => []],
        ],
    ])->assertCreated()
        ->assertJsonPath('survey.scope', 'restaurant');
});

it('requires restaurant_id when scope is restaurant', function (): void {
    postJson('/api/v1/admin/surveys', [
        'scope' => 'restaurant',
        'title' => 'Missing Restaurant',
        'questions' => [],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['restaurant_id']);
});

it('shows a single survey', function (): void {
    $survey = GuestSurvey::factory()->platform()->create();

    getJson("/api/v1/admin/surveys/{$survey->id}")
        ->assertOk()
        ->assertJsonPath('survey.id', $survey->id)
        ->assertJsonPath('survey.scope', 'platform');
});

it('includes dispatch history on survey show', function (): void {
    $survey = GuestSurvey::factory()->platform()->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now(),
    ]);

    AdminSurveyDispatch::query()->create([
        'guest_survey_id' => $survey->id,
        'status' => 'dispatched',
        'recipients_count' => 12,
        'dispatched_at' => now(),
    ]);

    getJson("/api/v1/admin/surveys/{$survey->id}")
        ->assertOk()
        ->assertJsonPath('survey.dispatches.0.status', 'dispatched')
        ->assertJsonPath('survey.dispatches.0.recipients_count', 12);
});

it('updates a draft survey', function (): void {
    $survey = GuestSurvey::factory()->platform()->create(['title' => 'Old Title']);

    patchJson("/api/v1/admin/surveys/{$survey->id}", ['title' => 'New Title'])
        ->assertOk()
        ->assertJsonPath('survey.title', 'New Title');
});

it('rejects updating a published survey', function (): void {
    $survey = GuestSurvey::factory()->platform()->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now(),
    ]);

    patchJson("/api/v1/admin/surveys/{$survey->id}", ['title' => 'Changed'])
        ->assertUnprocessable();
});

it('publishes a draft survey', function (): void {
    $survey = GuestSurvey::factory()->platform()->create([
        'questions' => [
            ['id' => 'q1', 'type' => 'rating', 'prompt' => 'Rate us.', 'required' => true, 'options' => []],
        ],
    ]);

    postJson("/api/v1/admin/surveys/{$survey->id}/publish")
        ->assertOk()
        ->assertJsonPath('survey.status', 'published');

    expect($survey->refresh()->status)->toBe('published');
});

it('rejects publishing a survey with no questions', function (): void {
    $survey = GuestSurvey::factory()->platform()->create(['questions' => []]);

    postJson("/api/v1/admin/surveys/{$survey->id}/publish")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['questions']);
});

it('dispatches a published survey immediately', function (): void {
    Queue::fake();

    $survey = GuestSurvey::factory()->platform()->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now(),
    ]);

    postJson("/api/v1/admin/surveys/{$survey->id}/send")
        ->assertStatus(202)
        ->assertJsonPath('dispatch.status', 'pending');

    Queue::assertPushed(DispatchAdminSurveyJob::class);
    expect(AdminSurveyDispatch::where('guest_survey_id', $survey->id)->count())->toBe(1);
});

it('schedules a survey dispatch for a future time', function (): void {
    Queue::fake();

    $survey = GuestSurvey::factory()->platform()->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now(),
    ]);

    $scheduledAt = now()->addDay()->toIso8601String();

    postJson("/api/v1/admin/surveys/{$survey->id}/send", ['scheduled_at' => $scheduledAt])
        ->assertStatus(202)
        ->assertJsonPath('dispatch.status', 'pending')
        ->assertJsonStructure(['dispatch' => ['scheduled_at']]);

    Queue::assertPushed(DispatchAdminSurveyJob::class);

    $dispatch = AdminSurveyDispatch::where('guest_survey_id', $survey->id)->first();
    expect($dispatch?->scheduled_at)->not->toBeNull();
});

it('rejects sending a scheduled_at in the past', function (): void {
    $survey = GuestSurvey::factory()->platform()->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now(),
    ]);

    postJson("/api/v1/admin/surveys/{$survey->id}/send", [
        'scheduled_at' => now()->subHour()->toIso8601String(),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['scheduled_at']);
});

it('rejects sending an unpublished survey', function (): void {
    $survey = GuestSurvey::factory()->platform()->create(['status' => 'draft']);

    postJson("/api/v1/admin/surveys/{$survey->id}/send")
        ->assertUnprocessable();
});

it('sends platform survey invitation emails without a restaurant', function (): void {
    $survey = GuestSurvey::factory()->platform()->create([
        'title' => 'Platform Feedback',
        'channels' => ['email'],
    ]);
    $user = User::factory()->create([
        'notify_dining_rating_emails' => true,
        'email' => 'platform-guest@example.com',
    ]);
    $token = str_repeat('a', 64);
    $invitation = GuestSurveyInvitation::factory()->create([
        'guest_survey_id' => $survey->id,
        'user_id' => $user->id,
        'reservation_id' => null,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(30),
    ]);

    $mail = (new GuestSurveyInvitationNotification($invitation->load('survey'), $token))
        ->toMail($user);

    expect($mail->subject)->toBe('Platform Feedback');
});

it('marks guest survey invitations as sent after notification delivery', function (): void {
    $survey = GuestSurvey::factory()->platform()->create(['channels' => ['email']]);
    $user = User::factory()->create(['email' => 'listener-test@example.com']);
    $token = str_repeat('b', 64);
    $invitation = GuestSurveyInvitation::factory()->create([
        'guest_survey_id' => $survey->id,
        'user_id' => $user->id,
        'reservation_id' => null,
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(30),
        'sent_at' => null,
    ]);

    $notification = new GuestSurveyInvitationNotification($invitation->load('survey'), $token);

    event(new NotificationSent($user, $notification, 'mail'));

    expect($invitation->refresh()->sent_at)->not->toBeNull();
});

it('sends restaurant-scoped surveys only to that restaurant guests', function (): void {
    Notification::fake();

    $org = Organization::factory()->create();
    $restaurant = Restaurant::factory()->for($org)->create();
    $otherRestaurant = Restaurant::factory()->for($org)->create();

    $targetGuest = User::factory()->create([
        'status' => UserStatus::Active,
        'notify_dining_rating_emails' => true,
        'email' => 'target-guest@example.com',
    ]);
    $otherGuest = User::factory()->create([
        'status' => UserStatus::Active,
        'notify_dining_rating_emails' => true,
        'email' => 'other-guest@example.com',
    ]);

    Reservation::factory()->for($restaurant)->for($targetGuest)->create([
        'status' => ReservationStatus::Completed,
        'completed_at' => now()->subHour(),
    ]);
    Reservation::factory()->for($otherRestaurant)->for($otherGuest)->create([
        'status' => ReservationStatus::Completed,
        'completed_at' => now()->subHour(),
    ]);

    $survey = GuestSurvey::factory()->create([
        'scope' => 'restaurant',
        'restaurant_id' => $restaurant->id,
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now(),
        'channels' => ['email'],
    ]);

    $dispatch = AdminSurveyDispatch::query()->create([
        'guest_survey_id' => $survey->id,
        'status' => 'pending',
    ]);

    (new DispatchAdminSurveyJob($survey->id, $dispatch->id))->handle();

    Notification::assertSentTo($targetGuest, GuestSurveyInvitationNotification::class);
    Notification::assertNotSentTo($otherGuest, GuestSurveyInvitationNotification::class);

    expect($dispatch->refresh())
        ->status->toBe('dispatched')
        ->recipients_count->toBe(1);
});

it('deletes an unreferenced draft survey', function (): void {
    $survey = GuestSurvey::factory()->platform()->create(['status' => 'draft']);

    deleteJson("/api/v1/admin/surveys/{$survey->id}")
        ->assertOk();

    expect(GuestSurvey::find($survey->id))->toBeNull();
});

it('rejects deleting a published survey', function (): void {
    $survey = GuestSurvey::factory()->platform()->create([
        'status' => 'published',
        'publication_sequence' => 1,
        'published_at' => now(),
    ]);

    deleteJson("/api/v1/admin/surveys/{$survey->id}")
        ->assertUnprocessable();
});

it('returns 401 for unauthenticated access', function (): void {
    auth()->guard('sanctum')->forgetUser();

    getJson('/api/v1/admin/surveys')
        ->assertUnauthorized();
});

it('returns 403 for non-admin users', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $user = User::factory()->create(['status' => UserStatus::Active]);
    Sanctum::actingAs($user);

    getJson('/api/v1/admin/surveys')
        ->assertForbidden();
});
