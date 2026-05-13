<?php

use App\AuthChallengeType;
use App\Enums\RestaurantOnboardingStep;
use App\Models\AuthChallenge;
use App\Models\CuisineOption;
use App\Models\RestaurantMealSchedule;
use App\Models\RestaurantMealType;
use App\Models\RestaurantSocialHandle;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// ── Helpers ──────────────────────────────────────────────────────────────────

function obUrl(int $restaurantId, string $suffix = ''): string
{
    return "/api/v1/merchant/restaurants/{$restaurantId}/onboarding{$suffix}";
}

function marketingActor(array $data): User
{
    $user = User::factory()->create();
    assignScopedRole($user, Role::MarketingGrowth, $data['organization'], $data['restaurant']);

    return $user;
}

// ── Contact / Cuisine / Price ─────────────────────────────────────────────────

it('updates contact, cuisine and social handles for a restaurant', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $actor = marketingActor($data);

    $primary = CuisineOption::factory()->create();
    $extra = CuisineOption::factory()->create();

    Sanctum::actingAs($actor);

    $response = $this->patchJson(obUrl($data['restaurant']->id, '/contact-cuisine-price'), [
        'email' => 'hello@restaurant.com',
        'phone' => '+2348000000001',
        'website' => 'https://restaurant.com',
        'average_price_range' => '$$',
        'primary_cuisine_option_id' => $primary->id,
        'additional_cuisine_option_ids' => [$extra->id],
        'social_handles' => [
            ['platform' => 'instagram', 'handle' => '@myrestaurant'],
            ['platform' => 'twitter', 'handle' => '@myrestaurant'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('restaurant.email', 'hello@restaurant.com')
        ->assertJsonPath('restaurant.phone', '+2348000000001')
        ->assertJsonPath('restaurant.website', 'https://restaurant.com')
        ->assertJsonPath('restaurant.average_price_range', '$$')
        ->assertJsonCount(2, 'restaurant.cuisines')
        ->assertJsonCount(2, 'restaurant.social_handles');

    $primaryInResponse = collect($response->json('restaurant.cuisines'))->firstWhere('id', $primary->id);
    expect($primaryInResponse['is_primary'])->toBeTrue();

    $this->assertDatabaseHas('cuisine_option_restaurant', [
        'restaurant_id' => $data['restaurant']->id,
        'cuisine_option_id' => $primary->id,
        'is_primary' => true,
    ]);

    $this->assertDatabaseHas('restaurant_social_handles', [
        'restaurant_id' => $data['restaurant']->id,
        'platform' => 'instagram',
        'handle' => '@myrestaurant',
    ]);
});

it('replaces social handles on a subsequent call', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $actor = marketingActor($data);

    RestaurantSocialHandle::create([
        'restaurant_id' => $data['restaurant']->id,
        'platform' => 'instagram',
        'handle' => '@old',
    ]);

    Sanctum::actingAs($actor);

    $this->patchJson(obUrl($data['restaurant']->id, '/contact-cuisine-price'), [
        'social_handles' => [
            ['platform' => 'tiktok', 'handle' => '@new'],
        ],
    ])->assertOk();

    $this->assertDatabaseMissing('restaurant_social_handles', [
        'restaurant_id' => $data['restaurant']->id,
        'platform' => 'instagram',
    ]);
    $this->assertDatabaseHas('restaurant_social_handles', [
        'restaurant_id' => $data['restaurant']->id,
        'platform' => 'tiktok',
        'handle' => '@new',
    ]);
});

it('rejects a non-existent cuisine_option_id', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $this->patchJson(obUrl($data['restaurant']->id, '/contact-cuisine-price'), [
        'primary_cuisine_option_id' => 99999,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('primary_cuisine_option_id');
});

it('forbids operations staff from updating contact cuisine price', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $ops = User::factory()->create();
    assignScopedRole($ops, Role::Operations, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($ops);

    $this->patchJson(obUrl($data['restaurant']->id, '/contact-cuisine-price'), [
        'email' => 'blocked@example.com',
    ])->assertForbidden();
});

// ── Profile Photo ─────────────────────────────────────────────────────────────

it('uploads a profile photo, adding to gallery and setting as featured', function () {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $response = $this->post(obUrl($data['restaurant']->id, '/profile-photo'), [
        'photo' => UploadedFile::fake()->image('profile.jpg'),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'featured_image', 'gallery_image']);

    $restaurant = $data['restaurant']->refresh();
    expect($restaurant->getFirstMedia('featured'))->not->toBeNull();
    expect($restaurant->getMedia('gallery'))->toHaveCount(1);
});

it('rejects photo uploads larger than 15MB', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $big = UploadedFile::fake()->image('big.jpg')->size(16000);

    $this->post(obUrl($data['restaurant']->id, '/profile-photo'), [
        'photo' => $big,
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('photo');
});

it('rejects non-image uploads for profile photo', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $this->post(obUrl($data['restaurant']->id, '/profile-photo'), [
        'photo' => UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('photo');
});

// ── Description ───────────────────────────────────────────────────────────────

it('updates the restaurant description', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $description = trim(str_repeat('A vibrant restaurant. ', 5)); // ≥100 chars

    $this->patchJson(obUrl($data['restaurant']->id, '/description'), [
        'description' => $description,
    ])->assertOk()
        ->assertJsonPath('description', $description);

    $this->assertDatabaseHas('restaurants', [
        'id' => $data['restaurant']->id,
        'description' => $description,
    ]);
});

it('rejects descriptions shorter than 100 characters', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $this->patchJson(obUrl($data['restaurant']->id, '/description'), [
        'description' => 'Too short.',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('description');
});

it('rejects descriptions longer than 1000 characters', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $this->patchJson(obUrl($data['restaurant']->id, '/description'), [
        'description' => str_repeat('A', 1001),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('description');
});

// ── Meal Types ────────────────────────────────────────────────────────────────

it('creates a meal type and auto-assigns sort order', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $response = $this->postJson(obUrl($data['restaurant']->id, '/meal-types'), [
        'name' => 'Lunch',
    ]);

    $response->assertCreated()
        ->assertJsonPath('meal_type.name', 'Lunch')
        ->assertJsonPath('meal_type.sort_order', 1);

    $this->assertDatabaseHas('restaurant_meal_types', [
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Lunch',
    ]);
});

it('lists meal types with their schedules', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $mealType = RestaurantMealType::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Dinner',
        'sort_order' => 1,
    ]);
    RestaurantMealSchedule::create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_meal_type_id' => $mealType->id,
        'day_of_week' => 1,
        'opens_at' => '18:00',
        'closes_at' => '22:00',
    ]);

    $ops = User::factory()->create();
    assignScopedRole($ops, Role::Operations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($ops);

    $response = $this->getJson(obUrl($data['restaurant']->id, '/meal-types'));

    $response->assertOk()
        ->assertJsonCount(1, 'meal_types')
        ->assertJsonPath('meal_types.0.name', 'Dinner')
        ->assertJsonCount(1, 'meal_types.0.schedules');
});

it('updates a meal type name', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $mealType = RestaurantMealType::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Dinner',
    ]);
    Sanctum::actingAs(marketingActor($data));

    $this->patchJson(obUrl($data['restaurant']->id, "/meal-types/{$mealType->id}"), [
        'name' => 'Late Night',
    ])->assertOk()
        ->assertJsonPath('meal_type.name', 'Late Night');
});

it('deletes a meal type that has no schedules', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $mealType = RestaurantMealType::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Brunch',
    ]);
    Sanctum::actingAs(marketingActor($data));

    $this->deleteJson(obUrl($data['restaurant']->id, "/meal-types/{$mealType->id}"))
        ->assertOk();

    $this->assertDatabaseMissing('restaurant_meal_types', ['id' => $mealType->id]);
});

it('blocks deleting a meal type that has schedules', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $mealType = RestaurantMealType::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Lunch',
    ]);
    RestaurantMealSchedule::create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_meal_type_id' => $mealType->id,
        'day_of_week' => 0,
        'opens_at' => '12:00',
        'closes_at' => '15:00',
    ]);
    Sanctum::actingAs(marketingActor($data));

    $this->deleteJson(obUrl($data['restaurant']->id, "/meal-types/{$mealType->id}"))
        ->assertUnprocessable();

    $this->assertDatabaseHas('restaurant_meal_types', ['id' => $mealType->id]);
});

it('returns 404 when a meal type belongs to a different restaurant', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $other = createBookableRestaurant();
    $foreignMealType = RestaurantMealType::factory()->create([
        'restaurant_id' => $other['restaurant']->id,
        'name' => 'Dinner',
    ]);
    Sanctum::actingAs(marketingActor($data));

    $this->patchJson(obUrl($data['restaurant']->id, "/meal-types/{$foreignMealType->id}"), [
        'name' => 'Brunch',
    ])->assertNotFound();
});

// ── Business Hours ────────────────────────────────────────────────────────────

it('replaces all meal schedules via PUT business-hours', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $lunch = RestaurantMealType::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Lunch',
    ]);
    $dinner = RestaurantMealType::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Dinner',
    ]);

    // Pre-existing schedule to be replaced
    RestaurantMealSchedule::create([
        'restaurant_id' => $data['restaurant']->id,
        'restaurant_meal_type_id' => $lunch->id,
        'day_of_week' => 0,
        'opens_at' => '09:00',
        'closes_at' => '11:00',
    ]);

    Sanctum::actingAs(marketingActor($data));

    $response = $this->putJson(obUrl($data['restaurant']->id, '/business-hours'), [
        'schedules' => [
            ['restaurant_meal_type_id' => $lunch->id, 'day_of_week' => 1, 'opens_at' => '12:00', 'closes_at' => '15:00'],
            ['restaurant_meal_type_id' => $dinner->id, 'day_of_week' => 1, 'opens_at' => '18:00', 'closes_at' => '22:00'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonCount(2, 'meal_types');

    // Old schedule on day 0 should be gone
    $this->assertDatabaseMissing('restaurant_meal_schedules', [
        'restaurant_id' => $data['restaurant']->id,
        'day_of_week' => 0,
    ]);

    expect(RestaurantMealSchedule::where('restaurant_id', $data['restaurant']->id)->count())->toBe(2);
});

it('rejects schedules referencing meal types from another restaurant', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $other = createBookableRestaurant();
    $foreignMealType = RestaurantMealType::factory()->create([
        'restaurant_id' => $other['restaurant']->id,
        'name' => 'Dinner',
    ]);
    Sanctum::actingAs(marketingActor($data));

    $this->putJson(obUrl($data['restaurant']->id, '/business-hours'), [
        'schedules' => [
            ['restaurant_meal_type_id' => $foreignMealType->id, 'day_of_week' => 1, 'opens_at' => '18:00', 'closes_at' => '22:00'],
        ],
    ])->assertUnprocessable();
});

it('validates closes_at must be after opens_at in business hours', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $mealType = RestaurantMealType::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
    ]);
    Sanctum::actingAs(marketingActor($data));

    $this->putJson(obUrl($data['restaurant']->id, '/business-hours'), [
        'schedules' => [
            ['restaurant_meal_type_id' => $mealType->id, 'day_of_week' => 1, 'opens_at' => '22:00', 'closes_at' => '18:00'],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('schedules.0.closes_at');
});

// ── Onboarding Status ─────────────────────────────────────────────────────────

it('returns onboarding status with empty progress for a fresh restaurant', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $response = $this->getJson(obUrl($data['restaurant']->id, '/status'));

    $response->assertOk()
        ->assertJsonStructure(['current_step', 'is_profile_published', 'progress'])
        ->assertJsonPath('is_profile_published', false)
        ->assertJsonPath('current_step', null);
});

it('marks a step completed and advances current_step', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $response = $this->patchJson(obUrl($data['restaurant']->id, '/status'), [
        'step' => RestaurantOnboardingStep::Cuisine->value,
        'step_status' => 'completed',
        'current_step' => RestaurantOnboardingStep::Hours->value,
    ]);

    $response->assertOk()
        ->assertJsonPath('current_step', RestaurantOnboardingStep::Hours->value)
        ->assertJsonPath('progress.'.RestaurantOnboardingStep::Cuisine->value, 'completed');

    $this->assertDatabaseHas('restaurants', [
        'id' => $data['restaurant']->id,
        'onboarding_current_step' => RestaurantOnboardingStep::Hours->value,
    ]);
});

it('persists multiple step updates across calls', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $url = obUrl($data['restaurant']->id, '/status');

    $this->patchJson($url, [
        'step' => RestaurantOnboardingStep::Basics->value,
        'step_status' => 'completed',
    ])->assertOk();

    $this->patchJson($url, [
        'step' => RestaurantOnboardingStep::Cuisine->value,
        'step_status' => 'skipped',
    ])->assertOk();

    $status = $this->getJson($url)->json('progress');

    expect($status[RestaurantOnboardingStep::Basics->value])->toBe('completed');
    expect($status[RestaurantOnboardingStep::Cuisine->value])->toBe('skipped');
});

it('publishes the profile via status update', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $this->patchJson(obUrl($data['restaurant']->id, '/status'), [
        'is_profile_published' => true,
    ])->assertOk()
        ->assertJsonPath('is_profile_published', true);

    $this->assertDatabaseHas('restaurants', [
        'id' => $data['restaurant']->id,
        'is_profile_published' => true,
    ]);
});

it('rejects an invalid step enum value in status update', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $this->patchJson(obUrl($data['restaurant']->id, '/status'), [
        'step' => 'not_a_real_step',
        'step_status' => 'completed',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('step');
});

it('forbids unauthenticated requests to all onboarding endpoints', function () {
    $data = createBookableRestaurant();
    $id = $data['restaurant']->id;

    $this->getJson(obUrl($id, '/status'))->assertUnauthorized();
    $this->patchJson(obUrl($id, '/status'), [])->assertUnauthorized();
    $this->patchJson(obUrl($id, '/contact-cuisine-price'), [])->assertUnauthorized();
    $this->patchJson(obUrl($id, '/description'), [])->assertUnauthorized();
    $this->putJson(obUrl($id, '/business-hours'), [])->assertUnauthorized();
    $this->getJson(obUrl($id, '/meal-types'))->assertUnauthorized();
    $this->postJson(obUrl($id, '/meal-types'), [])->assertUnauthorized();
});

// ── Email verification ────────────────────────────────────────────────────────

it('sends a verification code to the restaurant contact email', function () {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $response = $this->postJson(obUrl($data['restaurant']->id, '/contact-email/send-code'), [
        'email' => 'contact@restaurant.com',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'challenge_token']);

    $this->assertDatabaseHas('auth_challenges', [
        'user_id' => User::query()->latest()->first()->id ?? null,
        'type' => AuthChallengeType::RestaurantEmailVerification->value,
        'status' => 'pending',
    ]);

    Notification::assertSentOnDemand(
        \App\Notifications\AuthChallengeCodeNotification::class,
        fn ($n, $channels, $notifiable) => ($notifiable->routes['mail'] ?? null) === 'contact@restaurant.com',
    );
});

it('verifies the code and stamps restaurant email and verified_at', function () {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $actor = marketingActor($data);
    Sanctum::actingAs($actor);

    $sendResponse = $this->postJson(obUrl($data['restaurant']->id, '/contact-email/send-code'), [
        'email' => 'verified@restaurant.com',
    ]);

    $sendResponse->assertCreated();
    $challengeToken = $sendResponse->json('challenge_token');

    $verifyResponse = $this->postJson(obUrl($data['restaurant']->id, '/contact-email/verify'), [
        'challenge_token' => $challengeToken,
        'code' => '1234', // test environment always generates 1234
    ]);

    $verifyResponse->assertOk()
        ->assertJsonPath('email', 'verified@restaurant.com')
        ->assertJsonStructure(['message', 'email', 'contact_email_verified_at']);

    $this->assertDatabaseHas('restaurants', [
        'id' => $data['restaurant']->id,
        'email' => 'verified@restaurant.com',
    ]);

    expect($data['restaurant']->refresh()->contact_email_verified_at)->not->toBeNull();
});

it('rejects an incorrect verification code', function () {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $sendResponse = $this->postJson(obUrl($data['restaurant']->id, '/contact-email/send-code'), [
        'email' => 'contact@restaurant.com',
    ]);

    $challengeToken = $sendResponse->json('challenge_token');

    $this->postJson(obUrl($data['restaurant']->id, '/contact-email/verify'), [
        'challenge_token' => $challengeToken,
        'code' => '0000',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('code');
});

it('cancels the previous pending challenge when a new send-code is requested', function () {
    Notification::fake();
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $actor = marketingActor($data);
    Sanctum::actingAs($actor);

    $first = $this->postJson(obUrl($data['restaurant']->id, '/contact-email/send-code'), [
        'email' => 'first@restaurant.com',
    ]);

    $this->postJson(obUrl($data['restaurant']->id, '/contact-email/send-code'), [
        'email' => 'second@restaurant.com',
    ])->assertCreated();

    $firstToken = $first->json('challenge_token');

    // Old token should now be cancelled — verification must fail
    $this->postJson(obUrl($data['restaurant']->id, '/contact-email/verify'), [
        'challenge_token' => $firstToken,
        'code' => '1234',
    ])->assertUnprocessable();
});

it('forbids operations staff from sending a verification code', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    $ops = User::factory()->create();
    assignScopedRole($ops, Role::Operations, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($ops);

    $this->postJson(obUrl($data['restaurant']->id, '/contact-email/send-code'), [
        'email' => 'contact@restaurant.com',
    ])->assertForbidden();
});

it('rejects send-code with an invalid email address', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $data = createBookableRestaurant();
    Sanctum::actingAs(marketingActor($data));

    $this->postJson(obUrl($data['restaurant']->id, '/contact-email/send-code'), [
        'email' => 'not-an-email',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});
