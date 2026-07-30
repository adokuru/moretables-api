<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('guest_survey_invitations', 'user_id')) {
            Schema::table('guest_survey_invitations', function (Blueprint $table): void {
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->after('guest_survey_id');
            });
        }

        if ($this->hasIndex('guest_survey_invitations', 'guest_survey_invitations_reservation_id_unique')) {
            Schema::table('guest_survey_invitations', function (Blueprint $table): void {
                $table->dropUnique(['reservation_id']);
            });
        }

        Schema::table('guest_survey_invitations', function (Blueprint $table): void {
            $table->foreignId('reservation_id')->nullable()->change();
        });

        if (! $this->hasIndex('guest_survey_invitations', 'guest_survey_invitations_guest_survey_id_user_id_unique')) {
            Schema::table('guest_survey_invitations', function (Blueprint $table): void {
                $table->unique(['guest_survey_id', 'user_id']);
            });
        }

        if (! $this->hasIndex('guest_survey_invitations', 'guest_survey_invitations_guest_survey_id_reservation_id_unique')) {
            Schema::table('guest_survey_invitations', function (Blueprint $table): void {
                $table->unique(['guest_survey_id', 'reservation_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('guest_survey_invitations', function (Blueprint $table): void {
            if ($this->hasIndex('guest_survey_invitations', 'guest_survey_invitations_guest_survey_id_user_id_unique')) {
                $table->dropUnique(['guest_survey_id', 'user_id']);
            }

            if ($this->hasIndex('guest_survey_invitations', 'guest_survey_invitations_guest_survey_id_reservation_id_unique')) {
                $table->dropUnique(['guest_survey_id', 'reservation_id']);
            }

            if (Schema::hasColumn('guest_survey_invitations', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }

            $table->foreignId('reservation_id')->nullable(false)->change();
            $table->unique(['reservation_id']);
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = collect(DB::select("PRAGMA index_list('{$table}')"))
                ->pluck('name')
                ->all();

            return in_array($index, $indexes, true);
        }

        $database = Schema::getConnection()->getDatabaseName();
        $result = DB::selectOne(
            'select count(*) as aggregate from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ?',
            [$database, $table, $index],
        );

        return ((int) ($result->aggregate ?? 0)) > 0;
    }
};
