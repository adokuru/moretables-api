<?php

namespace App\Console\Commands;

use App\Models\GuestSurvey;
use App\Models\Reservation;
use App\Notifications\GuestSurveyInvitationNotification;
use App\ReservationStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SendDueGuestSurveys extends Command
{
    protected $signature = 'guest-surveys:send-due';

    protected $description = 'Queue published guest surveys for eligible completed reservations';

    public function handle(): int
    {
        $sent = 0;

        GuestSurvey::query()
            ->whereIn('status', ['published', 'archived'])
            ->whereNotNull('published_at')
            ->whereNotNull('publication_sequence')
            ->with('restaurant')
            ->each(function (GuestSurvey $survey) use (&$sent): void {
                $nextPublishedAt = GuestSurvey::query()
                    ->where('restaurant_id', $survey->restaurant_id)
                    ->whereNotNull('published_at')
                    ->where('publication_sequence', '>', $survey->publication_sequence)
                    ->orderBy('publication_sequence')
                    ->value('published_at');

                Reservation::query()
                    ->where('restaurant_id', $survey->restaurant_id)
                    ->where('status', ReservationStatus::Completed)
                    ->whereNotNull('completed_at')
                    ->where('completed_at', '>=', $survey->published_at)
                    ->when($nextPublishedAt, fn (Builder $query) => $query->where('completed_at', '<', $nextPublishedAt))
                    ->where('completed_at', '<=', now()->subMinutes($survey->send_delay_minutes))
                    ->where(function (Builder $query) use ($survey): void {
                        $query->whereDoesntHave('guestSurveyInvitation')
                            ->orWhereHas('guestSurveyInvitation', fn (Builder $invitationQuery) => $invitationQuery
                                ->where('guest_survey_id', $survey->id)
                                ->whereNull('sent_at'));
                    })
                    ->with(['user', 'guestContact', 'guestSurveyInvitation.survey.restaurant'])
                    ->chunkById(200, function ($reservations) use ($survey, &$sent): void {
                        foreach ($reservations as $reservation) {
                            $recipient = $reservation->user ?? $reservation->guestContact;

                            if (! $recipient || GuestSurveyInvitationNotification::deliveryChannels($survey, $recipient) === []) {
                                continue;
                            }

                            $token = $this->tokenFor($survey, $reservation);
                            $invitation = $survey->invitations()->createOrFirst(
                                ['reservation_id' => $reservation->id],
                                ['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30)],
                            );

                            if ($invitation->guest_survey_id !== $survey->id || $invitation->sent_at !== null) {
                                continue;
                            }

                            $claimed = $survey->invitations()
                                ->whereKey($invitation->id)
                                ->whereNull('sent_at')
                                ->where(function (Builder $query): void {
                                    $query->whereNull('delivery_claimed_at')
                                        ->orWhere('delivery_claimed_at', '<=', now()->subMinutes(10));
                                })
                                ->update([
                                    'token_hash' => hash('sha256', $token),
                                    'expires_at' => now()->addDays(30),
                                    'delivery_claimed_at' => now(),
                                ]);

                            if ($claimed === 0) {
                                continue;
                            }

                            $invitation->refresh();
                            $recipient->notify(new GuestSurveyInvitationNotification($invitation->load('survey.restaurant'), $token));
                            $invitation->update(['sent_at' => now()]);
                            $sent++;
                        }
                    });
            });

        $this->info("Queued {$sent} guest survey invitation(s).");

        return self::SUCCESS;
    }

    private function tokenFor(GuestSurvey $survey, Reservation $reservation): string
    {
        return hash_hmac(
            'sha256',
            "guest-survey:{$survey->id}:reservation:{$reservation->id}",
            (string) config('app.key'),
        );
    }
}
