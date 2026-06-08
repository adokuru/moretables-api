<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const RESTAURANT_SHIFT_MIGRATION = '2026_06_08_143748_create_restaurant_shift_tables';
const RESTAURANT_SHIFT_MIGRATION_PATH = 'database/migrations/2026_06_08_143748_create_restaurant_shift_tables.php';

it('can rerun the restaurant shift migration when tables already exist', function () {
    DB::table('migrations')->where('migration', RESTAURANT_SHIFT_MIGRATION)->delete();

    Artisan::call('migrate', [
        '--path' => RESTAURANT_SHIFT_MIGRATION_PATH,
        '--force' => true,
    ]);

    expect(Schema::hasTable('restaurant_shifts'))->toBeTrue()
        ->and(Schema::hasTable('restaurant_shift_turn_times'))->toBeTrue()
        ->and(Schema::hasTable('restaurant_shift_table_availability'))->toBeTrue()
        ->and(Schema::hasTable('restaurant_shift_turn_controls'))->toBeTrue()
        ->and(Schema::hasTable('restaurant_shift_flow_intervals'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', RESTAURANT_SHIFT_MIGRATION)->exists())->toBeTrue();
});
