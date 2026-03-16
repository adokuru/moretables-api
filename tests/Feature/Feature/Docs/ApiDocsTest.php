<?php

it('serves the generated api specification route', function () {
    $response = $this->getJson('/docs/api.json');

    $response->assertOk();

    $paths = array_keys($response->json('paths'));

    expect($paths)->toContain('/auth/register', '/auth/google', '/auth/apple', '/me/expo-push-tokens', '/reservations', '/admin/restaurants');
});
