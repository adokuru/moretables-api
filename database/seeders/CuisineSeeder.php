<?php

namespace Database\Seeders;

use App\Models\Cuisine;
use App\Support\DefaultCuisines;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CuisineSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach (DefaultCuisines::names() as $name) {
            $slug = Str::slug($name);

            Cuisine::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}
