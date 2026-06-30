<?php

use App\AveragePriceRange;
use App\Jobs\ProcessRestaurantMediaUploads;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('returns 404 with json message when organization id is missing', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/organizations/999999')
        ->assertNotFound()
        ->assertJsonPath('message', 'No query results for model [App\\Models\\Organization] 999999');

    $this->getJson('/api/v1/admin/organizations/not-an-id')
        ->assertNotFound()
        ->assertJsonPath('message', 'Organization not found.');
});

it('returns organization payload on show and supports partial patch updates', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create([
        'name' => 'Original Org',
        'city' => 'Lagos',
        'billing_email' => 'billing@example.com',
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/admin/organizations/'.$organization->id)
        ->assertOk()
        ->assertJsonPath('organization.id', $organization->id)
        ->assertJsonPath('organization.name', 'Original Org');

    $this->patchJson('/api/v1/admin/organizations/'.$organization->id, [
        'business_city' => 'Abuja',
        'billing_email' => 'new-billing@example.com',
    ])
        ->assertOk()
        ->assertJsonPath('organization.city', 'Abuja')
        ->assertJsonPath('organization.billing_email', 'new-billing@example.com')
        ->assertJsonPath('organization.name', 'Original Org');
});

it('accepts new average price range values and maps legacy slugs', function (): void {
    expect(AveragePriceRange::normalize('budget'))->toBe('10k and under')
        ->and(AveragePriceRange::normalize('mid'))->toBe('20k and under')
        ->and(AveragePriceRange::normalize('fine'))->toBe('50k and above')
        ->and(AveragePriceRange::normalize('20k and under'))->toBe('20k and under');
});

it('creates restaurants with top-level id and queues heavy media uploads', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    Storage::fake('local');
    Queue::fake();

    config(['queue.default' => 'database']);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();

    Sanctum::actingAs($admin);

    $response = $this->post('/api/v1/admin/restaurants', [
        'organization_id' => $organization->id,
        'name' => 'Queued Upload Bistro',
        'slug' => 'queued-upload-bistro',
        'average_price_range' => '50k and under',
        'restaurant_logo' => UploadedFile::fake()->image('logo.jpg'),
        'restaurant_photos' => [
            UploadedFile::fake()->image('photo-1.jpg'),
        ],
    ], [
        'Accept' => 'application/json',
    ]);

    $response->assertCreated()
        ->assertJsonPath('id', fn ($id) => is_int($id))
        ->assertJsonPath('restaurant.id', $response->json('id'))
        ->assertJsonPath('restaurant.average_price_range', '50k and under')
        ->assertJsonPath('media_processing.status', 'processing');

    Queue::assertPushed(ProcessRestaurantMediaUploads::class, function ($job) use ($response): bool {
        return $job->restaurantId === $response->json('id');
    });

    expect(Restaurant::query()->whereKey($response->json('id'))->exists())->toBeTrue();
});

it('creates restaurants without uploads synchronously', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    Queue::fake();

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();

    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/admin/restaurants', [
        'organization_id' => $organization->id,
        'name' => 'Fast Create Bistro',
        'slug' => 'fast-create-bistro',
        'average_price_range' => '10k and under',
        'menu' => [
            'mode' => 'link',
            'link' => 'https://example.com/menu',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('id', fn ($id) => is_int($id))
        ->assertJsonMissingPath('media_processing');

    Queue::assertNothingPushed();
});
