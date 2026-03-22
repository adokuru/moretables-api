<?php

namespace App\Models;

use Database\Factories\GuestContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestContact extends Model
{
    /** @use HasFactory<GuestContactFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'notes',
        'is_temporary',
    ];

    protected function casts(): array
    {
        return [
            'is_temporary' => 'boolean',
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
