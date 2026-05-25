<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Models\RestaurantAccessConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:backfill-restaurant-access-configs')]
#[Description('Seed the 5 default access configs for any restaurant that does not already have them.')]
class BackfillRestaurantAccessConfigs extends Command
{
    public function handle(): int
    {
        $defaults = RestaurantAccessConfig::defaults();

        $restaurants = Restaurant::query()
            ->whereDoesntHave('accessConfigs')
            ->cursor();

        $total = 0;

        foreach ($restaurants as $restaurant) {
            foreach ($defaults as $config) {
                RestaurantAccessConfig::query()->create([
                    'restaurant_id' => $restaurant->id,
                    'name' => $config['name'],
                    'slug' => $config['slug'],
                    'description' => $config['description'],
                    'permissions' => $config['permissions'],
                    'is_default' => true,
                ]);
            }

            $this->line("  Seeded: {$restaurant->name} (ID {$restaurant->id})");
            $total++;
        }

        $this->info("Done. Backfilled {$total} restaurant(s).");

        return self::SUCCESS;
    }
}
