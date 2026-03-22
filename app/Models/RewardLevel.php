<?php

namespace App\Models;

use Database\Factories\RewardLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardLevel extends Model
{
    /** @use HasFactory<RewardLevelFactory> */
    use HasFactory;

    protected $fillable = [
        'reward_program_id',
        'name',
        'slug',
        'start_points',
        'end_points',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_points' => 'integer',
            'end_points' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function rewardProgram(): BelongsTo
    {
        return $this->belongsTo(RewardProgram::class);
    }
}
