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
    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'status' => RestaurantStatus::Active,
        'city' => 'Lagos',
        'country' => 'Nigeria',
    ]);

    Reservation::factory()->create([
        'restaurant_id' => $restaurant->id,
        'user_id' => $customer->id,
        'restaurant_table_id' => null,
        'starts_at' => now()->setTime(19, 0),
        'ends_at' => now()->setTime(21, 0),
        'party_size' => 4,
        'metadata' => ['total_amount' => 25000],
    ]);

    OnboardingRequest::factory()->create([
        'restaurant_name' => 'Chicken Republic',
        'owner_name' => 'Ada Owner',
        'status' => OnboardingRequestStatus::Pending,
    ]);

    Sanctum::actingAs($admin);

    $dashboardResponse = $this->getJson('/api/v1/admin/dashboard');

    $dashboardResponse->assertOk()
        ->assertJsonStructure([
            'cards' => [
                'total_restaurants' => ['label', 'value', 'previous_value', 'change', 'change_percentage'],
                'active' => ['label', 'value', 'previous_value', 'change', 'change_percentage'],
                'pending' => ['label', 'value', 'previous_value', 'change', 'change_percentage', 'change_today'],
                'reservations_today' => ['label', 'value', 'previous_value', 'change', 'change_percentage'],
                'weekly_reservations' => ['label', 'value', 'previous_value', 'change', 'change_percentage'],
                'total_diners' => ['label', 'value', 'previous_value', 'change', 'change_percentage'],
                'monthly_revenue' => ['label', 'value', 'previous_value', 'change', 'change_percentage', 'currency'],
                'new_this_month' => ['label', 'value', 'previous_value', 'change', 'change_percentage', 'change_vs_last_month'],
            ],
            'reservation_trends' => [
                'range',
                'ranges' => ['7d', '30d', '90d'],
            ],
            'reservation_growth' => ['range', 'series'],
            'recent_activity',
            'recent_restaurants',
            'overview' => [
                'organizations_count',
                'restaurants_count',
                'users_count',
                'reservations_count',
                'reviews_count',
                'pending_approvals_count',
            ],
            'reservations' => ['upcoming_count', 'today_count', 'by_status'],
        ])
        ->assertJsonPath('cards.total_restaurants.value', 1)
        ->assertJsonPath('cards.active.value', 1)
        ->assertJsonPath('cards.pending.value', 1)
        ->assertJsonPath('cards.reservations_today.value', 1)
        ->assertJsonPath('cards.total_diners.value', 4)
        ->assertJsonPath('cards.monthly_revenue.value', 25000)
        ->assertJsonPath('recent_restaurants.0.name', $restaurant->name);

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

    $indexResponse = $this->getJson('/api/v1/admin/users?search=taylor.admin@example.com&per_page=5');

    $indexResponse->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonFragment(['email' => 'taylor.admin@example.com'])
        ->assertJsonPath('links.prev', null)
        ->assertJsonPath('links.next', null)
        ->assertJsonPath('meta.per_page', 5);

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

it('allows admins to manage customer, merchant, and admin user accounts', function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
    ]);

    Sanctum::actingAs($admin);

    $customerResponse = $this->postJson('/api/v1/admin/users', [
        'first_name' => 'Chioma',
        'last_name' => 'Customer',
        'email' => 'chioma.customer@example.com',
        'phone' => '+2348000000201',
        'account_type' => 'customer',
    ]);

    $customerResponse->assertCreated()
        ->assertJsonPath('user.account_type', 'customer')
        ->assertJsonPath('user.auth_method', 'passwordless')
        ->assertJsonPath('user.roles.0', Role::Customer);

    $merchantResponse = $this->postJson('/api/v1/admin/users', [
        'first_name' => 'Musa',
        'last_name' => 'Merchant',
        'email' => 'musa.merchant@example.com',
        'phone' => '+2348000000202',
        'password' => 'MerchantPass123!',
        'password_confirmation' => 'MerchantPass123!',
        'account_type' => 'merchant',
        'roles' => [Role::Operations],
        'organization_id' => $organization->id,
        'restaurant_id' => $restaurant->id,
    ]);

    $merchantResponse->assertCreated()
        ->assertJsonPath('user.account_type', 'merchant')
        ->assertJsonPath('user.roles.0', Role::Operations)
        ->assertJsonPath('user.role_assignments.0.scope_type', 'restaurant')
        ->assertJsonPath('user.role_assignments.0.restaurant.id', $restaurant->id);

    $merchantId = $merchantResponse->json('user.id');

    $adminUserResponse = $this->postJson('/api/v1/admin/users', [
        'first_name' => 'Tola',
        'last_name' => 'Admin',
        'email' => 'tola.admin@example.com',
        'phone' => '+2348000000203',
        'password' => 'AdminPass123!',
        'password_confirmation' => 'AdminPass123!',
        'account_type' => 'admin',
        'roles' => [Role::BusinessAdmin],
    ]);

    $adminUserResponse->assertCreated()
        ->assertJsonPath('user.account_type', 'admin')
        ->assertJsonPath('user.roles.0', Role::BusinessAdmin);

    $merchantListResponse = $this->getJson('/api/v1/admin/users?account_type=merchant&search=musa.merchant@example.com');

    $merchantListResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'musa.merchant@example.com')
        ->assertJsonPath('data.0.account_type', 'merchant');

    $merchantShowResponse = $this->getJson('/api/v1/admin/users/'.$merchantId);

    $merchantShowResponse->assertOk()
        ->assertJsonPath('data.role_assignments.0.organization.id', $organization->id)
        ->assertJsonPath('data.role_assignments.0.restaurant.id', $restaurant->id);

    $merchantUpdateResponse = $this->patchJson('/api/v1/admin/users/'.$merchantId, [
        'status' => 'suspended',
        'account_type' => 'admin',
        'roles' => [Role::DevAdmin],
    ]);

    $merchantUpdateResponse->assertOk()
        ->assertJsonPath('user.status', 'suspended')
        ->assertJsonPath('user.account_type', 'admin')
        ->assertJsonPath('user.roles.0', Role::DevAdmin)
        ->assertJsonPath('user.role_assignments.0.scope_type', null);

    expect(User::query()->findOrFail($merchantId)->accountType())->toBe('admin');
    expect(User::query()->findOrFail($merchantId)->hasRole(Role::Operations, restaurant: $restaurant))->toBeFalse();
    expect(User::query()->findOrFail($merchantId)->hasRole(Role::DevAdmin))->toBeTrue();

    $invalidCustomerResponse = $this->postJson('/api/v1/admin/users', [
        'first_name' => 'Wrong',
        'last_name' => 'Scope',
        'email' => 'wrong.scope@example.com',
        'account_type' => 'customer',
        'organization_id' => $organization->id,
    ]);

    $invalidCustomerResponse->assertUnprocessable()
        ->assertJsonValidationErrors(['account_type']);

    $deleteResponse = $this->deleteJson('/api/v1/admin/users/'.$merchantId);

    $deleteResponse->assertOk()
        ->assertJsonPath('message', 'User deleted successfully.');
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

    $listResponse = $this->getJson('/api/v1/admin/reservations?restaurant_id='.$restaurant->id.'&per_page=5');

    $listResponse->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonFragment(['id' => $reservationId])
        ->assertJsonPath('links.prev', null)
        ->assertJsonPath('links.next', null)
        ->assertJsonPath('meta.per_page', 5);

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

    $reviewIndexResponse = $this->getJson('/api/v1/admin/reviews?restaurant_id='.$restaurant->id.'&per_page=5');

    $reviewIndexResponse->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonFragment(['id' => $reviewId])
        ->assertJsonPath('links.prev', null)
        ->assertJsonPath('links.next', null)
        ->assertJsonPath('meta.per_page', 5);

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

    $onboardingIndexResponse = $this->getJson('/api/v1/admin/onboarding-requests?status=pending&per_page=5');

    $onboardingIndexResponse->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonFragment(['id' => $onboardingId])
        ->assertJsonPath('links.prev', null)
        ->assertJsonPath('links.next', null)
        ->assertJsonPath('meta.per_page', 5);

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

    $organizationListResponse = $this->getJson('/api/v1/admin/organizations?search='.$organization->slug.'&per_page=5');
    $organizationListResponse->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonFragment(['id' => $organization->id])
        ->assertJsonPath('links.prev', null)
        ->assertJsonPath('links.next', null)
        ->assertJsonPath('meta.per_page', 5);

    $restaurantListResponse = $this->getJson('/api/v1/admin/restaurants?status=suspended&is_featured=1&per_page=5');
    $restaurantListResponse->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonFragment(['id' => $restaurant->id])
        ->assertJsonPath('links.prev', null)
        ->assertJsonPath('links.next', null)
        ->assertJsonPath('meta.per_page', 5);

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
