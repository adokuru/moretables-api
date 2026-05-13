<?php

namespace Database\Factories;

use App\Models\CuisineOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuisineOption>
 */
class CuisineOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).' Cuisine';

        return [
            'name' => $name,
            'slug' => fake()->unique()->slug(),
        ];
    }
}
