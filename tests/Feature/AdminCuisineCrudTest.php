<?php

use App\Models\Cuisine;
use App\Models\Role;
use App\Models\User;
use App\Support\DefaultCuisines;
use Database\Seeders\CuisineSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

it('seeds the default cuisines', function (): void {
    $this->seed(CuisineSeeder::class);

    expect(Cuisine::query()->count())->toBe(count(DefaultCuisines::names()));

    $nigerian = Cuisine::query()->where('slug', 'nigerian')->first();

    expect($nigerian)->not->toBeNull()
        ->and($nigerian->name)->toBe('Nigerian');
});

it('allows admins to manage cuisines', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $listResponse = $this->getJson('/api/v1/admin/cuisines?per_page=5');

    $listResponse->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta']);

    $createResponse = $this->postJson('/api/v1/admin/cuisines', [
        'name' => 'Peruvian',
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('cuisine.name', 'Peruvian')
        ->assertJsonPath('cuisine.slug', 'peruvian');

    $cuisineId = $createResponse->json('cuisine.id');

    $showResponse = $this->getJson('/api/v1/admin/cuisines/'.$cuisineId);

    $showResponse->assertOk()
        ->assertJsonPath('data.name', 'Peruvian')
        ->assertJsonPath('data.slug', 'peruvian');

    $updateResponse = $this->patchJson('/api/v1/admin/cuisines/'.$cuisineId, [
        'name' => 'Peruvian Fusion',
        'slug' => 'peruvian-fusion',
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('cuisine.name', 'Peruvian Fusion')
        ->assertJsonPath('cuisine.slug', 'peruvian-fusion');

    $deleteResponse = $this->deleteJson('/api/v1/admin/cuisines/'.$cuisineId);

    $deleteResponse->assertOk()
        ->assertJsonPath('message', 'Cuisine deleted successfully.');

    $this->assertDatabaseMissing('cuisines', ['id' => $cuisineId]);
});

it('searches cuisines by name or slug', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(CuisineSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/cuisines?search=mex');

    $response->assertOk();

    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('Mexican');
});

it('validates unique cuisine names and slugs', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    Cuisine::factory()->create([
        'name' => 'Nigerian',
        'slug' => 'nigerian',
    ]);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/cuisines', [
        'name' => 'Nigerian',
        'slug' => 'nigerian',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'slug']);
});

it('forbids non-admin users from managing cuisines', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/admin/cuisines')->assertForbidden();
    $this->postJson('/api/v1/admin/cuisines', ['name' => 'Blocked'])->assertForbidden();
});
