<?php

declare(strict_types=1);

use App\Models\Reservation;
use App\Models\ReservationGuest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('attendee_name');
            $table->string('email_address');
            $table->string('email_normalized');
            $table->string('phone_number')->nullable();
            $table->timestamps();

            $table->unique(['reservation_id', 'email_normalized']);
            $table->index(['restaurant_id', 'email_normalized']);
            $table->index('restaurant_id');
        });

        Reservation::query()
            ->whereNotNull('metadata')
            ->chunkById(100, function ($reservations): void {
                foreach ($reservations as $reservation) {
                    $raw = data_get($reservation->metadata, 'guests');
                    if ($raw === null) {
                        continue;
                    }

                    $list = Reservation::normalizeMetadataGuests($raw);
                    $byEmail = [];
                    foreach ($list as $guest) {
                        $email = strtolower(trim((string) ($guest['email_address'] ?? '')));
                        if ($email === '') {
                            continue;
                        }
                        $byEmail[$email] = $guest;
                    }

                    foreach ($byEmail as $emailNorm => $guest) {
                        $name = $guest['attendee_name'] ?? null;
                        if (! is_string($name) || trim($name) === '') {
                            $name = trim(
                                (($guest['first_name'] ?? '').' '.($guest['last_name'] ?? ''))
                            );
                        }
                        if ($name === '') {
                            $name = 'Guest';
                        }

                        ReservationGuest::query()->create([
                            'reservation_id' => $reservation->id,
                            'restaurant_id' => $reservation->restaurant_id,
                            'attendee_name' => $name,
                            'email_address' => (string) ($guest['email_address'] ?? $emailNorm),
                            'email_normalized' => $emailNorm,
                            'phone_number' => $guest['phone_number'] ?? null,
                        ]);
                    }

                    $meta = $reservation->metadata;
                    if (is_array($meta) && array_key_exists('guests', $meta)) {
                        unset($meta['guests']);
                        $reservation->forceFill(['metadata' => $meta])->save();
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guests');
    }
};
