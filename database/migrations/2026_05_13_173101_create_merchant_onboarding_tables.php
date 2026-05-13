<?php

use App\Support\DefaultCuisineOptions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuisine_options', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        $now = now();
        foreach (DefaultCuisineOptions::definitions() as $row) {
            DB::table('cuisine_options')->insert([
                'name' => $row['name'],
                'slug' => $row['slug'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::create('cuisine_option_restaurant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cuisine_option_id')->constrained('cuisine_options')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['restaurant_id', 'cuisine_option_id']);
        });

        $this->migrateLegacyRestaurantCuisines();

        Schema::dropIfExists('restaurant_cuisines');

        Schema::create('restaurant_social_handles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('handle');
            $table->timestamps();
            $table->unique(['restaurant_id', 'platform']);
        });

        Schema::create('restaurant_meal_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('restaurant_meal_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_meal_type_id')->constrained('restaurant_meal_types')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->json('onboarding_progress')->nullable()->after('instagram_handle');
            $table->string('onboarding_current_step')->nullable()->after('onboarding_progress');
            $table->boolean('is_profile_published')->default(false)->after('onboarding_current_step');
            $table->timestamp('contact_email_verified_at')->nullable()->after('is_profile_published');
            $table->timestamp('contact_phone_verified_at')->nullable()->after('contact_email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_progress',
                'onboarding_current_step',
                'is_profile_published',
                'contact_email_verified_at',
                'contact_phone_verified_at',
            ]);
        });

        Schema::dropIfExists('restaurant_meal_schedules');
        Schema::dropIfExists('restaurant_meal_types');
        Schema::dropIfExists('restaurant_social_handles');

        Schema::create('restaurant_cuisines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['restaurant_id', 'name']);
        });

        $pivotRows = DB::table('cuisine_option_restaurant')
            ->join('cuisine_options', 'cuisine_options.id', '=', 'cuisine_option_restaurant.cuisine_option_id')
            ->select('cuisine_option_restaurant.restaurant_id', 'cuisine_options.name')
            ->get();

        foreach ($pivotRows as $row) {
            DB::table('restaurant_cuisines')->insert([
                'restaurant_id' => $row->restaurant_id,
                'name' => $row->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::dropIfExists('cuisine_option_restaurant');
        Schema::dropIfExists('cuisine_options');
    }

    protected function migrateLegacyRestaurantCuisines(): void
    {
        if (! Schema::hasTable('restaurant_cuisines')) {
            return;
        }

        $optionsByLowerName = DB::table('cuisine_options')
            ->get()
            ->keyBy(fn ($row) => Str::lower($row->name));

        $legacyRows = DB::table('restaurant_cuisines')->orderBy('id')->get();

        $dedupedRows = [];
        $seenPair = [];

        foreach ($legacyRows as $legacy) {
            $normalized = Str::lower(trim($legacy->name));
            $option = $optionsByLowerName->get($normalized);

            if ($option === null) {
                $slug = Str::slug($legacy->name);
                $slug = $this->uniqueCuisineSlug($slug);
                $id = DB::table('cuisine_options')->insertGetId([
                    'name' => $legacy->name,
                    'slug' => $slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $option = (object) ['id' => $id, 'name' => $legacy->name, 'slug' => $slug];
                $optionsByLowerName[$normalized] = $option;
            }

            $pairKey = $legacy->restaurant_id.'_'.$option->id;
            if (isset($seenPair[$pairKey])) {
                continue;
            }
            $seenPair[$pairKey] = true;
            $dedupedRows[] = [
                'restaurant_id' => $legacy->restaurant_id,
                'cuisine_option_id' => $option->id,
                'legacy_order' => $legacy->id,
            ];
        }

        usort($dedupedRows, fn (array $a, array $b): int => $a['legacy_order'] <=> $b['legacy_order']);

        $pivotInserts = [];
        $primaryAssigned = [];

        foreach ($dedupedRows as $row) {
            $isPrimary = ! isset($primaryAssigned[$row['restaurant_id']]);
            if ($isPrimary) {
                $primaryAssigned[$row['restaurant_id']] = true;
            }

            $pivotInserts[] = [
                'restaurant_id' => $row['restaurant_id'],
                'cuisine_option_id' => $row['cuisine_option_id'],
                'is_primary' => $isPrimary,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($pivotInserts, 500) as $chunk) {
            DB::table('cuisine_option_restaurant')->insert($chunk);
        }
    }

    protected function uniqueCuisineSlug(string $baseSlug): string
    {
        $slug = $baseSlug !== '' ? $baseSlug : 'cuisine';
        $candidate = $slug;
        $suffix = 1;

        while (DB::table('cuisine_options')->where('slug', $candidate)->exists()) {
            $candidate = $slug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
};
