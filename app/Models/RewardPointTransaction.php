<?php

namespace App\Models;

use App\RewardPointTransactionType;
use Database\Factories\RewardPointTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardPointTransaction extends Model
{
    /** @use HasFactory<RewardPointTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'reward_program_id',
        'user_id',
        'created_by',
        'type',
        'points',
        'balance_after',
        'description',
        'reference_type',
        'reference_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => RewardPointTransactionType::class,
            'points' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function rewardProgram(): BelongsTo
    {
        return $this->belongsTo(RewardProgram::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
