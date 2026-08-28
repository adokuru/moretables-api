<?php

use App\Models\RestaurantGalleryCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('allows marketing and growth staff to create menu items with media on create', function () {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $marketingLead = User::factory()->create();
    assignScopedRole($marketingLead, Role::MarketingGrowth, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($marketingLead);

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

it('allows marketing and growth staff to upload restaurant media and promote a gallery image to featured', function () {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $marketingLead = User::factory()->create();
    assignScopedRole($marketingLead, Role::MarketingGrowth, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($marketingLead);

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

it('allows marketing and growth staff to duplicate a gallery image into another category', function () {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $marketingLead = User::factory()->create();
    assignScopedRole($marketingLead, Role::MarketingGrowth, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($marketingLead);

    $foodCategory = RestaurantGalleryCategory::create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Food',
        'sort_order' => 1,
    ]);
    $drinksCategory = RestaurantGalleryCategory::create([
        'restaurant_id' => $data['restaurant']->id,
        'name' => 'Drinks',
        'sort_order' => 2,
    ]);

    $uploadResponse = $this->post('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/media', [
        'gallery_images' => [UploadedFile::fake()->image('dish.png')],
        'gallery_category_ids' => [$foodCategory->id],
    ], ['Accept' => 'application/json']);

    $uploadResponse->assertCreated();
    $mediaId = $uploadResponse->json('gallery_images.0.id');

    $duplicateResponse = $this->postJson(
        '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/media/'.$mediaId.'/duplicate',
        ['gallery_category_id' => $drinksCategory->id],
    );

    $duplicateResponse->assertCreated()
        ->assertJsonPath('media.gallery_category_id', $drinksCategory->id);

    $galleryResponse = $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/gallery');

    $galleryResponse->assertOk();
    $categories = collect($galleryResponse->json('categories'));
    expect($categories->firstWhere('name', 'Food')['photos'])->toHaveCount(1);
    expect($categories->firstWhere('name', 'Drinks')['photos'])->toHaveCount(1);
});

it('rejects duplicating a gallery image into a category id that does not exist', function () {
    Storage::fake('public');
    $this->seed(RoleAndPermissionSeeder::class);

    $data = createBookableRestaurant();
    $marketingLead = User::factory()->create();
    assignScopedRole($marketingLead, Role::MarketingGrowth, $data['organization'], $data['restaurant']);

    Sanctum::actingAs($marketingLead);

    $uploadResponse = $this->post('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/media', [
        'gallery_images' => [UploadedFile::fake()->image('dish.png')],
    ], ['Accept' => 'application/json']);

    $mediaId = $uploadResponse->json('gallery_images.0.id');

    $duplicateResponse = $this->postJson(
        '/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/media/'.$mediaId.'/duplicate',
        ['gallery_category_id' => 999999],
    );

    $duplicateResponse->assertUnprocessable();
});
