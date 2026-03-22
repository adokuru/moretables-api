<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OnboardingRequest;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\Models\User;
use App\OnboardingRequestStatus;
use App\ReservationStatus;
use App\RestaurantStatus;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Dashboard', weight: 45)]
class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $today = now();
        $startOfToday = $today->copy()->startOfDay();
        $startOfYesterday = $today->copy()->subDay()->startOfDay();
        $startOfWeek = $today->copy()->startOfWeek();
        $startOfPreviousWeek = $today->copy()->subWeek()->startOfWeek();
        $startOfMonth = $today->copy()->startOfMonth();
        $startOfPreviousMonth = $today->copy()->subMonth()->startOfMonth();

        $reservationStatusCounts = Reservation::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $totalRestaurants = Restaurant::query()->count();
        $activeRestaurants = Restaurant::query()->where('status', RestaurantStatus::Active->value)->count();
        $pendingApprovals = OnboardingRequest::query()->where('status', OnboardingRequestStatus::Pending->value)->count();
        $reservationsToday = Reservation::query()->whereDate('starts_at', $today->toDateString())->count();
        $weeklyReservations = Reservation::query()->whereBetween('starts_at', [$startOfWeek, $today->copy()->endOfDay()])->count();
        $totalDiners = (int) Reservation::query()->sum('party_size');
        $monthlyRevenue = $this->sumReservationRevenue($startOfMonth, $today->copy()->endOfDay());
        $newThisMonth = Restaurant::query()->whereBetween('created_at', [$startOfMonth, $today->copy()->endOfDay()])->count();

        $recentRestaurants = Restaurant::query()
            ->latest()
            ->limit(5)
            ->get();

        $recentActivity = $this->recentActivity($today);

        return response()->json([
            'cards' => [
                'total_restaurants' => $this->metricPayload(
                    value: $totalRestaurants,
                    previousValue: Restaurant::query()->where('created_at', '<', $startOfMonth)->count(),
                    label: 'Total Restaurants',
                ),
                'active' => $this->metricPayload(
                    value: $activeRestaurants,
                    previousValue: Restaurant::query()
                        ->where('status', RestaurantStatus::Active->value)
                        ->where('created_at', '<', $startOfMonth)
                        ->count(),
                    label: 'Active',
                ),
                'pending' => [
                    ...$this->metricPayload(
                        value: $pendingApprovals,
                        previousValue: OnboardingRequest::query()
                            ->where('status', OnboardingRequestStatus::Pending->value)
                            ->where('created_at', '<', $startOfToday)
                            ->count(),
                        label: 'Pending',
                    ),
                    'change_today' => $pendingApprovals - OnboardingRequest::query()
                        ->where('status', OnboardingRequestStatus::Pending->value)
                        ->where('created_at', '<', $startOfToday)
                        ->count(),
                ],
                'reservations_today' => $this->metricPayload(
                    value: $reservationsToday,
                    previousValue: Reservation::query()->whereDate('starts_at', $startOfYesterday->toDateString())->count(),
                    label: 'Reservations Today',
                ),
                'weekly_reservations' => $this->metricPayload(
                    value: $weeklyReservations,
                    previousValue: Reservation::query()->whereBetween('starts_at', [
                        $startOfPreviousWeek,
                        $startOfWeek->copy()->subSecond(),
                    ])->count(),
                    label: 'Weekly Reservations',
                ),
                'total_diners' => $this->metricPayload(
                    value: $totalDiners,
                    previousValue: (int) Reservation::query()
                        ->where('starts_at', '<', $startOfMonth)
                        ->sum('party_size'),
                    label: 'Total Diners',
                ),
                'monthly_revenue' => [
                    ...$this->metricPayload(
                        value: $monthlyRevenue,
                        previousValue: $this->sumReservationRevenue($startOfPreviousMonth, $startOfMonth->copy()->subSecond()),
                        label: 'Monthly Revenue',
                    ),
                    'currency' => 'NGN',
                ],
                'new_this_month' => [
                    ...$this->metricPayload(
                        value: $newThisMonth,
                        previousValue: Restaurant::query()->whereBetween('created_at', [
                            $startOfPreviousMonth,
                            $startOfMonth->copy()->subSecond(),
                        ])->count(),
                        label: 'New This Month',
                    ),
                    'change_vs_last_month' => $newThisMonth - Restaurant::query()->whereBetween('created_at', [
                        $startOfPreviousMonth,
                        $startOfMonth->copy()->subSecond(),
                    ])->count(),
                ],
            ],
            'reservation_trends' => [
                'range' => '7d',
                'ranges' => [
                    '7d' => $this->reservationTrendSeries($today, 7),
                    '30d' => $this->reservationTrendSeries($today, 30),
                    '90d' => $this->reservationTrendSeries($today, 90),
                ],
            ],
            'reservation_growth' => [
                'range' => '6m',
                'series' => $this->reservationGrowthSeries($today),
            ],
            'recent_activity' => $recentActivity,
            'recent_restaurants' => $recentRestaurants->values()->map(
                fn (Restaurant $restaurant, int $index): array => [
                    'serial_number' => $index + 1,
                    'id' => $restaurant->id,
                    'name' => $restaurant->name,
                    'location' => trim(implode(', ', array_filter([$restaurant->city, $restaurant->country]))),
                    'status' => $restaurant->status?->value,
                    'display_status' => $this->dashboardRestaurantStatus($restaurant),
                    'created_at' => $restaurant->created_at?->toIso8601String(),
                ],
            ),
            'overview' => [
                'organizations_count' => Organization::query()->count(),
                'restaurants_count' => $totalRestaurants,
                'active_restaurants_count' => $activeRestaurants,
                'suspended_restaurants_count' => Restaurant::query()->where('status', RestaurantStatus::Suspended->value)->count(),
                'users_count' => User::query()->count(),
                'reservations_count' => Reservation::query()->count(),
                'reviews_count' => RestaurantReview::query()->count(),
                'pending_approvals_count' => $pendingApprovals,
            ],
            'reservations' => [
                'upcoming_count' => Reservation::query()->where('starts_at', '>=', now())->count(),
                'today_count' => $reservationsToday,
                'by_status' => [
                    ReservationStatus::Booked->value => (int) ($reservationStatusCounts[ReservationStatus::Booked->value] ?? 0),
                    ReservationStatus::Confirmed->value => (int) ($reservationStatusCounts[ReservationStatus::Confirmed->value] ?? 0),
                    ReservationStatus::Arrived->value => (int) ($reservationStatusCounts[ReservationStatus::Arrived->value] ?? 0),
                    ReservationStatus::Seated->value => (int) ($reservationStatusCounts[ReservationStatus::Seated->value] ?? 0),
                    ReservationStatus::Completed->value => (int) ($reservationStatusCounts[ReservationStatus::Completed->value] ?? 0),
                    ReservationStatus::Cancelled->value => (int) ($reservationStatusCounts[ReservationStatus::Cancelled->value] ?? 0),
                    ReservationStatus::NoShow->value => (int) ($reservationStatusCounts[ReservationStatus::NoShow->value] ?? 0),
                ],
            ],
        ]);
    }

    protected function metricPayload(int|float $value, int|float $previousValue, string $label): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'previous_value' => $previousValue,
            'change' => $value - $previousValue,
            'change_percentage' => $this->percentageChange($value, $previousValue),
        ];
    }

    protected function percentageChange(int|float $value, int|float $previousValue): ?float
    {
        if ((float) $previousValue === 0.0) {
            return (float) $value === 0.0 ? 0.0 : 100.0;
        }

        return round((($value - $previousValue) / $previousValue) * 100, 1);
    }

    protected function sumReservationRevenue(Carbon $start, Carbon $end): int
    {
        return (int) Reservation::query()
            ->whereBetween('starts_at', [$start, $end])
            ->get()
            ->sum(function (Reservation $reservation): int {
                $totalAmount = data_get($reservation->metadata, 'total_amount');

                return is_numeric($totalAmount) ? (int) round((float) $totalAmount) : 0;
            });
    }

    /**
     * @return array<int, array{label: string, date: string, reservations_count: int}>
     */
    protected function reservationTrendSeries(Carbon $today, int $days): array
    {
        $startDate = $today->copy()->subDays($days - 1)->startOfDay();
        $counts = Reservation::query()
            ->selectRaw('DATE(starts_at) as reservation_date, count(*) as aggregate')
            ->whereBetween('starts_at', [$startDate, $today->copy()->endOfDay()])
            ->groupBy('reservation_date')
            ->pluck('aggregate', 'reservation_date');

        return collect(CarbonPeriod::create($startDate, '1 day', $today->copy()->startOfDay()))
            ->map(fn (Carbon $date): array => [
                'label' => $date->format('D'),
                'date' => $date->toDateString(),
                'reservations_count' => (int) ($counts[$date->toDateString()] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, month: string, reservations_count: int}>
     */
    protected function reservationGrowthSeries(Carbon $today): array
    {
        $series = collect();

        foreach (range(5, 0) as $offset) {
            $monthStart = $today->copy()->subMonths($offset)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $series->push([
                'label' => $monthStart->format('M'),
                'month' => $monthStart->format('Y-m'),
                'reservations_count' => Reservation::query()
                    ->whereBetween('starts_at', [$monthStart, $monthEnd])
                    ->count(),
            ]);
        }

        $series->push([
            'label' => $today->format('M'),
            'month' => $today->format('Y-m'),
            'reservations_count' => Reservation::query()
                ->whereBetween('starts_at', [$today->copy()->startOfMonth(), $today->copy()->endOfDay()])
                ->count(),
        ]);

        return $series->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function recentActivity(Carbon $today): array
    {
        $activities = collect();

        $recentOnboarding = OnboardingRequest::query()->latest()->limit(3)->get();
        $recentRestaurants = Restaurant::query()->latest()->limit(2)->get();

        foreach ($recentOnboarding as $request) {
            $activities->push([
                'type' => $request->status === OnboardingRequestStatus::Pending ? 'application' : 'approval',
                'title' => $request->status === OnboardingRequestStatus::Pending
                    ? 'New application from '.$request->restaurant_name
                    : $request->restaurant_name.' '.str($request->status->value)->replace('_', ' '),
                'description' => $request->owner_name,
                'timestamp' => ($request->reviewed_at ?? $request->created_at)?->toIso8601String(),
            ]);
        }

        foreach ($recentRestaurants as $restaurant) {
            $activities->push([
                'type' => 'restaurant',
                'title' => $restaurant->name.' added',
                'description' => trim(implode(', ', array_filter([$restaurant->city, $restaurant->country]))),
                'timestamp' => $restaurant->created_at?->toIso8601String(),
            ]);
        }

        $reservationsToday = Reservation::query()
            ->whereDate('created_at', $today->toDateString())
            ->count();

        if ($reservationsToday > 0) {
            $activities->push([
                'type' => 'reservation',
                'title' => $reservationsToday.' reservations created today',
                'description' => 'Daily reservation activity',
                'timestamp' => $today->copy()->endOfDay()->toIso8601String(),
            ]);
        }

        return $activities
            ->sortByDesc('timestamp')
            ->take(6)
            ->values()
            ->all();
    }

    protected function dashboardRestaurantStatus(Restaurant $restaurant): string
    {
        return match ($restaurant->status) {
            RestaurantStatus::Active => 'approved',
            RestaurantStatus::Suspended => 'suspended',
            default => 'pending',
        };
    }
}
