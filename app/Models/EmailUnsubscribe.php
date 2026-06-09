<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class EmailUnsubscribe extends Model
{
    protected $fillable = [
        'email',
        'email_normalized',
        'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'unsubscribed_at' => 'datetime',
        ];
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function isSuppressed(string $email): bool
    {
        return static::query()
            ->where('email_normalized', static::normalizeEmail($email))
            ->exists();
    }

    public static function suppress(string $email): self
    {
        return static::query()->firstOrCreate(
            ['email_normalized' => static::normalizeEmail($email)],
            ['email' => $email, 'unsubscribed_at' => Carbon::now()],
        );
    }
}
