<?php

use App\Models\MoretableLineup;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\MoretableLineupStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('allows admins to manage moretable lineups', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $restaurant = Restaurant::factory()->create();

    $listResponse = $this->getJson('/api/v1/admin/moretable-lineups?per_page=5');
    $listResponse->assertOk()->assertJsonStructure(['data', 'links', 'meta']);

    $createResponse = $this->postJson('/api/v1/admin/moretable-lineups', [
        'restaurant_id' => $restaurant->id,
        'title' => 'Best Brunch Spots',
        'body' => 'A roundup of the finest brunch in town.',
        'status' => MoretableLineupStatus::Published->value,
    ]);

    $createResponse->assertCreated()
        ->assertJsonPath('lineup.title', 'Best Brunch Spots')
        ->assertJsonPath('lineup.slug', 'best-brunch-spots')
        ->assertJsonPath('lineup.status', 'published')
        ->assertJsonPath('lineup.restaurant.id', $restaurant->id);

    expect($createResponse->json('lineup.published_at'))->not->toBeNull();

    $lineupId = $createResponse->json('lineup.id');

    $showResponse = $this->getJson('/api/v1/admin/moretable-lineups/'.$lineupId);
    $showResponse->assertOk()->assertJsonPath('data.title', 'Best Brunch Spots');

    $updateResponse = $this->patchJson('/api/v1/admin/moretable-lineups/'.$lineupId, [
        'title' => 'Best Brunch Spots in Lagos',
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('lineup.title', 'Best Brunch Spots in Lagos')
        ->assertJsonPath('lineup.slug', 'best-brunch-spots-in-lagos');

    $deleteResponse = $this->deleteJson('/api/v1/admin/moretable-lineups/'.$lineupId);
    $deleteResponse->assertOk()->assertJsonPath('message', 'Moretable lineup deleted successfully.');

    $this->assertDatabaseMissing('moretable_lineups', ['id' => $lineupId]);
});

it('validates required fields and slug uniqueness', function (): void {
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $existing = MoretableLineup::factory()->create(['slug' => 'taken-slug']);

    $this->postJson('/api/v1/admin/moretable-lineups', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['restaurant_id', 'title', 'body']);

    $this->postJson('/api/v1/admin/moretable-lineups', [
        'restaurant_id' => $existing->restaurant_id,
        'title' => 'Another',
        'body' => 'Body',
        'slug' => 'taken-slug',
    ])->assertUnprocessable()->assertJsonValidationErrors(['slug']);
});

it('allows admins to upload and remove a cover image', function (): void {
    Storage::fake('public');

    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    Sanctum::actingAs($admin);

    $lineup = MoretableLineup::factory()->create();

    $uploadResponse = $this->postJson('/api/v1/admin/moretable-lineups/'.$lineup->id.'/cover', [
        'cover' => UploadedFile::fake()->image('cover.jpg', 1200, 800),
        'alt_text' => 'Brunch table spread',
    ]);

    $uploadResponse->assertCreated()
        ->assertJsonPath('lineup.cover_image.collection', 'cover')
        ->assertJsonPath('lineup.cover_image.alt_text', 'Brunch table spread')
        ->assertJsonStructure(['lineup' => ['cover_image' => ['id', 'original_url', 'thumb_url', 'card_url']]]);

    expect($lineup->refresh()->getFirstMedia('cover'))->not->toBeNull();

    $this->postJson('/api/v1/admin/moretable-lineups/'.$lineup->id.'/cover', [
        'cover' => UploadedFile::fake()->create('not-an-image.pdf', 100, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors(['cover']);

    $this->deleteJson('/api/v1/admin/moretable-lineups/'.$lineup->id.'/cover')
        ->assertOk()
        ->assertJsonPath('message', 'Cover image removed successfully.');

    expect($lineup->refresh()->getFirstMedia('cover'))->toBeNull();
});

it('forbids non-admin users from managing lineups', function (): void {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create();

    $this->getJson('/api/v1/admin/moretable-lineups')->assertForbidden();
    $this->postJson('/api/v1/admin/moretable-lineups', [
        'restaurant_id' => $restaurant->id,
        'title' => 'Blocked',
        'body' => 'Blocked body',
    ])->assertForbidden();
});

it('exposes only published lineups publicly', function (): void {
    $published = MoretableLineup::factory()->published()->create(['slug' => 'published-lineup']);
    MoretableLineup::factory()->create(['slug' => 'draft-lineup']);

    $indexResponse = $this->getJson('/api/v1/moretable-lineups');
    $indexResponse->assertOk();

    $slugs = collect($indexResponse->json('data'))->pluck('slug')->all();

    expect($slugs)->toContain('published-lineup')
        ->and($slugs)->not->toContain('draft-lineup');

    $this->getJson('/api/v1/moretable-lineups/published-lineup')
        ->assertOk()
        ->assertJsonPath('data.slug', 'published-lineup')
        ->assertJsonPath('data.restaurant.id', $published->restaurant_id);

    $this->getJson('/api/v1/moretable-lineups/draft-lineup')->assertNotFound();
});

it('returns lineups near the given coordinates ordered by distance', function (): void {
    $near = Restaurant::factory()->create(['latitude' => 6.4300, 'longitude' => 3.4220]);
    $closer = Restaurant::factory()->create(['latitude' => 6.4285, 'longitude' => 3.4219]);
    $far = Restaurant::factory()->create(['latitude' => 9.0570, 'longitude' => 7.4951]);

    MoretableLineup::factory()->published()->for($near)->create(['slug' => 'near-lineup']);
    MoretableLineup::factory()->published()->for($closer)->create(['slug' => 'closer-lineup']);
    MoretableLineup::factory()->published()->for($far)->create(['slug' => 'far-lineup']);

    $response = $this->getJson('/api/v1/moretable-lineups?latitude=6.4281&longitude=3.4219&radius_km=25');

    $response->assertOk();

    $slugs = collect($response->json('data'))->pluck('slug')->all();

    expect($slugs)->toContain('near-lineup', 'closer-lineup')
        ->and($slugs)->not->toContain('far-lineup')
        ->and($slugs[0])->toBe('closer-lineup');

    expect($response->json('data.0.distance_km'))->not->toBeNull()
        ->and($response->json('data.0.restaurant.distance_km'))->not->toBeNull()
        ->and($response->json('data.0.restaurant.distance_km'))->toBe($response->json('data.0.distance_km'));
});

it('validates latitude and longitude pairing', function (): void {
    $this->getJson('/api/v1/moretable-lineups?latitude=6.4281')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['longitude']);
});
