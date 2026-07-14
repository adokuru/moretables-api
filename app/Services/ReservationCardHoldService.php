<?php

namespace App\Services;

use App\Contracts\PaymentProvider;
use App\Enums\CancellationPolicyManagementMethod;
use App\Models\Reservation;
use App\Models\ReservationCardHold;
use App\Models\Restaurant;
use App\Models\RestaurantCancellationPolicy;
use App\Models\User;
use App\Notifications\NoShowChargeNotification;
use App\ReservationCardHoldStatus;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationCardHoldService
{
    public function __construct(
        protected PaymentProvider $provider,
        protected RestaurantCancellationPolicyService $cancellationPolicies,
    ) {}

    /**
     * Capture a card for an intended booking, before the reservation exists. Charges the nominal
     * verification amount to tokenize the card; the real hold amount is only debited on a no-show.
     * The returned reference is passed back when creating the reservation to link the two.
     *
     * @return array{card_hold: ReservationCardHold, provider_response: array<string, mixed>}
     */
    public function initializeVerification(User $user, Restaurant $restaurant, Carbon $startsAt, int $partySize): array
    {
        $policy = $this->requireCardHoldPolicy($restaurant, $startsAt, $partySize);

        if (! $user->email) {
            throw ValidationException::withMessages([
                'email' => 'A contact email is required to place a card hold.',
            ]);
        }

        // Reuse an already-authorized, unlinked card so a retry never double-charges the guest.
        $existing = ReservationCardHold::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->whereNull('reservation_id')
            ->where('status', ReservationCardHoldStatus::Authorized)
            ->latest('id')
            ->first();

        if ($existing) {
            return ['card_hold' => $existing, 'provider_response' => []];
        }

        $cardHold = ReservationCardHold::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'restaurant_id' => $restaurant->id,
                'reservation_id' => null,
                'status' => ReservationCardHoldStatus::Pending,
            ],
            [
                'provider' => 'paystack',
                'reference' => 'rch_'.strtolower((string) Str::ulid()),
                'authorization_code' => null,
                'email' => $user->email,
                'amount' => (int) $policy->hold_charge_amount,
                'currency' => config('billing.card_hold.currency', 'NGN'),
                'charge_reference' => null,
                'charged_amount' => null,
                'charged_at' => null,
                'failure_reason' => null,
            ],
        );

        $response = $this->provider->initializeCardAuthorization(
            $user->email,
            (int) config('billing.card_hold.verification_amount', 5000),
            $cardHold->reference,
            $cardHold->currency,
            [
                'purpose' => 'reservation_card_hold',
                'user_id' => $user->id,
                'restaurant_id' => $restaurant->id,
                'reservation_card_hold_id' => $cardHold->id,
            ],
        );

        return [
            'card_hold' => $cardHold->refresh(),
            'provider_response' => $response,
        ];
    }

    /**
     * Resolve the authorized card a card-hold booking requires, before the reservation is created.
     * Returns null when the requested slot's policy does not require a card hold. The hold's amount
     * is re-snapshotted from the matched policy but it stays unlinked until linkToReservation().
     *
     * @throws ValidationException when a card hold is required but none is authorized.
     */
    public function resolveForBooking(User $user, Restaurant $restaurant, Carbon $startsAt, int $partySize, ?string $reference): ?ReservationCardHold
    {
        $restaurant->loadMissing(['cancellationPolicies', 'policy']);

        $policy = $this->cancellationPolicies->matchingPolicy($restaurant, \Illuminate\Support\Carbon::instance($startsAt), $partySize);

        if (! $policy || $policy->management_method !== CancellationPolicyManagementMethod::CardHold) {
            return null;
        }

        $cardHold = ReservationCardHold::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->whereNull('reservation_id')
            ->where('status', ReservationCardHoldStatus::Authorized)
            ->when($reference, fn ($query) => $query->where('reference', $reference))
            ->latest('id')
            ->first();

        if (! $cardHold) {
            throw ValidationException::withMessages([
                'card_hold_reference' => ['Please verify a card to secure this reservation before booking.'],
            ]);
        }

        $cardHold->amount = (int) $policy->hold_charge_amount;

        return $cardHold;
    }

    public function linkToReservation(ReservationCardHold $cardHold, Reservation $reservation): void
    {
        $cardHold->update([
            'reservation_id' => $reservation->id,
            'amount' => $cardHold->amount,
        ]);
    }

    /**
     * Persist the card authorization once Paystack confirms the verification charge succeeded.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): void
    {
        if (($payload['event'] ?? null) !== 'charge.success') {
            return;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = Arr::get($data, 'reference');

        if (! is_string($reference) || ! str_starts_with($reference, 'rch_')) {
            return;
        }

        $cardHold = ReservationCardHold::query()->where('reference', $reference)->first();

        if (! $cardHold) {
            return;
        }

        $authorization = Arr::get($data, 'authorization', []);
        $authorizationCode = is_array($authorization) ? Arr::get($authorization, 'authorization_code') : null;

        if (! $authorizationCode) {
            $cardHold->update([
                'status' => ReservationCardHoldStatus::VerificationFailed,
                'failure_reason' => 'No authorization code returned by provider.',
                'metadata' => $data,
            ]);

            return;
        }

        $cardHold->update([
            'authorization_code' => $authorizationCode,
            'brand' => Arr::get($authorization, 'brand'),
            'last4' => Arr::get($authorization, 'last4'),
            'status' => ReservationCardHoldStatus::Authorized,
            'metadata' => $data,
        ]);

        $this->refundVerificationCharge($cardHold);
    }

    /**
     * The card is now tokenized, so refund the nominal verification charge — the guest only
     * ever pays the real hold amount, and only on a no-show.
     */
    protected function refundVerificationCharge(ReservationCardHold $cardHold): void
    {
        try {
            $this->provider->refundTransaction($cardHold->reference);

            $cardHold->update([
                'metadata' => [...($cardHold->metadata ?? []), 'verification_refunded_at' => now()->toIso8601String()],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Card hold verification refund failed.', [
                'card_hold_id' => $cardHold->id,
                'reference' => $cardHold->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Charge the saved authorization the policy's hold amount for a no-show reservation.
     */
    public function chargeNoShow(Reservation $reservation): ?ReservationCardHold
    {
        $cardHold = $reservation->cardHold;

        if (! $cardHold || ! $cardHold->isChargeable()) {
            return null;
        }

        $chargeReference = 'rns_'.strtolower((string) Str::ulid());

        try {
            $response = $this->provider->chargeAuthorization(
                $cardHold->authorization_code,
                $cardHold->email,
                $cardHold->amount,
                $chargeReference,
                $cardHold->currency,
                [
                    'purpose' => 'reservation_no_show_charge',
                    'reservation_id' => $reservation->id,
                    'reservation_card_hold_id' => $cardHold->id,
                ],
            );
        } catch (\Throwable $e) {
            $cardHold->update([
                'status' => ReservationCardHoldStatus::ChargeFailed,
                'charge_reference' => $chargeReference,
                'failure_reason' => Str::limit($e->getMessage(), 250),
            ]);

            Log::warning('No-show charge failed.', [
                'reservation_id' => $reservation->id,
                'card_hold_id' => $cardHold->id,
                'error' => $e->getMessage(),
            ]);

            return $cardHold->refresh();
        }

        $succeeded = Arr::get($response, 'data.status') === 'success';

        $cardHold->update([
            'status' => $succeeded ? ReservationCardHoldStatus::Charged : ReservationCardHoldStatus::ChargeFailed,
            'charge_reference' => $chargeReference,
            'charged_amount' => $succeeded ? $cardHold->amount : null,
            'charged_at' => $succeeded ? now() : null,
            'failure_reason' => $succeeded ? null : (string) Arr::get($response, 'data.gateway_response', 'Charge declined.'),
        ]);

        if ($succeeded) {
            $this->notifyChargeParties($cardHold->refresh());
        }

        return $cardHold->refresh();
    }

    protected function notifyChargeParties(ReservationCardHold $cardHold): void
    {
        $cardHold->loadMissing(['reservation.user', 'reservation.guestContact', 'restaurant']);

        $customerEmail = $cardHold->reservation->user?->email ?? $cardHold->reservation->guestContact?->email;
        $restaurantEmail = $cardHold->restaurant->email;

        if ($customerEmail) {
            Notification::route('mail', $customerEmail)
                ->notify(new NoShowChargeNotification($cardHold, forRestaurant: false));
        }

        if ($restaurantEmail) {
            Notification::route('mail', $restaurantEmail)
                ->notify(new NoShowChargeNotification($cardHold, forRestaurant: true));
        }
    }

    protected function requireCardHoldPolicy(Restaurant $restaurant, Carbon $startsAt, int $partySize): RestaurantCancellationPolicy
    {
        $restaurant->loadMissing(['cancellationPolicies', 'policy']);

        $policy = $this->cancellationPolicies->matchingPolicy($restaurant, \Illuminate\Support\Carbon::instance($startsAt), $partySize);

        if (! $policy || $policy->management_method !== CancellationPolicyManagementMethod::CardHold) {
            throw ValidationException::withMessages([
                'reservation' => 'This reservation does not require a card hold.',
            ]);
        }

        return $policy;
    }
}
