<?php

use App\Models\OnboardingRequest;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\OnboardingContactReason;
use App\OnboardingJobTitle;
use App\OnboardingLocationCount;
use App\OnboardingRequestStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

it('accepts admin manual lead fields from the dashboard payload', function (): void {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/onboarding-requests', [
        'restaurant_name' => 'Napoli\'s Kitchen',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'phone' => '+2348000000000',
        'job_title' => 'Owner',
        'location_count' => '2-5',
        'contact_reason' => 'restaurant_onboarding',
        'address' => '12 Admiralty Way, Lekki',
        'notes' => 'Optional internal note',
    ]);

    $response->assertCreated()
        ->assertJsonPath('onboarding_request.job_title', OnboardingJobTitle::Owner->value)
        ->assertJsonPath('onboarding_request.location_count', OnboardingLocationCount::TwoToFive->value)
        ->assertJsonPath('onboarding_request.contact_reason', OnboardingContactReason::RestaurantOnboarding->value);
});

it('allows onboard with planned restaurants count greater than submitted restaurants', function (): void {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);
    Sanctum::actingAs($admin);

    $response = $this->post('/api/v1/admin/organizations/onboard', [
        'business_name' => 'Multi Location Group',
        'business_phone' => '+2348000000500',
        'owner_name' => 'Multi Owner',
        'owner_phone' => '+2348000000501',
        'owner_email' => 'multi-owner@example.com',
        'business_email' => 'multi@example.com',
        'business_website' => 'https://multi.example.com',
        'restaurants_count' => 3,
        'restaurants' => [
            [
                'name' => 'First Branch',
                'email' => 'first@multi.example.com',
                'phone' => '+2348000000502',
                'cuisine_type' => 'Nigerian',
                'average_price_range' => '$$',
                'dining_style' => 'Casual Dining',
                'dress_code' => 'Casual',
                'address_line_1' => '12 Marina Road, Lagos',
                'latitude' => 6.4541,
                'longitude' => 3.3947,
                'menu' => [
                    'mode' => 'link',
                    'link' => 'https://multi.example.com/menu',
                ],
            ],
        ],
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('organization.planned_restaurants_count', 3)
        ->assertJsonCount(1, 'restaurants');

    $organization = Organization::query()->where('name', 'Multi Location Group')->first();

    expect($organization?->planned_restaurants_count)->toBe(3)
        ->and($organization?->restaurants()->count())->toBe(1);
});

it('links an onboarding request when onboarding_request_id is provided', function (): void {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);
    Sanctum::actingAs($admin);

    $onboardingRequest = OnboardingRequest::factory()->create([
        'status' => OnboardingRequestStatus::Pending,
    ]);

    $response = $this->post('/api/v1/admin/organizations/onboard', [
        'business_name' => 'Lead Linked Group',
        'business_phone' => '+2348000000600',
        'owner_name' => 'Lead Owner',
        'owner_phone' => '+2348000000601',
        'owner_email' => 'lead-owner@example.com',
        'business_email' => 'lead@example.com',
        'business_website' => 'https://lead.example.com',
        'restaurants_count' => 1,
        'onboarding_request_id' => $onboardingRequest->id,
        'restaurants' => [
            [
                'name' => 'Lead Branch',
                'email' => 'branch@lead.example.com',
                'phone' => '+2348000000602',
                'cuisine_type' => 'Nigerian',
                'average_price_range' => '$$',
                'dining_style' => 'Casual Dining',
                'dress_code' => 'Casual',
                'address_line_1' => '1 Lead Street, Lagos',
                'latitude' => 6.4541,
                'longitude' => 3.3947,
                'menu' => [
                    'mode' => 'link',
                    'link' => 'https://lead.example.com/menu',
                ],
            ],
        ],
    ], ['Accept' => 'application/json']);

    $response->assertCreated();

    $onboardingRequest->refresh();

    expect($onboardingRequest->organization_id)->not->toBeNull()
        ->and($onboardingRequest->status)->toBe(OnboardingRequestStatus::Approved)
        ->and($onboardingRequest->reviewed_by)->toBe($admin->id);
});

it('normalizes legacy location count values on onboarding requests', function (): void {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/onboarding-requests', [
        'first_name' => 'Legacy',
        'last_name' => 'Lead',
        'restaurant_name' => 'Legacy Place',
        'email' => 'legacy@example.com',
        'phone' => '+2348000000700',
        'job_title' => 'general_manager',
        'location_count' => '11-20',
        'contact_reason' => 'general_inquiry',
        'address' => '10 Victoria Island, Lagos',
    ]);

    $response->assertCreated()
        ->assertJsonPath('onboarding_request.location_count', OnboardingLocationCount::ElevenToTwentyFive->value)
        ->assertJsonPath('onboarding_request.contact_reason', OnboardingContactReason::GeneralInquiry->value);
});
