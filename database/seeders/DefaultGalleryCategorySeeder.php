<?php

namespace Database\Seeders;

use App\Models\Restaurant;
use Illuminate\Database\Seeder;

class DefaultGalleryCategorySeeder extends Seeder
{
    // private const DEFAULTS = [
    //     'Food & Dishes',
    //     'Ambiance',
    //     'Interior',
    //     'Bar & Drinks',
    //     'Events',
    // ];

    public function run(): void
    {
        Restaurant::query()->each(function (Restaurant $restaurant): void {
            if ($restaurant->galleryCategories()->exists()) {
                return;
            }

            foreach (array_values(self::DEFAULTS) as $i => $name) {
                $restaurant->galleryCategories()->create([
                    'name' => $name,
                    'sort_order' => $i + 1,
                ]);
            }

            $this->command->info("Seeded gallery categories for restaurant: {$restaurant->name}");
        });
    }
}
