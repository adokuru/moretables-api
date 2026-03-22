<?php

namespace App\Services;

use App\Models\RewardLevel;
use App\Models\RewardPointTransaction;
use App\Models\RewardProgram;
use App\Models\User;
use App\RewardPointTransactionType;
use App\RewardProgramPeriodType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RewardProgramService
{
    protected const DEFAULT_PROGRAM_SLUG = 'moretables-lifetime-loyalty';

    /**
     * @var list<array{name: string, slug: string, start_points: int, end_points: ?int, sort_order: int}>
     */
    protected const DEFAULT_LEVELS = [
        [
            'name' => 'Bronze',
            'slug' => 'bronze',
            'start_points' => 0,
            'end_points' => 999,
            'sort_order' => 0,
        ],
        [
            'name' => 'Silver',
            'slug' => 'silver',
            'start_points' => 1000,
            'end_points' => 4999,
            'sort_order' => 1,
        ],
        [
            'name' => 'Gold',
            'slug' => 'gold',
            'start_points' => 5000,
            'end_points' => 9999,
            'sort_order' => 2,
        ],
        [
            'name' => 'Platinum',
            'slug' => 'platinum',
            'start_points' => 10000,
            'end_points' => null,
            'sort_order' => 3,
        ],
    ];

    public function activeProgram(): RewardProgram
    {
        $program = RewardProgram::query()
            ->with('levels')
            ->where('is_active', true)
            ->first();

        return $program ?? $this->provisionDefaultProgram();
    }

    /**
     * @return array<string, mixed>
     */
    public function statusForUser(User $user, ?RewardProgram $program = null): array
    {
        $program = $program?->loadMissing('levels') ?? $this->activeProgram();
        $points = $this->currentPointsForUser($user, $program);
        $currentLevel = $this->levelForPoints($program, $points);
        $nextLevel = $program->levels->first(fn (RewardLevel $level): bool => $level->start_points > $points);

        return [
            'program' => $this->programPayload($program),
            'points' => $points,
            'current_level' => $this->levelPayload($currentLevel),
            'next_level' => $this->levelPayload($nextLevel),
            'points_to_next_level' => $nextLevel ? max($nextLevel->start_points - $points, 0) : 0,
            'progress_percentage' => $this->progressPercentage($currentLevel, $points),
        ];
    }

    public function transactionsForUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $program = $this->activeProgram();

        return RewardPointTransaction::query()
            ->with(['rewardProgram.levels', 'createdBy'])
            ->where('reward_program_id', $program->id)
            ->where('user_id', $user->id)
            ->latest()
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function awardPoints(User $user, array $attributes, ?User $actor = null): RewardPointTransaction
    {
        $program = $this->activeProgram();

        return DB::transaction(function () use ($user, $attributes, $actor, $program): RewardPointTransaction {
            $latestTransaction = RewardPointTransaction::query()
                ->where('reward_program_id', $program->id)
                ->where('user_id', $user->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $currentBalance = $latestTransaction?->balance_after ?? 0;
            $points = (int) $attributes['points'];
            $newBalance = $currentBalance + $points;

            if ($newBalance < 0) {
                throw ValidationException::withMessages([
                    'points' => ['This transaction would reduce the user below 0 points.'],
                ]);
            }

            return RewardPointTransaction::query()->create([
                'reward_program_id' => $program->id,
                'user_id' => $user->id,
                'created_by' => $actor?->id,
                'type' => $attributes['type'] ?? RewardPointTransactionType::Adjustment,
                'points' => $points,
                'balance_after' => $newBalance,
                'description' => $attributes['description'] ?? null,
                'reference_type' => $attributes['reference_type'] ?? null,
                'reference_id' => $attributes['reference_id'] ?? null,
                'metadata' => $attributes['metadata'] ?? null,
            ])->load(['rewardProgram.levels', 'createdBy']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateProgram(array $attributes): RewardProgram
    {
        return DB::transaction(function () use ($attributes): RewardProgram {
            $program = $this->activeProgram();

            $program->fill([
                'name' => $attributes['name'] ?? $program->name,
                'description' => array_key_exists('description', $attributes) ? $attributes['description'] : $program->description,
            ]);

            $program->forceFill([
                'period_type' => RewardProgramPeriodType::Lifetime,
                'period_value' => null,
                'resets_points' => false,
                'tier_locked_until_period_end' => false,
                'is_active' => true,
            ])->save();

            RewardProgram::query()
                ->whereKeyNot($program->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            if (array_key_exists('levels', $attributes)) {
                $levels = collect($attributes['levels'])
                    ->sortBy('start_points')
                    ->values()
                    ->map(function (array $level, int $index): array {
                        return [
                            'name' => $level['name'],
                            'slug' => Str::slug($level['name']),
                            'start_points' => (int) $level['start_points'],
                            'end_points' => $level['end_points'] !== null ? (int) $level['end_points'] : null,
                            'sort_order' => (int) ($level['sort_order'] ?? $index),
                        ];
                    })
                    ->all();

                $program->levels()->delete();
                $program->levels()->createMany($levels);
            }

            return $program->refresh()->load('levels');
        });
    }

    public function currentPointsForUser(User $user, ?RewardProgram $program = null): int
    {
        $program = $program ?? $this->activeProgram();

        return (int) (RewardPointTransaction::query()
            ->where('reward_program_id', $program->id)
            ->where('user_id', $user->id)
            ->latest('id')
            ->value('balance_after') ?? 0);
    }

    public function levelForPoints(RewardProgram $program, int $points): ?RewardLevel
    {
        $program->loadMissing('levels');

        return $program->levels
            ->sortBy('start_points')
            ->first(function (RewardLevel $level) use ($points): bool {
                return $points >= $level->start_points
                    && ($level->end_points === null || $points <= $level->end_points);
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function programPayload(RewardProgram $program): array
    {
        $program->loadMissing('levels');

        return [
            'id' => $program->id,
            'name' => $program->name,
            'slug' => $program->slug,
            'description' => $program->description,
            'period_type' => $program->period_type?->value,
            'period_value' => $program->period_value,
            'resets_points' => (bool) $program->resets_points,
            'tier_locked_until_period_end' => (bool) $program->tier_locked_until_period_end,
            'is_active' => (bool) $program->is_active,
            'levels' => $program->levels
                ->sortBy('start_points')
                ->values()
                ->map(fn (RewardLevel $level): array => $this->levelPayload($level))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transactionPayload(RewardPointTransaction $transaction): array
    {
        $transaction->loadMissing(['rewardProgram.levels', 'createdBy']);
        $level = $transaction->rewardProgram
            ? $this->levelForPoints($transaction->rewardProgram, $transaction->balance_after)
            : null;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type?->value,
            'points' => $transaction->points,
            'balance_after' => $transaction->balance_after,
            'description' => $transaction->description,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            'metadata' => $transaction->metadata ?? [],
            'created_at' => $transaction->created_at?->toIso8601String(),
            'created_by' => $transaction->createdBy ? [
                'id' => $transaction->createdBy->id,
                'name' => $transaction->createdBy->fullName(),
            ] : null,
            'level_after' => $this->levelPayload($level),
        ];
    }

    protected function provisionDefaultProgram(): RewardProgram
    {
        return DB::transaction(function (): RewardProgram {
            $program = RewardProgram::query()->firstOrCreate(
                ['slug' => self::DEFAULT_PROGRAM_SLUG],
                [
                    'name' => 'MoreTables Loyalty Rewards',
                    'description' => 'Lifetime loyalty program with Bronze, Silver, Gold, and Platinum tiers.',
                    'period_type' => RewardProgramPeriodType::Lifetime,
                    'period_value' => null,
                    'resets_points' => false,
                    'tier_locked_until_period_end' => false,
                    'is_active' => true,
                ],
            );

            if (! $program->levels()->exists()) {
                $program->levels()->createMany(self::DEFAULT_LEVELS);
            }

            RewardProgram::query()
                ->whereKeyNot($program->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            if (! $program->is_active) {
                $program->update(['is_active' => true]);
            }

            return $program->refresh()->load('levels');
        });
    }

    /**
     * @return array{name: string, slug: string, start_points: int, end_points: ?int}|null
     */
    protected function levelPayload(?RewardLevel $level): ?array
    {
        if (! $level) {
            return null;
        }

        return [
            'name' => $level->name,
            'slug' => $level->slug,
            'start_points' => $level->start_points,
            'end_points' => $level->end_points,
        ];
    }

    protected function progressPercentage(?RewardLevel $currentLevel, int $points): int
    {
        if (! $currentLevel) {
            return 0;
        }

        if ($currentLevel->end_points === null) {
            return 100;
        }

        $range = max($currentLevel->end_points - $currentLevel->start_points, 1);
        $progress = (($points - $currentLevel->start_points) / $range) * 100;

        return (int) round(max(0, min($progress, 100)));
    }
}
