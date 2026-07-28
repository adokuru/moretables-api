<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table labels used to only need to be unique per dining area (see
     * 2026_05_24_231127_change_restaurant_tables_unique_constraint_to_dining_area),
     * which let two floors each have their own "Table 1" — ambiguous for
     * reservations/status to resolve against. This restores restaurant-wide
     * uniqueness, so a label always identifies exactly one table.
     *
     * Written defensively (checks current indexes/foreign keys before
     * touching them) rather than assuming the exact end-state the migration
     * it reverts left behind, since that varies by driver.
     */
    public function up(): void
    {
        $this->deduplicateLabelsWithinRestaurant();

        $indexes = collect(Schema::getIndexes('restaurant_tables'));
        $indexNames = $indexes->pluck('name');
        $foreignKeys = collect(Schema::getForeignKeys('restaurant_tables'));
        $hasRestaurantFk = $foreignKeys
            ->contains(fn ($fk) => $fk['columns'] === ['restaurant_id']);
        $hasDiningAreaFk = $foreignKeys
            ->contains(fn ($fk) => $fk['columns'] === ['dining_area_id']);
        $hasDiningAreaIndex = $indexes
            ->contains(fn (array $index): bool => $index['columns'] === ['dining_area_id']);

        Schema::table('restaurant_tables', function (Blueprint $table) use ($hasDiningAreaFk, $hasDiningAreaIndex, $hasRestaurantFk, $indexNames): void {
            // MySQL may use the composite unique index to back the dining area
            // foreign key, so remove that FK before changing the index.
            if ($hasDiningAreaFk) {
                $table->dropForeign(['dining_area_id']);
            }

            if ($indexNames->contains('restaurant_tables_dining_area_id_name_unique')) {
                $table->dropUnique(['dining_area_id', 'name']);
            }

            if (! $hasDiningAreaIndex) {
                $table->index('dining_area_id');
            }

            // MySQL can't drop a unique index that's the sole backing index
            // for a FK, so the FK has to go first if one exists.
            if ($hasRestaurantFk) {
                $table->dropForeign(['restaurant_id']);
            }

            if ($indexNames->contains('restaurant_tables_restaurant_id_index')) {
                $table->dropIndex(['restaurant_id']);
            }

            if (! $indexNames->contains('restaurant_tables_restaurant_id_name_unique')) {
                $table->unique(['restaurant_id', 'name']);
            }

            if ($hasDiningAreaFk) {
                $table->foreign('dining_area_id')->references('id')->on('dining_areas')->nullOnDelete();
            }

            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $indexNames = collect(Schema::getIndexes('restaurant_tables'))->pluck('name');
        $hasRestaurantFk = collect(Schema::getForeignKeys('restaurant_tables'))
            ->contains(fn ($fk) => $fk['columns'] === ['restaurant_id']);

        Schema::table('restaurant_tables', function (Blueprint $table) use ($indexNames, $hasRestaurantFk) {
            if ($hasRestaurantFk) {
                $table->dropForeign(['restaurant_id']);
            }

            if ($indexNames->contains('restaurant_tables_restaurant_id_name_unique')) {
                $table->dropUnique(['restaurant_id', 'name']);
            }

            if (! $indexNames->contains('restaurant_tables_restaurant_id_index')) {
                $table->index('restaurant_id');
            }

            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();

            if (! $indexNames->contains('restaurant_tables_dining_area_id_name_unique')) {
                $table->unique(['dining_area_id', 'name']);
            }
        });
    }

    /**
     * Resolves any existing restaurant+name collisions across floors before
     * the unique index can be added. Keeps the oldest table's label as-is
     * and renumbers every later duplicate to the next free numeric label for
     * that restaurant — never reusing a number already in use, matching the
     * "no reuse, always max + 1" rule the rest of the app now follows for
     * new tables.
     */
    private function deduplicateLabelsWithinRestaurant(): void
    {
        $duplicates = DB::table('restaurant_tables')
            ->select('restaurant_id', 'name')
            ->groupBy('restaurant_id', 'name')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $rowIds = DB::table('restaurant_tables')
                ->where('restaurant_id', $duplicate->restaurant_id)
                ->where('name', $duplicate->name)
                ->orderBy('id')
                ->pluck('id');

            foreach ($rowIds->slice(1) as $id) {
                $nextLabel = $this->nextFreeNumericLabel((int) $duplicate->restaurant_id);
                DB::table('restaurant_tables')->where('id', $id)->update(['name' => (string) $nextLabel]);
            }
        }
    }

    private function nextFreeNumericLabel(int $restaurantId): int
    {
        $names = DB::table('restaurant_tables')
            ->where('restaurant_id', $restaurantId)
            ->pluck('name');

        $max = 0;
        foreach ($names as $name) {
            if (ctype_digit((string) $name)) {
                $max = max($max, (int) $name);
            }
        }

        return $max + 1;
    }
};
