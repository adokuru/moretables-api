<?php

use App\Models\DiningArea;
use App\Models\Restaurant;

it('creates a default dining area when a restaurant is created', function (): void {
    $restaurant = Restaurant::factory()->create();

    expect($restaurant->diningAreas()->count())->toBe(1);

    $diningArea = $restaurant->diningAreas()->first();

    expect($diningArea?->name)->toBe(DiningArea::DEFAULT_NAME)
        ->and($diningArea?->floor_type)->toBe(DiningArea::DEFAULT_FLOOR_TYPE)
        ->and($diningArea?->is_active)->toBeTrue()
        ->and($diningArea?->sort_order)->toBe(0);
});
