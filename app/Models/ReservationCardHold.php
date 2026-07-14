<?php

namespace App\Models;

use App\ReservationCardHoldStatus;
use Database\Factories\ReservationCardHoldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationCardHold extends Model
{
    /** @use HasFactory<ReservationCardHoldFactory> */
    use HasFactory;

    protected $fillable = [
        'reservation_id',
        'user_id',
        'restaurant_id',
        'provider',
        'reference',
        'authorization_code',
        'email',
        'brand',
        'last4',
        'status',
        'amount',
        'currency',
        'charge_reference',
        'charged_amount',
        'charged_at',
        'failure_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationCardHoldStatus::class,
            'amount' => 'integer',
            'charged_amount' => 'integer',
            'charged_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function isChargeable(): bool
    {
        return $this->authorization_code !== null
            && in_array($this->status, [ReservationCardHoldStatus::Authorized, ReservationCardHoldStatus::ChargeFailed], true);
    }
}
