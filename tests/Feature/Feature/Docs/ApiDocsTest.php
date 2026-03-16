<?php

it('serves the generated api specification route', function () {
    $response = $this->getJson('/docs/api.json');

    $response->assertOk();

    $specification = $response->json();
    $paths = array_keys($specification['paths']);

    expect($paths)->toContain(
        '/auth/register',
        '/auth/google',
        '/auth/apple',
        '/admin/auth/login',
        '/me/expo-push-tokens',
        '/reservations',
        '/waitlist-entries/{waitlistEntry}/accept',
        '/admin/restaurants',
        '/merchant/restaurants/{restaurant}/media',
        '/merchant/restaurants/{restaurant}/menu-items'
    );

    expect($specification['paths']['/auth/register']['post']['tags'][0])->toBe('Customer Auth');
    expect($specification['paths']['/admin/auth/login']['post']['tags'][0])->toBe('Admin Auth');
    expect($specification['paths']['/guest/start']['post']['tags'][0])->toBe('Guest Auth');
    expect($specification['paths']['/restaurants']['get']['tags'][0])->toBe('Public Restaurants');
    expect($specification['paths']['/merchant/restaurants/{restaurant}/menu-items']['post']['tags'][0])->toBe('Merchant Menu');
    expect($specification['paths']['/admin/restaurants']['post']['tags'][0])->toBe('Admin Restaurants');
});
