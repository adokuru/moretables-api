<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;

class ReservationGuest extends Model
{
    use Notifiable;

    public function routeNotificationForMail(): ?string
    {
        return $this->email_address;
    }

    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->phone_number;
    }

    protected $fillable = [
        'reservation_id',
        'restaurant_id',
        'attendee_name',
        'email_address',
        'email_normalized',
        'phone_number',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
