<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\Role;
use App\ReservationStatus;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Overview', weight: 35)]
class MerchantRestaurantOverviewController extends Controller
{
    /**
     * Restaurant overview dashboard.
     *
     * Returns quick-view stats (total reservations, guests, servers, users)
     * and a 7-day calendar overview for the current week (Sunday–Saturday),
     * scoped to the restaurant's timezone.
     */
    public function show(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $timezone = $restaurant->timezone ?? 'UTC';
        $now = Carbon::now($timezone);
        $startOfMonth = $now->copy()->startOfMonth()->utc();
        $endOfMonth = $now->copy()->endOfMonth()->utc();
        $startOfPreviousMonth = $now->copy()->subMonth()->startOfMonth()->utc();
        $endOfPreviousMonth = $now->copy()->subMonth()->endOfMonth()->utc();

        // ------------------------------------------------------------------ //
        //  Quick View                                                          //
        // ------------------------------------------------------------------ //

        $totalReservations = $restaurant->reservations()->count();
        $reservationsThisMonth = $restaurant->reservations()
            ->whereBetween('starts_at', [$startOfMonth, $endOfMonth])
            ->count();
        $reservationsPreviousMonth = $restaurant->reservations()
            ->whereBetween('starts_at', [$startOfPreviousMonth, $endOfPreviousMonth])
            ->count();

        $totalGuests = (int) $restaurant->reservations()->sum('party_size');
        $guestsThisMonth = (int) $restaurant->reservations()
            ->whereBetween('starts_at', [$startOfMonth, $endOfMonth])
            ->sum('party_size');
        $guestsPreviousMonth = (int) $restaurant->reservations()
            ->whereBetween('starts_at', [$startOfPreviousMonth, $endOfPreviousMonth])
            ->sum('party_size');

        $totalServers = $restaurant->userRoles()
            ->whereHas('role', fn ($q) => $q->whereIn('name', Role::allRestaurantStaffRoles()))
            ->count();

        $totalUsers = $restaurant->reservations()
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        // ------------------------------------------------------------------ //
        //  30-day sparkline trends (PHP-side grouping for DB compatibility)   //
        // ------------------------------------------------------------------ //

        $trendStart = $now->copy()->subDays(29)->startOfDay()->utc();
        $trendEnd = $now->copy()->endOfDay()->utc();

        $trendReservations = $restaurant->reservations()
            ->whereBetween('starts_at', [$trendStart, $trendEnd])
            ->get(['starts_at', 'party_size']);

        $reservationsByDay = $trendReservations->groupBy(
            fn (Reservation $r): string => Carbon::parse($r->starts_at)->setTimezone($timezone)->toDateString()
        );

        $reservationTrend = collect(CarbonPeriod::create($now->copy()->subDays(29)->startOfDay(), '1 day', $now->copy()->startOfDay()))
            ->map(fn (Carbon $date): array => [
                'date' => $date->toDateString(),
                'count' => $reservationsByDay->get($date->toDateString(), collect())->count(),
            ])->values()->all();

        $guestTrend = collect(CarbonPeriod::create($now->copy()->subDays(29)->startOfDay(), '1 day', $now->copy()->startOfDay()))
            ->map(fn (Carbon $date): array => [
                'date' => $date->toDateString(),
                'count' => (int) $reservationsByDay->get($date->toDateString(), collect())->sum('party_size'),
            ])->values()->all();

        // ------------------------------------------------------------------ //
        //  Calendar Overview (current week Sun–Sat)                           //
        // ------------------------------------------------------------------ //

        $weekStart = $now->copy()->startOfWeek(Carbon::SUNDAY)->startOfDay()->utc();
        $weekEnd = $now->copy()->startOfWeek(Carbon::SUNDAY)->addDays(6)->endOfDay()->utc();

        $weekReservations = $restaurant->reservations()
            ->whereBetween('starts_at', [$weekStart, $weekEnd])
            ->whereNotIn('status', [ReservationStatus::Cancelled])
            ->get(['starts_at', 'party_size']);

        $weekByDay = $weekReservations->groupBy(
            fn (Reservation $r): string => Carbon::parse($r->starts_at)->setTimezone($timezone)->toDateString()
        );

        // Average covers per weekday over the past 8 weeks for historical context
        $historicalStart = Carbon::now($timezone)->copy()->startOfWeek(Carbon::SUNDAY)->subWeeks(8)->startOfDay()->utc();
        $historicalEnd = $weekStart->copy()->subSecond();

        $historicalReservations = $restaurant->reservations()
            ->whereBetween('starts_at', [$historicalStart, $historicalEnd])
            ->whereNotIn('status', [ReservationStatus::Cancelled])
            ->get(['starts_at', 'party_size']);

        /** @var array<int, list<int>> $coversByWeekday */
        $coversByWeekday = array_fill(0, 7, []);
        $historicalByDay = $historicalReservations->groupBy(
            fn (Reservation $r): string => Carbon::parse($r->starts_at)->setTimezone($timezone)->toDateString()
        );

        foreach ($historicalByDay as $dateStr => $dayReservations) {
            $dow = Carbon::parse($dateStr)->dayOfWeek;
            $coversByWeekday[$dow][] = (int) $dayReservations->sum('party_size');
        }

        $weekStartLocal = Carbon::now($timezone)->copy()->startOfWeek(Carbon::SUNDAY)->startOfDay();
        $calendarDays = collect(CarbonPeriod::create($weekStartLocal, '1 day', $weekStartLocal->copy()->addDays(6)))
            ->map(function (Carbon $date) use ($weekByDay, $coversByWeekday, $now): array {
                $dateStr = $date->toDateString();
                $dayReservations = $weekByDay->get($dateStr);
                $dow = $date->dayOfWeek;

                $historicalCovers = $coversByWeekday[$dow];
                $avgCovers = count($historicalCovers) > 0
                    ? (int) round(array_sum($historicalCovers) / count($historicalCovers))
                    : null;

                return [
                    'date' => $dateStr,
                    'day_of_week' => $date->dayName,
                    'day_label' => $date->format('l, F j'),
                    'is_today' => $date->isSameDay($now),
                    'reservations_count' => $dayReservations ? $dayReservations->count() : null,
                    'total_covers' => $dayReservations ? (int) $dayReservations->sum('party_size') : null,
                    'average_covers_historical' => $avgCovers,
                ];
            })->values()->all();

        return response()->json([
            'quick_view' => [
                'total_reservations' => [
                    'value' => $totalReservations,
                    'this_month' => $reservationsThisMonth,
                    'previous_month' => $reservationsPreviousMonth,
                    'change_percentage' => $this->percentageChange($reservationsThisMonth, $reservationsPreviousMonth),
                    'trend' => $reservationTrend,
                ],
                'total_guests' => [
                    'value' => $totalGuests,
                    'this_month' => $guestsThisMonth,
                    'previous_month' => $guestsPreviousMonth,
                    'change_percentage' => $this->percentageChange($guestsThisMonth, $guestsPreviousMonth),
                    'trend' => $guestTrend,
                ],
                'total_servers' => [
                    'value' => $totalServers,
                ],
                'total_users' => [
                    'value' => $totalUsers,
                ],
            ],
            'calendar_overview' => [
                'week_start' => $weekStartLocal->toDateString(),
                'week_end' => $weekStartLocal->copy()->addDays(6)->toDateString(),
                'days' => $calendarDays,
            ],
        ]);
    }

    private function percentageChange(int|float $current, int|float $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return (float) $current === 0.0 ? 0.0 : 100.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
