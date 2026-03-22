<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('allows business admins to create restaurants and invite owners', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $organizationResponse = $this->postJson('/api/v1/admin/organizations', [
        'name' => 'Admin Org',
        'slug' => 'admin-org',
    ]);

    $organizationResponse->assertCreated()
        ->assertJsonPath('organization.slug', 'admin-org');

    $organizationId = $organizationResponse->json('organization.id');

    $restaurantResponse = $this->postJson('/api/v1/admin/restaurants', [
        'organization_id' => $organizationId,
        'name' => 'Admin Restaurant',
        'slug' => 'admin-restaurant',
        'status' => 'active',
        'is_featured' => true,
    ]);

    $restaurantResponse->assertCreated()
        ->assertJsonPath('restaurant.slug', 'admin-restaurant')
        ->assertJsonPath('restaurant.is_featured', true);

    $restaurantId = $restaurantResponse->json('restaurant.id');

    $inviteResponse = $this->postJson('/api/v1/admin/restaurants/'.$restaurantId.'/invite-owner', [
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner@example.com',
        'phone' => '+2348077777777',
        'password' => 'OwnerPass123!',
        'password_confirmation' => 'OwnerPass123!',
    ]);

    $inviteResponse->assertCreated()
        ->assertJsonPath('user.email', 'owner@example.com');
});

it('allows business admins to create restaurants with featured and gallery images', function () {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $organizationResponse = $this->postJson('/api/v1/admin/organizations', [
        'name' => 'Media Org',
        'slug' => 'media-org',
    ]);

    $response = $this->post('/api/v1/admin/restaurants', [
        'organization_id' => $organizationResponse->json('organization.id'),
        'name' => 'Media Restaurant',
        'slug' => 'media-restaurant',
        'featured_image' => UploadedFile::fake()->image('featured.png'),
        'gallery_images' => [
            UploadedFile::fake()->image('gallery-one.png'),
            UploadedFile::fake()->image('gallery-two.png'),
        ],
        'gallery_image_alt_texts' => ['Main room', 'Chef plating'],
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('restaurant.featured_image.featured', true)
        ->assertJsonCount(2, 'restaurant.gallery_images');
});

it('returns audit logs to authorized admins', function () {
    $this->seed(RoleAndPermissionSeeder::class);
    $admin = User::factory()->create();
    assignScopedRole($admin, Role::SuperAdmin);

    AuditLog::factory()->create([
        'action' => 'restaurant.updated',
    ]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/audit-logs');

    $response->assertOk()
        ->assertJsonFragment(['action' => 'restaurant.updated']);
});
