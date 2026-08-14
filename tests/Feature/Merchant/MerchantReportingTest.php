<?php

use App\BillingPlanSlug;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\User;
use App\ReservationSource;
use App\ReservationStatus;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);

    $this->data = createBookableRestaurant();
    activateMerchantBilling($this->data['restaurant']);
    // Customizable Advanced Analytics (this whole Reporting page) is
    // Premium-only (docs/PLAN_PERMISSIONS.md) — this file's existing tests
    // predate that gate and expect every report to just work, so default to
    // Premium. The plan-gating tests further down explicitly downgrade instead.
    setRestaurantBillingPlan($this->data['restaurant'], BillingPlanSlug::Premium);

    $this->staff = User::factory()->create();
    assignScopedRole($this->staff, Role::AnalyticsReporting, $this->data['organization'], $this->data['restaurant']);

    $this->reportingBase = '/api/v1/merchant/restaurants/'.$this->data['restaurant']->id.'/reporting';
    $this->dateFrom = now()->startOfMonth()->toDateString();
    $this->dateTo = now()->toDateString();
    $this->periodQuery = 'date_from='.$this->dateFrom.'&date_to='.$this->dateTo;

    $firstTimeGuest = User::factory()->create(['name' => 'Alice First']);
    $repeatGuest = User::factory()->create(['name' => 'Bob Repeat']);

    Reservation::factory()->create([
        'restaurant_id' => $this->data['restaurant']->id,
        'restaurant_table_id' => $this->data['table']->id,
        'user_id' => $repeatGuest->id,
        'party_size' => 2,
        'source' => ReservationSource::Customer,
        'status' => ReservationStatus::Completed,
        'starts_at' => now()->subMonths(2)->setTime(18, 0),
        'ends_at' => now()->subMonths(2)->setTime(20, 0),
        'seated_at' => now()->subMonths(2)->setTime(18, 5),
        'completed_at' => now()->subMonths(2)->setTime(19, 35),
    ]);

    $visitAt = now()->setTime(18, 0);
    $seatedAt = (clone $visitAt)->addMinutes(5);
    $completedAt = (clone $visitAt)->addMinutes(95);

    foreach ([
        ['user' => $firstTimeGuest, 'source' => ReservationSource::Customer, 'party_size' => 2],
        ['user' => null, 'source' => ReservationSource::WalkIn, 'party_size' => 4],
        ['user' => null, 'source' => ReservationSource::Phone, 'party_size' => 3],
        ['user' => $repeatGuest, 'source' => ReservationSource::Staff, 'party_size' => 2],
    ] as $row) {
        Reservation::factory()->create([
            'restaurant_id' => $this->data['restaurant']->id,
            'restaurant_table_id' => $this->data['table']->id,
            'user_id' => $row['user']?->id ?? User::factory()->create()->id,
            'party_size' => $row['party_size'],
            'source' => $row['source'],
            'status' => ReservationStatus::Completed,
            'starts_at' => $visitAt,
            'ends_at' => (clone $visitAt)->addHours(2),
            'seated_at' => $seatedAt,
            'completed_at' => $completedAt,
        ]);
    }

    Reservation::factory()->create([
        'restaurant_id' => $this->data['restaurant']->id,
        'restaurant_table_id' => $this->data['table']->id,
        'party_size' => 2,
        'source' => ReservationSource::Customer,
        'status' => ReservationStatus::Booked,
        'starts_at' => now()->addDay()->setTime(19, 0),
        'ends_at' => now()->addDay()->setTime(21, 0),
    ]);

    Sanctum::actingAs($this->staff);
});

it('forbids reporting without reservations.view permission', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson($this->reportingBase.'/shift-occupancy?'.$this->periodQuery)
        ->assertForbidden();
});

it('allows a staff member with only audit_logs.view to view but not export reporting', function (): void {
    $viewer = User::factory()->create();
    grantAccessConfigPermissions($viewer, $this->data['restaurant'], ['audit_logs.view']);
    Sanctum::actingAs($viewer);

    $this->getJson($this->reportingBase.'/shift-occupancy?'.$this->periodQuery)->assertSuccessful();
    $this->get($this->reportingBase.'/reservations/export?'.$this->periodQuery)->assertForbidden();
});

it('allows a staff member with only reporting.export to export reporting even without audit_logs.view', function (): void {
    $exporter = User::factory()->create();
    grantAccessConfigPermissions($exporter, $this->data['restaurant'], ['reporting.export']);
    Sanctum::actingAs($exporter);

    $this->get($this->reportingBase.'/reservations/export?'.$this->periodQuery)->assertSuccessful();
});

it('returns reporting filter metadata', function (): void {
    $this->getJson($this->reportingBase.'/filters')
        ->assertOk()
        ->assertJsonStructure([
            'periods',
            'compare_periods',
            'shifts',
            'statuses',
            'days_of_week',
        ]);
});

it('returns shift occupancy analytics', function (): void {
    $response = $this->getJson($this->reportingBase.'/shift-occupancy?'.$this->periodQuery);

    $response->assertOk()
        ->assertJsonStructure([
            'summary',
            'sources',
            'chart',
            'sourceStats',
            'circleStats' => ['resPct', 'walkPct', 'resCount', 'walkCount'],
        ]);

    expect($response->json('circleStats.walkCount'))->toBe(4);
    expect($response->json('circleStats.resCount'))->toBeGreaterThanOrEqual(7);
});

it('returns cover trends analytics', function (): void {
    $this->getJson($this->reportingBase.'/cover-trends?'.$this->periodQuery)
        ->assertOk()
        ->assertJsonStructure([
            'summary' => ['title', 'value', 'trend'],
            'info',
            'sourceStats',
            'coversOverTime',
        ]);
});

it('returns first time visits analytics', function (): void {
    $response = $this->getJson($this->reportingBase.'/first-time-visits?'.$this->periodQuery);

    $response->assertOk()
        ->assertJsonStructure([
            'summary' => ['title', 'value', 'trend'],
            'info',
            'lineChart',
            'sourceStats',
            'partySizeChart',
        ]);

    expect($response->json('info.0.label'))->toBe('First-time guests');
});

it('returns guest frequency list', function (): void {
    $response = $this->getJson($this->reportingBase.'/guest-frequency?'.$this->periodQuery.'&frequency_period=all_time');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'lastVisit',
                    'covers',
                    'visits',
                    'totalSpend',
                    'lifetimeVisits',
                    'lifetimeSpend',
                    'lifetimeCovers',
                ],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

    expect($response->json('meta.total'))->toBeGreaterThanOrEqual(3);
});

it('returns reservations reporting', function (): void {
    $response = $this->getJson($this->reportingBase.'/reservations?'.$this->periodQuery);

    $response->assertOk()
        ->assertJsonStructure([
            'summary' => [
                'totalCovers' => ['title', 'value', 'trend'],
                'totalReservations' => ['title', 'value', 'trend'],
            ],
            'sources',
            'discoveryCampaign' => ['label', 'count', 'color'],
            'data',
            'meta',
        ]);

    expect($response->json('summary.totalReservations.value'))->toBeGreaterThanOrEqual(4);
});

it('returns turn times analytics', function (): void {
    $response = $this->getJson($this->reportingBase.'/turn-times?'.$this->periodQuery);

    $response->assertOk()
        ->assertJsonStructure([
            'averageCards',
            'bySourceCards',
            'partyRows',
            'sourceRows',
        ]);

    expect($response->json('averageCards.0.value'))->not->toBeEmpty();
});

it('returns guest export list', function (): void {
    $this->getJson($this->reportingBase.'/guest-export?'.$this->periodQuery)
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'meta',
        ]);
});

it('exports guest frequency as csv', function (): void {
    // Exporting is a distinct capability from viewing (reporting.export vs
    // audit_logs.view) — $this->staff (Role::AnalyticsReporting) only has
    // audit_logs.view, so a dedicated exporter is needed here.
    $exporter = User::factory()->create();
    grantAccessConfigPermissions($exporter, $this->data['restaurant'], ['reporting.export']);
    Sanctum::actingAs($exporter);

    $response = $this->get($this->reportingBase.'/guest-frequency/export?'.$this->periodQuery.'&frequency_period=all_time');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->streamedContent())->toContain('Name');
});

it('exports reservations as csv', function (): void {
    $exporter = User::factory()->create();
    grantAccessConfigPermissions($exporter, $this->data['restaurant'], ['reporting.export']);
    Sanctum::actingAs($exporter);

    $response = $this->get($this->reportingBase.'/reservations/export?'.$this->periodQuery);

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->streamedContent())->toContain('Guest');
});

it('exports guest list as csv', function (): void {
    $exporter = User::factory()->create();
    grantAccessConfigPermissions($exporter, $this->data['restaurant'], ['reporting.export']);
    Sanctum::actingAs($exporter);

    $response = $this->get($this->reportingBase.'/guest-export/export?'.$this->periodQuery);

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
});

// Plan-tier gating — Customizable Advanced Analytics (the entire Reporting page)
// is Premium-only (docs/PLAN_PERMISSIONS.md), not just Group Reporting. Foundation
// AND Core are both blocked. $this->data['restaurant'] is Premium by default (see
// beforeEach); these tests explicitly downgrade it.

it('rejects viewing reports for a restaurant below Premium with an upgrade message', function (): void {
    setRestaurantBillingPlan($this->data['restaurant'], BillingPlanSlug::Foundation);

    $this->getJson($this->reportingBase.'/shift-occupancy?'.$this->periodQuery)
        ->assertForbidden()
        ->assertJsonPath('message', 'Upgrade to Premium to access Reporting.');
});

it('rejects viewing reports for a restaurant on Core too, not just Foundation', function (): void {
    setRestaurantBillingPlan($this->data['restaurant'], BillingPlanSlug::Core);

    $this->getJson($this->reportingBase.'/shift-occupancy?'.$this->periodQuery)
        ->assertForbidden()
        ->assertJsonPath('message', 'Upgrade to Premium to access Reporting.');
});

it('rejects exporting reports for a restaurant below Premium, even with reporting.export', function (): void {
    setRestaurantBillingPlan($this->data['restaurant'], BillingPlanSlug::Foundation);
    $exporter = User::factory()->create();
    grantAccessConfigPermissions($exporter, $this->data['restaurant'], ['reporting.export']);
    Sanctum::actingAs($exporter);

    $this->getJson($this->reportingBase.'/guest-frequency/export?'.$this->periodQuery.'&frequency_period=all_time')
        ->assertForbidden()
        ->assertJsonPath('message', 'Upgrade to Premium to access Reporting.');
});
