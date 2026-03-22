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
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Dashboard', weight: 45)]
class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $reservationStatusCounts = Reservation::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->json([
            'overview' => [
                'organizations_count' => Organization::query()->count(),
                'restaurants_count' => Restaurant::query()->count(),
                'active_restaurants_count' => Restaurant::query()->where('status', RestaurantStatus::Active->value)->count(),
                'suspended_restaurants_count' => Restaurant::query()->where('status', RestaurantStatus::Suspended->value)->count(),
                'users_count' => User::query()->count(),
                'reservations_count' => Reservation::query()->count(),
                'reviews_count' => RestaurantReview::query()->count(),
                'pending_approvals_count' => OnboardingRequest::query()->where('status', OnboardingRequestStatus::Pending->value)->count(),
            ],
            'reservations' => [
                'upcoming_count' => Reservation::query()->where('starts_at', '>=', now())->count(),
                'today_count' => Reservation::query()->whereDate('starts_at', now()->toDateString())->count(),
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
}
