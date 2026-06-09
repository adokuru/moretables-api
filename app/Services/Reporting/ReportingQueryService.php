<?php

namespace App\Services\Reporting;

use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantShift;
use App\Models\User;
use App\ReservationSource;
use App\ReservationStatus;
use App\Services\RestaurantShiftService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class ReportingQueryService
{
    private const SOURCE_COLORS = [
        ReportingSourceMapper::CHART_MORETABLES => '#7880BD',
        ReportingSourceMapper::CHART_NETWORK => '#D34645',
        ReportingSourceMapper::CHART_PHONE => '#27A001',
        ReportingSourceMapper::CHART_WALKIN => '#EAB94F',
    ];

    private const DISCOVERY_COLOR = '#F66435';

    /** @var list<array{label: string, min: int, max: int, settingSize: int}> */
    private const PARTY_BUCKETS = [
        ['label' => '1-2', 'min' => 1, 'max' => 2, 'settingSize' => 2],
        ['label' => '3-4', 'min' => 3, 'max' => 4, 'settingSize' => 4],
        ['label' => '5-6', 'min' => 5, 'max' => 6, 'settingSize' => 6],
        ['label' => '7-8', 'min' => 7, 'max' => 8, 'settingSize' => 8],
        ['label' => '9-10', 'min' => 9, 'max' => 10, 'settingSize' => 10],
        ['label' => '11+', 'min' => 11, 'max' => PHP_INT_MAX, 'settingSize' => 11],
    ];

    public function __construct(
        private readonly ReportingFilterService $filters,
        private readonly RestaurantShiftService $shiftService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function shiftOccupancy(Restaurant $restaurant, ReportingFilterContext $context): array
    {
        $current = $this->loadReservations($this->filters->baseQuery($restaurant, $context));
        $compare = $this->loadReservations($this->filters->compareQuery($restaurant, $context));

        $totalCovers = $this->sumCovers($current);
        $compareCovers = $this->sumCovers($compare);
        $walkCovers = $this->sumCovers($current->filter(fn (Reservation $r) => ReportingSourceMapper::isWalkIn($r->source)));
        $resCovers = $totalCovers - $walkCovers;

        $summary = [
            $this->summaryCard('Total Covers', $totalCovers, $compareCovers),
            $this->summaryCard('Reservation Covers', $resCovers, $this->sumCovers($compare->filter(
                fn (Reservation $r) => ! ReportingSourceMapper::isWalkIn($r->source)
            ))),
            $this->summaryCard('Walk-in Covers', $walkCovers, $this->sumCovers($compare->filter(
                fn (Reservation $r) => ReportingSourceMapper::isWalkIn($r->source)
            ))),
        ];

        $sources = [];
        foreach (ReportingSourceMapper::chartKeys() as $key) {
            $actual = $this->coversForChartKey($current, $key);
            $compareTotal = $this->coversForChartKey($compare, $key);
            $sources[] = [
                'label' => ReportingSourceMapper::displayLabel($key),
                'actual' => $actual,
                'avg' => (int) round($compareTotal / max(1, $this->compareDayCount($context))),
            ];
        }

        $chart = $this->buildResWalkChart($current, $context);
        $sourceStats = $this->buildSourceStatsSeries($current, $context);
        $circleStats = [
            'resPct' => $totalCovers > 0 ? round(($resCovers / $totalCovers) * 100, 1) : 0.0,
            'walkPct' => $totalCovers > 0 ? round(($walkCovers / $totalCovers) * 100, 1) : 0.0,
            'resCount' => $resCovers,
            'walkCount' => $walkCovers,
        ];

        return compact('summary', 'sources', 'chart', 'sourceStats', 'circleStats');
    }

    /**
     * @return array<string, mixed>
     */
    public function coverTrends(Restaurant $restaurant, ReportingFilterContext $context): array
    {
        $current = $this->loadReservations($this->filters->baseQuery($restaurant, $context));
        $compare = $this->loadReservations($this->filters->compareQuery($restaurant, $context));

        $totalCovers = $this->sumCovers($current);
        $compareCovers = $this->sumCovers($compare);

        $summary = $this->summaryCard('Total Covers', $totalCovers, $compareCovers);

        $avgLeadDays = $this->averageLeadTimeDays($current);
        $compareLeadDays = $this->averageLeadTimeDays($compare);
        $avgParty = $current->isNotEmpty() ? round($this->sumCovers($current) / $current->count(), 1) : 0.0;

        $info = [
            [
                'label' => 'Average lead time',
                'value' => $this->formatLeadTime($avgLeadDays),
                'subtitle' => 'vs '.$this->formatLeadTime($compareLeadDays).' last period',
            ],
            [
                'label' => 'Average party size',
                'value' => (string) $avgParty,
                'subtitle' => $current->count().' reservations',
            ],
            [
                'label' => 'Daily average covers',
                'value' => (string) round($totalCovers / max(1, $context->dayCount), 1),
                'subtitle' => 'over '.$context->dayCount.' days',
            ],
        ];

        return [
            'summary' => $summary,
            'info' => $info,
            'sourceStats' => $this->buildSourceStatsSeries($current, $context),
            'coversOverTime' => $this->buildCoversOverTime($current, $context),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function firstTimeVisits(Restaurant $restaurant, ReportingFilterContext $context): array
    {
        $current = $this->visitReservations($this->filters->baseQuery($restaurant, $context));
        $compare = $this->visitReservations($this->filters->compareQuery($restaurant, $context));

        $classified = $this->classifyFirstTimeVisits($restaurant, $current);
        $compareClassified = $this->classifyFirstTimeVisits($restaurant, $compare);

        $firstTimeCovers = $classified['firstTime']->sum('party_size');
        $repeatCovers = $classified['repeat']->sum('party_size');
        $compareFirst = $compareClassified['firstTime']->sum('party_size');
        $compareRepeat = $compareClassified['repeat']->sum('party_size');

        $summary = $this->summaryCard('First-time covers', $firstTimeCovers, $compareFirst);

        $firstTimePct = ($firstTimeCovers + $repeatCovers) > 0
            ? round(($firstTimeCovers / ($firstTimeCovers + $repeatCovers)) * 100, 1)
            : 0.0;

        $info = [
            [
                'label' => 'First-time guests',
                'value' => (string) $classified['firstTime']->count(),
                'subtitle' => $firstTimeCovers.' covers',
            ],
            [
                'label' => 'Repeat guests',
                'value' => (string) $classified['repeat']->count(),
                'subtitle' => $repeatCovers.' covers',
            ],
            [
                'label' => 'First-time share',
                'value' => $firstTimePct.'%',
                'subtitle' => 'of visit covers',
                'subtitleClassName' => 'text-[#27A001]',
            ],
        ];

        return [
            'summary' => $summary,
            'info' => $info,
            'lineChart' => $this->buildFirstTimeLineChart($classified, $context),
            'sourceStats' => $this->buildSourceStatsFromVisits($classified['firstTime'], $context),
            'partySizeChart' => $this->buildPartySizeChart($classified, $compareClassified, $context),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function guestFrequency(Restaurant $restaurant, ReportingFilterContext $context): array
    {
        $rows = $this->buildGuestRows($restaurant, $this->filters->baseQuery($restaurant, $context), $context);
        $sorted = $rows->sortByDesc('visits')->values();

        return $this->paginateGuestRows($sorted, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function guestExport(Restaurant $restaurant, ReportingFilterContext $context): array
    {
        $rows = $this->buildGuestRows($restaurant, $this->filters->baseQuery($restaurant, $context), $context);
        $sorted = $rows->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return $this->paginateGuestRows($sorted, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function reservations(Restaurant $restaurant, ReportingFilterContext $context): array
    {
        $query = $this->filters->baseQuery($restaurant, $context);
        $reservations = $this->loadReservations($query);

        $totalCovers = $this->sumCovers($reservations);
        $compare = $this->loadReservations($this->filters->compareQuery($restaurant, $context));
        $compareCovers = $this->sumCovers($compare);

        $summary = [
            'totalCovers' => $this->summaryCard('Total Covers', $totalCovers, $compareCovers),
            'totalReservations' => $this->summaryCard('Total Reservations', $reservations->count(), $compare->count()),
        ];

        $sources = [];
        foreach (ReportingSourceMapper::chartKeys() as $key) {
            $sources[] = [
                'label' => ReportingSourceMapper::displayLabel($key),
                'count' => $this->coversForChartKey($reservations, $key),
                'color' => self::SOURCE_COLORS[$key],
            ];
        }

        $discoveryCount = $this->coversForChartKey($reservations, ReportingSourceMapper::CHART_MORETABLES);

        $sorted = $reservations->sortByDesc('starts_at')->values();

        $paginated = $context->export
            ? [
                'items' => $sorted,
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $sorted->count(),
                    'total' => $sorted->count(),
                ],
            ]
            : $this->paginateCollection($sorted, $context->page, $context->perPage);

        $data = $paginated['items']->map(fn (Reservation $r) => $this->formatReservationRow($r, $context))->values()->all();

        return [
            'summary' => $summary,
            'sources' => $sources,
            'discoveryCampaign' => [
                'label' => 'MoreTables discovery',
                'count' => $discoveryCount,
                'color' => self::DISCOVERY_COLOR,
            ],
            'data' => $data,
            'meta' => $paginated['meta'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function turnTimes(Restaurant $restaurant, ReportingFilterContext $context): array
    {
        $reservations = $this->turnTimeReservations($this->filters->baseQuery($restaurant, $context));
        $restaurant->loadMissing('shifts.turnTimes');

        $durations = $reservations->map(function (Reservation $reservation) use ($restaurant, $context): ?array {
            if ($reservation->seated_at === null || $reservation->completed_at === null) {
                return null;
            }

            $minutes = (int) $reservation->seated_at->diffInMinutes($reservation->completed_at);
            $localStart = $reservation->starts_at->setTimezone($context->timezone);
            $shift = $this->shiftService->resolveShiftForSlot($restaurant, $localStart);
            $settingMinutes = $shift instanceof RestaurantShift
                ? $this->shiftService->turnDurationForPartySize($shift, $reservation->party_size, 120)
                : 120;

            return [
                'minutes' => $minutes,
                'party_size' => $reservation->party_size,
                'source' => ReportingSourceMapper::chartKey($reservation->source),
                'settingMinutes' => $settingMinutes,
            ];
        })->filter()->values();

        $overallAvg = $durations->isNotEmpty()
            ? (int) round($durations->avg('minutes'))
            : 0;
        $overallSetting = $durations->isNotEmpty()
            ? (int) round($durations->avg('settingMinutes'))
            : 0;

        $averageCards = [
            [
                'title' => 'Average turn time',
                'value' => $this->formatDuration($overallAvg),
                'subtitle' => 'Across all completed visits',
            ],
            [
                'title' => 'Average vs setting',
                'value' => $this->formatDifference($overallAvg - $overallSetting),
                'subtitle' => 'Setting '.$this->formatDuration($overallSetting),
            ],
        ];

        $bySourceCards = collect(self::PARTY_BUCKETS)->take(2)->map(function (array $bucket) use ($durations): array {
            $bucketDurations = $durations->filter(
                fn (array $row) => $row['party_size'] >= $bucket['min'] && $row['party_size'] <= $bucket['max']
            );

            $sources = collect(ReportingSourceMapper::chartKeys())->map(function (string $key) use ($bucketDurations): array {
                $subset = $bucketDurations->where('source', $key);
                $avg = $subset->isNotEmpty() ? (int) round($subset->avg('minutes')) : 0;

                return [
                    'name' => ReportingSourceMapper::displayLabel($key),
                    'value' => $subset->isEmpty() ? '—' : $this->formatDuration($avg),
                ];
            })->all();

            return [
                'title' => $bucket['label'].' guests',
                'sources' => $sources,
            ];
        })->all();

        $partyRows = collect(self::PARTY_BUCKETS)->map(function (array $bucket) use ($durations, $restaurant, $context): array {
            $bucketDurations = $durations->filter(
                fn (array $row) => $row['party_size'] >= $bucket['min'] && $row['party_size'] <= $bucket['max']
            );
            $averageMin = $bucketDurations->isNotEmpty()
                ? (int) round($bucketDurations->avg('minutes'))
                : 0;

            $settingMin = $this->settingForBucket($restaurant, $bucket['settingSize'], $context);

            return [
                'size' => $bucket['label'],
                'setting' => $this->formatDuration($settingMin),
                'average' => $bucketDurations->isEmpty() ? '—' : $this->formatDuration($averageMin),
                'difference' => $bucketDurations->isEmpty() ? '—' : $this->formatDifference($averageMin - $settingMin),
                'settingMin' => $settingMin,
                'averageMin' => $averageMin,
                'dotColor' => $averageMin > $settingMin ? '#D34645' : '#27A001',
            ];
        })->all();

        $sourceRows = collect(ReportingSourceMapper::chartKeys())->map(function (string $key) use ($durations): array {
            $values = collect(self::PARTY_BUCKETS)->map(function (array $bucket) use ($durations, $key): string {
                $subset = $durations->filter(
                    fn (array $row) => $row['source'] === $key
                        && $row['party_size'] >= $bucket['min']
                        && $row['party_size'] <= $bucket['max']
                );

                return $subset->isEmpty()
                    ? '—'
                    : $this->formatDuration((int) round($subset->avg('minutes')));
            })->all();

            return [
                'source' => ReportingSourceMapper::displayLabel($key),
                'values' => $values,
            ];
        })->all();

        return compact('averageCards', 'bySourceCards', 'partyRows', 'sourceRows');
    }

    /**
     * @return array<string, mixed>
     */
    public function filtersMetadata(Restaurant $restaurant): array
    {
        $restaurant->loadMissing('shifts');

        return [
            'periods' => $this->filters->periodPresets(),
            'compare_periods' => [
                ['value' => 'last_year', 'label' => 'Last Year'],
                ['value' => 'last_4_weeks', 'label' => 'Last 4 Weeks'],
            ],
            'shifts' => $restaurant->shifts->map(fn (RestaurantShift $shift) => [
                'id' => $shift->id,
                'name' => $shift->name,
                'day_of_week' => $shift->day_of_week,
            ])->values()->all(),
            'statuses' => array_merge(
                [['value' => 'not_confirmed', 'label' => 'Not confirmed']],
                collect(ReservationStatus::cases())->map(fn (ReservationStatus $status) => [
                    'value' => $status->value,
                    'label' => str($status->value)->replace('_', ' ')->title()->toString(),
                ])->all(),
            ),
            'days_of_week' => collect(range(0, 6))->map(fn (int $day) => [
                'value' => $day,
                'label' => CarbonImmutable::now()->startOfWeek(CarbonImmutable::SUNDAY)->addDays($day)->format('l'),
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function guestRowsToCsv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'Name',
            'Last Visit',
            'Covers',
            'Visits',
            'Total Spend',
            'Lifetime Visits',
            'Lifetime Spend',
            'Lifetime Covers',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['name'],
                $row['lastVisit'],
                $row['covers'],
                $row['visits'],
                $row['totalSpend'],
                $row['lifetimeVisits'],
                $row['lifetimeSpend'],
                $row['lifetimeCovers'] ?? 0,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function reservationRowsToCsv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Guest Name', 'Visit Date', 'Phone', 'Size', 'Source', 'Status']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['guestName'],
                $row['visitDate'],
                $row['phone'],
                $row['size'],
                $row['source'],
                $row['status'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * @return EloquentCollection<int, Reservation>
     */
    private function loadReservations(HasMany $query): EloquentCollection
    {
        return $query
            ->with(['user', 'guestContact'])
            ->where(function ($builder): void {
                $builder->whereNull('guest_contact_id')
                    ->orWhereHas('guestContact', fn ($guest) => $guest->where('is_temporary', false));
            })
            ->get();
    }

    /**
     * @return EloquentCollection<int, Reservation>
     */
    private function visitReservations(HasMany $query): EloquentCollection
    {
        return $this->loadReservations($query)->filter(
            fn (Reservation $r) => in_array($r->status, [ReservationStatus::Seated, ReservationStatus::Completed], true)
        )->values();
    }

    /**
     * @return EloquentCollection<int, Reservation>
     */
    private function turnTimeReservations(HasMany $query): EloquentCollection
    {
        $query
            ->whereNotNull('seated_at')
            ->whereNotNull('completed_at')
            ->whereIn('status', [ReservationStatus::Seated, ReservationStatus::Completed]);

        return $this->loadReservations($query);
    }

  /**
     * @param  EloquentCollection<int, Reservation>  $reservations
     */
    private function sumCovers(EloquentCollection|Collection $reservations): int
    {
        return (int) $reservations->sum('party_size');
    }

    /**
     * @param  EloquentCollection<int, Reservation>  $reservations
     */
    private function coversForChartKey(EloquentCollection|Collection $reservations, string $key): int
    {
        return (int) $reservations
            ->filter(fn (Reservation $r) => ReportingSourceMapper::chartKey($r->source) === $key)
            ->sum('party_size');
    }

    /**
     * @return array{title: string, value: int|string, trend: float}
     */
    private function summaryCard(string $title, int|float $current, int|float $previous): array
    {
        return [
            'title' => $title,
            'value' => $current,
            'trend' => $this->percentageChange($current, $previous),
        ];
    }

    private function percentageChange(int|float $current, int|float $previous): float
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : 100.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function compareDayCount(ReportingFilterContext $context): int
    {
        return max(1, (int) $context->compareStartUtc->setTimezone($context->timezone)
            ->diffInDays($context->compareEndUtc->setTimezone($context->timezone)));
    }

    /**
     * @param  EloquentCollection<int, Reservation>  $reservations
     * @return list<array{name: string, res: int, walk: int}>
     */
    private function buildResWalkChart(EloquentCollection $reservations, ReportingFilterContext $context): array
    {
        $grouped = $reservations->groupBy(fn (Reservation $r) => $this->periodLabel($r->starts_at, $context));

        return $grouped->map(function (Collection $items, string $name): array {
            $walk = $this->sumCovers($items->filter(fn (Reservation $r) => ReportingSourceMapper::isWalkIn($r->source)));

            return [
                'name' => $name,
                'res' => $this->sumCovers($items) - $walk,
                'walk' => $walk,
            ];
        })->sortKeys()->values()->all();
    }

    /**
     * @param  EloquentCollection<int, Reservation>  $reservations
     * @return list<array{name: string, walkin: int, phone: int, network: int, moretables: int}>
     */
    private function buildSourceStatsSeries(EloquentCollection $reservations, ReportingFilterContext $context): array
    {
        return $reservations
            ->groupBy(fn (Reservation $r) => $this->periodLabel($r->starts_at, $context))
            ->map(function (Collection $items, string $name): array {
                $stats = $this->emptySourceStats($name);
                foreach ($items as $reservation) {
                    $key = ReportingSourceMapper::chartKey($reservation->source);
                    $stats[$key] += $reservation->party_size;
                }

                return $stats;
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * @param  EloquentCollection<int, Reservation>  $firstTime
     * @return list<array{name: string, walkin: int, phone: int, network: int, moretables: int}>
     */
    private function buildSourceStatsFromVisits(EloquentCollection $firstTime, ReportingFilterContext $context): array
    {
        return $this->buildSourceStatsSeries($firstTime, $context);
    }

    /**
     * @return array{name: string, walkin: int, phone: int, network: int, moretables: int}
     */
    private function emptySourceStats(string $name): array
    {
        return [
            'name' => $name,
            'walkin' => 0,
            'phone' => 0,
            'network' => 0,
            'moretables' => 0,
        ];
    }

    /**
     * @param  EloquentCollection<int, Reservation>  $reservations
     * @return list<array{name: string, covers: int}>
     */
    private function buildCoversOverTime(EloquentCollection $reservations, ReportingFilterContext $context): array
    {
        return $reservations
            ->groupBy(fn (Reservation $r) => $this->periodLabel($r->starts_at, $context))
            ->map(fn (Collection $items, string $name) => [
                'name' => $name,
                'covers' => $this->sumCovers($items),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }

    private function periodLabel(CarbonInterface $startsAt, ReportingFilterContext $context): string
    {
        $local = CarbonImmutable::parse($startsAt)->setTimezone($context->timezone);

        return $context->dayCount <= 31
            ? $local->format('M j')
            : $local->format('M Y');
    }

    /**
     * @param  EloquentCollection<int, Reservation>  $reservations
     */
    private function averageLeadTimeDays(EloquentCollection $reservations): float
    {
        $samples = $reservations->map(function (Reservation $reservation): ?float {
            if ($reservation->created_at === null) {
                return null;
            }

            return max(0, $reservation->created_at->diffInHours($reservation->starts_at) / 24);
        })->filter();

        return $samples->isEmpty() ? 0.0 : round($samples->avg(), 1);
    }

    private function formatLeadTime(float $days): string
    {
        if ($days < 1) {
            return round($days * 24).' hrs';
        }

        return round($days, 1).' days';
    }

    /**
     * @param  EloquentCollection<int, Reservation>  $visits
     * @return array{firstTime: EloquentCollection<int, Reservation>, repeat: EloquentCollection<int, Reservation>}
     */
    private function classifyFirstTimeVisits(Restaurant $restaurant, EloquentCollection $visits): array
    {
        $priorCounts = $this->priorVisitCounts($restaurant, $visits);

        $firstTime = collect();
        $repeat = collect();

        foreach ($visits->sortBy('starts_at') as $visit) {
            $key = $this->guestKey($visit);
            if ($key === null) {
                continue;
            }

            $seenBefore = ($priorCounts[$key] ?? 0) > 0;
            if ($seenBefore) {
                $repeat->push($visit);
            } else {
                $firstTime->push($visit);
            }

            $priorCounts[$key] = ($priorCounts[$key] ?? 0) + 1;
        }

        return [
            'firstTime' => new EloquentCollection($firstTime->all()),
            'repeat' => new EloquentCollection($repeat->all()),
        ];
    }

    /**
     * @param  EloquentCollection<int, Reservation>  $visits
     * @return array<string, int>
     */
    private function priorVisitCounts(Restaurant $restaurant, EloquentCollection $visits): array
    {
        if ($visits->isEmpty()) {
            return [];
        }

        $earliest = $visits->min('starts_at');
        $guestKeys = $visits->map(fn (Reservation $r) => $this->guestKey($r))->filter()->unique()->values();

        $prior = $restaurant->reservations()
            ->where('starts_at', '<', $earliest)
            ->whereIn('status', [ReservationStatus::Seated, ReservationStatus::Completed])
            ->where(function ($builder): void {
                $builder->whereNull('guest_contact_id')
                    ->orWhereHas('guestContact', fn ($guest) => $guest->where('is_temporary', false));
            })
            ->get(['guest_contact_id', 'user_id']);

        $counts = [];
        foreach ($prior as $reservation) {
            $key = $this->guestKey($reservation);
            if ($key === null || ! $guestKeys->contains($key)) {
                continue;
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array{firstTime: EloquentCollection<int, Reservation>, repeat: EloquentCollection<int, Reservation>}  $classified
     * @return list<array{name: string, firstTime: int, repeat: int}>
     */
    private function buildFirstTimeLineChart(array $classified, ReportingFilterContext $context): array
    {
        $labels = $classified['firstTime']
            ->merge($classified['repeat'])
            ->groupBy(fn (Reservation $r) => $this->periodLabel($r->starts_at, $context))
            ->keys()
            ->sort()
            ->values();

        return $labels->map(function (string $name) use ($classified, $context): array {
            $first = $classified['firstTime']->filter(
                fn (Reservation $r) => $this->periodLabel($r->starts_at, $context) === $name
            );
            $repeat = $classified['repeat']->filter(
                fn (Reservation $r) => $this->periodLabel($r->starts_at, $context) === $name
            );

            return [
                'name' => $name,
                'firstTime' => $this->sumCovers($first),
                'repeat' => $this->sumCovers($repeat),
            ];
        })->all();
    }

    /**
     * @param  array{firstTime: EloquentCollection<int, Reservation>, repeat: EloquentCollection<int, Reservation>}  $classified
     * @param  array{firstTime: EloquentCollection<int, Reservation>, repeat: EloquentCollection<int, Reservation>}  $compareClassified
     * @return list<array<string, int|string>>
     */
    private function buildPartySizeChart(array $classified, array $compareClassified, ReportingFilterContext $context): array
    {
        $labels = ['1', '2', '3', '4', '5', '6+'];

        return collect($labels)->map(function (string $label) use ($classified, $compareClassified): array {
            $firstTime = $this->sumCoversForPartyLabel($classified['firstTime'], $label);
            $repeat = $this->sumCoversForPartyLabel($classified['repeat'], $label);
            $lastYearFirst = $this->sumCoversForPartyLabel($compareClassified['firstTime'], $label);
            $lastYearRepeat = $this->sumCoversForPartyLabel($compareClassified['repeat'], $label);

            return [
                'name' => $label,
                'lastYearFirst' => $lastYearFirst,
                'firstTime' => $firstTime,
                'spacer' => 0,
                'repeat' => $repeat,
                'lastYearRepeat' => $lastYearRepeat,
            ];
        })->all();
    }

    /**
     * @param  EloquentCollection<int, Reservation>  $reservations
     */
    private function sumCoversForPartyLabel(EloquentCollection $reservations, string $label): int
    {
        return (int) $reservations
            ->filter(fn (Reservation $r) => $this->partySizeLabel($r->party_size) === $label)
            ->sum('party_size');
    }

    private function partySizeLabel(int $partySize): string
    {
        return $partySize >= 6 ? '6+' : (string) $partySize;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildGuestRows(Restaurant $restaurant, HasMany $query, ReportingFilterContext $context): Collection
    {
        $reservations = $this->loadReservations($query);
        $lifetime = $this->lifetimeGuestStats($restaurant);

        $grouped = [];
        foreach ($reservations as $reservation) {
            $key = $this->guestKey($reservation);
            if ($key === null) {
                continue;
            }

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'id' => $this->guestRowId($reservation),
                    'name' => $this->guestName($reservation),
                    'lastVisit' => $reservation->starts_at->setTimezone($context->timezone)->format('M j, Y'),
                    'covers' => 0,
                    'visits' => 0,
                    'totalSpend' => $this->formatCurrency(0),
                    'lifetimeVisits' => $lifetime[$key]['visits'] ?? 0,
                    'lifetimeSpend' => $this->formatCurrency(0),
                    'lifetimeCovers' => $lifetime[$key]['covers'] ?? 0,
                    '_lastVisitAt' => $reservation->starts_at,
                ];
            }

            $grouped[$key]['covers'] += $reservation->party_size;
            $grouped[$key]['visits'] += 1;

            if ($reservation->starts_at->greaterThan($grouped[$key]['_lastVisitAt'])) {
                $grouped[$key]['_lastVisitAt'] = $reservation->starts_at;
                $grouped[$key]['lastVisit'] = $reservation->starts_at->setTimezone($context->timezone)->format('M j, Y');
            }
        }

        return collect($grouped)->map(function (array $row): array {
            unset($row['_lastVisitAt']);

            return $row;
        })->values();
    }

    /**
     * @return array<string, array{visits: int, covers: int}>
     */
    private function lifetimeGuestStats(Restaurant $restaurant): array
    {
        $rows = $restaurant->reservations()
            ->whereIn('status', [ReservationStatus::Seated, ReservationStatus::Completed])
            ->where(function ($builder): void {
                $builder->whereNull('guest_contact_id')
                    ->orWhereHas('guestContact', fn ($guest) => $guest->where('is_temporary', false));
            })
            ->get(['guest_contact_id', 'user_id', 'party_size']);

        $stats = [];
        foreach ($rows as $reservation) {
            $key = $this->guestKey($reservation);
            if ($key === null) {
                continue;
            }

            if (! isset($stats[$key])) {
                $stats[$key] = ['visits' => 0, 'covers' => 0];
            }

            $stats[$key]['visits']++;
            $stats[$key]['covers'] += $reservation->party_size;
        }

        return $stats;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    private function paginateGuestRows(Collection $rows, ReportingFilterContext $context): array
    {
        if ($context->export) {
            return [
                'data' => $rows->values()->all(),
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $rows->count(),
                    'total' => $rows->count(),
                ],
            ];
        }

        $paginated = $this->paginateCollection($rows, $context->page, $context->perPage);

        return [
            'data' => $paginated['items']->values()->all(),
            'meta' => $paginated['meta'],
        ];
    }

    /**
     * @return array{items: Collection, meta: array<string, int>}
     */
    private function paginateCollection(Collection $items, int $page, int $perPage): array
    {
        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'items' => $items->slice($offset, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatReservationRow(Reservation $reservation, ReportingFilterContext $context): array
    {
        return [
            'id' => $reservation->id,
            'guestName' => $this->guestName($reservation),
            'visitDate' => $reservation->starts_at->setTimezone($context->timezone)->format('M j, Y g:i A'),
            'phone' => $reservation->guestContact?->phone ?? $reservation->user?->phone ?? '',
            'size' => $reservation->party_size,
            'source' => ReportingSourceMapper::displayLabel(ReportingSourceMapper::chartKey($reservation->source)),
            'status' => str($reservation->status->value)->replace('_', ' ')->title()->toString(),
        ];
    }

    private function guestKey(Reservation $reservation): ?string
    {
        if ($reservation->guest_contact_id) {
            return 'gc:'.$reservation->guest_contact_id;
        }

        if ($reservation->user_id) {
            return 'u:'.$reservation->user_id;
        }

        return null;
    }

    private function guestRowId(Reservation $reservation): int
    {
        return (int) ($reservation->guest_contact_id ?? $reservation->user_id ?? $reservation->id);
    }

    private function guestName(Reservation $reservation): string
    {
        if ($reservation->guestContact instanceof GuestContact) {
            return trim($reservation->guestContact->first_name.' '.$reservation->guestContact->last_name);
        }

        if ($reservation->user instanceof User) {
            return $reservation->user->fullName();
        }

        return 'Guest';
    }

    private function formatCurrency(int $amount): string
    {
        return '₦'.number_format($amount);
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0 min';
        }

        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        if ($hours === 0) {
            return $mins.' min';
        }

        if ($mins === 0) {
            return $hours.' hr';
        }

        return $hours.' hr '.$mins.' min';
    }

    private function formatDifference(int $diffMinutes): string
    {
        $sign = $diffMinutes >= 0 ? '+' : '-';
        $abs = abs($diffMinutes);

        if ($abs < 60) {
            return $sign.$abs.' min';
        }

        $hours = intdiv($abs, 60);
        $mins = $abs % 60;

        if ($mins === 0) {
            return $sign.$hours.' hr';
        }

        return $sign.$hours.' hr '.$mins.' min';
    }

    private function settingForBucket(Restaurant $restaurant, int $partySize, ReportingFilterContext $context): int
    {
        $shift = $context->shiftId !== null
            ? $restaurant->shifts->firstWhere('id', $context->shiftId)
            : $restaurant->shifts->first();

        if (! $shift instanceof RestaurantShift) {
            return 120;
        }

        $shift->loadMissing('turnTimes');

        return $this->shiftService->turnDurationForPartySize($shift, $partySize, 120);
    }
}
