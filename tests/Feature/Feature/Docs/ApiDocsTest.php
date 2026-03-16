<?php

it('serves the generated api specification route', function () {
    $response = $this->getJson('/docs/api.json');

    $response->assertOk()
        ->assertSee('/api/v1/auth/register')
        ->assertSee('/api/v1/reservations')
        ->assertSee('/api/v1/admin/restaurants');
});
