<?php

namespace App\Models;

use Database\Factories\GuestCommunicationSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestCommunicationSetting extends Model
{
    /** @use HasFactory<GuestCommunicationSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'automated_messaging_enabled',
        'reservation_messaging_enabled',
    ];

    protected function casts(): array
    {
        return [
            'automated_messaging_enabled' => 'boolean',
            'reservation_messaging_enabled' => 'boolean',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
