<?php

namespace App\Services\Reporting;

use App\Models\Restaurant;
use App\Models\RestaurantShift;
use App\ReservationStatus;
use App\Services\AvailabilityService;
use App\Services\RestaurantDateRangeService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportingFilterService
{
    private const PERIODS = [
        'this_week',
        'last_week',
        'last_month',
        'this_month',
        'this_year',
        'last_year',
        'all_time',
        'last_4_weeks',
    ];

    public function __construct(
        private readonly RestaurantDateRangeService $dateRanges,
        private readonly AvailabilityService $availabilityService,
    ) {}

    /**
     * @param  array{include_status?: bool, include_frequency_period?: bool}  $options
     * @return array<string, mixed>
     */
    public function validate(Request $request, array $options = []): array
    {
        $rules = [
            'date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:date_to'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:date_from', 'after_or_equal:date_from'],
            'period' => ['nullable', 'string', Rule::in(self::PERIODS)],
            'compare_period' => ['nullable', 'string', Rule::in(['last_year', 'last_4_weeks'])],
            'compare_date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:compare_date_to'],
            'compare_date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:compare_date_from', 'after_or_equal:compare_date_from'],
            'shift_id' => ['nullable', 'integer'],
            'meal_type_id' => ['nullable', 'integer'],
            'day_of_week' => ['nullable'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'export' => ['nullable', 'boolean'],
        ];

        if ($options['include_status'] ?? false) {
            $rules['status'] = ['nullable', 'string', Rule::in([
                'not_confirmed',
                ...array_column(ReservationStatus::cases(), 'value'),
            ])];
        }

        if ($options['include_frequency_period'] ?? false) {
            $rules['frequency_period'] = ['nullable', 'string', Rule::in(['all_time', 'last_month'])];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array{include_status?: bool, include_frequency_period?: bool}  $options
     */
    public function resolveContext(Request $request, Restaurant $restaurant, array $options = []): ReportingFilterContext
    {
        $validated = $this->validate($request, $options);
        $timezone = $this->timezone($restaurant);
        $now = CarbonImmutable::now($timezone);

        if ($options['include_frequency_period'] ?? false) {
            if (($validated['frequency_period'] ?? null) === 'all_time') {
                $validated['period'] = 'all_time';
            } elseif (($validated['frequency_period'] ?? null) === 'last_month') {
                $validated['period'] = 'last_month';
            }
        }

        [$periodStartLocal, $periodEndLocal] = $this->resolvePeriodBounds($validated, $restaurant, $now);
        [$compareStartLocal, $compareEndLocal] = $this->resolveCompareBounds(
            $validated,
            $periodStartLocal,
            $periodEndLocal,
            $timezone,
        );

        $periodStartUtc = $periodStartLocal->utc();
        $periodEndUtc = $periodEndLocal->utc();
        $compareStartUtc = $compareStartLocal->utc();
        $compareEndUtc = $compareEndLocal->utc();

        $dayCount = max(1, (int) $periodStartLocal->diffInDays($periodEndLocal));

        $dayOfWeek = null;
        if (isset($validated['day_of_week']) && $validated['day_of_week'] !== 'all' && $validated['day_of_week'] !== '') {
            $dayOfWeek = (int) $validated['day_of_week'];
        }

        $statusFilter = $validated['status'] ?? null;

        return new ReportingFilterContext(
            periodStartUtc: $periodStartUtc,
            periodEndUtc: $periodEndUtc,
            compareStartUtc: $compareStartUtc,
            compareEndUtc: $compareEndUtc,
            timezone: $timezone,
            dayCount: $dayCount,
            shiftId: isset($validated['shift_id']) ? (int) $validated['shift_id'] : null,
            mealTypeId: isset($validated['meal_type_id']) ? (int) $validated['meal_type_id'] : null,
            dayOfWeek: $dayOfWeek,
            statusFilter: $statusFilter,
            frequencyPeriod: $validated['frequency_period'] ?? 'all_time',
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 20),
            export: (bool) ($validated['export'] ?? false),
        );
    }

    public function baseQuery(Restaurant $restaurant, ReportingFilterContext $context): HasMany
    {
        $query = $restaurant->reservations()
            ->where('starts_at', '>=', $context->periodStartUtc)
            ->where('starts_at', '<', $context->periodEndUtc);

        $this->applyStatusFilter($query, $context->statusFilter);
        $this->applyWindowFilters($query, $restaurant, $context);

        return $query;
    }

    public function compareQuery(Restaurant $restaurant, ReportingFilterContext $context): HasMany
    {
        $query = $restaurant->reservations()
            ->where('starts_at', '>=', $context->compareStartUtc)
            ->where('starts_at', '<', $context->compareEndUtc);

        $this->applyStatusFilter($query, $context->statusFilter);

        $compareContext = new ReportingFilterContext(
            periodStartUtc: $context->compareStartUtc,
            periodEndUtc: $context->compareEndUtc,
            compareStartUtc: $context->compareStartUtc,
            compareEndUtc: $context->compareEndUtc,
            timezone: $context->timezone,
            dayCount: max(1, (int) CarbonImmutable::parse($context->compareStartUtc)->setTimezone($context->timezone)
                ->diffInDays(CarbonImmutable::parse($context->compareEndUtc)->setTimezone($context->timezone))),
            shiftId: $context->shiftId,
            mealTypeId: $context->mealTypeId,
            dayOfWeek: $context->dayOfWeek,
            statusFilter: $context->statusFilter,
            frequencyPeriod: $context->frequencyPeriod,
            page: $context->page,
            perPage: $context->perPage,
            export: $context->export,
        );

        $this->applyWindowFilters($query, $restaurant, $compareContext);

        return $query;
    }

    private function applyStatusFilter(Builder|HasMany $query, ?string $statusFilter): void
    {
        if ($statusFilter === null || $statusFilter === '') {
            $query->whereNotIn('status', [ReservationStatus::Cancelled, ReservationStatus::NoShow]);

            return;
        }

        if ($statusFilter === 'not_confirmed') {
            $query->where('status', ReservationStatus::Booked);

            return;
        }

        if ($statusFilter === ReservationStatus::Cancelled->value) {
            $query->where('status', ReservationStatus::Cancelled);

            return;
        }

        $query->where('status', ReservationStatus::from($statusFilter));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function periodPresets(): array
    {
        return array_map(
            fn (string $value): array => [
                'value' => $value,
                'label' => str($value)->replace('_', ' ')->title()->toString(),
            ],
            self::PERIODS,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolvePeriodBounds(array $validated, Restaurant $restaurant, CarbonImmutable $now): array
    {
        if (! empty($validated['date_from']) && ! empty($validated['date_to'])) {
            $start = CarbonImmutable::parse($validated['date_from'], $this->timezone($restaurant))->startOfDay();
            $end = CarbonImmutable::parse($validated['date_to'], $this->timezone($restaurant))->addDay()->startOfDay();

            return [$start, $end];
        }

        $period = $validated['period'] ?? 'this_month';

        return match ($period) {
            'this_week' => [$now->startOfWeek(CarbonImmutable::SUNDAY), $now->endOfWeek(CarbonImmutable::SATURDAY)->addDay()->startOfDay()],
            'last_week' => [
                $now->subWeek()->startOfWeek(CarbonImmutable::SUNDAY),
                $now->subWeek()->endOfWeek(CarbonImmutable::SATURDAY)->addDay()->startOfDay(),
            ],
            'last_month' => [
                $now->subMonthNoOverflow()->startOfMonth(),
                $now->subMonthNoOverflow()->endOfMonth()->addDay()->startOfDay(),
            ],
            'this_year' => [$now->startOfYear(), $now->endOfYear()->addDay()->startOfDay()],
            'last_year' => [
                $now->subYear()->startOfYear(),
                $now->subYear()->endOfYear()->addDay()->startOfDay(),
            ],
            'all_time' => [
                CarbonImmutable::parse($restaurant->created_at)->setTimezone($this->timezone($restaurant))->startOfDay(),
                $now->addDay()->startOfDay(),
            ],
            'last_4_weeks' => [$now->subWeeks(4)->startOfDay(), $now->addDay()->startOfDay()],
            default => [$now->startOfMonth(), $now->endOfMonth()->addDay()->startOfDay()],
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveCompareBounds(
        array $validated,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        string $timezone,
    ): array {
        if (! empty($validated['compare_date_from']) && ! empty($validated['compare_date_to'])) {
            $start = CarbonImmutable::parse($validated['compare_date_from'], $timezone)->startOfDay();
            $end = CarbonImmutable::parse($validated['compare_date_to'], $timezone)->addDay()->startOfDay();

            return [$start, $end];
        }

        $comparePeriod = $validated['compare_period'] ?? 'last_year';

        if ($comparePeriod === 'last_4_weeks') {
            $durationDays = max(1, (int) $periodStart->diffInDays($periodEnd));

            return [
                $periodStart->subWeeks(4),
                $periodStart->subWeeks(4)->addDays($durationDays),
            ];
        }

        return [
            $periodStart->subYear(),
            $periodEnd->subYear(),
        ];
    }

    private function applyWindowFilters(Builder|HasMany $query, Restaurant $restaurant, ReportingFilterContext $context): void
    {
        if ($context->shiftId === null && $context->mealTypeId === null && $context->dayOfWeek === null) {
            return;
        }

        $windows = $this->buildUtcWindows($restaurant, $context);

        if ($windows === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where(function (Builder $builder) use ($windows): void {
            foreach ($windows as $window) {
                $builder->orWhere(function (Builder $inner) use ($window): void {
                    $inner->where('starts_at', '>=', $window['start'])
                        ->where('starts_at', '<', $window['end']);
                });
            }
        });
    }

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function buildUtcWindows(Restaurant $restaurant, ReportingFilterContext $context): array
    {
        $timezone = $context->timezone;
        $periodStartLocal = $context->periodStartUtc->setTimezone($timezone)->startOfDay();
        $periodEndLocal = $context->periodEndUtc->setTimezone($timezone)->startOfDay();

        $shift = $context->shiftId !== null
            ? $restaurant->shifts()->find($context->shiftId)
            : null;

        $windows = [];

        foreach (CarbonPeriod::create($periodStartLocal, '1 day', $periodEndLocal->subDay()) as $date) {
            /** @var CarbonImmutable $day */
            $day = CarbonImmutable::instance($date);

            if ($context->dayOfWeek !== null && $day->dayOfWeek !== $context->dayOfWeek) {
                continue;
            }

            if ($shift instanceof RestaurantShift) {
                if ($shift->day_of_week !== $day->dayOfWeek) {
                    continue;
                }

                $range = $this->dateRanges->forTimeWindow(
                    $restaurant,
                    $day->toDateString(),
                    $this->normalizeTime($shift->starts_at),
                    $this->normalizeTime($shift->ends_at),
                );
                $windows[] = $range;

                continue;
            }

            if ($context->mealTypeId !== null) {
                $mealWindow = $this->availabilityService->timeWindowForMealType(
                    $restaurant,
                    $day->toDateString(),
                    $context->mealTypeId,
                );

                if ($mealWindow === null || empty($mealWindow['starts_at']) || empty($mealWindow['ends_at'])) {
                    continue;
                }

                $range = $this->dateRanges->forTimeWindow(
                    $restaurant,
                    $day->toDateString(),
                    $mealWindow['starts_at'],
                    $mealWindow['ends_at'],
                );
                $windows[] = $range;

                continue;
            }

            $range = $this->dateRanges->forDate($restaurant, $day->toDateString());
            $windows[] = $range;
        }

        return $windows;
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time : substr($time, 0, 5);
    }

    private function timezone(Restaurant $restaurant): string
    {
        return $restaurant->timezone ?: (string) config('app.timezone');
    }
}
