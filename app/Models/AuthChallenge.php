<?php

namespace App\Models;

use App\AuthChallengeStatus;
use App\AuthChallengeType;
use Database\Factories\AuthChallengeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthChallenge extends Model
{
    /** @use HasFactory<AuthChallengeFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'challenge_token',
        'code_hash',
        'code_expires_at',
        'attempts',
        'max_attempts',
        'last_sent_at',
        'consumed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => AuthChallengeType::class,
            'status' => AuthChallengeStatus::class,
            'code_expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'consumed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
