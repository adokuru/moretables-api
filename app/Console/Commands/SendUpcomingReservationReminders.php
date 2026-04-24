<?php

namespace App\Console\Commands;

use App\Models\GuestContact;
use App\Models\Reservation;
use App\Models\ReservationGuest;
use App\Models\User;
use App\Notifications\GuestReservationLifecycleMailNotification;
use App\ReservationStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

#[Signature('app:send-upcoming-reservation-reminders')]
#[Description('Send upcoming reservation reminder emails for configured cadences')]
class SendUpcomingReservationReminders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $cadenceDays = collect(config('reservations.upcoming_reminder_days_before', []))
            ->map(fn (mixed $days): int => (int) $days)
            ->filter(fn (int $days): bool => $days > 0)
            ->unique()
            ->sortDesc()
            ->values();

        if ($cadenceDays->isEmpty()) {
            $this->info('No upcoming reservation reminder cadences configured.');

            return self::SUCCESS;
        }

        $windowMinutes = max(1, (int) config('reservations.upcoming_reminder_window_minutes', 60));
        $sentReminderCount = 0;

        foreach ($cadenceDays as $daysBefore) {
            $sentReminderCount += $this->sendRemindersForCadence($daysBefore, $windowMinutes);
        }

        $this->info("Sent {$sentReminderCount} upcoming reservation reminder email(s).");

        return self::SUCCESS;
    }

    protected function sendRemindersForCadence(int $daysBefore, int $windowMinutes): int
    {
        $windowStart = now()->addDays($daysBefore);
        $windowEnd = $windowStart->copy()->addMinutes($windowMinutes);

        $reservations = Reservation::query()
            ->whereIn('status', [ReservationStatus::Booked->value, ReservationStatus::Confirmed->value])
            ->whereBetween('starts_at', [$windowStart, $windowEnd])
            ->with(['restaurant.media', 'user', 'guestContact', 'reservationGuests'])
            ->get();

        $sentReminderCount = 0;

        foreach ($reservations as $reservation) {
            if ($reservation->hasUpcomingReminderSent($daysBefore)) {
                continue;
            }

            $recipients = $this->reminderRecipients($reservation);

            if ($recipients->isEmpty()) {
                continue;
            }

            foreach ($recipients as $recipient) {
                Notification::route('mail', $recipient['email'])
                    ->notify(new GuestReservationLifecycleMailNotification(
                        $reservation,
                        $recipient['model'],
                        'upcoming_reminder',
                        $daysBefore,
                    ));

                $sentReminderCount++;
            }

            $reservation->markUpcomingReminderSent($daysBefore, now());
        }

        return $sentReminderCount;
    }

    /**
     * @return Collection<int, array{email: string, model: User|GuestContact|ReservationGuest}>
     */
    protected function reminderRecipients(Reservation $reservation): Collection
    {
        $recipients = collect();

        if ($this->isValidEmail($reservation->user?->email)) {
            $recipients->push([
                'email' => $reservation->user->email,
                'model' => $reservation->user,
            ]);
        }

        if ($this->isValidEmail($reservation->guestContact?->email)) {
            $recipients->push([
                'email' => $reservation->guestContact->email,
                'model' => $reservation->guestContact,
            ]);
        }

        foreach ($reservation->reservationGuests as $guest) {
            if (! $this->isValidEmail($guest->email_address)) {
                continue;
            }

            $recipients->push([
                'email' => $guest->email_address,
                'model' => $guest,
            ]);
        }

        return $recipients
            ->unique(fn (array $recipient): string => strtolower($recipient['email']))
            ->values();
    }

    protected function isValidEmail(?string $email): bool
    {
        if (! is_string($email) || $email === '') {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
