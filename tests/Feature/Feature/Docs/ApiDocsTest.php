<?php

it('serves the generated api specification route', function () {
    $response = $this->getJson('/docs/api.json');

    $response->assertOk();

    $specification = $response->json();
    $paths = array_keys($specification['paths']);

    expect($paths)->toContain(
        '/auth/start',
        '/auth/google',
        '/auth/apple',
        '/auth/staff/login',
        '/auth/staff/profile',
        '/admin/auth/login',
        '/admin/auth/profile',
        '/admin/organizations/onboard',
        '/admin/roles',
        '/admin/roles/{role}',
        '/admin/reward-program',
        '/admin/users/{user}/reward-points',
        '/me/expo-push-tokens',
        '/me/rewards/status',
        '/me/rewards/transactions',
        '/me/saved-restaurants',
        '/me/restaurant-lists',
        '/me/restaurant-lists/{restaurantList}/restaurants',
        '/reservations',
        '/restaurants/discovery',
        '/restaurants/discovery/{section}',
        '/restaurants/{restaurant}/views',
        '/restaurants/{restaurant}/reviews',
        '/restaurants/{restaurant}/save',
        '/waitlist-entries/{waitlistEntry}/accept',
        '/admin/restaurants',
        '/merchant/restaurants/{restaurant}/staff',
        '/merchant/restaurants/{restaurant}/media',
        '/merchant/restaurants/{restaurant}/menu-items'
    );

    expect($paths)->not->toContain('/auth/login', '/auth/register', '/guest/start');

    expect($specification['paths']['/auth/start']['post']['tags'][0])->toBe('Customer Auth');
    expect($specification['paths']['/auth/staff/login']['post']['tags'][0])->toBe('Merchant Staff Auth');
    expect($specification['paths']['/admin/auth/login']['post']['tags'][0])->toBe('Admin Auth');
    expect($specification['paths']['/restaurants']['get']['tags'][0])->toBe('Public Restaurants');
    expect($specification['paths']['/restaurants/discovery']['get']['tags'][0])->toBe('Public Restaurants');
    expect($specification['paths']['/me/rewards/status']['get']['tags'][0])->toBe('Customer Rewards');
    expect($specification['paths']['/admin/reward-program']['get']['tags'][0])->toBe('Admin Rewards');
    expect($specification['paths']['/restaurants/{restaurant}/reviews']['get']['tags'][0])->toBe('Restaurant Reviews');
    expect($specification['paths']['/me/saved-restaurants']['get']['tags'][0])->toBe('Customer Saved Restaurants');
    expect($specification['paths']['/me/restaurant-lists']['get']['tags'][0])->toBe('Customer Restaurant Lists');
    expect($specification['paths']['/admin/organizations/onboard']['post']['tags'][0])->toBe('Admin Organizations');
    expect($specification['paths']['/merchant/restaurants/{restaurant}/staff']['get']['tags'][0])->toBe('Merchant Staff');
    expect($specification['paths']['/merchant/restaurants/{restaurant}/menu-items']['post']['tags'][0])->toBe('Merchant Menu');
    expect($specification['paths']['/admin/restaurants']['post']['tags'][0])->toBe('Admin Restaurants');
    expect($specification['paths']['/admin/organizations']['get']['responses']['200']['content']['application/json']['schema']['required'])->toContain('data', 'links', 'meta');
    expect($specification['paths']['/admin/organizations']['get']['responses']['200']['content']['application/json']['schema']['properties']['links']['required'])->toContain('first', 'last', 'prev', 'next');
    expect($specification['paths']['/admin/restaurants']['get']['responses']['200']['content']['application/json']['schema']['required'])->toContain('data', 'links', 'meta');
    expect($specification['paths']['/admin/users']['get']['responses']['200']['content']['application/json']['schema']['required'])->toContain('data', 'links', 'meta');
    expect($specification['paths']['/admin/roles']['get']['responses']['200']['content']['application/json']['schema']['required'])->toContain('data', 'links', 'meta');
    expect($specification['paths']['/admin/reservations']['get']['responses']['200']['content']['application/json']['schema']['required'])->toContain('data', 'links', 'meta');
    expect($specification['paths']['/admin/reviews']['get']['responses']['200']['content']['application/json']['schema']['required'])->toContain('data', 'links', 'meta');
    expect($specification['paths']['/admin/onboarding-requests']['get']['responses']['200']['content']['application/json']['schema']['required'])->toContain('data', 'links', 'meta');
    expect($specification['paths']['/admin/audit-logs']['get']['responses']['200']['content']['application/json']['schema']['required'])->toContain('data', 'links', 'meta');
});
