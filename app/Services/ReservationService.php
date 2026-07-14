<?php

namespace App\Services;

use App\Events\ReservationUpdated;
use App\Events\TableStatusUpdated;
use App\Events\WaitlistEntryUpdated;
use App\Jobs\ChargeNoShowFeeJob;
use App\Jobs\ProcessRestaurantAvailabilityAlerts;
use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\GuestWaitlistOfferExpiredMailNotification;
use App\Notifications\GuestWaitlistTableAvailableMailNotification;
use App\Notifications\GuestWaitlistTableUnavailableMailNotification;
use App\Notifications\OwnerReservationLifecycleNotification;
use App\Notifications\ReservationLifecycleNotification;
use App\Notifications\WaitlistAvailabilityNotification;
use App\Notifications\WaitlistOfferExpiredNotification;
use App\Notifications\WaitlistTableNoLongerAvailableNotification;
use App\ReservationServiceStage;
use App\ReservationSource;
use App\ReservationStatus;
use App\RewardPointTransactionType;
use App\TableStatus;
use App\UserStatus;
use App\WaitlistExpiryReason;
use App\WaitlistStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected AuditLogService $auditLogService,
        protected RewardProgramService $rewardProgramService,
        protected RestaurantRewardRuleService $rewardRuleService,
        protected ReservationCardHoldService $cardHoldService,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createCustomerReservation(User $user, Restaurant $restaurant, array $attributes): Reservation
    {
        if ($user->status !== UserStatus::Active) {
            throw ValidationException::withMessages([
                'user' => ['Complete your profile before creating a reservation.'],
            ]);
        }

        $startsAt = Carbon::parse($attributes['starts_at']);

        $duplicate = Reservation::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->where('starts_at', $startsAt)
            ->where('status', '!=', ReservationStatus::Cancelled)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'starts_at' => ['You already have a reservation at this restaurant for the selected date and time.'],
            ]);
        }

        // Slots under a card-hold policy require a verified card before booking; resolve it up front
        // so the booking is rejected before any reservation row is created, then link it afterwards.
        $cardHold = $this->cardHoldService->resolveForBooking(
            $user,
            $restaurant,
            $startsAt,
            (int) $attributes['party_size'],
            $attributes['card_hold_reference'] ?? null,
        );

        // Add the customer to the restaurant's guestbook on first booking.
        // Match by phone (preferred) then email, so the same person is never
        // duplicated even if they book from different devices.
        $guestContact = null;

        if ($user->phone || $user->email) {
            $guestContact = GuestContact::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('is_temporary', false)
                ->where(function ($q) use ($user): void {
                    $q->when($user->phone, fn ($q) => $q->where('phone', $user->phone))
                        ->when($user->email, fn ($q) => $q->orWhere('email', $user->email));
                })
                ->first();

            if ($guestContact) {
                // Keep the record fresh with the latest details from their profile
                $guestContact->fill(array_filter([
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ], fn ($v) => $v !== null))->save();
            } else {
                $guestContact = GuestContact::query()->create([
                    'restaurant_id' => $restaurant->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'is_temporary' => false,
                ]);
            }
        }

        $reservation = $this->createReservation(
            actor: $user,
            restaurant: $restaurant,
            source: ReservationSource::Customer,
            attributes: $attributes,
            user: $user,
            guestContact: $guestContact,
        );

        if ($cardHold !== null) {
            $this->cardHoldService->linkToReservation($cardHold, $reservation);
        }

        return $reservation;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createMerchantReservation(User $actor, Restaurant $restaurant, array $attributes): Reservation
    {
        $guestContact = null;

        if (! empty($attributes['guest_contact']) && empty($attributes['user_id'])) {
            $contact = $attributes['guest_contact'];

            // Support a single `full_name` field — split on first space.
            if (! empty($contact['full_name']) && empty($contact['first_name'])) {
                $parts = explode(' ', trim($contact['full_name']), 2);
                $contact['first_name'] = $parts[0];
                $contact['last_name'] = $parts[1] ?? null;
            }

            $guestContact = ! empty($contact['phone'])
                ? GuestContact::query()
                    ->where('restaurant_id', $restaurant->id)
                    ->where('phone', $contact['phone'])
                    ->where('is_temporary', false)
                    ->first()
                : null;

            if ($guestContact) {
                // Update any missing details from the new booking.
                $guestContact->fill(array_filter([
                    'first_name' => $contact['first_name'] ?? null,
                    'last_name' => $guestContact->last_name ?? ($contact['last_name'] ?? null),
                    'email' => $guestContact->email ?? ($contact['email'] ?? null),
                ], fn ($v) => $v !== null))->save();
            } else {
                $guestContact = GuestContact::query()->create([
                    'restaurant_id' => $restaurant->id,
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'] ?? null,
                    'email' => $contact['email'] ?? null,
                    'phone' => $contact['phone'] ?? null,
                    'notes' => $attributes['notes'] ?? null,
                    'is_temporary' => false,
                ]);
            }
        }

        return $this->createReservation(
            actor: $actor,
            restaurant: $restaurant,
            source: ReservationSource::from($attributes['source']),
            attributes: $attributes,
            user: isset($attributes['user_id']) ? User::query()->findOrFail($attributes['user_id']) : null,
            guestContact: $guestContact,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateReservation(Reservation $reservation, User $actor, array $attributes): Reservation
    {
        return $this->withRestaurantReservationLock($reservation->restaurant, fn (): Reservation => DB::transaction(function () use ($reservation, $actor, $attributes): Reservation {
            $oldValues = $reservation->only([
                'starts_at',
                'ends_at',
                'party_size',
                'restaurant_table_id',
                'status',
                'notes',
                'internal_notes',
            ]);

            if (isset($attributes['starts_at']) || isset($attributes['party_size'])) {
                $startsAt = isset($attributes['starts_at'])
                    ? Carbon::parse($attributes['starts_at'])
                    : $reservation->starts_at;
                $partySize = $attributes['party_size'] ?? $reservation->party_size;

                $this->ensureBookableTime($reservation->restaurant, $startsAt);

                if (isset($attributes['party_size']) && count($reservation->guestsForApi()) > max(0, (int) $partySize - 1)) {
                    throw ValidationException::withMessages([
                        'party_size' => ['Party size cannot be smaller than the current guest list.'],
                    ]);
                }

                $table = $this->availabilityService->findAvailableTable(
                    $reservation->restaurant,
                    $startsAt,
                    $partySize,
                    $reservation->id,
                );

                if (! $table) {
                    throw ValidationException::withMessages([
                        'starts_at' => ['No table is available for the selected time.'],
                    ]);
                }

                $attributes['restaurant_table_id'] = $table->id;
                $attributes['ends_at'] = $this->availabilityService
                    ->calculateEndTime($reservation->restaurant, $startsAt, $partySize)
                    ->toDateTimeString();
            }

            $reservation->fill($attributes);
            $reservation->save();
            $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);

            $this->auditLogService->log(
                action: 'reservation.updated',
                actor: $actor,
                auditable: $reservation,
                oldValues: $oldValues,
                newValues: $reservation->only(array_keys($oldValues)),
                restaurant: $reservation->restaurant,
                organization: $reservation->restaurant->organization,
                description: 'Reservation updated',
            );

            event(new ReservationUpdated($reservation, 'updated'));

            foreach ($reservation->notifiableParticipants() as $participant) {
                $participant->notify(new ReservationLifecycleNotification($reservation, 'updated'));
            }

            $this->notifyReservationOwners($reservation, 'updated');

            return $reservation;
        }));
    }

    /**
     * How many additional attendees may be listed in `reservation_guests` (legacy: `metadata.guests`).
     * The person who made the booking occupies one seat, so the cap is `party_size - 1`.
     */
    public function maxAdditionalGuestListEntries(Reservation $reservation): int
    {
        return max(0, (int) $reservation->party_size - 1);
    }

    /**
     * @param  array<int, array{attendee_name: string, email_address: string, phone_number?: string|null}>  $guests
     */
    public function updateReservationGuests(Reservation $reservation, User $actor, array $guests): Reservation
    {
        $max = $this->maxAdditionalGuestListEntries($reservation);
        if (count($guests) > $max) {
            throw ValidationException::withMessages([
                'guests' => [
                    $max === 0
                        ? 'This reservation is for 1 person, so you cannot add additional guests.'
                        : "Guest list is full. You can add up to {$max} additional guest(s) for this reservation.",
                ],
            ]);
        }

        return $this->persistReservationGuests(
            $reservation,
            $actor,
            $guests,
            'reservation.guests_updated',
            'Reservation guests updated',
            'guests_updated',
        );
    }

    public function removeReservationGuest(Reservation $reservation, User $actor, ReservationGuest $guest): Reservation
    {
        if ($guest->reservation_id !== $reservation->id) {
            throw (new ModelNotFoundException)->setModel(ReservationGuest::class, [$guest->id]);
        }

        $reservation->loadMissing('reservationGuests');
        $current = $reservation->guestsForApi();
        $targetEmail = Str::lower(trim($guest->email_address));
        $filtered = array_values(array_filter(
            $current,
            fn (array $row): bool => Str::lower(trim($row['email_address'] ?? '')) !== $targetEmail
        ));

        return $this->persistReservationGuests(
            $reservation,
            $actor,
            $filtered,
            'reservation.guest_removed',
            'Reservation guest removed',
            'guests_updated',
        );
    }

    /**
     * Appends (merges) guests into `reservation_guests` by email. Same email: later entry wins.
     * Use this for incremental adds; use {@see updateReservationGuests} to replace the full list.
     *
     * @param  array<int, array{attendee_name: string, email_address: string, phone_number?: string|null}>  $newGuests
     */
    public function addReservationGuests(Reservation $reservation, User $actor, array $newGuests): Reservation
    {
        $existing = $reservation->guestsForApi();
        $merged = $this->mergeGuestListsByEmail($existing, $newGuests);

        $max = $this->maxAdditionalGuestListEntries($reservation);
        if (count($merged) > $max) {
            throw ValidationException::withMessages([
                'guests' => [
                    $max === 0
                        ? 'This reservation is for 1 person, so you cannot add additional guests.'
                        : "Guest list is full. You can add up to {$max} additional guest(s) for this reservation.",
                ],
            ]);
        }

        return $this->persistReservationGuests(
            $reservation,
            $actor,
            $merged,
            'reservation.guests_appended',
            'Reservation guests merged',
            'guests_updated',
        );
    }

    /**
     * @param  list<array{attendee_name: string, email_address: string, phone_number?: string|null}>  $existing
     * @param  array<int, array{attendee_name: string, email_address: string, phone_number?: string|null}>  $newGuests
     * @return list<array{attendee_name: string, email_address: string, phone_number?: string|null}>
     */
    protected function mergeGuestListsByEmail(array $existing, array $newGuests): array
    {
        $byEmail = [];
        $missingEmailKey = 0;

        foreach (array_merge($existing, $newGuests) as $guest) {
            $email = strtolower(trim($guest['email_address'] ?? ''));
            $key = $email !== '' ? $email : 'missing-email:'.($missingEmailKey++);
            $byEmail[$key] = $guest;
        }

        return array_values($byEmail);
    }

    /**
     * @param  list<array{attendee_name: string, email_address: string, phone_number?: string|null}>  $guests
     */
    protected function persistReservationGuests(
        Reservation $reservation,
        User $actor,
        array $guests,
        string $auditAction,
        string $auditDescription,
        string $eventAction,
    ): Reservation {
        return DB::transaction(function () use ($reservation, $actor, $guests, $auditAction, $auditDescription, $eventAction): Reservation {
            $reservation->loadMissing('reservationGuests');
            $oldGuests = $reservation->guestsForApi();
            $existingGuestEmails = collect($oldGuests)
                ->map(fn (array $guest): string => Str::lower(trim($guest['email_address'] ?? '')))
                ->filter()
                ->values()
                ->all();

            $reservation->reservationGuests()->delete();

            $metadata = $reservation->metadata;
            if (is_array($metadata) && array_key_exists('guests', $metadata)) {
                $metadata = Arr::except($metadata, ['guests']);
                if ($metadata === []) {
                    $metadata = null;
                }
            }
            $reservation->forceFill(['metadata' => $metadata]);
            $reservation->save();

            foreach ($guests as $guest) {
                $email = trim($guest['email_address']);
                ReservationGuest::query()->create([
                    'reservation_id' => $reservation->id,
                    'restaurant_id' => $reservation->restaurant_id,
                    'attendee_name' => $guest['attendee_name'],
                    'email_address' => $email,
                    'email_normalized' => Str::lower($email),
                    'phone_number' => $guest['phone_number'] ?? null,
                ]);
            }

            $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);

            $this->auditLogService->log(
                action: $auditAction,
                actor: $actor,
                auditable: $reservation,
                oldValues: ['guests' => $oldGuests],
                newValues: ['guests' => $guests],
                restaurant: $reservation->restaurant,
                organization: $reservation->restaurant->organization,
                description: $auditDescription,
            );

            event(new ReservationUpdated($reservation, $eventAction));

            $newlyAddedGuests = $reservation->reservationGuests
                ->filter(fn (ReservationGuest $guest): bool => ! in_array($guest->email_normalized, $existingGuestEmails, true))
                ->unique('email_normalized')
                ->values();

            foreach ($newlyAddedGuests as $guest) {
                $guest->notify(new ReservationLifecycleNotification($reservation, 'guest_added'));
            }

            return $reservation;
        });
    }

    public function cancelReservation(Reservation $reservation, ?User $actor, string $action = 'cancelled'): Reservation
    {
        $reservation->forceFill([
            'status' => ReservationStatus::Cancelled,
            'canceled_at' => now(),
            'canceled_by_user_id' => $actor?->id,
        ])->save();

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);

        $this->auditLogService->log(
            action: 'reservation.cancelled',
            actor: $actor,
            auditable: $reservation,
            restaurant: $reservation->restaurant,
            organization: $reservation->restaurant->organization,
            description: 'Reservation cancelled',
        );

        event(new ReservationUpdated($reservation, $action));

        foreach ($reservation->notifiableParticipants() as $participant) {
            $participant->notify(new ReservationLifecycleNotification($reservation, 'cancelled'));
        }

        $this->notifyReservationOwners($reservation, 'cancelled');

        $this->dispatchAvailabilityAlertCheck($reservation);

        return $reservation;
    }

    public function assignTable(Reservation $reservation, RestaurantTable $table, User $actor): Reservation
    {
        return $this->withRestaurantReservationLock($reservation->restaurant, function () use ($reservation, $table, $actor): Reservation {
            return DB::transaction(function () use ($reservation, $table, $actor): Reservation {
                if (! $this->availabilityService->isTableAvailable(
                    restaurant: $reservation->restaurant,
                    table: $table,
                    startsAt: $reservation->starts_at,
                    partySize: $reservation->party_size,
                    excludingReservationId: $reservation->id,
                )) {
                    throw ValidationException::withMessages([
                        'restaurant_table_id' => ['Selected table is unavailable or conflicts with an existing booking. Please retry.'],
                    ]);
                }

                $reservation->forceFill(['restaurant_table_id' => $table->id])->save();
                $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);

                $this->auditLogService->log(
                    action: 'reservation.table_assigned',
                    actor: $actor,
                    auditable: $reservation,
                    restaurant: $reservation->restaurant,
                    organization: $reservation->restaurant->organization,
                    description: 'Reservation table assigned',
                );

                event(new ReservationUpdated($reservation, 'table_assigned'));

                return $reservation;
            });
        });
    }

    public function seatReservation(Reservation $reservation, User $actor): Reservation
    {
        if (! in_array($reservation->status, [
            ReservationStatus::Booked,
            ReservationStatus::Confirmed,
            ReservationStatus::Arrived,
            ReservationStatus::PartiallyArrived,
            ReservationStatus::LeftMessage,
            ReservationStatus::RunningLate,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only an active reservation can be seated.'],
            ]);
        }

        if (! $reservation->restaurant_table_id) {
            throw ValidationException::withMessages([
                'restaurant_table_id' => ['Assign an available table before seating this reservation.'],
            ]);
        }

        $reservation->forceFill([
            'status' => ReservationStatus::Seated,
            'service_stage' => ReservationServiceStage::Seated,
            'seated_at' => now(),
        ])->save();

        if ($reservation->table) {
            $reservation->table->update(['status' => TableStatus::Occupied]);
            event(new TableStatusUpdated($reservation->table, 'occupied'));
        }

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, 'seated'));

        return $reservation;
    }

    public function completeReservation(Reservation $reservation, User $actor): Reservation
    {
        if ($reservation->status !== ReservationStatus::Seated) {
            throw ValidationException::withMessages([
                'status' => ['Only a seated reservation can be completed.'],
            ]);
        }

        $reservation->forceFill([
            'status' => ReservationStatus::Completed,
            'completed_at' => now(),
        ])->save();

        if ($reservation->table) {
            $reservation->table->update(['status' => TableStatus::Cleaning]);
            event(new TableStatusUpdated($reservation->table, 'cleaning'));
        }

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, 'completed'));

        foreach ($reservation->notifiableParticipants() as $participant) {
            $participant->notify(new ReservationLifecycleNotification($reservation, 'review_request'));
        }

        $this->maybeAwardReservationPoints($reservation);

        $this->dispatchAvailabilityAlertCheck($reservation);

        return $reservation;
    }

    public function updateServiceStage(
        Reservation $reservation,
        ReservationServiceStage $stage,
        User $actor,
    ): Reservation {
        // A completed reservation may still toggle between needing its table
        // bussed and being fully finished (bussed), but no other stage change
        // makes sense once the guest has left.
        $allowedAfterCompletion = [ReservationServiceStage::BussingNeeded, ReservationServiceStage::Finished];
        $allowed = $reservation->status === ReservationStatus::Seated
            || ($reservation->status === ReservationStatus::Completed && in_array($stage, $allowedAfterCompletion, true));

        if (! $allowed) {
            throw ValidationException::withMessages([
                'service_stage' => ['The service stage can only be changed while a reservation is seated, or toggled between bussing needed and finished after completion.'],
            ]);
        }

        $reservation->forceFill(['service_stage' => $stage])->save();
        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);

        $this->auditLogService->log(
            action: 'reservation.service_stage_updated',
            actor: $actor,
            auditable: $reservation,
            restaurant: $reservation->restaurant,
            organization: $reservation->restaurant->organization,
            description: 'Reservation service stage updated',
        );

        event(new ReservationUpdated($reservation, 'service_stage_updated'));

        return $reservation;
    }

    /**
     * Award loyalty points to the user for a completed reservation,
     * if the reservation has `accept_points` set and the restaurant participates in rewards.
     */
    protected function maybeAwardReservationPoints(Reservation $reservation): void
    {
        if (! $reservation->accept_points) {
            return;
        }

        if (! $reservation->user_id) {
            return;
        }

        $reservation->loadMissing('restaurant');

        if (! $reservation->restaurant->rewards_enabled) {
            return;
        }

        $points = $this->rewardRuleService->resolvePoints($reservation->restaurant, $reservation->starts_at);

        $this->rewardProgramService->awardPoints(
            user: $reservation->user,
            attributes: [
                'points' => $points,
                'type' => RewardPointTransactionType::Earn,
                'description' => 'Points earned for completing a reservation.',
                'reference_type' => Reservation::class,
                'reference_id' => $reservation->id,
                'metadata' => [
                    'restaurant_id' => $reservation->restaurant_id,
                    'restaurant_name' => $reservation->restaurant->name,
                    'reservation_reference' => $reservation->reservation_reference,
                    'reservation_starts_at' => $reservation->starts_at?->toIso8601String(),
                ],
            ],
        );
    }

    public function arriveReservation(Reservation $reservation, User $actor): Reservation
    {
        $reservation->forceFill([
            'status' => ReservationStatus::Arrived,
        ])->save();

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, 'arrived'));

        return $reservation;
    }

    public function partiallyArriveReservation(Reservation $reservation, User $actor): Reservation
    {
        $reservation->forceFill([
            'status' => ReservationStatus::PartiallyArrived,
        ])->save();

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, 'partially_arrived'));

        return $reservation;
    }

    public function leftMessageReservation(Reservation $reservation, User $actor): Reservation
    {
        $reservation->forceFill([
            'status' => ReservationStatus::LeftMessage,
        ])->save();

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, 'left_message'));

        return $reservation;
    }

    public function runningLateReservation(Reservation $reservation, User $actor): Reservation
    {
        $reservation->forceFill([
            'status' => ReservationStatus::RunningLate,
        ])->save();

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, 'running_late'));

        return $reservation;
    }

    public function noShowReservation(Reservation $reservation, User $actor, bool $automated = false): Reservation
    {
        if ($automated) {
            $eligibleStatuses = config('reservations.no_show_eligible_statuses', []);

            if (! in_array($reservation->status->value, $eligibleStatuses, true)) {
                return $reservation;
            }
        }

        $reservation->forceFill([
            'status' => ReservationStatus::NoShow,
        ])->save();

        if ($reservation->table) {
            $reservation->table->update(['status' => TableStatus::Available]);
            event(new TableStatusUpdated($reservation->table, 'available'));
        }

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, $automated ? 'no_show_automated' : 'no_show'));

        ChargeNoShowFeeJob::dispatch($reservation->id);

        $this->dispatchAvailabilityAlertCheck($reservation);

        return $reservation;
    }

    protected function dispatchAvailabilityAlertCheck(Reservation $reservation): void
    {
        if ($reservation->restaurant_id === null) {
            return;
        }

        ProcessRestaurantAvailabilityAlerts::dispatch(
            $reservation->restaurant_id,
            $reservation->starts_at?->copy(),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createWaitlistEntry(
        Restaurant $restaurant,
        User $actor,
        array $attributes,
        ?User $customer = null,
        ?GuestContact $guestContact = null,
    ): WaitlistEntry {
        $entry = WaitlistEntry::query()->create([
            'restaurant_id' => $restaurant->id,
            'user_id' => $customer?->id,
            'guest_contact_id' => $guestContact?->id,
            'status' => WaitlistStatus::Waiting,
            'party_size' => $attributes['party_size'],
            'preferred_starts_at' => Carbon::parse($attributes['preferred_starts_at']),
            'preferred_ends_at' => isset($attributes['preferred_ends_at']) ? Carbon::parse($attributes['preferred_ends_at']) : null,
            'notes' => $attributes['notes'] ?? null,
            'occasion' => $attributes['occasion'] ?? null,
        ]);

        $entry->load(['restaurant', 'reservation.reservationGuests', 'user', 'guestContact']);

        $this->auditLogService->log(
            action: 'waitlist.created',
            actor: $actor,
            auditable: $entry,
            restaurant: $restaurant,
            organization: $restaurant->organization,
            description: 'Waitlist entry created',
        );

        event(new WaitlistEntryUpdated($entry, 'created'));

        return $entry;
    }

    public function notifyWaitlistEntry(WaitlistEntry $entry, User $actor, int $expiresInMinutes = 15): WaitlistEntry
    {
        return DB::transaction(function () use ($entry, $expiresInMinutes): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->status !== WaitlistStatus::Waiting) {
                throw ValidationException::withMessages([
                    'waitlist_entry' => ['Only waiting entries can be notified.'],
                ]);
            }

            $entry->forceFill([
                'status' => WaitlistStatus::Notified,
                'notified_at' => now(),
                'expires_at' => now()->addMinutes($expiresInMinutes),
            ])->save();

            $entry->refresh()->load(['restaurant', 'reservation.reservationGuests', 'user', 'guestContact']);

            if ($entry->user) {
                $entry->user->notify(new WaitlistAvailabilityNotification($entry));
            } elseif ($this->guestContactHasEmail($entry->guestContact)) {
                Notification::route('mail', $entry->guestContact->email)
                    ->notify(new GuestWaitlistTableAvailableMailNotification($entry));
            }

            event(new WaitlistEntryUpdated($entry, 'notified'));

            return $entry;
        });
    }

    public function arriveWaitlistEntry(WaitlistEntry $entry, User $actor): WaitlistEntry
    {
        return DB::transaction(function () use ($entry, $actor): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if (! in_array($entry->status, [WaitlistStatus::Waiting, WaitlistStatus::Notified], true)) {
                throw ValidationException::withMessages([
                    'waitlist_entry' => ['Only waiting or notified entries can be marked arrived.'],
                ]);
            }

            $entry->forceFill(['status' => WaitlistStatus::Arrived])->save();
            $entry->refresh()->load(['restaurant', 'reservation.reservationGuests', 'user', 'guestContact']);

            $this->auditLogService->log(
                action: 'waitlist.arrived',
                actor: $actor,
                auditable: $entry,
                restaurant: $entry->restaurant,
                organization: $entry->restaurant->organization,
                description: 'Waitlist entry marked arrived',
            );

            event(new WaitlistEntryUpdated($entry, 'arrived'));

            return $entry;
        });
    }

    public function cancelWaitlistEntry(WaitlistEntry $entry, User $actor): WaitlistEntry
    {
        return DB::transaction(function () use ($entry, $actor): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if (! in_array($entry->status, [WaitlistStatus::Waiting, WaitlistStatus::Notified, WaitlistStatus::Arrived], true)) {
                throw ValidationException::withMessages([
                    'waitlist_entry' => ['Only waiting, notified, or arrived entries can be cancelled.'],
                ]);
            }

            $entry->forceFill(['status' => WaitlistStatus::Cancelled])->save();
            $entry->refresh()->load(['restaurant', 'reservation.reservationGuests', 'user', 'guestContact']);

            $this->auditLogService->log(
                action: 'waitlist.cancelled',
                actor: $actor,
                auditable: $entry,
                restaurant: $entry->restaurant,
                organization: $entry->restaurant->organization,
                description: 'Waitlist entry cancelled',
            );

            event(new WaitlistEntryUpdated($entry, 'cancelled'));

            return $entry;
        });
    }

    public function acceptWaitlistEntry(WaitlistEntry $entry, User $customer): Reservation
    {
        return DB::transaction(function () use ($entry, $customer): Reservation {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $entry->loadMissing('restaurant');

            $this->ensureWaitlistEntryBelongsToCustomer($entry, $customer);
            $this->ensureWaitlistEntryCanBeRespondedTo($entry);
            $this->ensureBookableTime($entry->restaurant, $entry->preferred_starts_at);

            $table = $this->availabilityService->findAvailableTable(
                $entry->restaurant,
                $entry->preferred_starts_at,
                $entry->party_size,
            );

            if (! $table) {
                $this->markWaitlistEntryExpired($entry, WaitlistExpiryReason::TableUnavailable);

                throw ValidationException::withMessages([
                    'waitlist_entry' => ['This waitlist offer is no longer available.'],
                ]);
            }

            $reservation = $this->createReservation(
                actor: $customer,
                restaurant: $entry->restaurant,
                source: ReservationSource::Waitlist,
                attributes: [
                    'starts_at' => $entry->preferred_starts_at,
                    'party_size' => $entry->party_size,
                    'notes' => $entry->notes,
                    'restaurant_table_id' => $table->id,
                ],
                user: $customer,
                guestContact: $entry->guestContact,
            );

            $entry->forceFill([
                'status' => WaitlistStatus::Accepted,
                'reservation_id' => $reservation->id,
                'metadata' => array_merge($entry->metadata ?? [], [
                    'decision' => 'accepted',
                    'responded_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $entry->refresh()->load(['restaurant', 'reservation.reservationGuests', 'user', 'guestContact']);
            event(new WaitlistEntryUpdated($entry, 'accepted'));

            return $reservation;
        });
    }

    public function declineWaitlistEntry(WaitlistEntry $entry, User $customer): WaitlistEntry
    {
        return DB::transaction(function () use ($entry, $customer): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            $this->ensureWaitlistEntryBelongsToCustomer($entry, $customer);
            $this->ensureWaitlistEntryCanBeRespondedTo($entry);

            $entry->forceFill([
                'status' => WaitlistStatus::Declined,
                'metadata' => array_merge($entry->metadata ?? [], [
                    'decision' => 'declined',
                    'responded_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $entry->refresh()->load(['restaurant', 'reservation.reservationGuests', 'user', 'guestContact']);
            event(new WaitlistEntryUpdated($entry, 'declined'));

            return $entry;
        });
    }

    public function assignWaitlistEntryToTable(WaitlistEntry $entry, RestaurantTable $table, User $actor): Reservation
    {
        return DB::transaction(function () use ($entry, $table, $actor): Reservation {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if (! in_array($entry->status, [WaitlistStatus::Waiting, WaitlistStatus::Notified, WaitlistStatus::Arrived], true)) {
                throw ValidationException::withMessages([
                    'waitlist_entry' => ['This waitlist entry can no longer be assigned.'],
                ]);
            }

            $reservation = $this->createReservation(
                actor: $actor,
                restaurant: $entry->restaurant,
                source: ReservationSource::Waitlist,
                attributes: [
                    'starts_at' => $entry->preferred_starts_at,
                    'party_size' => $entry->party_size,
                    'notes' => $entry->notes,
                    'restaurant_table_id' => $table->id,
                ],
                user: $entry->user,
                guestContact: $entry->guestContact,
            );

            // Assigning a table to a waitlist party seats them now: the reservation
            // must land in the Seated bucket, not Reservations (it is created as Booked).
            $reservation = $this->seatReservation($reservation, $actor);

            $entry->forceFill([
                'status' => WaitlistStatus::Seated,
                'reservation_id' => $reservation->id,
                'seated_at' => now(),
            ])->save();

            $entry->refresh()->load(['restaurant', 'reservation.reservationGuests', 'user', 'guestContact']);
            event(new WaitlistEntryUpdated($entry, 'seated'));

            return $reservation;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createReservation(
        User $actor,
        Restaurant $restaurant,
        ReservationSource $source,
        array $attributes,
        ?User $user = null,
        ?GuestContact $guestContact = null,
    ): Reservation {
        return $this->withRestaurantReservationLock($restaurant, function () use ($actor, $restaurant, $source, $attributes, $user, $guestContact): Reservation {
            return DB::transaction(function () use ($actor, $restaurant, $source, $attributes, $user, $guestContact): Reservation {
                $startsAt = Carbon::parse($attributes['starts_at']);
                $diningAreaId = isset($attributes['dining_area_id']) ? (int) $attributes['dining_area_id'] : null;
                $hasSelectedTable = isset($attributes['restaurant_table_id']);

                $this->ensureBookableTime($restaurant, $startsAt);

                $table = $hasSelectedTable
                    ? RestaurantTable::query()->where('restaurant_id', $restaurant->id)->findOrFail($attributes['restaurant_table_id'])
                    : $this->availabilityService->findAvailableTable($restaurant, $startsAt, (int) $attributes['party_size'], null, $diningAreaId);

                if (! $table) {
                    throw ValidationException::withMessages([
                        'starts_at' => ['No table is available for the selected time.'],
                    ]);
                }

                if (! $this->availabilityService->isTableAvailable(
                    restaurant: $restaurant,
                    table: $table,
                    startsAt: $startsAt,
                    partySize: (int) $attributes['party_size'],
                )) {
                    throw ValidationException::withMessages([
                        $hasSelectedTable ? 'restaurant_table_id' : 'starts_at' => [
                            $hasSelectedTable
                                ? 'Selected table is unavailable or conflicts with an existing booking. Please retry.'
                                : 'No table is available for the selected time. Please retry.',
                        ],
                    ]);
                }

                $reservation = Reservation::query()->create([
                    'restaurant_id' => $restaurant->id,
                    'user_id' => $user?->id,
                    'guest_contact_id' => $guestContact?->id,
                    'restaurant_table_id' => $table->id,
                    'reservation_reference' => $this->generateReference(),
                    'source' => $source,
                    'status' => ReservationStatus::Booked,
                    'party_size' => $attributes['party_size'],
                    'starts_at' => $startsAt,
                    'ends_at' => $this->availabilityService->calculateEndTime($restaurant, $startsAt, $attributes['party_size']),
                    'notes' => $attributes['notes'] ?? null,
                    'occasion' => $attributes['occasion'] ?? null,
                    'accept_points' => (bool) ($attributes['accept_points'] ?? false),
                    'subscribe_to_promotions' => (bool) ($attributes['subscribe_to_promotions'] ?? false),
                    'internal_notes' => $attributes['internal_notes'] ?? null,
                ]);

                $reservation->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);

                $this->auditLogService->log(
                    action: 'reservation.created',
                    actor: $actor,
                    auditable: $reservation,
                    restaurant: $restaurant,
                    organization: $restaurant->organization,
                    description: 'Reservation created',
                );

                event(new ReservationUpdated($reservation, 'created'));

                if ($user) {
                    $user->notify(new ReservationLifecycleNotification($reservation, 'created'));
                } elseif ($guestContact) {
                    $guestContact->notify(new ReservationLifecycleNotification($reservation, 'created'));
                }

                $this->notifyReservationOwners($reservation, 'created');

                return $reservation;
            });
        });
    }

    protected function notifyReservationOwners(Reservation $reservation, string $action): void
    {
        $reservation->loadMissing('restaurant.organization');

        $owners = User::query()
            ->whereNotNull('email')
            ->whereHas('roleAssignments', function ($query) use ($reservation): void {
                $query
                    ->where('organization_id', $reservation->restaurant->organization_id)
                    ->whereHas('role', fn ($roleQuery) => $roleQuery->where('name', Role::OrganizationOwner));
            })
            ->get()
            ->unique('email');

        Notification::send($owners, new OwnerReservationLifecycleNotification($reservation, $action));
    }

    private function ensureBookableTime(Restaurant $restaurant, CarbonInterface $startsAt): void
    {
        if (! $this->availabilityService->isBookableAt($restaurant, $startsAt)) {
            throw ValidationException::withMessages([
                'starts_at' => ['The selected time is outside the restaurant booking hours.'],
            ]);
        }
    }

    protected function withRestaurantReservationLock(Restaurant $restaurant, Closure $callback): mixed
    {
        try {
            return Cache::lock(
                "restaurant:{$restaurant->id}:reservations",
                (int) config('performance.locks.reservation_seconds'),
            )->block(
                (int) config('performance.locks.reservation_wait_seconds'),
                $callback,
            );
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'starts_at' => ['Reservation availability changed while processing your request. Please retry.'],
            ]);
        }
    }

    protected function generateReference(): string
    {
        do {
            $reference = 'MT-'.Str::upper(Str::random(8));
        } while (Reservation::query()->where('reservation_reference', $reference)->exists());

        return $reference;
    }

    protected function ensureWaitlistEntryBelongsToCustomer(WaitlistEntry $entry, User $customer): void
    {
        abort_unless($entry->user_id === $customer->id, 404);
    }

    protected function ensureWaitlistEntryCanBeRespondedTo(WaitlistEntry $entry): void
    {
        if ($entry->status !== WaitlistStatus::Notified) {
            throw ValidationException::withMessages([
                'waitlist_entry' => ['This waitlist entry is not awaiting a response.'],
            ]);
        }

        if ($entry->expires_at && $entry->expires_at->isPast()) {
            $this->markWaitlistEntryExpired($entry, WaitlistExpiryReason::TimeExpired);

            throw ValidationException::withMessages([
                'waitlist_entry' => ['This waitlist offer has expired.'],
            ]);
        }
    }

    protected function markWaitlistEntryExpired(WaitlistEntry $entry, WaitlistExpiryReason $reason): WaitlistEntry
    {
        $entry->forceFill([
            'status' => WaitlistStatus::Expired,
            'metadata' => array_merge($entry->metadata ?? [], [
                'decision' => 'expired',
                'expiry_reason' => $reason->value,
                'responded_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $entry->refresh()->load(['restaurant', 'reservation.reservationGuests', 'user', 'guestContact']);
        event(new WaitlistEntryUpdated($entry, 'expired'));

        $this->notifyWaitlistOfferClosed($entry, $reason);

        return $entry;
    }

    protected function notifyWaitlistOfferClosed(WaitlistEntry $entry, WaitlistExpiryReason $reason): void
    {
        if ($entry->user) {
            match ($reason) {
                WaitlistExpiryReason::TimeExpired => $entry->user->notify(new WaitlistOfferExpiredNotification($entry)),
                WaitlistExpiryReason::TableUnavailable => $entry->user->notify(new WaitlistTableNoLongerAvailableNotification($entry)),
            };

            return;
        }

        if (! $this->guestContactHasEmail($entry->guestContact)) {
            return;
        }

        $email = $entry->guestContact->email;

        match ($reason) {
            WaitlistExpiryReason::TimeExpired => Notification::route('mail', $email)
                ->notify(new GuestWaitlistOfferExpiredMailNotification($entry)),
            WaitlistExpiryReason::TableUnavailable => Notification::route('mail', $email)
                ->notify(new GuestWaitlistTableUnavailableMailNotification($entry)),
        };
    }

    protected function guestContactHasEmail(?GuestContact $guestContact): bool
    {
        if (! $guestContact?->email) {
            return false;
        }

        return filter_var($guestContact->email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
