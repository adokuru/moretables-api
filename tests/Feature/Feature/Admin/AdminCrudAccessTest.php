<?php

use App\Models\OnboardingRequest;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\Models\Role;
use App\Models\User;
use App\OnboardingRequestStatus;
use App\ReservationSource;
use App\ReservationStatus;
use App\RestaurantStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('allows admins to view dashboard metrics and manage users', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $customer = User::factory()->create([
        'email' => 'customer@example.com',
    ]);

    Sanctum::actingAs($admin);

    $dashboardResponse = $this->getJson('/api/v1/admin/dashboard');

    $dashboardResponse->assertOk()
        ->assertJsonStructure([
            'overview' => [
                'organizations_count',
                'restaurants_count',
                'users_count',
                'reservations_count',
                'reviews_count',
                'pending_approvals_count',
            ],
            'reservations' => ['upcoming_count', 'today_count', 'by_status'],
        ]);

    $createResponse = $this->postJson('/api/v1/admin/users', [
        'first_name' => 'Taylor',
        'last_name' => 'Admin',
        'email' => 'taylor.admin@example.com',
        'phone' => '+2348000000101',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'status' => 'active',
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('user.email', 'taylor.admin@example.com');

    $createdUserId = $createResponse->json('user.id');

    $indexResponse = $this->getJson('/api/v1/admin/users?search=taylor.admin@example.com');

    $indexResponse->assertOk()
        ->assertJsonFragment(['email' => 'taylor.admin@example.com']);

    $showResponse = $this->getJson('/api/v1/admin/users/'.$customer->id);

    $showResponse->assertOk()
        ->assertJsonPath('data.email', 'customer@example.com');

    $updateResponse = $this->patchJson('/api/v1/admin/users/'.$createdUserId, [
        'status' => 'suspended',
        'phone' => '+2348000000999',
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('user.status', 'suspended')
        ->assertJsonPath('user.phone', '+2348000000999');

    $deleteResponse = $this->deleteJson('/api/v1/admin/users/'.$createdUserId);

    $deleteResponse->assertOk()
        ->assertJsonPath('message', 'User deleted successfully.');

    expect(User::query()->whereKey($createdUserId)->exists())->toBeFalse();
});

it('allows admins to manage reservations and reservation analytics', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    ['organization' => $organization, 'restaurant' => $restaurant, 'table' => $table] = createBookableRestaurant();
    $guest = User::factory()->create();

    Sanctum::actingAs($admin);

    $createResponse = $this->postJson('/api/v1/admin/reservations', [
        'restaurant_id' => $restaurant->id,
        'user_id' => $guest->id,
        'restaurant_table_id' => $table->id,
        'source' => ReservationSource::Staff->value,
        'status' => ReservationStatus::Confirmed->value,
        'party_size' => 3,
        'starts_at' => now()->addDay()->toIso8601String(),
        'ends_at' => now()->addDay()->addHours(2)->toIso8601String(),
        'notes' => 'VIP guest',
        'internal_notes' => 'Window table preferred',
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('reservation.status', ReservationStatus::Confirmed->value)
        ->assertJsonPath('reservation.restaurant_id', $restaurant->id);

    $reservationId = $createResponse->json('reservation.id');

    $listResponse = $this->getJson('/api/v1/admin/reservations?restaurant_id='.$restaurant->id);

    $listResponse->assertOk()
        ->assertJsonFragment(['id' => $reservationId]);

    $showResponse = $this->getJson('/api/v1/admin/reservations/'.$reservationId);

    $showResponse->assertOk()
        ->assertJsonPath('data.id', $reservationId);

    $updateResponse = $this->patchJson('/api/v1/admin/reservations/'.$reservationId, [
        'status' => ReservationStatus::Cancelled->value,
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('reservation.status', ReservationStatus::Cancelled->value);

    $analyticsResponse = $this->getJson('/api/v1/admin/reservations/analytics');

    $analyticsResponse->assertOk()
        ->assertJsonPath('analytics.by_status.cancelled', 1);

    $deleteResponse = $this->deleteJson('/api/v1/admin/reservations/'.$reservationId);

    $deleteResponse->assertOk()
        ->assertJsonPath('message', 'Reservation deleted successfully.');

    expect(Reservation::query()->whereKey($reservationId)->exists())->toBeFalse();
});

it('allows admins to manage reviews and onboarding approvals', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::DevAdmin);

    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'status' => RestaurantStatus::Active,
    ]);
    $reviewer = User::factory()->create();

    Sanctum::actingAs($admin);

    $reviewResponse = $this->postJson('/api/v1/admin/reviews', [
        'restaurant_id' => $restaurant->id,
        'user_id' => $reviewer->id,
        'rating' => 4,
        'title' => 'Solid dinner',
        'body' => 'Friendly service and nice ambience.',
        'visited_at' => now()->subDay()->toDateString(),
    ]);

    $reviewResponse->assertCreated()
        ->assertJsonPath('review.rating', 4);

    $reviewId = $reviewResponse->json('review.id');

    $reviewIndexResponse = $this->getJson('/api/v1/admin/reviews?restaurant_id='.$restaurant->id);

    $reviewIndexResponse->assertOk()
        ->assertJsonFragment(['id' => $reviewId]);

    $reviewUpdateResponse = $this->patchJson('/api/v1/admin/reviews/'.$reviewId, [
        'rating' => 5,
        'title' => 'Excellent dinner',
    ]);

    $reviewUpdateResponse->assertOk()
        ->assertJsonPath('review.rating', 5)
        ->assertJsonPath('review.title', 'Excellent dinner');

    $onboardingCreateResponse = $this->postJson('/api/v1/admin/onboarding-requests', [
        'restaurant_name' => 'Pending Place',
        'owner_name' => 'Ada Owner',
        'email' => 'pending@example.com',
        'phone' => '+2348000000123',
        'address' => '12 Marina, Lagos',
        'notes' => 'Needs fast review',
    ]);

    $onboardingCreateResponse->assertCreated()
        ->assertJsonPath('onboarding_request.status', OnboardingRequestStatus::Pending->value);

    $onboardingId = $onboardingCreateResponse->json('onboarding_request.id');

    $onboardingIndexResponse = $this->getJson('/api/v1/admin/onboarding-requests?status=pending');

    $onboardingIndexResponse->assertOk()
        ->assertJsonFragment(['id' => $onboardingId]);

    $onboardingUpdateResponse = $this->patchJson('/api/v1/admin/onboarding-requests/'.$onboardingId, [
        'status' => OnboardingRequestStatus::Approved->value,
    ]);

    $onboardingUpdateResponse->assertOk()
        ->assertJsonPath('onboarding_request.status', OnboardingRequestStatus::Approved->value)
        ->assertJsonPath('onboarding_request.reviewed_by.id', $admin->id);

    $reviewDeleteResponse = $this->deleteJson('/api/v1/admin/reviews/'.$reviewId);

    $reviewDeleteResponse->assertOk();

    $onboardingDeleteResponse = $this->deleteJson('/api/v1/admin/onboarding-requests/'.$onboardingId);

    $onboardingDeleteResponse->assertOk();

    expect(RestaurantReview::query()->whereKey($reviewId)->exists())->toBeFalse();
    expect(OnboardingRequest::query()->whereKey($onboardingId)->exists())->toBeFalse();
});

it('allows admins to delete organizations and restaurants from the admin surface', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create([
        'status' => 'active',
    ]);
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'status' => RestaurantStatus::Suspended,
        'is_featured' => true,
    ]);

    Sanctum::actingAs($admin);

    $organizationListResponse = $this->getJson('/api/v1/admin/organizations?search='.$organization->slug);
    $organizationListResponse->assertOk()
        ->assertJsonFragment(['id' => $organization->id]);

    $restaurantListResponse = $this->getJson('/api/v1/admin/restaurants?status=suspended&is_featured=1');
    $restaurantListResponse->assertOk()
        ->assertJsonFragment(['id' => $restaurant->id]);

    $restaurantDeleteResponse = $this->deleteJson('/api/v1/admin/restaurants/'.$restaurant->id);
    $restaurantDeleteResponse->assertOk();

    expect(Restaurant::query()->whereKey($restaurant->id)->exists())->toBeFalse();

    $organizationDeleteResponse = $this->deleteJson('/api/v1/admin/organizations/'.$organization->id);
    $organizationDeleteResponse->assertOk();

    expect(Organization::query()->whereKey($organization->id)->exists())->toBeFalse();
});

it('forbids non admins from the new admin crud endpoints', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $customer = User::factory()->create();
    assignScopedRole($customer, Role::Customer);

    Sanctum::actingAs($customer);

    $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    $this->getJson('/api/v1/admin/users')->assertForbidden();
    $this->getJson('/api/v1/admin/reservations')->assertForbidden();
    $this->getJson('/api/v1/admin/reviews')->assertForbidden();
    $this->getJson('/api/v1/admin/onboarding-requests')->assertForbidden();
});
