<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminReservationRequest;
use App\Http\Requests\Admin\UpdateAdminReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\ReservationStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('Admin Reservations', weight: 56)]
class AdminReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $reservations = Reservation::query()
            ->with(['restaurant.organization', 'table', 'user.roles', 'guestContact'])
            ->when(
                filled($request->string('search')->toString()),
                fn ($query) => $query->where('reservation_reference', 'like', '%'.$request->string('search')->toString().'%'),
            )
            ->when(
                $request->has('restaurant_id'),
                fn ($query) => $query->where('restaurant_id', $request->integer('restaurant_id')),
            )
            ->when(
                $request->has('user_id'),
                fn ($query) => $query->where('user_id', $request->integer('user_id')),
            )
            ->when(
                filled($request->string('status')->toString()),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->latest('starts_at')
            ->paginate(20);

        return response()->json(ReservationResource::collection($reservations));
    }

    public function analytics(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $statusCounts = Reservation::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->json([
            'analytics' => [
                'total' => Reservation::query()->count(),
                'upcoming' => Reservation::query()->where('starts_at', '>=', now())->count(),
                'completed' => Reservation::query()->where('status', ReservationStatus::Completed->value)->count(),
                'cancelled' => Reservation::query()->where('status', ReservationStatus::Cancelled->value)->count(),
                'no_show' => Reservation::query()->where('status', ReservationStatus::NoShow->value)->count(),
                'by_status' => collect(ReservationStatus::cases())
                    ->mapWithKeys(fn (ReservationStatus $status): array => [$status->value => (int) ($statusCounts[$status->value] ?? 0)])
                    ->all(),
            ],
        ]);
    }

    public function store(StoreAdminReservationRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();
        $reservation = Reservation::query()->create($this->reservationPayload($validated, $request));

        return response()->json([
            'message' => 'Reservation created successfully.',
            'reservation' => ReservationResource::make($reservation->load(['restaurant.organization', 'table', 'user.roles', 'guestContact'])),
        ], 201);
    }

    public function show(Request $request, Reservation $reservation): ReservationResource
    {
        $this->ensureAdminAccess($request);

        return ReservationResource::make($reservation->load(['restaurant.organization', 'table', 'user.roles', 'guestContact']));
    }

    public function update(UpdateAdminReservationRequest $request, Reservation $reservation): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $reservation->update($this->reservationPayload($request->validated(), $request, $reservation));

        return response()->json([
            'message' => 'Reservation updated successfully.',
            'reservation' => ReservationResource::make($reservation->refresh()->load(['restaurant.organization', 'table', 'user.roles', 'guestContact'])),
        ]);
    }

    public function destroy(Request $request, Reservation $reservation): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $reservation->delete();

        return response()->json([
            'message' => 'Reservation deleted successfully.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function reservationPayload(array $validated, Request $request, ?Reservation $reservation = null): array
    {
        $status = isset($validated['status'])
            ? ReservationStatus::from($validated['status'])
            : $reservation?->status;

        if (! $reservation) {
            $validated['reservation_reference'] = 'MT'.Str::upper(Str::random(8));
            $status ??= ReservationStatus::Booked;
        }

        if ($status === ReservationStatus::Cancelled && empty($validated['canceled_at'])) {
            $validated['canceled_at'] = now();
            $validated['canceled_by_user_id'] = $request->user()->id;
        }

        if ($status === ReservationStatus::Seated && empty($validated['seated_at']) && ! $reservation?->seated_at) {
            $validated['seated_at'] = now();
        }

        if ($status === ReservationStatus::Completed && empty($validated['completed_at']) && ! $reservation?->completed_at) {
            $validated['completed_at'] = now();
        }

        return $validated;
    }
}
