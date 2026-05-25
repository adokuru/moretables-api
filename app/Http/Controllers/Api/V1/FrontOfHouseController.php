<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\WaitlistEntryResource;
use App\Models\Restaurant;
use App\Models\RestaurantMealType;
use App\ReservationStatus;
use App\WaitlistStatus;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Front of House endpoints give restaurant staff a real-time operational view
 * of the dining room for any given date and service period.
 */
#[Group('Front of House / Dashboard', weight: 35)]
class FrontOfHouseController extends Controller
{
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

    /**
     * @return array{windowStart: ?string, windowEnd: ?string, mealType: ?RestaurantMealType}
     */
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
        ]);
    }

    private function periodMeta(?string $windowStart, ?string $windowEnd, ?RestaurantMealType $mealType): array
    {
        return [
            'meal_type' => $mealType ? ['id' => $mealType->id, 'name' => $mealType->name] : null,
            'starts_at' => $windowStart,
            'ends_at' => $windowEnd,
        ];
    }

    // ------------------------------------------------------------------ //
    //  Endpoints                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Get the front-of-house summary.
     *
     * Returns counts and expected diner totals for the requested date and optional
     * time window — no reservation or waitlist payloads. Use the individual section
     * endpoints to fetch the actual records.
     */
    public function summary(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'mealType' => $mealType] = $this->resolveWindow($request, $restaurant, $date);

        $baseRes = $restaurant->reservations()->whereDate('starts_at', $date->toDateString());

        if ($windowStart && $windowEnd) {
            $baseRes->whereTime('starts_at', '>=', $windowStart)
                ->whereTime('starts_at', '<=', $windowEnd);
        }

        $reservationCount = (clone $baseRes)
            ->whereIn('status', [ReservationStatus::Booked, ReservationStatus::Confirmed])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(party_size), 0) as diners')
            ->first();

        $arrivedCount = (clone $baseRes)
            ->where('status', ReservationStatus::Arrived)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(party_size), 0) as diners')
            ->first();

        $seatedCount = (clone $baseRes)
            ->where('status', ReservationStatus::Seated)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(party_size), 0) as diners')
            ->first();

        $finishedCount = (clone $baseRes)
            ->where('status', ReservationStatus::Completed)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(party_size), 0) as diners')
            ->first();

        $noShowCount = (clone $baseRes)
            ->where('status', ReservationStatus::NoShow)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(party_size), 0) as diners')
            ->first();

        $waitlistQuery = $restaurant->waitlistEntries()
            ->whereIn('status', [WaitlistStatus::Waiting, WaitlistStatus::Notified, WaitlistStatus::Accepted])
            ->whereDate('created_at', $date->toDateString());

        if ($windowStart && $windowEnd) {
            $waitlistQuery->where(fn ($q) => $q
                ->whereNull('preferred_starts_at')
                ->orWhere(fn ($inner) => $inner
                    ->whereTime('preferred_starts_at', '>=', $windowStart)
                    ->whereTime('preferred_starts_at', '<=', $windowEnd)
                )
            );
        }

        $waitlistCount = $waitlistQuery
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(party_size), 0) as diners')
            ->first();

        return response()->json([
            'date' => $date->toDateString(),
            'period' => $this->periodMeta($windowStart, $windowEnd, $mealType),
            'summary' => [
                'expected_diners'    => (int) $reservationCount->diners + (int) $arrivedCount->diners + (int) $seatedCount->diners,
                'reservation_count'  => (int) $reservationCount->count,
                'reservation_diners' => (int) $reservationCount->diners,
                'arrived_count'      => (int) $arrivedCount->count,
                'arrived_diners'     => (int) $arrivedCount->diners,
                'waitlist_count'     => (int) $waitlistCount->count,
                'waitlist_diners'    => (int) $waitlistCount->diners,
                'seated_count'       => (int) $seatedCount->count,
                'seated_diners'      => (int) $seatedCount->diners,
                'finished_count'     => (int) $finishedCount->count,
                'finished_diners'    => (int) $finishedCount->diners,
                'no_show_count'      => (int) $noShowCount->count,
                'no_show_diners'     => (int) $noShowCount->diners,
            ],
        ]);
    }

    /**
     * List upcoming reservations.
     *
     * Returns paginated reservations with a status of `booked` or `confirmed` —
     * guests expected but not yet arrived — for the requested date and optional
     * service window.
     */
    public function reservations(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'mealType' => $mealType] = $this->resolveWindow($request, $restaurant, $date);

        $query = $restaurant->reservations()
            ->with(['table', 'user', 'guestContact', 'reservationGuests'])
            ->whereDate('starts_at', $date->toDateString())
            ->whereIn('status', [ReservationStatus::Booked, ReservationStatus::Confirmed])
            ->orderBy('starts_at');

        if ($windowStart && $windowEnd) {
            $query->whereTime('starts_at', '>=', $windowStart)
                ->whereTime('starts_at', '<=', $windowEnd);
        }

        $reservations = $query->paginate(20);

        return response()->json([
            'date' => $date->toDateString(),
            'period' => $this->periodMeta($windowStart, $windowEnd, $mealType),
            ...(ReservationResource::collection($reservations)->response()->getData(true)),
        ]);
    }

    /**
     * List arrived reservations.
     *
     * Returns paginated reservations with a status of `arrived` — guests who have
     * checked in but are not yet seated — for the requested date and optional
     * service window.
     */
    public function arrived(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'mealType' => $mealType] = $this->resolveWindow($request, $restaurant, $date);

        $query = $restaurant->reservations()
            ->with(['table', 'user', 'guestContact', 'reservationGuests'])
            ->whereDate('starts_at', $date->toDateString())
            ->where('status', ReservationStatus::Arrived)
            ->orderBy('starts_at');

        if ($windowStart && $windowEnd) {
            $query->whereTime('starts_at', '>=', $windowStart)
                ->whereTime('starts_at', '<=', $windowEnd);
        }

        $reservations = $query->paginate(20);

        return response()->json([
            'date' => $date->toDateString(),
            'period' => $this->periodMeta($windowStart, $windowEnd, $mealType),
            ...(ReservationResource::collection($reservations)->response()->getData(true)),
        ]);
    }

    /**
     * List active waitlist entries.
     *
     * Returns paginated walk-in waitlist entries with a status of `waiting`,
     * `notified`, or `accepted` for the requested date and optional service window.
     */
    public function waitlist(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('waitlist.manage', $restaurant), 403);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'mealType' => $mealType] = $this->resolveWindow($request, $restaurant, $date);

        $query = $restaurant->waitlistEntries()
            ->with(['reservation.reservationGuests', 'user', 'guestContact'])
            ->whereIn('status', [WaitlistStatus::Waiting, WaitlistStatus::Notified, WaitlistStatus::Accepted])
            ->whereDate('created_at', $date->toDateString())
            ->orderBy('created_at');

        if ($windowStart && $windowEnd) {
            $query->where(fn ($q) => $q
                ->whereNull('preferred_starts_at')
                ->orWhere(fn ($inner) => $inner
                    ->whereTime('preferred_starts_at', '>=', $windowStart)
                    ->whereTime('preferred_starts_at', '<=', $windowEnd)
                )
            );
        }

        $entries = $query->paginate(20);

        return response()->json([
            'date' => $date->toDateString(),
            'period' => $this->periodMeta($windowStart, $windowEnd, $mealType),
            ...(WaitlistEntryResource::collection($entries)->response()->getData(true)),
        ]);
    }

    /**
     * List seated reservations.
     *
     * Returns paginated reservations with a status of `seated` — guests currently
     * at a table — for the requested date and optional service window.
     */
    public function seated(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'mealType' => $mealType] = $this->resolveWindow($request, $restaurant, $date);

        $query = $restaurant->reservations()
            ->with(['table', 'user', 'guestContact', 'reservationGuests'])
            ->whereDate('starts_at', $date->toDateString())
            ->where('status', ReservationStatus::Seated)
            ->orderBy('seated_at');

        if ($windowStart && $windowEnd) {
            $query->whereTime('starts_at', '>=', $windowStart)
                ->whereTime('starts_at', '<=', $windowEnd);
        }

        $reservations = $query->paginate(20);

        return response()->json([
            'date' => $date->toDateString(),
            'period' => $this->periodMeta($windowStart, $windowEnd, $mealType),
            ...(ReservationResource::collection($reservations)->response()->getData(true)),
        ]);
    }

    /**
     * List finished reservations.
     *
     * Returns paginated reservations with a status of `completed` for the requested
     * date and optional service window.
     */
    public function finished(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'mealType' => $mealType] = $this->resolveWindow($request, $restaurant, $date);

        $query = $restaurant->reservations()
            ->with(['table', 'user', 'guestContact', 'reservationGuests'])
            ->whereDate('starts_at', $date->toDateString())
            ->where('status', ReservationStatus::Completed)
            ->orderBy('completed_at');

        if ($windowStart && $windowEnd) {
            $query->whereTime('starts_at', '>=', $windowStart)
                ->whereTime('starts_at', '<=', $windowEnd);
        }

        $reservations = $query->paginate(20);

        return response()->json([
            'date' => $date->toDateString(),
            'period' => $this->periodMeta($windowStart, $windowEnd, $mealType),
            ...(ReservationResource::collection($reservations)->response()->getData(true)),
        ]);
    }

    /**
     * List no-show reservations.
     *
     * Returns paginated reservations with a status of `no_show` for the requested
     * date and optional service window.
     */
    public function noshow(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $this->validateCommonParams($request);

        $date = $this->resolveDate($request, $restaurant);
        ['windowStart' => $windowStart, 'windowEnd' => $windowEnd, 'mealType' => $mealType] = $this->resolveWindow($request, $restaurant, $date);

        $query = $restaurant->reservations()
            ->with(['table', 'user', 'guestContact', 'reservationGuests'])
            ->whereDate('starts_at', $date->toDateString())
            ->where('status', ReservationStatus::NoShow)
            ->orderBy('starts_at');

        if ($windowStart && $windowEnd) {
            $query->whereTime('starts_at', '>=', $windowStart)
                ->whereTime('starts_at', '<=', $windowEnd);
        }

        $reservations = $query->paginate(20);

        return response()->json([
            'date' => $date->toDateString(),
            'period' => $this->periodMeta($windowStart, $windowEnd, $mealType),
            ...(ReservationResource::collection($reservations)->response()->getData(true)),
        ]);
    }
}
