<?php

namespace App\Models;

use App\WaitlistStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistEntry extends Model
{
    /** @use HasFactory<\Database\Factories\WaitlistEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'user_id',
        'guest_contact_id',
        'reservation_id',
        'status',
        'party_size',
        'preferred_starts_at',
        'preferred_ends_at',
        'notes',
        'notified_at',
        'expires_at',
        'seated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => WaitlistStatus::class,
            'preferred_starts_at' => 'datetime',
            'preferred_ends_at' => 'datetime',
            'notified_at' => 'datetime',
            'expires_at' => 'datetime',
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
}
