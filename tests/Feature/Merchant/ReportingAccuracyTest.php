<?php

use App\Models\Reservation;
use App\Models\RestaurantShift;
use App\Models\User;
use App\ReservationSource;
use App\ReservationStatus;
use App\Services\Reporting\ReportingFilterService;
use App\Services\Reporting\ReportingSourceMapper;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

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
    $this->visit = fn (array $attributes = []) => Reservation::factory()->create(array_merge([
        'restaurant_id' => $this->data['restaurant']->id,
        'restaurant_table_id' => $this->data['table']->id,
        'starts_at' => '2026-09-07 12:00:00', 'ends_at' => '2026-09-07 14:00:00',
        'status' => ReservationStatus::Completed, 'party_size' => 2,
        'source' => ReservationSource::Customer,
        'seated_at' => '2026-09-07 12:00:00', 'completed_at' => '2026-09-07 13:30:00',
    ], $attributes));
});

it('resolves local calendar periods and comparisons without UTC date drift', function (): void {
    $filters = app(ReportingFilterService::class);
    $context = $filters->resolveContext(new Request(['period' => 'this_week']), $this->data['restaurant']);
    expect($context->periodStartUtc->toDateTimeString())->toBe('2026-09-06 23:00:00')
        ->and($context->periodEndUtc->toDateTimeString())->toBe('2026-09-07 23:00:00');
    $context = $filters->resolveContext(new Request(['period' => 'last_month', 'compare_period' => 'last_year']), $this->data['restaurant']);
    expect($context->periodStartUtc->toDateTimeString())->toBe('2026-07-31 23:00:00')
        ->and($context->periodEndUtc->toDateTimeString())->toBe('2026-08-31 23:00:00')
        ->and($context->compareStartUtc->toDateTimeString())->toBe('2025-07-31 23:00:00');
    $context = $filters->resolveContext(new Request(['period' => 'this_month', 'compare_period' => 'last_4_weeks']), $this->data['restaurant']);
    expect($context->compareEndUtc->equalTo($context->periodStartUtc))->toBeTrue()
        ->and((int) $context->compareStartUtc->diffInDays($context->compareEndUtc))->toBe(28);
    $context = $filters->resolveContext(new Request(['period' => 'this_year', 'date_from' => '2024-02-29', 'date_to' => '2024-02-29', 'compare_date_from' => '2023-01-01', 'compare_date_to' => '2023-01-02']), $this->data['restaurant']);
    expect($context->dayCount)->toBe(1)->and($context->compareStartUtc->toDateTimeString())->toBe('2022-12-31 23:00:00');
});

it('resolves rolling quick-filter periods and compares against the window immediately before', function (): void {
    $filters = app(ReportingFilterService::class);

    // Rolling windows are inclusive of today, so "last 7 days" spans exactly 7 days.
    foreach (['last_7_days' => 7, 'last_14_days' => 14, 'last_30_days' => 30] as $period => $days) {
        $context = $filters->resolveContext(new Request(['period' => $period]), $this->data['restaurant']);
        expect($context->dayCount)->toBe($days)
            ->and($context->periodEndUtc->toDateTimeString())->toBe('2026-09-07 23:00:00');
    }

    $context = $filters->resolveContext(new Request(['period' => 'last_12_months']), $this->data['restaurant']);
    expect($context->periodStartUtc->toDateTimeString())->toBe('2025-09-06 23:00:00');

    // previous_period must mirror the selected period's own length, whatever it is.
    foreach (['last_7_days' => 7, 'last_30_days' => 30, 'last_month' => 31] as $period => $days) {
        $context = $filters->resolveContext(
            new Request(['period' => $period, 'compare_period' => 'previous_period']),
            $this->data['restaurant'],
        );
        expect($context->compareEndUtc->equalTo($context->periodStartUtc))->toBeTrue()
            ->and((int) $context->compareStartUtc->diffInDays($context->compareEndUtc))->toBe($days);
    }

    // A calendar period running to today shifts by its own unit, not its day count:
    // the clock is Mon 2026-09-07, so "this week" is 1 day long and must compare
    // against the same 1 day of last week, not the day before this Monday.
    $context = $filters->resolveContext(
        new Request(['period' => 'this_week', 'compare_period' => 'previous_period']),
        $this->data['restaurant'],
    );
    expect($context->compareStartUtc->toDateTimeString())->toBe('2026-08-30 23:00:00')
        ->and($context->dayCount)->toBe((int) $context->compareStartUtc->diffInDays($context->compareEndUtc));

    $context = $filters->resolveContext(
        new Request(['period' => 'this_month', 'compare_period' => 'previous_period']),
        $this->data['restaurant'],
    );
    expect($context->compareStartUtc->toDateTimeString())->toBe('2026-07-31 23:00:00')
        ->and($context->dayCount)->toBe((int) $context->compareStartUtc->diffInDays($context->compareEndUtc));

    // It works for an explicit custom range too, not just the presets.
    $context = $filters->resolveContext(
        new Request(['date_from' => '2026-09-01', 'date_to' => '2026-09-03', 'compare_period' => 'previous_period']),
        $this->data['restaurant'],
    );
    expect($context->compareStartUtc->toDateTimeString())->toBe('2026-08-28 23:00:00')
        ->and($context->compareEndUtc->toDateTimeString())->toBe('2026-08-31 23:00:00');

    // Omitting compare_period must land on the same window the UI captions the
    // cards with, or the caption describes a different range than the trend.
    $explicit = $filters->resolveContext(
        new Request(['period' => 'last_7_days', 'compare_period' => 'previous_period']),
        $this->data['restaurant'],
    );
    $implicit = $filters->resolveContext(new Request(['period' => 'last_7_days']), $this->data['restaurant']);
    expect($implicit->compareStartUtc->equalTo($explicit->compareStartUtc))->toBeTrue()
        ->and($implicit->compareEndUtc->equalTo($explicit->compareEndUtc))->toBeTrue();

    $this->getJson($this->base.'/cover-trends?period=last_7_days&compare_period=previous_period')->assertOk();
    $this->getJson($this->base.'/cover-trends?period=last_7_days&compare_period=nonsense')->assertUnprocessable();

    // Every quick-filter period must be accepted by every reporting endpoint.
    foreach (ReportingFilterService::PERIODS as $period) {
        $this->getJson($this->base.'/reservations?period='.$period)->assertOk();
    }
});

it('counts phone bookings as part of your own network rather than a separate source', function (): void {
    $mapper = new ReportingSourceMapper;

    expect($mapper::chartKey(ReservationSource::Phone))->toBe(ReportingSourceMapper::CHART_NETWORK)
        ->and($mapper::chartKey(ReservationSource::Staff))->toBe(ReportingSourceMapper::CHART_NETWORK)
        ->and($mapper::chartKey(ReservationSource::Customer))->toBe(ReportingSourceMapper::CHART_MORETABLES)
        ->and($mapper::chartKey(ReservationSource::WalkIn))->toBe(ReportingSourceMapper::CHART_WALKIN)
        ->and($mapper::chartKey(ReservationSource::Waitlist))->toBe(ReportingSourceMapper::CHART_WALKIN);

    // Three buckets, not four, and every one still has a label and a colour.
    expect($mapper::chartKeys())->toBe(['walkin', 'network', 'moretables'])
        ->and(array_keys($mapper::chartKeyLabels()))->toEqualCanonicalizing($mapper::chartKeys());

    ($this->visit)(['source' => ReservationSource::Phone, 'party_size' => 2]);
    ($this->visit)(['source' => ReservationSource::Staff, 'party_size' => 3]);

    $sources = $this->getJson($this->base.'/reservations?period=this_month')->assertOk()->json('sources');

    expect($sources)->toHaveCount(3);
    $network = collect($sources)->firstWhere('label', 'Your Network');
    expect($network['count'])->toBe(5)
        ->and(collect($sources)->pluck('label'))->not->toContain('Phone/In house');
});

it('includes every reservation status but counts actual visits separately and exports the full filtered set', function (): void {
    foreach (ReservationStatus::cases() as $status) {
        ($this->visit)(['status' => $status, 'party_size' => 3]);
    }
    $reservations = $this->getJson($this->base.'/reservations?period=this_month&per_page=1&page=2')->assertOk();
    $count = count(ReservationStatus::cases());
    $reservations->assertJsonPath('summary.totalReservations.value', $count)
        ->assertJsonPath('summary.totalCovers.value', 3 * $count)->assertJsonCount(1, 'data');
    expect(array_sum(array_column($reservations->json('sources'), 'count')))->toBe(3 * $count);
    $occupancy = $this->getJson($this->base.'/shift-occupancy?period=this_month')->assertOk();
    expect(array_sum(array_column($occupancy->json('sources'), 'actual')))->toBe($count - 2);
    $occupancy->assertJsonPath('summary.0.value', 3 * ($count - 2));
    foreach (['guest-frequency', 'guest-export'] as $endpoint) {
        $response = $this->getJson($this->base.'/'.$endpoint.'?frequency_period=all_time')->assertOk();
        $response->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.totalSpend', null)->assertJsonPath('data.0.lifetimeSpend', null);
        expect(array_sum(array_column($response->json('data'), 'visits')))->toBe(2);
        $csv = $this->get($this->base.'/'.$endpoint.'/export?frequency_period=all_time&per_page=1&page=2')->assertOk()->streamedContent();
        expect(count(array_filter(explode("\n", trim($csv)))))->toBe(3)->and($csv)->toContain('—');
    }
    $csv = $this->get($this->base.'/reservations/export?period=this_month&per_page=1&page=2')->assertOk()->streamedContent();
    expect(count(array_filter(explode("\n", trim($csv)))))->toBe($count + 1);
    $this->getJson($this->base.'/reservations?period=this_month&status=cancelled')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson($this->base.'/cover-trends?period=this_month')->assertOk()->assertJsonPath('summary.value', 3 * ($count - 2));
});

it('includes older all-time data and respects the inclusive local end date', function (): void {
    foreach (['2024-05-01 10:00:00', '2026-09-06 22:59:59', '2026-09-06 23:00:00', '2026-09-07 22:59:59', '2026-09-07 23:00:00'] as $at) {
        ($this->visit)(['starts_at' => $at]);
    }
    $this->getJson($this->base.'/reservations?period=all_time')->assertOk()->assertJsonPath('meta.total', 4);
    $this->getJson($this->base.'/reservations?date_from=2026-09-07&date_to=2026-09-07')->assertOk()->assertJsonPath('meta.total', 2);
});

it('classifies using unfiltered visit history and counts distinct repeat guests', function (): void {
    $guest = User::factory()->create();
    ($this->visit)(['starts_at' => '2026-09-01 12:00:00']);
    ($this->visit)(['user_id' => $guest->id, 'starts_at' => '2026-09-02 12:00:00']);
    ($this->visit)(['user_id' => $guest->id, 'starts_at' => '2026-09-08 12:00:00']);
    ($this->visit)(['user_id' => $guest->id, 'starts_at' => '2026-09-15 12:00:00']);
    $response = $this->getJson($this->base.'/first-time-visits?date_from=2026-09-01&date_to=2026-09-30&day_of_week=2')->assertOk();
    $response->assertJsonPath('info.0.value', '1')->assertJsonPath('info.1.value', '1')->assertJsonPath('summary.value', 2);
    expect(array_sum(array_column($response->json('lineChart'), 'repeat')))->toBe(4)
        ->and(array_sum(array_column($response->json('lineChartVisits'), 'repeat')))->toBe(2)
        ->and(array_sum(array_column($response->json('partySizeChartVisits'), 'repeat')))->toBe(2);
});

it('orders chart dates chronologically across months and years', function (): void {
    foreach (['2026-01-02', '2025-12-31', '2026-01-01', '2025-01-01'] as $day) {
        ($this->visit)(['starts_at' => $day.' 12:00:00']);
    }
    $query = '?date_from=2025-01-01&date_to=2026-01-02&chart_group=day';
    $expected = ['Jan 1, 2025', 'Dec 31, 2025', 'Jan 1, 2026', 'Jan 2, 2026'];
    $response = $this->getJson($this->base.'/cover-trends'.$query)->assertOk();
    expect(array_column(array_filter($response->json('coversOverTime'), fn ($row) => $row['covers'] > 0), 'name'))->toBe($expected)
        ->and(array_column(array_filter($response->json('sourceStats'), fn ($row) => $row['moretables'] > 0), 'name'))->toBe($expected);
    expect(array_column(array_filter($this->getJson($this->base.'/shift-occupancy'.$query)->assertOk()->json('chart'), fn ($row) => $row['res'] > 0), 'name'))->toBe($expected);
    expect(array_column(array_filter($this->getJson($this->base.'/first-time-visits'.$query)->assertOk()->json('lineChart'), fn ($row) => $row['firstTime'] > 0), 'name'))->toBe($expected);
});

it('keeps zero lead times and rejects negative turn durations', function (): void {
    ($this->visit)(['created_at' => '2026-09-07 12:00:00']);
    ($this->visit)(['created_at' => '2026-09-05 12:00:00', 'seated_at' => '2026-09-07 14:00:00', 'completed_at' => '2026-09-07 13:00:00']);
    $this->getJson($this->base.'/cover-trends?period=this_month')->assertOk()->assertJsonPath('info.0.value', '1 days');
    $this->getJson($this->base.'/turn-times?period=this_month')->assertOk()->assertJsonPath('averageCards.0.value', '1 hr 30 min');
});

it('rejects invalid or foreign filter identifiers and invalid days', function (): void {
    $other = createBookableRestaurant();
    $shift = RestaurantShift::factory()->create(['restaurant_id' => $other['restaurant']->id]);
    foreach (['shift_id='.$shift->id, 'shift_id=999999', 'day_of_week=8', 'day_of_week=banana'] as $query) {
        $this->getJson($this->base.'/cover-trends?'.$query)->assertUnprocessable();
    }
});

it('selects the exact shift ID when shift names are duplicated', function (): void {
    $first = RestaurantShift::factory()->create(['restaurant_id' => $this->data['restaurant']->id, 'name' => 'Dinner', 'day_of_week' => 1, 'starts_at' => '12:00', 'ends_at' => '14:00']);
    $second = RestaurantShift::factory()->create(['restaurant_id' => $this->data['restaurant']->id, 'name' => 'Dinner', 'day_of_week' => 1, 'starts_at' => '18:00', 'ends_at' => '20:00']);
    ($this->visit)(['party_size' => 3, 'starts_at' => '2026-09-07 12:00:00']);
    ($this->visit)(['party_size' => 8, 'starts_at' => '2026-09-07 18:00:00']);
    $this->getJson($this->base.'/cover-trends?period=this_month&shift_id='.$first->id)->assertOk()->assertJsonPath('summary.value', 3);
    $this->getJson($this->base.'/cover-trends?period=this_month&shift_id='.$second->id)->assertOk()->assertJsonPath('summary.value', 8);
});

it('still requires active billing for ordinary reports', function (): void {
    $this->data['restaurant']->activeBillingSubscription()->update(['current_period_end' => now()->subDay()]);
    $this->getJson($this->base.'/reservations?period=this_month')->assertPaymentRequired();
});

it('honors guest export periods in both the list and full CSV', function (): void {
    ($this->visit)(['starts_at' => '2026-08-15 12:00:00']);
    ($this->visit)(['starts_at' => '2026-09-07 12:00:00']);
    $this->getJson($this->base.'/guest-export?frequency_period=all_time')->assertOk()->assertJsonPath('meta.total', 2);
    $this->getJson($this->base.'/guest-export?frequency_period=last_month')->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.lastVisit', 'Aug 15, 2026');
    $all = $this->get($this->base.'/guest-export/export?frequency_period=all_time&per_page=1&page=2')->assertOk()->streamedContent();
    expect($all)->toContain('Aug 15, 2026')->toContain('Sep 7, 2026');
    $last = $this->get($this->base.'/guest-export/export?frequency_period=last_month')->assertOk()->streamedContent();
    expect($last)->toContain('Aug 15, 2026')->not->toContain('Sep 7, 2026');
    $this->getJson($this->base.'/guest-export?frequency_period=invalid')->assertUnprocessable();
});

it('provides shift labels to reporting-only staff without granting restaurant settings access', function (): void {
    $shift = RestaurantShift::factory()->create(['restaurant_id' => $this->data['restaurant']->id, 'starts_at' => '18:00', 'ends_at' => '22:00']);
    $response = $this->getJson($this->base.'/filters')->assertOk();
    $option = collect($response->json('shifts'))->firstWhere('id', $shift->id);
    expect($option['starts_at'])->toStartWith('18:00')->and($option['ends_at'])->toStartWith('22:00');
    $this->getJson('/api/v1/merchant/restaurants/'.$this->data['restaurant']->id.'/shifts')->assertForbidden();
});

it('returns unavailable growth when the comparison has no covers', function (): void {
    $this->getJson($this->base.'/cover-trends?period=this_month')->assertOk()->assertJsonPath('summary.trend', null);
    ($this->visit)(['party_size' => 20]);
    $this->getJson($this->base.'/cover-trends?period=this_month')->assertOk()->assertJsonPath('summary.trend', null);
    $this->getJson($this->base.'/shift-occupancy?period=this_month')->assertOk()->assertJsonPath('summary.0.trend', null);
    $this->getJson($this->base.'/reservations?period=this_month')->assertOk()->assertJsonPath('summary.totalCovers.trend', null);
    $this->getJson($this->base.'/first-time-visits?period=this_month')->assertOk()->assertJsonPath('summary.trend', null);
    // A year-ago visit only lands in the comparison window when that's the
    // comparison asked for — the default is now the immediately preceding period.
    ($this->visit)(['party_size' => 10, 'starts_at' => '2025-09-07 12:00:00']);
    expect((float) $this->getJson($this->base.'/cover-trends?period=this_month&compare_period=last_year')->assertOk()->json('summary.trend'))->toBe(100.0);
    $this->getJson($this->base.'/cover-trends?period=this_month')->assertOk()->assertJsonPath('summary.trend', null);
});

it('uses the visited shift settings for turn-time rows and leaves empty averages unavailable', function (): void {
    $this->getJson($this->base.'/turn-times?period=this_month')->assertOk()
        ->assertJsonPath('averageCards.0.value', '—')->assertJsonPath('averageCards.1.value', '—');
    $restaurant = $this->data['restaurant'];
    $restaurant->shifts()->delete();
    $lunch = RestaurantShift::factory()->create(['restaurant_id' => $restaurant->id, 'day_of_week' => 1, 'starts_at' => '12:00', 'ends_at' => '15:00']);
    $dinner = RestaurantShift::factory()->create(['restaurant_id' => $restaurant->id, 'day_of_week' => 1, 'starts_at' => '18:00', 'ends_at' => '23:00']);
    $lunch->turnTimes()->create(['party_size' => 2, 'duration_minutes' => 60]);
    $dinner->turnTimes()->create(['party_size' => 2, 'duration_minutes' => 180]);
    ($this->visit)(['starts_at' => '2026-09-07 18:00:00', 'seated_at' => '2026-09-07 18:00:00', 'completed_at' => '2026-09-07 21:00:00']);
    $this->getJson($this->base.'/turn-times?period=this_month')->assertOk()
        ->assertJsonPath('averageCards.1.value', '+0 min')->assertJsonPath('partyRows.0.settingMin', 180)->assertJsonPath('partyRows.0.difference', '+0 min');
    ($this->visit)(['starts_at' => '2026-09-07 12:00:00', 'seated_at' => '2026-09-07 12:00:00', 'completed_at' => '2026-09-07 13:00:00']);
    $this->getJson($this->base.'/turn-times?period=this_month')->assertOk()
        ->assertJsonPath('averageCards.1.value', '+0 min')->assertJsonPath('partyRows.0.settingMin', 120)->assertJsonPath('partyRows.0.difference', '+0 min');
});

it('keeps zero calendar buckets in all time series', function (string $group, string $from, string $to, string $first, string $last): void {
    ($this->visit)(['starts_at' => $first.' 12:00:00']);
    ($this->visit)(['starts_at' => $last.' 12:00:00']);
    $query = '?date_from='.$from.'&date_to='.$to.'&chart_group='.$group;
    $cover = $this->getJson($this->base.'/cover-trends'.$query)->assertOk()->assertJsonCount(3, 'coversOverTime');
    expect(array_column($cover->json('coversOverTime'), 'covers'))->toBe([2, 0, 2]);
    expect(array_column($cover->json('sourceStats'), 'moretables'))->toBe([2, 0, 2]);
    $occupancy = $this->getJson($this->base.'/shift-occupancy'.$query)->assertOk();
    expect(array_column($occupancy->json('chart'), 'res'))->toBe([2, 0, 2]);
    $firstTime = $this->getJson($this->base.'/first-time-visits'.$query)->assertOk();
    expect(array_column($firstTime->json('lineChart'), 'firstTime'))->toBe([2, 0, 2])
        ->and(array_column($firstTime->json('lineChartVisits'), 'firstTime'))->toBe([1, 0, 1]);
})->with([
    ['day', '2026-09-01', '2026-09-03', '2026-09-01', '2026-09-03'],
    ['week', '2025-12-22', '2026-01-11', '2025-12-22', '2026-01-11'],
    ['month', '2025-12-01', '2026-02-28', '2025-12-01', '2026-02-28'],
]);
