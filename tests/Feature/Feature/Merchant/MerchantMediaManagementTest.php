<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('allows restaurant managers to create menu items with media on create', function () {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $manager = User::factory()->create();
    assignScopedRole($manager, Role::RestaurantManager, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($manager);

    $response = $this->post('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/menu-items', [
        'section_name' => 'Mains',
        'item_name' => 'Smoked Lobster Rice',
        'description' => 'Butter lobster over coconut rice.',
        'price' => 24500,
        'currency' => 'NGN',
        'featured_image' => UploadedFile::fake()->image('menu-featured.png'),
        'gallery_images' => [
            UploadedFile::fake()->image('menu-gallery-one.png'),
            UploadedFile::fake()->image('menu-gallery-two.png'),
        ],
        'gallery_image_alt_texts' => ['Hero shot', 'Detail plate'],
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('menu_item.name', 'Smoked Lobster Rice')
        ->assertJsonPath('menu_item.featured_image.featured', true)
        ->assertJsonCount(2, 'menu_item.gallery_images');
});

it('allows restaurant managers to upload restaurant media and promote a gallery image to featured', function () {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $manager = User::factory()->create();
    assignScopedRole($manager, Role::RestaurantManager, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($manager);

    $uploadResponse = $this->post('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/media', [
        'gallery_images' => [
            UploadedFile::fake()->image('dining-room.png'),
            UploadedFile::fake()->image('terrace.png'),
        ],
        'gallery_image_alt_texts' => ['Dining room', 'Terrace'],
    ], ['Accept' => 'application/json']);

    $uploadResponse->assertCreated()
        ->assertJsonCount(2, 'gallery_images');

    $mediaId = $uploadResponse->json('gallery_images.0.id');

    $featureResponse = $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/media/'.$mediaId.'/feature');

    $featureResponse->assertOk()
        ->assertJsonPath('featured_image.featured', true)
        ->assertJsonPath('featured_image.alt_text', 'Dining room');
});
