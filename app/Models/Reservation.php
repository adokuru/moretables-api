<?php

namespace App\Models;

use App\ReservationServiceStage;
use App\ReservationSource;
use App\ReservationStatus;
use App\Support\PhoneNumber;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'user_id',
        'guest_contact_id',
        'restaurant_table_id',
        'canceled_by_user_id',
        'reservation_reference',
        'source',
        'status',
        'service_stage',
        'party_size',
        'starts_at',
        'ends_at',
        'notes',
        'occasion',
        'accept_points',
        'subscribe_to_promotions',
        'internal_notes',
        'seated_at',
        'completed_at',
        'canceled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'source' => ReservationSource::class,
            'status' => ReservationStatus::class,
            'service_stage' => ReservationServiceStage::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'seated_at' => 'datetime',
            'completed_at' => 'datetime',
            'canceled_at' => 'datetime',
            'accept_points' => 'boolean',
            'subscribe_to_promotions' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guestContact(): BelongsTo
    {
        return $this->belongsTo(GuestContact::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    public function canceledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by_user_id');
    }

    public function reservationGuests(): HasMany
    {
        return $this->hasMany(ReservationGuest::class);
    }

    /**
     * Additional attendees for the reservation: DB rows if present, otherwise legacy `metadata.guests`.
     *
     * @return list<array{attendee_name: string, email_address: string, phone_number?: string|null}>
     */
    public function guestsForApi(): array
    {
        $this->loadMissing('reservationGuests');

        if ($this->reservationGuests->isNotEmpty()) {
            return $this->reservationGuests->sortBy('id')->values()->map(fn (ReservationGuest $g): array => array_filter([
                'attendee_name' => $g->attendee_name,
                'email_address' => $g->email_address,
                'phone_number' => $g->phone_number,
            ], fn ($v) => $v !== null))->values()->all();
        }

        return self::normalizeMetadataGuests(data_get($this->metadata, 'guests'));
    }

    /**
     * Ensure `metadata.guests` is always a list of guest objects.
     * A single guest may be stored as one associative array (not wrapped in a list).
     *
     * @return list<array<string, mixed>>
     */
    public static function normalizeMetadataGuests(mixed $guests): array
    {
        if ($guests === null || $guests === []) {
            return [];
        }

        if (! is_array($guests)) {
            return [];
        }

        if (array_is_list($guests)) {
            return array_values($guests);
        }

        if (isset($guests['attendee_name'], $guests['email_address'])) {
            return [$guests];
        }

        return array_values($guests);
    }

    /**
     * Everyone who should be notified about this reservation: the primary booker
     * (registered user or guest contact) plus the additional guests, deduplicated
     * by email and phone so nobody receives the same notification twice.
     *
     * @return Collection<int, User|GuestContact|ReservationGuest>
     */
    public function notifiableParticipants(): Collection
    {
        $this->loadMissing(['user', 'guestContact', 'reservationGuests']);

        $seenEmails = [];
        $seenPhones = [];

        $alreadySeen = function (?string $email, ?string $phone) use (&$seenEmails, &$seenPhones): bool {
            $email = Str::lower(trim((string) $email));
            $phone = PhoneNumber::forWhatsApp((string) $phone);

            $duplicate = ($email !== '' && in_array($email, $seenEmails, true))
                || ($phone !== '' && in_array($phone, $seenPhones, true));

            if ($email !== '') {
                $seenEmails[] = $email;
            }

            if ($phone !== '') {
                $seenPhones[] = $phone;
            }

            return $duplicate;
        };

        $participants = collect();
        $primaryBooker = $this->user ?? $this->guestContact;

        if ($primaryBooker !== null) {
            $alreadySeen($primaryBooker->email, $primaryBooker->phone);
            $participants->push($primaryBooker);
        }

        foreach ($this->reservationGuests as $guest) {
            if (! $alreadySeen($guest->email_address, $guest->phone_number)) {
                $participants->push($guest);
            }
        }

        return $participants;
    }

    public function hasUpcomingReminderSent(int $daysBefore): bool
    {
        return filled(data_get($this->metadata, "upcoming_reminders_sent.{$daysBefore}"));
    }

    public function markUpcomingReminderSent(int $daysBefore, \DateTimeInterface $sentAt): void
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        data_set(
            $metadata,
            "upcoming_reminders_sent.{$daysBefore}",
            $sentAt->format(DATE_ATOM),
        );

        $this->forceFill(['metadata' => $metadata])->save();
    }
}
