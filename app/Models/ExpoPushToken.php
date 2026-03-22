<?php

namespace App\Models;

use Database\Factories\ExpoPushTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpoPushToken extends Model
{
    /** @use HasFactory<ExpoPushTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'expo_token',
        'device_id',
        'device_name',
        'platform',
        'app_version',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
