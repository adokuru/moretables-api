<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\ReservationSource;
use App\ReservationStatus;
use App\Services\RestaurantDateRangeService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shift Overview endpoints provide analytical breakdowns of covers
 * (total diners) for a given date and optional service window.
 *
 * A **cover** = one diner. A reservation with party_size 4 = 4 covers.
 */
#[Group('Front of House / Shift Overview', weight: 37)]
class FrontOfHouseShiftOverviewController extends Controller
{
    public function __construct(private readonly RestaurantDateRangeService $dateRanges) {}

    // ------------------------------------------------------------------ //
    //  Shared helpers                                                      //
    // ------------------------------------------------------------------ //

    private function resolveDate(Request $request, Restaurant $restaurant): Carbon
    {
        $timezone = $restaurant->timezone ?? 'UTC';

        return $request->filled('date')
            ? Carbon::parse($request->string('date')->toString(), $timezone)->startOfDay()
            : Carbon::now($timezone)->startOfDay();
    }

    private function resolveWindow(Request $request, Restaurant $restaurant, Carbon $date): array
    {
        $windowStart = null;
        $windowEnd = null;
        $mealType = null;

        if ($request->filled('meal_type_id')) {
            $mealType = $restaurant->mealTypes()
                ->with(['schedules' => fn ($q) => $q->where('day_of_week', $date->dayOfWeek)])
                ->findOrFail($request->integer('meal_type_id'));

            $schedule = $mealType->schedules->first();
            $windowStart = $schedule?->opens_at;
            $windowEnd = $schedule?->closes_at;
        } elseif ($request->filled('starts_at') && $request->filled('ends_at')) {
            $windowStart = $request->string('starts_at')->toString();
            $windowEnd = $request->string('ends_at')->toString();
        }

        return compact('windowStart', 'windowEnd', 'mealType');
    }

    private function validateCommonParams(Request $request): void
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'starts_at' => ['nullable', 'date_format:H:i', 'required_with:ends_at'],
            'ends_at' => ['nullable', 'date_format:H:i', 'required_with:starts_at', 'after:starts_at'],
            'meal_type_id' => ['nullable', 'integer', 'exists:restaurant_meal_types,id'],
            'slot_minutes' => ['nullable', 'integer', 'in:15,30,60'],
        ]);
    }

    private function periodMeta(?string $windowStart, ?string $windowEnd, $mealType): array
    {
        return [
            'meal_type' => $mealType ? ['id' => $mealType->id, 'name' => $mealType->name] : null,
            'starts_at' => $windowStart,
            'ends_at' => $windowEnd,
        ];
    }

    /**
     * Build a base reservations query scoped to the date + window.
     * Only active statuses are included (excludes cancelled).
     */
    private function baseReservationQuery(Restaurant $restaurant, Carbon $date, ?string $windowStart, ?string $windowEnd): HasMany
    {
        $range = $windowStart && $windowEnd
            ? $this->dateRanges->forTimeWindow($restaurant, $date->toDateString(), $windowStart, $windowEnd)
            : $this->dateRanges->forDate($restaurant, $date->toDateString());

        $query = $restaurant->reservations()
            ->where('starts_at', '>=', $range['start'])
            ->where('starts_at', '<', $range['end'])
            ->whereNotIn('status', [ReservationStatus::Cancelled]);

        return $query;
    }

    /**
     * Bucket a HH:MM time string into the nearest slot boundary.
     * e.g. "11:47" with 30-min slots → "11:30"
     */
    private function slotKey(string $time, int $slotMinutes): string
    {
        [$h, $m] = explode(':', $time);
        $slotted = (int) (((int) $h * 60 + (int) $m) / $slotMinutes) * $slotMinutes;

        return sprintf('%02d:%02d', intdiv($slotted, 60), $slotted % 60);
    }

    /**
     * Generate all slot keys between two HH:MM boundaries.
     *
     * @return list<string>
     */
    private function generateSlots(string $from, string $to, int $slotMinutes): array
    {
        $slots = [];
        $current = Carbon::createFromFormat('H:i', $from);
        $end = Carbon::createFromFormat('H:i', $to);

        while ($current->lte($end)) {
            $slots[] = $current->format('H:i');
            $current->addMinutes($slotMinutes);
        }

        return $slots;
    }

    // ------------------------------------------------------------------ //
    //  Endpoints                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Cover count report.
     *
     * Returns every reservation for the day bucketed into 30-minute time slots
     * (configurable via `slot_minutes`). Each slot lists the individual
     * reservations within it so the UI can render the timeline grid.
     *
     * Each reservation entry includes `party_size`, `source`, `status`, and
     * `guest_name` — enough to render a labelled, colour-coded block on the timeline.
     */
    public function coverCountReport(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        $slotMinutes = $request->integer('slot_minutes', 30);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'mealType' => $mealType] = $this->resolveWindow($request, $restaurant, $date);

        $reservations = $this->baseReservationQuery($restaurant, $date, $windowStart, $windowEnd)
            ->with(['user', 'guestContact'])
            ->orderBy('starts_at')
            ->get();

        // Group by slot
        $grouped = $reservations->groupBy(fn ($r) => $this->slotKey(
            Carbon::parse($r->starts_at)->format('H:i'),
            $slotMinutes,
        ));

        // Build ordered slots — generate full range if window is known
        $allSlotKeys = ($windowStart && $windowEnd)
            ? $this->generateSlots(
                $this->slotKey($windowStart, $slotMinutes),
                $this->slotKey($windowEnd, $slotMinutes),
                $slotMinutes,
            )
            : $grouped->keys()->sort()->values()->all();

        $slots = collect($allSlotKeys)->map(fn (string $slotKey) => [
            'time' => $slotKey,
            'time_label' => Carbon::createFromFormat('H:i', $slotKey)->format('g:i A'),
            'total_covers' => $grouped->get($slotKey, collect())->sum('party_size'),
            'reservations' => $grouped->get($slotKey, collect())->map(fn ($r) => [
                'id' => $r->id,
                'reference' => $r->reservation_reference,
                'party_size' => $r->party_size,
                'status' => $r->status?->value,
                'source' => $r->source?->value,
                'starts_at' => $r->starts_at?->toIso8601String(),
                'ends_at' => $r->ends_at?->toIso8601String(),
                'guest_name' => $r->user
                    ? $r->user->fullName()
                    : trim(($r->guestContact?->first_name ?? '').' '.($r->guestContact?->last_name ?? '')),
            ])->values(),
        ])->values();

        return response()->json([
            'date' => $date->toDateString(),
            'period' => $this->periodMeta($windowStart, $windowEnd, $mealType),
            'slot_minutes' => $slotMinutes,
            'slots' => $slots,
        ]);
    }

    /**
     * Covers by time.
     *
     * Returns the total cover count (sum of `party_size`) per time slot for the
     * day. Use this to render the bar chart on the shift overview.
     */
    public function coversByTime(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        $slotMinutes = $request->integer('slot_minutes', 30);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'mealType' => $mealType] = $this->resolveWindow($request, $restaurant, $date);

        $reservations = $this->baseReservationQuery($restaurant, $date, $windowStart, $windowEnd)
            ->orderBy('starts_at')
            ->get(['party_size', 'starts_at']);

        $grouped = $reservations->groupBy(fn ($r) => $this->slotKey(
            Carbon::parse($r->starts_at)->format('H:i'),
            $slotMinutes,
        ));

        $allSlotKeys = ($windowStart && $windowEnd)
            ? $this->generateSlots(
                $this->slotKey($windowStart, $slotMinutes),
                $this->slotKey($windowEnd, $slotMinutes),
                $slotMinutes,
            )
            : $grouped->keys()->sort()->values()->all();

        $slots = collect($allSlotKeys)->map(fn (string $slotKey) => [
            'time' => $slotKey,
            'time_label' => Carbon::createFromFormat('H:i', $slotKey)->format('g:i A'),
            'covers' => $grouped->get($slotKey, collect())->sum('party_size'),
            'parties' => $grouped->get($slotKey, collect())->count(),
        ])->values();

        return response()->json([
            'date' => $date->toDateString(),
            'period' => $this->periodMeta($windowStart, $windowEnd, $mealType),
            'slot_minutes' => $slotMinutes,
            'total_covers' => $reservations->sum('party_size'),
            'slots' => $slots,
        ]);
    }

    /**
     * Covers by source.
     *
     * Returns the total cover count broken down by how the booking originated.
     * Sources are grouped into display categories:
     *
     * | `source_group`   | Raw sources included               |
     * |------------------|------------------------------------|
     * | `phone_in_house` | `phone`, `staff`                   |
     * | `walk_in`        | `walk_in`, `waitlist`              |
     * | `online`         | `customer`                         |
     */
    public function coversBySource(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'mealType' => $mealType] = $this->resolveWindow($request, $restaurant, $date);

        $reservations = $this->baseReservationQuery($restaurant, $date, $windowStart, $windowEnd)
            ->get(['source', 'party_size']);

        $totalCovers = $reservations->sum('party_size');

        // Map raw sources to display groups
        $groupMap = [
            ReservationSource::Phone->value => 'phone_in_house',
            ReservationSource::Staff->value => 'phone_in_house',
            ReservationSource::WalkIn->value => 'walk_in',
            ReservationSource::Waitlist->value => 'walk_in',
            ReservationSource::Customer->value => 'online',
        ];

        $groupLabels = [
            'phone_in_house' => 'Phone / In house',
            'walk_in' => 'Walk-ins',
            'online' => 'Online',
        ];

        $byGroup = $reservations->groupBy(fn ($r) => $groupMap[$r->source?->value] ?? 'other');

        $breakdown = collect($groupLabels)->map(fn (string $label, string $group) => [
            'source_group' => $group,
            'label' => $label,
            'covers' => $byGroup->get($group, collect())->sum('party_size'),
            'parties' => $byGroup->get($group, collect())->count(),
            'percentage' => $totalCovers > 0
                ? round(($byGroup->get($group, collect())->sum('party_size') / $totalCovers) * 100, 1)
                : 0,
        ])->values();

        return response()->json([
            'date' => $date->toDateString(),
            'period' => $this->periodMeta($windowStart, $windowEnd, $mealType),
            'total_covers' => $totalCovers,
            'total_parties' => $reservations->count(),
            'by_source' => $breakdown,
        ]);
    }

    /**
     * Covers by party size.
     *
     * Returns the distribution of parties grouped by `party_size`. Sizes of 10
     * or more are bucketed together under `"10+"`. Use this to render the
     * "Parties by Party Size" bar chart on the shift overview.
     */
    public function coversByPartySize(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'mealType' => $mealType] = $this->resolveWindow($request, $restaurant, $date);

        $reservations = $this->baseReservationQuery($restaurant, $date, $windowStart, $windowEnd)
            ->get(['party_size']);

        // Bucket sizes ≥ 10 together
        $grouped = $reservations->groupBy(fn ($r) => $r->party_size >= 10 ? '10+' : (string) $r->party_size);

        // Build ordered list: 1–9 then 10+
        $sizeKeys = array_merge(
            array_map('strval', range(1, 9)),
            ['10+'],
        );

        $distribution = collect($sizeKeys)->map(fn (string $size) => [
            'party_size' => $size,
            'party_count' => $grouped->get($size, collect())->count(),
            'total_covers' => $grouped->get($size, collect())->sum('party_size'),
        ])->values();

        return response()->json([
            'date' => $date->toDateString(),
            'period' => $this->periodMeta($windowStart, $windowEnd, $mealType),
            'total_covers' => $reservations->sum('party_size'),
            'total_parties' => $reservations->count(),
            'by_party_size' => $distribution,
        ]);
    }
}
