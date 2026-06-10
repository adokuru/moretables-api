<?php

namespace App\Models;

use Database\Factories\GuestContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

class GuestContact extends Model
{
    /** @use HasFactory<GuestContactFactory> */
    use HasFactory, Notifiable;

    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->phone;
    }

    protected $fillable = [
        'restaurant_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'notes',
        'is_temporary',
        'marketing_opt_in',
        'birthday',
        'wedding_anniversary',
        'preferences',
    ];

    protected function casts(): array
    {
        return [
            'is_temporary' => 'boolean',
            'marketing_opt_in' => 'boolean',
            'birthday' => 'date',
            'wedding_anniversary' => 'date',
            'preferences' => 'array',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }
}
