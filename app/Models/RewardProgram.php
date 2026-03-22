<?php

namespace App\Models;

use App\RewardProgramPeriodType;
use Database\Factories\RewardProgramFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RewardProgram extends Model
{
    /** @use HasFactory<RewardProgramFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'period_type',
        'period_value',
        'resets_points',
        'tier_locked_until_period_end',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => RewardProgramPeriodType::class,
            'period_value' => 'integer',
            'resets_points' => 'boolean',
            'tier_locked_until_period_end' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function levels(): HasMany
    {
        return $this->hasMany(RewardLevel::class)->orderBy('sort_order')->orderBy('start_points');
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(RewardPointTransaction::class);
    }
}
