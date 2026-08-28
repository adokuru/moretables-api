<?php

namespace App\Models;

use App\WaitlistStatus;
use App\WaitlistType;
use Database\Factories\WaitlistEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WaitlistEntry extends Model
{
    /** @use HasFactory<WaitlistEntryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (WaitlistEntry $entry): void {
            if ($entry->restaurant_table_id !== null) {
                $entry->assignedTables()->syncWithoutDetaching([$entry->restaurant_table_id]);
            }
        });

        static::updated(function (WaitlistEntry $entry): void {
            if ($entry->wasChanged('restaurant_table_id')) {
                $entry->assignedTables()->sync(
                    $entry->restaurant_table_id === null ? [] : [$entry->restaurant_table_id],
                );
            }
        });
    }

    protected $fillable = [
        'restaurant_id',
        'user_id',
        'guest_contact_id',
        'reservation_id',
        'restaurant_table_id',
        'status',
        'type',
        'party_size',
        'preferred_starts_at',
        'preferred_ends_at',
        'notes',
        'occasion',
        'whatsapp_updates',
        'notified_at',
        'notification_count',
        'expires_at',
        'arrived_at',
        'seated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => WaitlistStatus::class,
            'type' => WaitlistType::class,
            'whatsapp_updates' => 'boolean',
            'preferred_starts_at' => 'datetime',
            'preferred_ends_at' => 'datetime',
            'notified_at' => 'datetime',
            'notification_count' => 'integer',
            'expires_at' => 'datetime',
            'arrived_at' => 'datetime',
            'seated_at' => 'datetime',
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

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    public function assignedTables(): BelongsToMany
    {
        return $this->belongsToMany(RestaurantTable::class, 'waitlist_table_assignments');
    }

    /**
     * @param  Builder<WaitlistEntry>  $query
     * @return Builder<WaitlistEntry>
     */
    public function scopeSeating(Builder $query): Builder
    {
        return $query->where('type', WaitlistType::Seating->value);
    }

    /**
     * @param  Builder<WaitlistEntry>  $query
     * @return Builder<WaitlistEntry>
     */
    public function scopeAvailabilityAlerts(Builder $query): Builder
    {
        return $query->where('type', WaitlistType::AvailabilityAlert->value);
    }

    public function isAvailabilityAlert(): bool
    {
        return $this->type === WaitlistType::AvailabilityAlert;
    }
}
