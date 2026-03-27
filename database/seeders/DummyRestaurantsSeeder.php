<?php

namespace Database\Seeders;

use App\Models\DiningArea;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantCuisine;
use App\Models\RestaurantHour;
use App\Models\RestaurantMenuItem;
use App\Models\RestaurantPolicy;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\RestaurantStatus;
use App\TableStatus;
use App\UserAuthMethod;
use App\UserStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class DummyRestaurantsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $owner = User::query()->firstOrCreate(
            ['email' => 'owner@moretables.test'],
            [
                'name' => 'MoreTables Owner',
                'first_name' => 'MoreTables',
                'last_name' => 'Owner',
                'phone' => '+2348010000001',
                'password' => 'password',
                'status' => UserStatus::Active->value,
                'auth_method' => UserAuthMethod::Password->value,
                'email_verified_at' => now(),
                'last_active_at' => now(),
            ],
        );

        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'moretables-curated-restaurants'],
            [
                'name' => 'MoreTables Curated Restaurants',
                'primary_contact_name' => $owner->fullName(),
                'primary_contact_email' => $owner->email,
                'primary_contact_phone' => $owner->phone,
                'status' => 'active',
            ],
        );

        $roleId = Role::query()->where('name', Role::OrganizationOwner)->value('id');

        if ($roleId) {
            UserRole::query()->firstOrCreate([
                'user_id' => $owner->id,
                'role_id' => $roleId,
                'organization_id' => $organization->id,
                'restaurant_id' => null,
            ], [
                'scope_type' => null,
                'assigned_by' => $owner->id,
            ]);
        }

        foreach ($this->restaurants() as $restaurantData) {
            $restaurant = Restaurant::query()->updateOrCreate(
                ['slug' => $restaurantData['slug']],
                [
                    'organization_id' => $organization->id,
                    'name' => $restaurantData['name'],
                    'status' => RestaurantStatus::Active->value,
                    'email' => $restaurantData['email'],
                    'phone' => $restaurantData['phone'],
                    'city' => $restaurantData['city'],
                    'state' => $restaurantData['state'],
                    'country' => 'Nigeria',
                    'timezone' => 'Africa/Lagos',
                    'address_line_1' => $restaurantData['address_line_1'],
                    'address_line_2' => $restaurantData['address_line_2'] ?? null,
                    'latitude' => $restaurantData['latitude'],
                    'longitude' => $restaurantData['longitude'],
                    'description' => $restaurantData['description'],
                ],
            );

            $this->seedCuisines($restaurant, $restaurantData['cuisines']);
            $this->seedMedia($restaurant, $restaurantData['images']);
            $this->seedHours($restaurant);
            $this->seedPolicy($restaurant, $restaurantData['policy']);
            $this->seedDiningAreasAndTables($restaurant);
            $this->seedMenus($restaurant, $restaurantData['menus']);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function restaurants(): array
    {
        $restaurantImages = [
            'https://plus.unsplash.com/premium_photo-1661883237884-263e8de8869b?q=80&w=2378&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
            'https://images.unsplash.com/photo-1556745750-68295fefafc5?q=80&w=1974&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDF8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
            'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?q=80&w=2340&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
            'https://images.unsplash.com/photo-1514933651103-005eec06c04b?q=80&w=1974&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
            'https://images.unsplash.com/photo-1590846406792-0adc7f938f1d?q=80&w=985&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
            'https://images.unsplash.com/photo-1552566626-52f8b828add9?q=80&w=2340&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
            'https://images.unsplash.com/photo-1537047902294-62a40c20a6ae?q=80&w=1335&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
            'https://images.unsplash.com/photo-1481833761820-0509d3217039?q=80&w=2340&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
            'https://images.unsplash.com/photo-1560053608-13721e0d69e8?q=80&w=2340&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
            'https://images.unsplash.com/photo-1502998070258-dc1338445ac2?q=80&w=979&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D',
        ];

        return [
            [
                'name' => 'Basilico Restaurant',
                'slug' => 'basilico-restaurant',
                'email' => 'hello@basilico.moretables.test',
                'phone' => '+2348124500011',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'address_line_1' => '14 Admiralty Way',
                'address_line_2' => 'Lekki Phase 1',
                'latitude' => 6.4474000,
                'longitude' => 3.4699000,
                'description' => 'A polished Italian dining room in Lekki with handmade pasta, wood-fired classics, and elegant date-night energy.',
                'cuisines' => ['Italian', 'Mediterranean', 'Fine Dining'],
                'images' => [
                    ['collection' => 'cover', 'url' => $restaurantImages[0], 'alt_text' => 'Basilico dining room'],
                    ['collection' => 'gallery', 'url' => $restaurantImages[1], 'alt_text' => 'Basilico interior details'],
                ],
                'policy' => ['duration' => 120, 'window' => 21, 'cutoff' => 24, 'min' => 1, 'max' => 8, 'deposit' => false],
                'menus' => [
                    'Starters' => [
                        ['name' => 'Truffle Arancini', 'description' => 'Golden risotto croquettes with parmesan cream.', 'price' => 9500],
                        ['name' => 'Burrata Caprese', 'description' => 'Creamy burrata, cherry tomatoes, basil oil, and focaccia.', 'price' => 12000],
                    ],
                    'Mains' => [
                        ['name' => 'Lobster Linguine', 'description' => 'Fresh linguine in roasted tomato butter with chili and herbs.', 'price' => 28500],
                        ['name' => 'Wood Fired Margherita', 'description' => 'San Marzano tomato, buffalo mozzarella, and fresh basil.', 'price' => 16500],
                    ],
                    'Desserts' => [
                        ['name' => 'Classic Tiramisu', 'description' => 'Espresso soaked sponge, mascarpone, and cocoa.', 'price' => 8500],
                        ['name' => 'Lemon Ricotta Cake', 'description' => 'Soft ricotta cake with candied citrus and cream.', 'price' => 7800],
                    ],
                ],
            ],
            [
                'name' => 'Ember & Palm',
                'slug' => 'ember-and-palm',
                'email' => 'bookings@emberandpalm.moretables.test',
                'phone' => '+2348124500012',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'address_line_1' => '22A Akin Adesola Street',
                'address_line_2' => 'Victoria Island',
                'latitude' => 6.4312000,
                'longitude' => 3.4215000,
                'description' => 'Live-fire cooking, tropical cocktails, and a warm coastal room built for celebrations and long dinners.',
                'cuisines' => ['Contemporary African', 'Grill', 'Cocktail Bar'],
                'images' => [
                    ['collection' => 'cover', 'url' => $restaurantImages[2], 'alt_text' => 'Ember and Palm main room'],
                    ['collection' => 'gallery', 'url' => $restaurantImages[3], 'alt_text' => 'Ember plated meal'],
                ],
                'policy' => ['duration' => 120, 'window' => 30, 'cutoff' => 12, 'min' => 1, 'max' => 10, 'deposit' => true],
                'menus' => [
                    'Starters' => [
                        ['name' => 'Jollof Arancini', 'description' => 'Smoked rice arancini with ata rodo aioli.', 'price' => 7800],
                        ['name' => 'Coconut Prawn Skewers', 'description' => 'Charred prawns with lime leaf glaze.', 'price' => 13500],
                    ],
                    'Mains' => [
                        ['name' => 'Plantain Butter Lobster', 'description' => 'Butter-poached lobster over sweet plantain puree.', 'price' => 34500],
                        ['name' => 'Charcoal Chicken Suya', 'description' => 'Half chicken with suya spice, greens, and jus.', 'price' => 19500],
                    ],
                    'Desserts' => [
                        ['name' => 'Biscoff Cheesecake', 'description' => 'Creamy cheesecake with caramelized biscuit crumb.', 'price' => 8800],
                        ['name' => 'Roasted Pineapple Tart', 'description' => 'Warm tart with vanilla bean ice cream.', 'price' => 7600],
                    ],
                ],
            ],
            [
                'name' => 'Coral Reef Kitchen',
                'slug' => 'coral-reef-kitchen',
                'email' => 'tables@coralreef.moretables.test',
                'phone' => '+2348124500013',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'address_line_1' => '3 Oniru Waterfront',
                'address_line_2' => 'Victoria Island',
                'latitude' => 6.4258000,
                'longitude' => 3.4427000,
                'description' => 'A breezy seafood restaurant with bright flavors, lagoon views, and a menu centered on premium shellfish and fish.',
                'cuisines' => ['Seafood', 'Mediterranean', 'Coastal'],
                'images' => [
                    ['collection' => 'cover', 'url' => $restaurantImages[4], 'alt_text' => 'Coral Reef dining room'],
                    ['collection' => 'gallery', 'url' => $restaurantImages[5], 'alt_text' => 'Coral Reef restaurant ambiance'],
                ],
                'policy' => ['duration' => 120, 'window' => 21, 'cutoff' => 24, 'min' => 1, 'max' => 12, 'deposit' => true],
                'menus' => [
                    'Starters' => [
                        ['name' => 'Crispy Calamari', 'description' => 'Lightly fried calamari with lemon pepper dip.', 'price' => 9800],
                        ['name' => 'Tiger Prawn Bisque', 'description' => 'Silky bisque with toasted brioche.', 'price' => 11200],
                    ],
                    'Mains' => [
                        ['name' => 'Grilled Red Snapper', 'description' => 'Whole snapper with herb rice and charred lemon.', 'price' => 25500],
                        ['name' => 'Seafood Coconut Rice', 'description' => 'Fragrant rice with prawns, mussels, and squid.', 'price' => 22800],
                    ],
                    'Desserts' => [
                        ['name' => 'Coconut Panna Cotta', 'description' => 'Chilled coconut cream with mango compote.', 'price' => 7200],
                        ['name' => 'Lime Olive Oil Cake', 'description' => 'Moist sponge with citrus glaze.', 'price' => 6800],
                    ],
                ],
            ],
            [
                'name' => 'Northern Table',
                'slug' => 'northern-table',
                'email' => 'reserve@northerntable.moretables.test',
                'phone' => '+2348124500014',
                'city' => 'Abuja',
                'state' => 'FCT',
                'address_line_1' => '18 Yedseram Street',
                'address_line_2' => 'Maitama',
                'latitude' => 9.0833000,
                'longitude' => 7.4951000,
                'description' => 'Modern Northern Nigerian cooking with refined plating, warm hospitality, and a calm intimate dining room.',
                'cuisines' => ['Northern Nigerian', 'Contemporary', 'Fine Dining'],
                'images' => [
                    ['collection' => 'cover', 'url' => $restaurantImages[6], 'alt_text' => 'Northern Table dining room'],
                    ['collection' => 'gallery', 'url' => $restaurantImages[7], 'alt_text' => 'Northern Table interior'],
                ],
                'policy' => ['duration' => 105, 'window' => 14, 'cutoff' => 24, 'min' => 1, 'max' => 6, 'deposit' => false],
                'menus' => [
                    'Starters' => [
                        ['name' => 'Kilishi Bao', 'description' => 'Soft bao buns with glazed kilishi and pickles.', 'price' => 8400],
                        ['name' => 'Miyan Kuka Croquettes', 'description' => 'Crisp croquettes with herbed yogurt.', 'price' => 6900],
                    ],
                    'Mains' => [
                        ['name' => 'Braised Lamb Chops', 'description' => 'Tender lamb with millet couscous and jus.', 'price' => 23800],
                        ['name' => 'Tuwo Risotto', 'description' => 'Creamy acha risotto with spiced mushrooms.', 'price' => 17400],
                    ],
                    'Desserts' => [
                        ['name' => 'Date Sticky Cake', 'description' => 'Warm date cake with salted caramel.', 'price' => 7500],
                        ['name' => 'Kunun Ice Cream', 'description' => 'Malty sorghum ice cream with sesame brittle.', 'price' => 6500],
                    ],
                ],
            ],
            [
                'name' => 'Zobo & Spice Lounge',
                'slug' => 'zobo-and-spice-lounge',
                'email' => 'hello@zoboandspice.moretables.test',
                'phone' => '+2348124500015',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'address_line_1' => '8 Balarabe Musa Crescent',
                'address_line_2' => 'Victoria Island',
                'latitude' => 6.4349000,
                'longitude' => 3.4308000,
                'description' => 'An energetic lounge restaurant pairing polished comfort food with house cocktails, late dinners, and shareable plates.',
                'cuisines' => ['Fusion', 'Lounge', 'Small Plates'],
                'images' => [
                    ['collection' => 'cover', 'url' => $restaurantImages[8], 'alt_text' => 'Zobo and Spice lounge room'],
                    ['collection' => 'gallery', 'url' => $restaurantImages[9], 'alt_text' => 'Zobo and Spice dining scene'],
                ],
                'policy' => ['duration' => 90, 'window' => 14, 'cutoff' => 6, 'min' => 1, 'max' => 8, 'deposit' => false],
                'menus' => [
                    'Starters' => [
                        ['name' => 'Yam Croquettes', 'description' => 'Smoky yam croquettes with pepper jam.', 'price' => 6200],
                        ['name' => 'Peppered Paneer', 'description' => 'Charred paneer cubes with suya glaze.', 'price' => 7900],
                    ],
                    'Mains' => [
                        ['name' => 'Ofada Risotto', 'description' => 'Creamy ofada rice with roasted mushrooms and parmesan.', 'price' => 16800],
                        ['name' => 'Zobo Glazed Duck', 'description' => 'Roasted duck breast with hibiscus reduction.', 'price' => 24900],
                    ],
                    'Desserts' => [
                        ['name' => 'Malted Brownie', 'description' => 'Fudgy brownie with chocolate malt ice cream.', 'price' => 6900],
                        ['name' => 'Puff Puff Sundae', 'description' => 'Warm puff puff with vanilla soft serve and caramel.', 'price' => 5800],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $cuisines
     */
    protected function seedCuisines(Restaurant $restaurant, array $cuisines): void
    {
        RestaurantCuisine::query()->where('restaurant_id', $restaurant->id)->delete();

        foreach (array_values($cuisines) as $index => $cuisine) {
            RestaurantCuisine::query()->create([
                'restaurant_id' => $restaurant->id,
                'name' => $cuisine,
            ]);
        }
    }

    /**
     * @param  array<int, array{collection: string, url: string, alt_text: string}>  $images
     */
    protected function seedMedia(Restaurant $restaurant, array $images): void
    {
        $restaurant->clearMediaCollection('featured');
        $restaurant->clearMediaCollection('gallery');

        foreach (array_values($images) as $index => $image) {
            $path = $this->createImageForSeeding(
                label: $restaurant->name.' '.($index + 1),
                seed: $restaurant->slug.'-'.$image['collection'].'-'.$index,
                sourceUrl: $image['url'],
            );

            $restaurant
                ->addMedia($path)
                ->withCustomProperties(['alt_text' => $image['alt_text']])
                ->toMediaCollection($image['collection'] === 'cover' ? 'featured' : 'gallery');

            File::delete($path);
        }
    }

    protected function seedHours(Restaurant $restaurant): void
    {
        RestaurantHour::query()->where('restaurant_id', $restaurant->id)->delete();

        foreach (range(0, 6) as $dayOfWeek) {
            RestaurantHour::query()->create([
                'restaurant_id' => $restaurant->id,
                'day_of_week' => $dayOfWeek,
                'opens_at' => in_array($dayOfWeek, [5, 6], true) ? '10:00:00' : '12:00:00',
                'closes_at' => in_array($dayOfWeek, [5, 6], true) ? '23:30:00' : '22:30:00',
                'is_closed' => false,
            ]);
        }
    }

    /**
     * @param  array{duration: int, window: int, cutoff: int, min: int, max: int, deposit: bool}  $policy
     */
    protected function seedPolicy(Restaurant $restaurant, array $policy): void
    {
        RestaurantPolicy::query()->updateOrCreate(
            ['restaurant_id' => $restaurant->id],
            [
                'reservation_duration_minutes' => $policy['duration'],
                'booking_window_days' => $policy['window'],
                'cancellation_cutoff_hours' => $policy['cutoff'],
                'min_party_size' => $policy['min'],
                'max_party_size' => $policy['max'],
                'deposit_required' => $policy['deposit'],
            ],
        );
    }

    protected function seedDiningAreasAndTables(Restaurant $restaurant): void
    {
        RestaurantTable::query()->where('restaurant_id', $restaurant->id)->delete();
        DiningArea::query()->where('restaurant_id', $restaurant->id)->delete();

        $areas = [
            ['name' => 'Main Dining', 'description' => 'The primary dining room with the best evening atmosphere.', 'tags' => ['indoor']],
            ['name' => 'Terrace', 'description' => 'A breezy outdoor section for relaxed lunches and sunset bookings.', 'tags' => ['outdoor']],
        ];

        foreach ($areas as $areaIndex => $areaData) {
            $area = DiningArea::query()->create([
                'restaurant_id' => $restaurant->id,
                'name' => $areaData['name'],
                'description' => $areaData['description'],
                'tags' => $areaData['tags'],
                'is_active' => true,
                'sort_order' => $areaIndex,
            ]);

            foreach (range(1, 4) as $tableNumber) {
                RestaurantTable::query()->create([
                    'restaurant_id' => $restaurant->id,
                    'dining_area_id' => $area->id,
                    'name' => ($areaIndex === 0 ? 'M' : 'T').$tableNumber,
                    'min_capacity' => 2,
                    'max_capacity' => $tableNumber <= 2 ? 2 : 4,
                    'status' => TableStatus::Available->value,
                    'is_active' => true,
                    'sort_order' => $tableNumber,
                ]);
            }
        }
    }

    /**
     * @param  array<string, array<int, array{name: string, description: string, price: int}>>  $menus
     */
    protected function seedMenus(Restaurant $restaurant, array $menus): void
    {
        RestaurantMenuItem::query()->where('restaurant_id', $restaurant->id)->delete();

        $sortOrder = 0;

        foreach ($menus as $section => $items) {
            foreach ($items as $item) {
                $menuItem = RestaurantMenuItem::query()->create([
                    'restaurant_id' => $restaurant->id,
                    'section_name' => $section,
                    'item_name' => $item['name'],
                    'description' => $item['description'],
                    'price' => $item['price'],
                    'currency' => 'NGN',
                    'sort_order' => $sortOrder,
                ]);

                $featuredPath = $this->createPlaceholderImage(
                    label: $item['name'],
                    seed: $restaurant->slug.'-menu-featured-'.$sortOrder,
                );

                $galleryPath = $this->createPlaceholderImage(
                    label: $section.' '.$item['name'],
                    seed: $restaurant->slug.'-menu-gallery-'.$sortOrder,
                );

                $menuItem
                    ->addMedia($featuredPath)
                    ->withCustomProperties(['alt_text' => $item['name'].' featured image'])
                    ->toMediaCollection('featured');

                $menuItem
                    ->addMedia($galleryPath)
                    ->withCustomProperties(['alt_text' => $item['name'].' gallery image'])
                    ->toMediaCollection('gallery');

                File::delete([$featuredPath, $galleryPath]);

                $sortOrder++;
            }
        }
    }

    protected function createPlaceholderImage(string $label, string $seed): string
    {
        $directory = storage_path('app/tmp/dummy-media');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/'.str($seed)->slug()->toString().'.png';

        if (function_exists('imagecreatetruecolor')) {
            $image = imagecreatetruecolor(1200, 900);
            $background = imagecolorallocate(
                $image,
                hexdec(substr(md5($seed), 0, 2)),
                hexdec(substr(md5($seed), 2, 2)),
                hexdec(substr(md5($seed), 4, 2)),
            );
            $accent = imagecolorallocate($image, 245, 239, 230);
            $text = imagecolorallocate($image, 255, 255, 255);

            imagefilledrectangle($image, 0, 0, 1200, 900, $background);
            imagefilledrectangle($image, 72, 72, 1128, 828, $accent);
            imagefilledrectangle($image, 96, 96, 1104, 804, $background);
            imagestring($image, 5, 120, 140, 'MoreTables', $text);
            imagestring($image, 4, 120, 210, mb_strimwidth($label, 0, 40, '...'), $text);

            imagepng($image, $path);
            imagedestroy($image);

            return $path;
        }

        File::put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9YxpoE4AAAAASUVORK5CYII='));

        return $path;
    }

    protected function createImageForSeeding(string $label, string $seed, string $sourceUrl): string
    {
        $directory = storage_path('app/tmp/dummy-media');
        File::ensureDirectoryExists($directory);

        $response = Http::timeout(30)->get($sourceUrl);

        if (! $response->successful()) {
            throw new \RuntimeException("Failed downloading restaurant image for [{$label}] from [{$sourceUrl}].");
        }

        $contentType = (string) $response->header('Content-Type');
        $extension = str_contains($contentType, 'png') ? 'png' : 'jpg';
        $path = $directory.'/'.str($seed)->slug()->toString().'.'.$extension;
        File::put($path, $response->body());

        return $path;
    }
}
