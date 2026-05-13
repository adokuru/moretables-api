<?php

namespace App\Support;

final class DefaultCuisineOptions
{
    /**
     * @return list<array{name: string, slug: string}>
     */
    public static function definitions(): array
    {
        return [
            ['name' => 'Nigerian', 'slug' => 'nigerian'],
            ['name' => 'African', 'slug' => 'african'],
            ['name' => 'Continental', 'slug' => 'continental'],
            ['name' => 'Italian', 'slug' => 'italian'],
            ['name' => 'Chinese', 'slug' => 'chinese'],
            ['name' => 'Japanese', 'slug' => 'japanese'],
            ['name' => 'Mexican', 'slug' => 'mexican'],
            ['name' => 'Indian', 'slug' => 'indian'],
            ['name' => 'Thai', 'slug' => 'thai'],
            ['name' => 'American', 'slug' => 'american'],
            ['name' => 'Mediterranean', 'slug' => 'mediterranean'],
            ['name' => 'French', 'slug' => 'french'],
            ['name' => 'Korean', 'slug' => 'korean'],
            ['name' => 'Vietnamese', 'slug' => 'vietnamese'],
            ['name' => 'Spanish', 'slug' => 'spanish'],
            ['name' => 'Middle Eastern', 'slug' => 'middle-eastern'],
            ['name' => 'Seafood', 'slug' => 'seafood'],
            ['name' => 'Steakhouse', 'slug' => 'steakhouse'],
            ['name' => 'Vegetarian', 'slug' => 'vegetarian'],
            ['name' => 'Vegan', 'slug' => 'vegan'],
            ['name' => 'Café', 'slug' => 'cafe'],
            ['name' => 'Bar & Grill', 'slug' => 'bar-grill'],
            ['name' => 'Fusion', 'slug' => 'fusion'],
            ['name' => 'Fast Casual', 'slug' => 'fast-casual'],
            ['name' => 'Street Food', 'slug' => 'street-food'],
            ['name' => 'Other', 'slug' => 'other'],
        ];
    }
}
