<?php

use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\User;
use App\ReservationSource;
use App\ReservationStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Laravel\Sanctum\Sanctum;

/**
 * Golden-file guard on the reporting API's response bodies.
 *
 * Reporting is consumed by clients outside this repo, so performance work must not
 * change a single byte of output. The fixture is generated once from known-good
 * behaviour; regenerate it ONLY when a response change is deliberate and intended
 * (delete the file, re-run, and review the diff in version control).
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-07 12:00:00', 'UTC'));
    $this->seed(RoleAndPermissionSeeder::class);
    $this->data = createBookableRestaurant();
    $this->data['restaurant']->forceFill(['timezone' => 'Africa/Lagos', 'created_at' => '2024-01-01 00:00:00'])->save();
    activateMerchantBilling($this->data['restaurant']);
    $this->staff = User::factory()->create();
    grantAccessConfigPermissions($this->staff, $this->data['restaurant'], ['audit_logs.view', 'reporting.export']);
    Sanctum::actingAs($this->staff);
    $this->base = '/api/v1/merchant/restaurants/'.$this->data['restaurant']->id.'/reporting';
});

it('returns byte-identical reporting responses', function (): void {
    $contacts = collect(range(1, 3))->map(fn (int $i) => GuestContact::factory()->create([
        'restaurant_id' => $this->data['restaurant']->id,
        'is_temporary' => false,
        'first_name' => 'Guest', 'last_name' => "Number{$i}",
        'email' => "guest{$i}@example.test",
        // Pinned: the factory's faker phone otherwise makes the fixture non-deterministic.
        'phone' => '+23480000000'.$i,
    ]));

    $sources = [ReservationSource::Customer, ReservationSource::WalkIn, ReservationSource::Phone, ReservationSource::Staff];
    $statuses = [ReservationStatus::Completed, ReservationStatus::Seated, ReservationStatus::Cancelled, ReservationStatus::NoShow];

    // Deterministic spread across days, party sizes, sources and statuses. The range
    // deliberately reaches back past the comparison window (previous_period and
    // last_year both) so comparison totals — and therefore the per-day averages — are
    // non-zero and fractional, which is what makes an int-vs-float change visible here.
    foreach (range(0, 89) as $i) {
        $day = CarbonImmutable::parse('2026-06-15 12:00:00')->addDays($i);
        Reservation::factory()->create([
            'restaurant_id' => $this->data['restaurant']->id,
            'restaurant_table_id' => $this->data['table']->id,
            'guest_contact_id' => $contacts[$i % 3]->id,
            'booking_email' => null,
            'user_id' => null,
            'party_size' => ($i % 6) + 1,
            'source' => $sources[$i % 4],
            'status' => $statuses[$i % 4],
            'starts_at' => $day->format('Y-m-d H:i:s'),
            'ends_at' => $day->addHours(2)->format('Y-m-d H:i:s'),
            'seated_at' => $day->format('Y-m-d H:i:s'),
            'completed_at' => $day->addMinutes(75 + ($i % 4) * 15)->format('Y-m-d H:i:s'),
        ]);
    }

    $requests = [
        'filters' => '/filters',
        'shift-occupancy' => '/shift-occupancy?period=last_30_days&compare_period=previous_period',
        'shift-occupancy.compare-year' => '/shift-occupancy?period=this_month&compare_period=last_year',
        'cover-trends' => '/cover-trends?period=last_30_days&compare_period=previous_period',
        'cover-trends.month-group' => '/cover-trends?period=last_12_months&chart_group=month',
        'first-time-visits' => '/first-time-visits?period=last_30_days&compare_period=previous_period',
        'guest-frequency' => '/guest-frequency?frequency_period=all_time',
        'guest-frequency.last-month' => '/guest-frequency?frequency_period=last_month',
        'guest-export' => '/guest-export?frequency_period=all_time',
        'reservations' => '/reservations?period=last_30_days',
        'reservations.status' => '/reservations?period=last_30_days&status=no_show',
        'turn-times' => '/turn-times?period=last_30_days',
        'turn-times.this-week' => '/turn-times?period=this_week',
    ];

    $actual = [];
    foreach ($requests as $name => $path) {
        $actual[$name] = $this->getJson($this->base.$path)->assertOk()->json();
    }

    $csv = [];
    foreach ([
        'guest-frequency' => '/guest-frequency/export?frequency_period=all_time',
        'reservations' => '/reservations/export?period=last_30_days',
        'guest-export' => '/guest-export/export?frequency_period=all_time',
    ] as $name => $path) {
        $csv[$name] = $this->get($this->base.$path)->assertOk()->streamedContent();
    }
    $actual['__csv'] = $csv;

    $fixture = base_path('tests/Fixtures/reporting-responses.json');
    $encoded = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (! file_exists($fixture)) {
        file_put_contents($fixture, $encoded);
        $this->markTestSkipped('Generated the reporting response fixture; re-run to assert against it.');
    }

    expect($encoded)->toBe(file_get_contents($fixture));
});
