<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\StoreReservationCardHoldRequest;
use App\Http\Resources\ReservationCardHoldResource;
use App\Models\Restaurant;
use App\Services\ReservationCardHoldService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Customer Reservations', weight: 20)]
class CustomerReservationCardHoldController extends Controller
{
    public function __construct(protected ReservationCardHoldService $cardHoldService) {}

    /**
     * Capture a card before booking a slot covered by a card-hold cancellation policy. Charges a nominal
     * verification amount to save the card (refunded once verified), and returns the Paystack checkout
     * payload the frontend uses to collect it. Pass the returned `reference` when creating the reservation
     * to link the hold. The card is only charged the policy hold amount if the guest is a no-show.
     * Returns 422 when the requested slot does not require a card hold.
     */
    #[Response(422, type: 'array{message: string, errors: array<string, list<string>>}')]
    public function store(StoreReservationCardHoldRequest $request, Restaurant $restaurant): JsonResponse
    {
        $result = $this->cardHoldService->initializeVerification(
            $request->user(),
            $restaurant,
            Carbon::parse($request->validated('starts_at')),
            $request->integer('party_size'),
        );

        return response()->json([
            'message' => 'Card hold verification started.',
            'card_hold' => ReservationCardHoldResource::make($result['card_hold']),
            'authorization_url' => data_get($result['provider_response'], 'data.authorization_url'),
            'access_code' => data_get($result['provider_response'], 'data.access_code'),
            'reference' => $result['card_hold']->reference,
        ], 201);
    }
}
