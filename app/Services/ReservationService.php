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
use App\Models\TableCombination;
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
use Illuminate\Database\Eloquent\Collection;
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

            $this->updateGuestContactFromBooking($guestContact, $contact);
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
        $guestContact = $attributes['guest_contact'] ?? null;
        unset($attributes['guest_contact']);

        return $this->withRestaurantReservationLock($reservation->restaurant, fn (): Reservation => DB::transaction(function () use ($reservation, $actor, $attributes, $guestContact): Reservation {
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
            if ($guestContact && $reservation->guestContact) {
                $this->updateGuestContactFromBooking($reservation->guestContact, $guestContact);
            }
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

            event(new ReservationUpdated($reservation, 'updated', $actor));

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
        $normalizedEmails = array_map(
            fn (array $guest): string => Str::lower(trim($guest['email_address'] ?? '')),
            $guests,
        );

        if (count($normalizedEmails) !== count(array_unique($normalizedEmails))) {
            throw ValidationException::withMessages([
                'guests' => ['Each attendee needs a different email address. Please update the duplicate and try again.'],
            ]);
        }

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

            event(new ReservationUpdated($reservation, $eventAction, $actor));

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
        $wasSeated = $reservation->status === ReservationStatus::Seated;
        $tables = $this->reservationTables($reservation);

        $reservation->forceFill([
            'status' => ReservationStatus::Cancelled,
            'canceled_at' => now(),
            'canceled_by_user_id' => $actor?->id,
        ])->save();

        $this->setTableStatuses(
            $wasSeated ? $tables : $tables->where('status', TableStatus::Reserved),
            TableStatus::Available,
        );

        $reservation->refresh()->load(['restaurant', 'table', 'assignedTables', 'user', 'guestContact', 'reservationGuests']);

        $this->auditLogService->log(
            action: 'reservation.cancelled',
            actor: $actor,
            auditable: $reservation,
            restaurant: $reservation->restaurant,
            organization: $reservation->restaurant->organization,
            description: 'Reservation cancelled',
        );

        event(new ReservationUpdated($reservation, $action, $actor));

        foreach ($reservation->notifiableParticipants() as $participant) {
            $participant->notify(new ReservationLifecycleNotification($reservation, 'cancelled'));
        }

        $this->notifyReservationOwners($reservation, 'cancelled');

        $this->dispatchAvailabilityAlertCheck($reservation);

        return $reservation;
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return Collection<int, RestaurantTable>
     */
    public function resolveTableSelection(Restaurant $restaurant, array $selection, int $partySize): Collection
    {
        if (isset($selection['table_combination_id'])) {
            $combination = TableCombination::query()
                ->where('restaurant_id', $restaurant->id)
                ->findOrFail((int) $selection['table_combination_id']);

            if ($partySize < $combination->min_capacity || $partySize > $combination->max_capacity) {
                throw ValidationException::withMessages([
                    'table_combination_id' => ["This combination seats {$combination->min_capacity}-{$combination->max_capacity} guests and does not fit a party of {$partySize}."],
                ]);
            }

            $tableIds = TableCombination::normalizeTableIds($combination->table_ids);
        } elseif (isset($selection['restaurant_table_ids'])) {
            $tableIds = TableCombination::normalizeTableIds($selection['restaurant_table_ids']);
        } else {
            $tableIds = [(int) $selection['restaurant_table_id']];
        }

        $tablesById = $restaurant->tables()->whereIn('id', $tableIds)->get()->keyBy('id');
        if ($tablesById->count() !== count($tableIds)) {
            throw ValidationException::withMessages([
                'restaurant_table_ids' => ['Every selected table must belong to this restaurant.'],
            ]);
        }

        $tables = new Collection(array_map(fn (int $id): RestaurantTable => $tablesById->get($id), $tableIds));

        if (isset($selection['restaurant_table_ids']) && ! $this->combinationFitsParty($restaurant, $tables, $partySize)) {
            throw ValidationException::withMessages([
                'restaurant_table_ids' => ["The selected tables seat {$tables->sum('max_capacity')} guests and do not fit a party of {$partySize}."],
            ]);
        }

        return $tables;
    }

    public function assignTable(Reservation $reservation, RestaurantTable $table, User $actor): Reservation
    {
        return $this->assignTables($reservation, new Collection([$table]), $actor);
    }

    /** @param Collection<int, RestaurantTable> $tables */
    public function assignTables(Reservation $reservation, Collection $tables, User $actor): Reservation
    {
        return $this->withRestaurantReservationLock($reservation->restaurant, function () use ($reservation, $tables, $actor): Reservation {
            return DB::transaction(function () use ($reservation, $tables, $actor): Reservation {
                $reservation = Reservation::query()
                    ->with(['restaurant', 'table', 'assignedTables'])
                    ->lockForUpdate()
                    ->findOrFail($reservation->id);
                $previousTables = $this->reservationTables($reservation);

                if ($previousTables->pluck('id')->sort()->values()->all() === $tables->pluck('id')->sort()->values()->all()) {
                    return $reservation->load(['user', 'guestContact', 'reservationGuests']);
                }

                if ($reservation->status === ReservationStatus::Seated || $tables->count() > 1) {
                    $this->ensureTablesAvailable(
                        reservation: $reservation,
                        tables: $tables,
                        startsAt: $reservation->status === ReservationStatus::Seated ? now() : $reservation->starts_at,
                        combination: $tables->count() > 1,
                    );
                }

                $reservation->forceFill(['restaurant_table_id' => $tables->first()->id])->save();
                $reservation->assignedTables()->sync($tables->modelKeys());

                if ($reservation->status === ReservationStatus::Seated) {
                    $this->setTableStatuses($previousTables->whereNotIn('id', $tables->modelKeys()), TableStatus::Available);
                    $this->setTableStatuses($tables, TableStatus::Occupied);
                }

                $reservation->refresh()->load(['restaurant', 'table', 'assignedTables', 'user', 'guestContact', 'reservationGuests']);

                $this->auditLogService->log(
                    action: 'reservation.table_assigned',
                    actor: $actor,
                    auditable: $reservation,
                    restaurant: $reservation->restaurant,
                    organization: $reservation->restaurant->organization,
                    description: 'Reservation tables assigned',
                );

                event(new ReservationUpdated($reservation, 'table_assigned', $actor));

                return $reservation;
            });
        });
    }

    public function seatReservation(
        Reservation $reservation,
        User $actor,
        RestaurantTable|Collection|null $requestedTables = null,
        ?ReservationServiceStage $serviceStage = null,
    ): Reservation {
        return $this->withRestaurantReservationLock($reservation->restaurant, function () use ($reservation, $actor, $requestedTables, $serviceStage): Reservation {
            return DB::transaction(function () use ($reservation, $actor, $requestedTables, $serviceStage): Reservation {
                $reservation = Reservation::query()
                    ->with(['restaurant', 'table', 'assignedTables'])
                    ->lockForUpdate()
                    ->findOrFail($reservation->id);

                if ($requestedTables !== null) {
                    $tables = $requestedTables instanceof RestaurantTable
                        ? new Collection([$requestedTables])
                        : $requestedTables;
                    $reservation->forceFill(['restaurant_table_id' => $tables->first()->id])->save();
                    $reservation->assignedTables()->sync($tables->modelKeys());
                    $reservation->setRelation('assignedTables', $tables);
                    $reservation->setRelation('table', $tables->first());
                }

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

                $tables = $this->reservationTables($reservation);

                if ($tables->isEmpty()) {
                    throw ValidationException::withMessages([
                        'restaurant_table_id' => ['Assign an available table before seating this reservation.'],
                    ]);
                }

                $this->ensureTablesAvailable($reservation, $tables, $reservation->starts_at, $tables->count() > 1);

                $reservation->forceFill([
                    'status' => ReservationStatus::Seated,
                    'service_stage' => $serviceStage ?? ReservationServiceStage::Seated,
                    'seated_at' => now(),
                ])->save();

                $this->setTableStatuses($tables, TableStatus::Occupied);

                $reservation->refresh()->load(['restaurant', 'table', 'assignedTables', 'user', 'guestContact', 'reservationGuests']);
                $this->auditLogService->log(
                    action: 'reservation.seated',
                    actor: $actor,
                    auditable: $reservation,
                    restaurant: $reservation->restaurant,
                    organization: $reservation->restaurant->organization,
                    description: 'Reservation seated',
                );
                event(new ReservationUpdated($reservation, 'seated', $actor));

                return $reservation;
            });
        });
    }

    public function moveReservation(
        Reservation $reservation,
        User $actor,
        ?string $requestedStartsAt = null,
        ?RestaurantTable $requestedTable = null,
    ): Reservation {
        return $this->withRestaurantReservationLock($reservation->restaurant, function () use ($reservation, $actor, $requestedStartsAt, $requestedTable): Reservation {
            return DB::transaction(function () use ($reservation, $actor, $requestedStartsAt, $requestedTable): Reservation {
                $reservation = Reservation::query()
                    ->with(['restaurant', 'table'])
                    ->lockForUpdate()
                    ->findOrFail($reservation->id);
                $previousTable = $reservation->table;
                $startsAt = $requestedStartsAt ? Carbon::parse($requestedStartsAt) : $reservation->starts_at;
                $table = $requestedTable ?? $reservation->table;

                $this->ensureBookableTime($reservation->restaurant, $startsAt);

                if (! $table) {
                    throw ValidationException::withMessages([
                        'restaurant_table_id' => ['Assign an available table before moving this reservation.'],
                    ]);
                }

                abort_unless($table->restaurant_id === $reservation->restaurant_id, 404);

                $seatedOnTable = $reservation->status === ReservationStatus::Seated
                    ? Reservation::query()
                        ->with(['user', 'guestContact'])
                        ->where('restaurant_table_id', $table->id)
                        ->where('status', ReservationStatus::Seated)
                        ->whereKeyNot($reservation->id)
                        ->first()
                    : null;

                if ($seatedOnTable !== null) {
                    throw ValidationException::withMessages([
                        'restaurant_table_id' => [$this->tableUnavailableMessage($reservation->restaurant, $table, $seatedOnTable)],
                    ]);
                }

                if (! $this->availabilityService->isTableAvailable(
                    restaurant: $reservation->restaurant,
                    table: $table,
                    startsAt: $startsAt,
                    partySize: $reservation->party_size,
                    excludingReservationId: $reservation->id,
                )) {
                    $reason = $this->availabilityService->explainUnavailable(
                        restaurant: $reservation->restaurant,
                        table: $table,
                        startsAt: $startsAt,
                        partySize: $reservation->party_size,
                        excludingReservationId: $reservation->id,
                    );

                    throw ValidationException::withMessages([
                        'restaurant_table_id' => [$this->tableUnavailableMessage($reservation->restaurant, $table, $reason)],
                    ]);
                }

                $oldValues = $reservation->only(['starts_at', 'ends_at', 'restaurant_table_id']);
                $reservation->forceFill([
                    'starts_at' => $startsAt,
                    'ends_at' => $this->availabilityService->calculateEndTime(
                        $reservation->restaurant,
                        $startsAt,
                        $reservation->party_size,
                    ),
                    'restaurant_table_id' => $table->id,
                ])->save();

                if ($reservation->status === ReservationStatus::Seated && $previousTable?->id !== $table->id) {
                    $previousTable?->update(['status' => TableStatus::Available]);
                    if ($previousTable) {
                        event(new TableStatusUpdated($previousTable, 'available'));
                    }
                    $table->update(['status' => TableStatus::Occupied]);
                    event(new TableStatusUpdated($table, 'occupied'));
                }

                $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
                $this->auditLogService->log(
                    action: 'reservation.moved',
                    actor: $actor,
                    auditable: $reservation,
                    oldValues: $oldValues,
                    newValues: $reservation->only(array_keys($oldValues)),
                    restaurant: $reservation->restaurant,
                    organization: $reservation->restaurant->organization,
                    description: 'Reservation moved',
                );
                event(new ReservationUpdated($reservation, 'moved', $actor));

                return $reservation;
            });
        });
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

        $this->setTableStatuses($this->reservationTables($reservation), TableStatus::Cleaning);

        $reservation->refresh()->load(['restaurant', 'table', 'assignedTables', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, 'completed', $actor));

        if (! $reservation->restaurant->guestSurveys()->where('status', 'published')->exists()) {
            foreach ($reservation->notifiableParticipants() as $participant) {
                $participant->notify(new ReservationLifecycleNotification($reservation, 'review_request'));
            }
        }

        $this->maybeAwardReservationPoints($reservation);

        $this->dispatchAvailabilityAlertCheck($reservation);

        return $reservation;
    }

    public function clearReservationTables(Reservation $reservation, User $actor): Reservation
    {
        if ($reservation->status !== ReservationStatus::Completed) {
            throw ValidationException::withMessages([
                'status' => ['Only a completed reservation can have its tables cleared.'],
            ]);
        }

        $this->setTableStatuses($this->reservationTables($reservation), TableStatus::Available);
        $reservation->refresh()->load(['restaurant', 'table', 'assignedTables', 'user', 'guestContact', 'reservationGuests']);

        $this->auditLogService->log(
            action: 'reservation.tables_cleared',
            actor: $actor,
            auditable: $reservation,
            restaurant: $reservation->restaurant,
            organization: $reservation->restaurant->organization,
            description: 'Reservation tables cleared',
        );

        event(new ReservationUpdated($reservation, 'tables_cleared', $actor));

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

        event(new ReservationUpdated($reservation, 'service_stage_updated', $actor));

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

        if (! $reservation->restaurant->offersMoretablesCredits()) {
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
            'arrived_at' => $reservation->arrived_at ?? now(),
        ])->save();

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, 'arrived', $actor));

        return $reservation;
    }

    public function partiallyArriveReservation(Reservation $reservation, User $actor): Reservation
    {
        $reservation->forceFill([
            'status' => ReservationStatus::PartiallyArrived,
            'arrived_at' => $reservation->arrived_at ?? now(),
        ])->save();

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, 'partially_arrived', $actor));

        return $reservation;
    }

    public function leftMessageReservation(Reservation $reservation, User $actor): Reservation
    {
        $reservation->forceFill([
            'status' => ReservationStatus::LeftMessage,
        ])->save();

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, 'left_message', $actor));

        return $reservation;
    }

    public function runningLateReservation(Reservation $reservation, User $actor): Reservation
    {
        $reservation->forceFill([
            'status' => ReservationStatus::RunningLate,
        ])->save();

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, 'running_late', $actor));

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

        $wasSeated = $reservation->status === ReservationStatus::Seated;
        $tables = $this->reservationTables($reservation);

        $reservation->forceFill([
            'status' => ReservationStatus::NoShow,
        ])->save();

        $this->setTableStatuses(
            $wasSeated ? $tables : $tables->where('status', TableStatus::Reserved),
            TableStatus::Available,
        );

        $reservation->refresh()->load(['restaurant', 'table', 'assignedTables', 'user', 'guestContact', 'reservationGuests']);
        event(new ReservationUpdated($reservation, $automated ? 'no_show_automated' : 'no_show', $actor));

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

            // PartiallyArrived is allowed here too — the two are peer choices
            // in the same "Update Status" list (see partiallyArriveWaitlistEntry
            // below), so staff can correct one to the other without needing
            // to go back through Waiting/Notified first.
            if (! in_array($entry->status, [WaitlistStatus::Waiting, WaitlistStatus::Notified, WaitlistStatus::PartiallyArrived], true)) {
                throw ValidationException::withMessages([
                    'waitlist_entry' => ['Only waiting, notified, or partially arrived entries can be marked arrived.'],
                ]);
            }

            $entry->forceFill([
                'status' => WaitlistStatus::Arrived,
                'arrived_at' => $entry->arrived_at ?? now(),
            ])->save();
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

    public function partiallyArriveWaitlistEntry(WaitlistEntry $entry, User $actor): WaitlistEntry
    {
        return DB::transaction(function () use ($entry, $actor): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if (! in_array($entry->status, [WaitlistStatus::Waiting, WaitlistStatus::Notified, WaitlistStatus::Arrived], true)) {
                throw ValidationException::withMessages([
                    'waitlist_entry' => ['Only waiting, notified, or arrived entries can be marked partially arrived.'],
                ]);
            }

            $entry->forceFill([
                'status' => WaitlistStatus::PartiallyArrived,
                'arrived_at' => $entry->arrived_at ?? now(),
            ])->save();
            $entry->refresh()->load(['restaurant', 'reservation.reservationGuests', 'user', 'guestContact']);

            $this->auditLogService->log(
                action: 'waitlist.partially_arrived',
                actor: $actor,
                auditable: $entry,
                restaurant: $entry->restaurant,
                organization: $entry->restaurant->organization,
                description: 'Waitlist entry marked partially arrived',
            );

            event(new WaitlistEntryUpdated($entry, 'partially_arrived'));

            return $entry;
        });
    }

    public function cancelWaitlistEntry(WaitlistEntry $entry, User $actor): WaitlistEntry
    {
        return DB::transaction(function () use ($entry, $actor): WaitlistEntry {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if (! in_array($entry->status, [WaitlistStatus::Waiting, WaitlistStatus::Notified, WaitlistStatus::Arrived, WaitlistStatus::PartiallyArrived], true)) {
                throw ValidationException::withMessages([
                    'waitlist_entry' => ['Only waiting, notified, arrived, or partially arrived entries can be cancelled.'],
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
        return $this->assignWaitlistEntryToTables($entry, new Collection([$table]), $actor);
    }

    /** @param Collection<int, RestaurantTable> $tables */
    public function assignWaitlistEntryToTables(WaitlistEntry $entry, Collection $tables, User $actor): Reservation
    {
        return DB::transaction(function () use ($entry, $tables, $actor): Reservation {
            $entry = WaitlistEntry::query()->with('assignedTables')->lockForUpdate()->findOrFail($entry->id);

            if (! in_array($entry->status, [WaitlistStatus::Waiting, WaitlistStatus::Notified, WaitlistStatus::Arrived, WaitlistStatus::PartiallyArrived], true)) {
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
                    'restaurant_table_id' => $tables->first()->id,
                    'restaurant_table_ids' => $tables->modelKeys(),
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
     * Save a tentative table for a waitlist party without seating or changing status.
     */
    public function preassignWaitlistEntryToTable(WaitlistEntry $entry, RestaurantTable $table, User $actor): WaitlistEntry
    {
        return $this->preassignWaitlistEntryToTables($entry, new Collection([$table]), $actor);
    }

    /** @param Collection<int, RestaurantTable> $tables */
    public function preassignWaitlistEntryToTables(WaitlistEntry $entry, Collection $tables, User $actor): WaitlistEntry
    {
        return $this->withRestaurantReservationLock($entry->restaurant, function () use ($entry, $tables, $actor): WaitlistEntry {
            return DB::transaction(function () use ($entry, $tables, $actor): WaitlistEntry {
                $entry = WaitlistEntry::query()->with('assignedTables')->lockForUpdate()->findOrFail($entry->id);

                if (! in_array($entry->status, [WaitlistStatus::Waiting, WaitlistStatus::Notified, WaitlistStatus::Arrived, WaitlistStatus::PartiallyArrived], true)) {
                    throw ValidationException::withMessages([
                        'waitlist_entry' => ['This waitlist entry can no longer be assigned.'],
                    ]);
                }

                if ($tables->count() > 1) {
                    foreach ($tables as $table) {
                        if (! $this->availabilityService->isCombinationMemberAvailable(
                            restaurant: $entry->restaurant,
                            table: $table,
                            startsAt: $entry->preferred_starts_at,
                            partySize: $entry->party_size,
                        )) {
                            $reason = $this->availabilityService->explainCombinationMemberUnavailable(
                                restaurant: $entry->restaurant,
                                table: $table,
                                startsAt: $entry->preferred_starts_at,
                                partySize: $entry->party_size,
                            );

                            throw ValidationException::withMessages([
                                'restaurant_table_ids' => [$this->tableUnavailableMessage($entry->restaurant, $table, $reason)],
                            ]);
                        }
                    }
                }

                $entry->forceFill(['restaurant_table_id' => $tables->first()->id])->save();
                $entry->assignedTables()->sync($tables->modelKeys());
                $entry->refresh()->load(['restaurant', 'reservation.reservationGuests', 'table', 'assignedTables', 'user', 'guestContact']);

                $this->auditLogService->log(
                    action: 'waitlist.table_preassigned',
                    actor: $actor,
                    auditable: $entry,
                    restaurant: $entry->restaurant,
                    organization: $entry->restaurant->organization,
                    description: 'Waitlist table pre-assigned',
                );

                event(new WaitlistEntryUpdated($entry, 'table_assigned'));

                return $entry;
            });
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
                $selectedTableIds = TableCombination::normalizeTableIds($attributes['restaurant_table_ids'] ?? []);
                $hasSelectedCombination = count($selectedTableIds) > 1;

                $this->ensureBookableTime($restaurant, $startsAt);

                $table = $hasSelectedTable
                    ? RestaurantTable::query()->where('restaurant_id', $restaurant->id)->findOrFail($attributes['restaurant_table_id'])
                    : $this->availabilityService->findAvailableTable($restaurant, $startsAt, (int) $attributes['party_size'], null, $diningAreaId);

                if (! $table) {
                    throw ValidationException::withMessages([
                        'starts_at' => ['No table is available for the selected time.'],
                    ]);
                }

                if ($hasSelectedCombination) {
                    $selectedTables = $restaurant->tables()->whereIn('id', $selectedTableIds)->get();
                    if ($selectedTables->count() !== count($selectedTableIds) || ! $this->combinationFitsParty($restaurant, $selectedTables, (int) $attributes['party_size'])) {
                        throw ValidationException::withMessages([
                            'restaurant_table_ids' => ['The selected table combination does not fit this party.'],
                        ]);
                    }

                    foreach ($selectedTables as $selectedTable) {
                        if (! $this->availabilityService->isCombinationMemberAvailable(
                            restaurant: $restaurant,
                            table: $selectedTable,
                            startsAt: $startsAt,
                            partySize: (int) $attributes['party_size'],
                        )) {
                            $reason = $this->availabilityService->explainCombinationMemberUnavailable(
                                restaurant: $restaurant,
                                table: $selectedTable,
                                startsAt: $startsAt,
                                partySize: (int) $attributes['party_size'],
                            );

                            throw ValidationException::withMessages([
                                'restaurant_table_ids' => [$this->tableUnavailableMessage($restaurant, $selectedTable, $reason)],
                            ]);
                        }
                    }
                } elseif (! $this->availabilityService->isTableAvailable(
                    restaurant: $restaurant,
                    table: $table,
                    startsAt: $startsAt,
                    partySize: (int) $attributes['party_size'],
                )) {
                    if (! $hasSelectedTable) {
                        throw ValidationException::withMessages([
                            'starts_at' => ['No table is available for the selected time. Please retry.'],
                        ]);
                    }

                    $reason = $this->availabilityService->explainUnavailable(
                        restaurant: $restaurant,
                        table: $table,
                        startsAt: $startsAt,
                        partySize: (int) $attributes['party_size'],
                    );

                    throw ValidationException::withMessages([
                        'restaurant_table_id' => [$this->tableUnavailableMessage($restaurant, $table, $reason)],
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
                    'redeemed_points' => 0,
                    'subscribe_to_promotions' => (bool) ($attributes['subscribe_to_promotions'] ?? false),
                    'internal_notes' => $attributes['internal_notes'] ?? null,
                ]);

                if ($hasSelectedCombination) {
                    $reservation->assignedTables()->sync($selectedTableIds);
                }

                if ($user && ($attributes['use_points'] ?? false)) {
                    $redemption = $this->rewardProgramService->redeemAllPointsForReservation($user, $reservation);

                    $reservation->update([
                        'redeemed_points' => abs($redemption->points),
                    ]);
                }

                $reservation->load(['restaurant', 'table', 'user', 'guestContact', 'reservationGuests']);

                $this->auditLogService->log(
                    action: 'reservation.created',
                    actor: $actor,
                    auditable: $reservation,
                    restaurant: $restaurant,
                    organization: $restaurant->organization,
                    description: 'Reservation created',
                );

                event(new ReservationUpdated($reservation, 'created', $actor));

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

    /**
     * @param  array<string, mixed>  $contact
     */
    private function updateGuestContactFromBooking(GuestContact $guestContact, array $contact): void
    {
        $seatingPreference = $contact['seating_preference'] ?? null;
        unset($contact['full_name'], $contact['seating_preference']);

        $guestContact->fill(array_filter($contact, fn ($value) => $value !== null));

        if ($seatingPreference !== null) {
            $guestContact->preferences = [
                ...($guestContact->preferences ?? []),
                'seating_preference' => $seatingPreference,
            ];
        }

        $guestContact->save();
    }

    private function ensureBookableTime(Restaurant $restaurant, CarbonInterface $startsAt): void
    {
        if (! $this->availabilityService->isBookableAt($restaurant, $startsAt)) {
            throw ValidationException::withMessages([
                'starts_at' => ['The selected time is outside the restaurant booking hours.'],
            ]);
        }
    }

    private function guestDisplayName(Reservation $reservation): string
    {
        $name = $reservation->user?->fullName()
            ?? trim(($reservation->guestContact?->first_name ?? '').' '.($reservation->guestContact?->last_name ?? ''));

        return $name !== '' ? $name : 'a guest';
    }

    /**
     * "Mon, Aug 10 at 6:00 PM" in the restaurant's own timezone.
     */
    private function formatReservationWhen(Reservation $reservation): string
    {
        if ($reservation->starts_at === null) {
            return 'an unscheduled time';
        }

        $timezone = $reservation->restaurant?->timezone ?: config('app.timezone');

        return $reservation->starts_at->copy()->timezone($timezone)->format('D, M j \a\t g:i A');
    }

    /**
     * Turns AvailabilityService::explainUnavailable()'s result into a full,
     * specific sentence naming the table and, when the reason is a genuine
     * time conflict, the actual blocking guest/reservation — instead of the
     * old one-size-fits-all "Selected table is unavailable or conflicts with
     * an existing booking. Please retry."
     */
    private function tableUnavailableMessage(Restaurant $restaurant, RestaurantTable $table, string|Reservation $reason): string
    {
        if ($reason instanceof Reservation) {
            if ($reason->status === ReservationStatus::Seated) {
                return sprintf(
                    'Table %s is still seated with %s\'s reservation from %s. Mark that reservation finished to free the table, or assign a different one.',
                    $table->name,
                    $this->guestDisplayName($reason),
                    $this->formatReservationWhen($reason),
                );
            }

            if ($reason->status === ReservationStatus::Completed) {
                // Names the exact reservation + shift that left the table
                // dirty, so staff can go find it in the Finished section
                // instead of guessing — this was the whole point of the ask,
                // a bare "still being cleaned" with no pointer to which
                // reservation caused it wasn't useful enough.
                $shiftName = $this->availabilityService->resolveShiftName($restaurant, $reason->starts_at);

                return sprintf(
                    'Table %s is still being cleaned after %s\'s reservation (%s%s, reservation #%s). Find it in the Finished section and mark it Cleared, or assign a different table.',
                    $table->name,
                    $this->guestDisplayName($reason),
                    $this->formatReservationWhen($reason),
                    $shiftName ? ", {$shiftName} shift" : '',
                    $reason->id,
                );
            }

            return sprintf(
                'Table %s is booked for %s\'s reservation at %s. Assign a different table.',
                $table->name,
                $this->guestDisplayName($reason),
                $this->formatReservationWhen($reason),
            );
        }

        return sprintf('Table %s is unavailable: %s', $table->name, $reason);
    }

    /** @return Collection<int, RestaurantTable> */
    private function reservationTables(Reservation $reservation): Collection
    {
        $reservation->loadMissing(['assignedTables', 'table']);

        if ($reservation->assignedTables->isNotEmpty()) {
            return $reservation->assignedTables;
        }

        return $reservation->table ? new Collection([$reservation->table]) : new Collection;
    }

    /** @param Collection<int, RestaurantTable> $tables */
    private function combinationFitsParty(Restaurant $restaurant, Collection $tables, int $partySize): bool
    {
        if ($tables->sum('max_capacity') >= $partySize) {
            return true;
        }

        $tableIds = TableCombination::normalizeTableIds($tables->modelKeys());

        return $restaurant->tableCombinations()
            ->where('min_capacity', '<=', $partySize)
            ->where('max_capacity', '>=', $partySize)
            ->get(['table_ids'])
            ->contains(fn (TableCombination $combination): bool => TableCombination::normalizeTableIds($combination->table_ids) === $tableIds);
    }

    /** @param Collection<int, RestaurantTable> $tables */
    private function ensureTablesAvailable(
        Reservation $reservation,
        Collection $tables,
        CarbonInterface $startsAt,
        bool $combination,
    ): void {
        if ($combination && ! $this->combinationFitsParty($reservation->restaurant, $tables, $reservation->party_size)) {
            throw ValidationException::withMessages([
                'restaurant_table_ids' => ['The assigned table combination no longer fits this party.'],
            ]);
        }

        foreach ($tables as $table) {
            abort_unless($table->restaurant_id === $reservation->restaurant_id, 404);

            $memberAvailable = $this->availabilityService->isCombinationMemberAvailable(
                restaurant: $reservation->restaurant,
                table: $table,
                startsAt: $startsAt,
                partySize: $reservation->party_size,
                excludingReservationId: $reservation->id,
            );
            $available = $memberAvailable && ($combination || $this->availabilityService->isTableAvailable(
                restaurant: $reservation->restaurant,
                table: $table,
                startsAt: $startsAt,
                partySize: $reservation->party_size,
                excludingReservationId: $reservation->id,
            ));

            if ($available) {
                continue;
            }

            $reason = $memberAvailable
                ? $this->availabilityService->explainUnavailable(
                    restaurant: $reservation->restaurant,
                    table: $table,
                    startsAt: $startsAt,
                    partySize: $reservation->party_size,
                    excludingReservationId: $reservation->id,
                )
                : $this->availabilityService->explainCombinationMemberUnavailable(
                    restaurant: $reservation->restaurant,
                    table: $table,
                    startsAt: $startsAt,
                    partySize: $reservation->party_size,
                    excludingReservationId: $reservation->id,
                );

            throw ValidationException::withMessages([
                $combination ? 'restaurant_table_ids' : 'restaurant_table_id' => [
                    $this->tableUnavailableMessage($reservation->restaurant, $table, $reason),
                ],
            ]);
        }
    }

    /** @param Collection<int, RestaurantTable> $tables */
    private function setTableStatuses(Collection $tables, TableStatus $status): void
    {
        DB::transaction(function () use ($tables, $status): void {
            foreach ($tables as $table) {
                $table->update(['status' => $status]);
                event(new TableStatusUpdated($table->refresh(), $status->value));
            }
        });
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
