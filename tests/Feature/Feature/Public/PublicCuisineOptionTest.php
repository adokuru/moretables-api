<?php

use App\Models\CuisineOption;

it('lists cuisine options ordered by name with pagination metadata', function (): void {
    CuisineOption::factory()->create([
        'name' => 'Zebra Kitchen',
        'slug' => 'zebra-kitchen',
    ]);
    CuisineOption::factory()->create([
        'name' => 'Apple Bistro',
        'slug' => 'apple-bistro',
    ]);

    $response = $this->getJson('/api/v1/cuisine-options?per_page=100');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'slug'],
            ],
            'links',
            'meta',
        ]);

    $names = collect($response->json('data'))->pluck('name')->all();
    $appleIndex = array_search('Apple Bistro', $names, true);
    $zebraIndex = array_search('Zebra Kitchen', $names, true);

    expect($appleIndex)->not->toBeFalse()
        ->and($zebraIndex)->not->toBeFalse()
        ->and($appleIndex)->toBeLessThan($zebraIndex);
});

it('respects per_page for cuisine options', function (): void {
    CuisineOption::factory()->count(5)->create();

    $response = $this->getJson('/api/v1/cuisine-options?per_page=2');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2);
});
