<?php

namespace App\Models;

use App\ReservationSource;
use App\ReservationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    /** @use HasFactory<\Database\Factories\ReservationFactory> */
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
        'party_size',
        'starts_at',
        'ends_at',
        'notes',
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
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'seated_at' => 'datetime',
            'completed_at' => 'datetime',
            'canceled_at' => 'datetime',
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
}
