<?php

namespace Database\Seeders;

use App\Models\CuisineOption;
use App\Support\DefaultCuisineOptions;
use Illuminate\Database\Seeder;

class CuisineSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach (DefaultCuisineOptions::definitions() as $definition) {
            CuisineOption::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
