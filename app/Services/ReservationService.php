<?php

namespace App\Services;

use App\Events\ReservationUpdated;
use App\Events\TableStatusUpdated;
use App\Events\WaitlistEntryUpdated;
use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\GuestReservationLifecycleMailNotification;
use App\Notifications\GuestWaitlistOfferExpiredMailNotification;
use App\Notifications\GuestWaitlistTableAvailableMailNotification;
use App\Notifications\GuestWaitlistTableUnavailableMailNotification;
use App\Notifications\ReservationLifecycleNotification;
use App\Notifications\WaitlistAvailabilityNotification;
use App\Notifications\WaitlistOfferExpiredNotification;
use App\Notifications\WaitlistTableNoLongerAvailableNotification;
use App\ReservationSource;
use App\ReservationStatus;
use App\TableStatus;
use App\UserStatus;
use App\WaitlistExpiryReason;
use App\WaitlistStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected AuditLogService $auditLogService,
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

        return $this->createReservation(
            actor: $user,
            restaurant: $restaurant,
            source: ReservationSource::Customer,
            attributes: $attributes,
            user: $user,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createMerchantReservation(User $actor, Restaurant $restaurant, array $attributes): Reservation
    {
        $guestContact = null;

        if (! empty($attributes['guest_contact']) && empty($attributes['user_id'])) {
            $guestContact = GuestContact::query()->create([
                'restaurant_id' => $restaurant->id,
                'first_name' => $attributes['guest_contact']['first_name'],
                'last_name' => $attributes['guest_contact']['last_name'] ?? null,
                'email' => $attributes['guest_contact']['email'] ?? null,
                'phone' => $attributes['guest_contact']['phone'],
                'notes' => $attributes['notes'] ?? null,
                'is_temporary' => true,
            ]);
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
        return DB::transaction(function () use ($reservation, $actor, $attributes): Reservation {
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
                    ->calculateEndTime($reservation->restaurant, $startsAt)
                    ->toDateTimeString();
            }

            $reservation->fill($attributes);
            $reservation->save();
            $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact']);

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

            if ($reservation->user) {
                $reservation->user->notify(new ReservationLifecycleNotification($reservation, 'updated'));
            } elseif ($this->guestContactHasEmail($reservation->guestContact)) {
                Notification::route('mail', $reservation->guestContact->email)
                    ->notify(new GuestReservationLifecycleMailNotification($reservation, $reservation->guestContact, 'updated'));
            }

            return $reservation;
        });
    }

    /**
     * @param  array<int, array{attendee_name: string, email_address: string, phone_number?: string|null}>  $guests
     */
    public function updateReservationGuests(Reservation $reservation, User $actor, array $guests): Reservation
    {
        if (count($guests) > $reservation->party_size) {
            throw ValidationException::withMessages([
                'guests' => ['Guest count cannot exceed the reservation party size.'],
            ]);
        }

        return DB::transaction(function () use ($reservation, $actor, $guests): Reservation {
            $oldMetadata = $reservation->metadata ?? [];
            $newMetadata = array_merge($oldMetadata, ['guests' => $guests]);

            $reservation->forceFill([
                'metadata' => $newMetadata,
            ])->save();

            $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact']);

            $this->auditLogService->log(
                action: 'reservation.guests_updated',
                actor: $actor,
                auditable: $reservation,
                oldValues: ['metadata' => $oldMetadata],
                newValues: ['metadata' => $newMetadata],
                restaurant: $reservation->restaurant,
                organization: $reservation->restaurant->organization,
                description: 'Reservation guests updated',
            );

            event(new ReservationUpdated($reservation, 'guests_updated'));

            return $reservation;
        });
    }

    public function cancelReservation(Reservation $reservation, User $actor, string $action = 'cancelled'): Reservation
    {
        $reservation->forceFill([
            'status' => ReservationStatus::Cancelled,
            'canceled_at' => now(),
            'canceled_by_user_id' => $actor->id,
        ])->save();

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact']);

        $this->auditLogService->log(
            action: 'reservation.cancelled',
            actor: $actor,
            auditable: $reservation,
            restaurant: $reservation->restaurant,
            organization: $reservation->restaurant->organization,
            description: 'Reservation cancelled',
        );

        event(new ReservationUpdated($reservation, $action));

        if ($reservation->user) {
            $reservation->user->notify(new ReservationLifecycleNotification($reservation, 'cancelled'));
        } elseif ($this->guestContactHasEmail($reservation->guestContact)) {
            Notification::route('mail', $reservation->guestContact->email)
                ->notify(new GuestReservationLifecycleMailNotification($reservation, $reservation->guestContact, 'cancelled'));
        }

        return $reservation;
    }

    public function assignTable(Reservation $reservation, RestaurantTable $table, User $actor): Reservation
    {
        if ($table->max_capacity < $reservation->party_size || $table->min_capacity > $reservation->party_size) {
            throw ValidationException::withMessages([
                'restaurant_table_id' => ['Selected table cannot accommodate this party size.'],
            ]);
        }

        $conflictingTable = $this->availabilityService->findAvailableTable(
            $reservation->restaurant,
            $reservation->starts_at,
            $reservation->party_size,
            $reservation->id,
        );

        if (! $conflictingTable || $conflictingTable->id !== $table->id) {
            throw ValidationException::withMessages([
                'restaurant_table_id' => ['Selected table conflicts with an existing booking.'],
            ]);
        }

        $reservation->forceFill(['restaurant_table_id' => $table->id])->save();
        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact']);

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
    }

    public function seatReservation(Reservation $reservation, User $actor): Reservation
    {
        $reservation->forceFill([
            'status' => ReservationStatus::Seated,
            'seated_at' => now(),
        ])->save();

        if ($reservation->table) {
            $reservation->table->update(['status' => TableStatus::Occupied]);
            event(new TableStatusUpdated($reservation->table, 'occupied'));
        }

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact']);
        event(new ReservationUpdated($reservation, 'seated'));

        return $reservation;
    }

    public function completeReservation(Reservation $reservation, User $actor): Reservation
    {
        $reservation->forceFill([
            'status' => ReservationStatus::Completed,
            'completed_at' => now(),
        ])->save();

        if ($reservation->table) {
            $reservation->table->update(['status' => TableStatus::Available]);
            event(new TableStatusUpdated($reservation->table, 'available'));
        }

        $reservation->refresh()->load(['restaurant', 'table', 'user', 'guestContact']);
        event(new ReservationUpdated($reservation, 'completed'));

        return $reservation;
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
        ]);

        $entry->load(['restaurant', 'reservation', 'user', 'guestContact']);

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

            $entry->refresh()->load(['restaurant', 'reservation', 'user', 'guestContact']);

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

    public function acceptWaitlistEntry(WaitlistEntry $entry, User $customer): Reservation
    {
        return DB::transaction(function () use ($entry, $customer): Reservation {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $entry->loadMissing('restaurant');

            $this->ensureWaitlistEntryBelongsToCustomer($entry, $customer);
            $this->ensureWaitlistEntryCanBeRespondedTo($entry);

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

            $entry->refresh()->load(['restaurant', 'reservation', 'user', 'guestContact']);
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

            $entry->refresh()->load(['restaurant', 'reservation', 'user', 'guestContact']);
            event(new WaitlistEntryUpdated($entry, 'declined'));

            return $entry;
        });
    }

    public function assignWaitlistEntryToTable(WaitlistEntry $entry, RestaurantTable $table, User $actor): Reservation
    {
        return DB::transaction(function () use ($entry, $table, $actor): Reservation {
            $entry = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if (! in_array($entry->status, [WaitlistStatus::Waiting, WaitlistStatus::Notified], true)) {
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

            $entry->forceFill([
                'status' => WaitlistStatus::Seated,
                'reservation_id' => $reservation->id,
                'seated_at' => now(),
            ])->save();

            $entry->refresh()->load(['restaurant', 'reservation', 'user', 'guestContact']);
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
        return DB::transaction(function () use ($actor, $restaurant, $source, $attributes, $user, $guestContact): Reservation {
            $startsAt = Carbon::parse($attributes['starts_at']);
            $table = isset($attributes['restaurant_table_id'])
                ? RestaurantTable::query()->where('restaurant_id', $restaurant->id)->findOrFail($attributes['restaurant_table_id'])
                : $this->availabilityService->findAvailableTable($restaurant, $startsAt, (int) $attributes['party_size']);

            if (! $table) {
                throw ValidationException::withMessages([
                    'starts_at' => ['No table is available for the selected time.'],
                ]);
            }

            if ($table->max_capacity < (int) $attributes['party_size']) {
                throw ValidationException::withMessages([
                    'restaurant_table_id' => ['Selected table cannot accommodate this party size.'],
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
                'ends_at' => $this->availabilityService->calculateEndTime($restaurant, $startsAt),
                'notes' => $attributes['notes'] ?? null,
                'internal_notes' => $attributes['internal_notes'] ?? null,
            ]);

            $reservation->load(['restaurant', 'table', 'user', 'guestContact']);

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
            } elseif ($this->guestContactHasEmail($guestContact)) {
                Notification::route('mail', $guestContact->email)
                    ->notify(new GuestReservationLifecycleMailNotification($reservation, $guestContact, 'created'));
            }

            return $reservation;
        });
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

        $entry->refresh()->load(['restaurant', 'reservation', 'user', 'guestContact']);
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
