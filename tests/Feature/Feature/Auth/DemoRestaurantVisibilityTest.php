<?php

use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * A real diner must never be able to book a table nobody will honour, so the
 * demo restaurant is hidden everywhere unless DEMO_RESTAURANT_VISIBLE is on.
 */
beforeEach(function () {
    Notification::fake();
    Cache::flush();

    config([
        'auth.demo.code' => '5678',
        'auth.demo.customer.email' => 'demo@moretables.com',
        'auth.demo.restaurant.email' => 'demo-restaurant@moretables.com',
        'auth.demo.restaurant.password' => 'MoreTablesDemo1!',
        'auth.demo.restaurant.name' => 'MoreTables Demo Kitchen',
        'auth.demo.restaurant.visible' => false,
        'auth.demo.emails' => ['demo@moretables.com', 'demo-restaurant@moretables.com'],
    ]);

    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);
    $this->artisan('app:provision-demo')->assertSuccessful();
});

function demoRestaurant(): Restaurant
{
    return Restaurant::query()->where('slug', 'moretables-demo-kitchen')->firstOrFail();
}

it('marks the provisioned restaurant as a demo', function () {
    expect(demoRestaurant()->is_demo)->toBeTrue();
});

it('hides it from the public feed while the switch is off', function () {
    $slugs = collect($this->getJson('/api/v1/restaurants')->assertOk()->json('data'))->pluck('slug');

    expect($slugs)->not->toContain('moretables-demo-kitchen');
});

it('excludes it from every publicly-listed query, then restores it', function () {
    // Asserted at the scope, which is what all six feed/search/detail call
    // sites are built on — the feed endpoint layers its own extra filters.
    $listed = fn (): bool => Restaurant::query()
        ->publiclyListed()
        ->where('slug', 'moretables-demo-kitchen')
        ->exists();

    expect($listed())->toBeFalse();

    config(['auth.demo.restaurant.visible' => true]);

    expect($listed())->toBeTrue();
});

it('404s the detail page while the switch is off, and serves it when on', function () {
    $this->getJson('/api/v1/restaurants/moretables-demo-kitchen')->assertNotFound();

    config(['auth.demo.restaurant.visible' => true]);

    $this->getJson('/api/v1/restaurants/moretables-demo-kitchen')->assertOk();
});

it('keeps it out of search results while the switch is off', function () {
    $hit = fn (): bool => str_contains(
        $this->getJson('/api/v1/search?q=Demo')->assertOk()->getContent(),
        'moretables-demo-kitchen',
    );

    expect($hit())->toBeFalse();

    config(['auth.demo.restaurant.visible' => true]);
    Cache::flush();

    expect($hit())->toBeTrue();
});

it('hides it from the admin dashboard while the switch is off', function () {
    $admin = User::factory()->create();
    $admin->roleAssignments()->create([
        'role_id' => App\Models\Role::query()->where('name', App\Models\Role::SuperAdmin)->value('id'),
        'assigned_by' => $admin->id,
    ]);

    $slugs = fn (): Illuminate\Support\Collection => collect(
        $this->actingAs($admin)->getJson('/api/v1/admin/restaurants')->assertOk()->json('data')
    )->pluck('slug');

    expect($slugs())->not->toContain('moretables-demo-kitchen');

    config(['auth.demo.restaurant.visible' => true]);

    expect($slugs())->toContain('moretables-demo-kitchen');
});

it('applies only to demo restaurants, never to a real one', function () {
    // Same restaurant, same everything, is_demo flipped off: it must list.
    demoRestaurant()->forceFill(['is_demo' => false])->save();

    expect(demoRestaurant()->isPubliclyListed())->toBeTrue()
        ->and(Restaurant::query()->publiclyListed()->where('slug', 'moretables-demo-kitchen')->exists())->toBeTrue();
});
