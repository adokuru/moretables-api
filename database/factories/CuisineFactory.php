<?php

namespace Database\Factories;

use App\Models\Cuisine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cuisine>
 */
class CuisineFactory extends Factory
{
    protected $model = Cuisine::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucwords($name),
            'slug' => Str::slug($name),
        ];
    }
}
