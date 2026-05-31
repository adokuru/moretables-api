<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('restaurant_special_days')
                ->select(['id', 'restaurant_id', 'date', 'updated_at'])
                ->get()
                ->groupBy(fn (object $specialDay): string => $specialDay->restaurant_id.':'.Carbon::parse($specialDay->date)->toDateString())
                ->each(function ($matchingSpecialDays): void {
                    $duplicateIds = $matchingSpecialDays
                        ->sortByDesc(fn (object $specialDay): string => ($specialDay->updated_at ?? '').':'.str_pad((string) $specialDay->id, 20, '0', STR_PAD_LEFT))
                        ->skip(1)
                        ->pluck('id');

                    if ($duplicateIds->isEmpty()) {
                        return;
                    }

                    DB::table('restaurant_special_day_shifts')
                        ->whereIn('restaurant_special_day_id', $duplicateIds)
                        ->delete();

                    DB::table('restaurant_special_days')
                        ->whereIn('id', $duplicateIds)
                        ->delete();
                });

            DB::table('restaurant_special_days')
                ->select(['id', 'date'])
                ->orderBy('id')
                ->chunkById(100, function ($specialDays): void {
                    foreach ($specialDays as $specialDay) {
                        DB::table('restaurant_special_days')
                            ->where('id', $specialDay->id)
                            ->update(['date' => Carbon::parse($specialDay->date)->toDateString()]);
                    }
                });
        });
    }

    public function down(): void {}
};
