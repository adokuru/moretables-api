<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailabilityAlert\StoreAvailabilityAlertRequest;
use App\Http\Resources\AvailabilityAlertResource;
use App\Models\Restaurant;
use App\Models\WaitlistEntry;
use App\Services\AvailabilityAlertService;
use App\WaitlistStatus;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

#[Group('Customer Availability Alerts', weight: 23)]
class CustomerAvailabilityAlertController extends Controller
{
    public function __construct(protected AvailabilityAlertService $availabilityAlertService) {}

    public function index(): JsonResponse
    {
        $alerts = request()->user()
            ->waitlistEntries()
            ->availabilityAlerts()
            ->with(['restaurant.cuisines', 'restaurant.media'])
            ->latest('preferred_starts_at')
            ->paginate(15);

        return response()->json(AvailabilityAlertResource::collection($alerts));
    }

    public function store(StoreAvailabilityAlertRequest $request, Restaurant $restaurant): JsonResponse
    {
        $user = $request->user();
        $timezone = $restaurant->timezone ?: config('app.timezone');

        $windowStart = Carbon::parse(
            $request->date('date')->format('Y-m-d').' '.$request->string('window_start'),
            $timezone,
        )->utc();
        $windowEnd = Carbon::parse(
            $request->date('date')->format('Y-m-d').' '.$request->string('window_end'),
            $timezone,
        )->utc();

        $this->guardAgainstActiveLimit($user->id);

        $existing = $this->findDuplicateActiveAlert($user->id, $restaurant->id, $request->integer('party_size'), $windowStart, $windowEnd);

        if ($existing !== null) {
            return response()->json([
                'message' => 'You already have an active alert for this time.',
                'availability_alert' => AvailabilityAlertResource::make($existing->load('restaurant')),
            ]);
        }

        $alert = $this->availabilityAlertService->create($restaurant, $user, [
            'party_size' => $request->integer('party_size'),
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'whatsapp_updates' => $request->boolean('whatsapp_updates'),
        ]);

        return response()->json([
            'message' => 'Availability alert created successfully.',
            'availability_alert' => AvailabilityAlertResource::make($alert),
        ], 201);
    }

    public function destroy(WaitlistEntry $availabilityAlert): JsonResponse
    {
        abort_unless(
            $availabilityAlert->isAvailabilityAlert() && $availabilityAlert->user_id === request()->user()->id,
            404,
        );

        $this->availabilityAlertService->cancel($availabilityAlert, request()->user());

        return response()->json([
            'message' => 'Availability alert cancelled successfully.',
        ]);
    }

    protected function guardAgainstActiveLimit(int $userId): void
    {
        $maxActive = (int) config('reservations.availability_alerts.max_active_per_user', 20);

        $activeCount = WaitlistEntry::query()
            ->availabilityAlerts()
            ->where('user_id', $userId)
            ->whereIn('status', [WaitlistStatus::Waiting->value, WaitlistStatus::Notified->value])
            ->count();

        if ($activeCount >= $maxActive) {
            throw ValidationException::withMessages([
                'party_size' => ["You have reached the maximum of {$maxActive} active alerts."],
            ]);
        }
    }

    protected function findDuplicateActiveAlert(
        int $userId,
        int $restaurantId,
        int $partySize,
        Carbon $windowStart,
        Carbon $windowEnd,
    ): ?WaitlistEntry {
        return WaitlistEntry::query()
            ->availabilityAlerts()
            ->where('user_id', $userId)
            ->where('restaurant_id', $restaurantId)
            ->where('party_size', $partySize)
            ->where('preferred_starts_at', $windowStart)
            ->where('preferred_ends_at', $windowEnd)
            ->whereIn('status', [WaitlistStatus::Waiting->value, WaitlistStatus::Notified->value])
            ->first();
    }
}
