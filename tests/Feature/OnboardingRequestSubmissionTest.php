<?php

use App\Models\OnboardingRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\OnboardingRequestSubmittedNotification;
use App\OnboardingContactReason;
use App\OnboardingJobTitle;
use App\OnboardingLocationCount;
use App\UserStatus;
use Database\Seeders\RoleAndPermissionSeeder;
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

            return $channels === ['mail', 'database']
                && $databasePayload['type'] === 'onboarding_request_submitted'
                && $databasePayload['restaurant_name'] === 'Chidi\'s Bistro'
                && $databasePayload['email'] === 'chidi@bistro.ng';
        },
    );
    Notification::assertNotSentTo($suspendedAdmin, OnboardingRequestSubmittedNotification::class);
    Notification::assertNotSentTo($customer, OnboardingRequestSubmittedNotification::class);
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
