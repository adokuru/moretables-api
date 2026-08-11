<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\RewardLevel;
use App\Models\RewardPointTransaction;
use App\Models\RewardProgram;
use App\Models\User;
use App\RewardPointTransactionType;
use App\RewardProgramPeriodType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RewardProgramService
{
    protected const DEFAULT_PROGRAM_SLUG = 'moretables-lifetime-loyalty';

    /**
     * @var list<array{points: int, credit_value: int, credit_currency: string}>
     */
    protected const DEFAULT_REDEMPTION_TIERS = [
        ['points' => 1000, 'credit_value' => 3000, 'credit_currency' => 'NGN'],
        ['points' => 2500, 'credit_value' => 5000, 'credit_currency' => 'NGN'],
        ['points' => 5000, 'credit_value' => 10000, 'credit_currency' => 'NGN'],
        ['points' => 10000, 'credit_value' => 30000, 'credit_currency' => 'NGN'],
    ];

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

            $type = $this->resolveTransactionType($attributes['type'] ?? RewardPointTransactionType::Adjustment);
            $metadata = $attributes['metadata'] ?? [];

            if ($points < 0) {
                $metadata['consumed_lots'] = $this->consumePointLots($user, $program, abs($points));
            }

            $createData = [
                'reward_program_id' => $program->id,
                'user_id' => $user->id,
                'created_by' => $actor?->id,
                'type' => $type,
                'points' => $points,
                'balance_after' => $newBalance,
                'description' => $attributes['description'] ?? null,
                'reference_type' => $attributes['reference_type'] ?? null,
                'reference_id' => $attributes['reference_id'] ?? null,
                'metadata' => $metadata !== [] ? $metadata : null,
                'credit_value' => $attributes['credit_value'] ?? null,
                'credit_currency' => $attributes['credit_currency'] ?? null,
            ];

            if ($type === RewardPointTransactionType::Earn && $points > 0) {
                $createData['expires_at'] = now()->addYear();
                $createData['points_remaining'] = $points;
            }

            return RewardPointTransaction::query()->create($createData)
                ->load(['rewardProgram.levels', 'createdBy']);
        });
    }

    public function redeemPoints(User $user, int $points, ?User $actor = null): RewardPointTransaction
    {
        $program = $this->activeProgram();
        $tier = $this->resolveRedemptionTier($program, $points);

        if ($tier === null) {
            throw ValidationException::withMessages([
                'points' => ['Select a valid redemption tier.'],
            ]);
        }

        return $this->awardPoints(
            user: $user,
            attributes: [
                'points' => -$points,
                'type' => RewardPointTransactionType::Redeem,
                'description' => 'Points redeemed for restaurant credit.',
                'credit_value' => $tier['credit_value'],
                'credit_currency' => $tier['credit_currency'],
                'metadata' => [
                    'redemption' => [
                        'points_redeemed' => $points,
                        'credit_value' => $tier['credit_value'],
                        'credit_currency' => $tier['credit_currency'],
                    ],
                ],
            ],
            actor: $actor,
        );
    }

    public function redeemAllPointsForReservation(User $user, Reservation $reservation): RewardPointTransaction
    {
        $program = $this->activeProgram();

        return DB::transaction(function () use ($user, $reservation, $program): RewardPointTransaction {
            $existingTransaction = RewardPointTransaction::query()
                ->where('reward_program_id', $program->id)
                ->where('user_id', $user->id)
                ->where('type', RewardPointTransactionType::Redeem)
                ->where('reference_type', Reservation::class)
                ->where('reference_id', $reservation->id)
                ->lockForUpdate()
                ->first();

            if ($existingTransaction) {
                return $existingTransaction->load(['rewardProgram.levels', 'createdBy']);
            }

            $latestTransaction = RewardPointTransaction::query()
                ->where('reward_program_id', $program->id)
                ->where('user_id', $user->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $points = (int) ($latestTransaction?->balance_after ?? 0);

            if ($points <= 0) {
                throw ValidationException::withMessages([
                    'use_points' => ['You do not have any points available to use.'],
                ]);
            }

            return $this->awardPoints(
                user: $user,
                attributes: [
                    'points' => -$points,
                    'type' => RewardPointTransactionType::Redeem,
                    'description' => 'All available points used for a reservation.',
                    'reference_type' => Reservation::class,
                    'reference_id' => $reservation->id,
                    'metadata' => [
                        'redemption' => [
                            'points_redeemed' => $points,
                            'use_all_points' => true,
                        ],
                        'restaurant_id' => $reservation->restaurant_id,
                        'reservation_reference' => $reservation->reservation_reference,
                    ],
                ],
                actor: $user,
            );
        });
    }

    public function expireDuePointLots(): int
    {
        $program = $this->activeProgram();
        $expiredLots = 0;

        $dueLots = RewardPointTransaction::query()
            ->where('reward_program_id', $program->id)
            ->where('type', RewardPointTransactionType::Earn)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (RewardPointTransaction $lot): bool => $this->remainingOnLot($lot) > 0);

        $dueLots->groupBy('user_id')->each(function (Collection $userLots) use ($program, &$expiredLots): void {
            DB::transaction(function () use ($userLots, $program, &$expiredLots): void {
                foreach ($userLots as $lot) {
                    $lockedLot = RewardPointTransaction::query()
                        ->whereKey($lot->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedLot) {
                        continue;
                    }

                    $remaining = $this->remainingOnLot($lockedLot);

                    if ($remaining <= 0) {
                        continue;
                    }

                    $latestTransaction = RewardPointTransaction::query()
                        ->where('reward_program_id', $program->id)
                        ->where('user_id', $lockedLot->user_id)
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();

                    $currentBalance = $latestTransaction?->balance_after ?? 0;
                    $newBalance = $currentBalance - $remaining;

                    RewardPointTransaction::query()->create([
                        'reward_program_id' => $program->id,
                        'user_id' => $lockedLot->user_id,
                        'created_by' => null,
                        'type' => RewardPointTransactionType::Expire,
                        'points' => -$remaining,
                        'balance_after' => $newBalance,
                        'description' => 'Points expired after one year.',
                        'reference_type' => $lockedLot->reference_type,
                        'reference_id' => $lockedLot->reference_id,
                        'metadata' => array_merge($lockedLot->metadata ?? [], [
                            'earn_transaction_id' => $lockedLot->id,
                        ]),
                    ]);

                    $lockedLot->update(['points_remaining' => 0]);
                    $expiredLots++;
                }
            });
        });

        return $expiredLots;
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

            if (array_key_exists('redemption_tiers', $attributes)) {
                $tiers = collect($attributes['redemption_tiers'])
                    ->sortBy('points')
                    ->values()
                    ->map(fn (array $tier): array => [
                        'points' => (int) $tier['points'],
                        'credit_value' => (int) $tier['credit_value'],
                        'credit_currency' => strtoupper((string) ($tier['credit_currency'] ?? 'NGN')),
                    ])
                    ->all();

                $program->update(['redemption_tiers' => $tiers]);
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
            'redemption_tiers' => collect($program->redemption_tiers ?? [])
                ->sortBy('points')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transactionPayloadsForCollection(Collection $transactions): array
    {
        $reservationIds = $transactions
            ->filter(fn (RewardPointTransaction $transaction): bool => $transaction->reference_type === Reservation::class && $transaction->reference_id !== null)
            ->pluck('reference_id')
            ->unique()
            ->values();

        $reservationsById = Reservation::query()
            ->with('restaurant')
            ->whereIn('id', $reservationIds)
            ->get()
            ->keyBy('id');

        return $transactions
            ->map(fn (RewardPointTransaction $transaction): array => $this->transactionPayload($transaction, $reservationsById))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function transactionPayload(RewardPointTransaction $transaction, ?Collection $reservationsById = null): array
    {
        $transaction->loadMissing(['rewardProgram.levels', 'createdBy']);
        $level = $transaction->rewardProgram
            ? $this->levelForPoints($transaction->rewardProgram, $transaction->balance_after)
            : null;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type?->value,
            'direction' => $transaction->points >= 0 ? 'credit' : 'debit',
            'points' => $transaction->points,
            'balance_after' => $transaction->balance_after,
            'description' => $transaction->description,
            'reference_type' => $transaction->reference_type,
            'reference_id' => $transaction->reference_id,
            'metadata' => $transaction->metadata ?? [],
            'expires_at' => $transaction->expires_at?->toIso8601String(),
            'points_remaining' => $transaction->points_remaining,
            'source' => $this->sourcePayload($transaction, $reservationsById),
            'credit' => $this->creditPayload($transaction),
            'redemption' => $this->redemptionPayload($transaction),
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
                    'redemption_tiers' => self::DEFAULT_REDEMPTION_TIERS,
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

    protected function resolveTransactionType(RewardPointTransactionType|string $type): RewardPointTransactionType
    {
        return $type instanceof RewardPointTransactionType
            ? $type
            : RewardPointTransactionType::from($type);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function consumePointLots(User $user, RewardProgram $program, int $points): array
    {
        $remainingToConsume = $points;
        $consumed = [];

        $lots = RewardPointTransaction::query()
            ->where('reward_program_id', $program->id)
            ->where('user_id', $user->id)
            ->where('type', RewardPointTransactionType::Earn)
            ->where(function ($query): void {
                $query->where('points_remaining', '>', 0)
                    ->orWhere(function ($query): void {
                        $query->whereNull('points_remaining')->where('points', '>', 0);
                    });
            })
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('expires_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            $available = $this->remainingOnLot($lot);

            if ($available <= 0) {
                continue;
            }

            $take = min($available, $remainingToConsume);
            $lot->update(['points_remaining' => $available - $take]);

            $consumed[] = [
                'earn_transaction_id' => $lot->id,
                'points' => $take,
                'reservation_id' => $lot->reference_type === Reservation::class ? $lot->reference_id : null,
                'restaurant_id' => $lot->metadata['restaurant_id'] ?? null,
                'restaurant_name' => $lot->metadata['restaurant_name'] ?? null,
                'expires_at' => $lot->expires_at?->toIso8601String(),
            ];

            $remainingToConsume -= $take;

            if ($remainingToConsume <= 0) {
                break;
            }
        }

        if ($remainingToConsume > 0) {
            throw ValidationException::withMessages([
                'points' => ['Not enough available points to complete this transaction.'],
            ]);
        }

        return $consumed;
    }

    protected function remainingOnLot(RewardPointTransaction $lot): int
    {
        if ($lot->points_remaining !== null) {
            return max($lot->points_remaining, 0);
        }

        return max($lot->points, 0);
    }

    /**
     * @return array{points: int, credit_value: int, credit_currency: string}|null
     */
    protected function resolveRedemptionTier(RewardProgram $program, int $points): ?array
    {
        return collect($program->redemption_tiers ?? [])
            ->first(fn (array $tier): bool => (int) $tier['points'] === $points);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function sourcePayload(RewardPointTransaction $transaction, ?Collection $reservationsById = null): ?array
    {
        if (! in_array($transaction->type, [RewardPointTransactionType::Earn, RewardPointTransactionType::Expire], true)) {
            return null;
        }

        if ($transaction->reference_type === Reservation::class && $transaction->reference_id) {
            $reservation = $reservationsById?->get($transaction->reference_id)
                ?? Reservation::query()->with('restaurant')->find($transaction->reference_id);

            if ($reservation) {
                return [
                    'type' => 'reservation',
                    'reservation' => [
                        'id' => $reservation->id,
                        'reference' => $reservation->reservation_reference,
                        'starts_at' => $reservation->starts_at?->toIso8601String(),
                    ],
                    'restaurant' => [
                        'id' => $reservation->restaurant_id,
                        'name' => $reservation->restaurant?->name,
                    ],
                ];
            }
        }

        $metadata = $transaction->metadata ?? [];

        if (! isset($metadata['restaurant_id'], $metadata['restaurant_name'])) {
            return null;
        }

        return [
            'type' => 'reservation',
            'reservation' => [
                'id' => $transaction->reference_id,
                'reference' => $metadata['reservation_reference'] ?? null,
                'starts_at' => $metadata['reservation_starts_at'] ?? null,
            ],
            'restaurant' => [
                'id' => $metadata['restaurant_id'],
                'name' => $metadata['restaurant_name'],
            ],
        ];
    }

    /**
     * @return array{value: int, currency: string}|null
     */
    protected function creditPayload(RewardPointTransaction $transaction): ?array
    {
        if ($transaction->credit_value === null) {
            return null;
        }

        return [
            'value' => $transaction->credit_value,
            'currency' => $transaction->credit_currency ?? 'NGN',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function redemptionPayload(RewardPointTransaction $transaction): ?array
    {
        if ($transaction->type !== RewardPointTransactionType::Redeem) {
            return null;
        }

        $metadata = $transaction->metadata ?? [];
        $redemption = $metadata['redemption'] ?? [];
        $consumedLots = $metadata['consumed_lots'] ?? [];

        return [
            'points_redeemed' => abs($transaction->points),
            'credit_value' => $redemption['credit_value'] ?? $transaction->credit_value,
            'credit_currency' => $redemption['credit_currency'] ?? $transaction->credit_currency ?? 'NGN',
            'consumed_from' => $consumedLots,
        ];
    }
}
