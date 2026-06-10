<?php

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Services\MediaUrlService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

it('returns a signed download url for menu documents', function (): void {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'menu_source' => 'pdf',
    ]);

    $restaurant
        ->addMedia(UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'))
        ->toMediaCollection('menu_documents');

    $media = $restaurant->getFirstMedia('menu_documents');

    expect($media)->not->toBeNull();

    $originalUrl = app(MediaUrlService::class)->originalUrl($media);

    expect($originalUrl)->toContain('/media/'.$media->id.'/download')
        ->and($originalUrl)->toContain('signature=');

    $path = parse_url($originalUrl, PHP_URL_PATH).'?'.parse_url($originalUrl, PHP_URL_QUERY);

    $this->get($path)->assertOk();
});

it('exposes menu document signed urls on admin restaurant show', function (): void {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $admin = User::factory()->create();
    assignScopedRole($admin, Role::BusinessAdmin);

    $organization = Organization::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'organization_id' => $organization->id,
        'menu_source' => 'pdf',
    ]);

    $restaurant
        ->addMedia(UploadedFile::fake()->create('Rplots.pdf', 100, 'application/pdf'))
        ->toMediaCollection('menu_documents');

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/admin/restaurants/'.$restaurant->id);

    $originalUrl = $response->json('data.menu_documents.0.original_url');

    expect($originalUrl)->toBeString()->toContain('signature=');

    $path = parse_url($originalUrl, PHP_URL_PATH).'?'.parse_url($originalUrl, PHP_URL_QUERY);

    $this->get($path)->assertOk();
});

it('rejects unsigned menu document downloads', function (): void {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $restaurant = Restaurant::factory()->create();
    $restaurant
        ->addMedia(UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'))
        ->toMediaCollection('menu_documents');

    /** @var Media $media */
    $media = $restaurant->getFirstMedia('menu_documents');

    $this->get('/media/'.$media->id.'/download')->assertForbidden();
});
