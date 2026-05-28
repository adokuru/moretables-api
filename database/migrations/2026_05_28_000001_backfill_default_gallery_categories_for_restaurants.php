<?php

use App\Models\Restaurant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const array DEFAULTS = [
        'Food',
        'Drinks',
        'Interior',
        'Exterior',
    ];

    public function up(): void
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
        });
    }

    public function down(): void
    {
        //
    }
};
