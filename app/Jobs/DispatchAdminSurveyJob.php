<?php

namespace App\Jobs;

use App\Models\AdminSurveyDispatch;
use App\Models\GuestSurvey;
use App\Models\GuestSurveyInvitation;
use App\Models\User;
use App\Notifications\GuestSurveyInvitationNotification;
use App\ReservationStatus;
use App\UserStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

class DispatchAdminSurveyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $surveyId,
        public readonly int $dispatchId,
    ) {}

    public function handle(): void
    {
        $survey = GuestSurvey::query()->with('restaurant')->findOrFail($this->surveyId);
        $dispatch = AdminSurveyDispatch::query()->findOrFail($this->dispatchId);

        if ($dispatch->status !== 'pending') {
            return;
        }

        $dispatch->update(['status' => 'processing']);

        $sent = 0;

        $this->recipientsQuery($survey)
            ->chunkById(500, function ($users) use ($survey, &$sent): void {
                foreach ($users as $user) {
                    $channels = GuestSurveyInvitationNotification::deliveryChannels($survey, $user);

                    if ($channels === []) {
                        continue;
                    }

                    $token = $this->tokenFor($survey, $user);

                    $invitation = GuestSurveyInvitation::query()->createOrFirst(
                        ['guest_survey_id' => $survey->id, 'user_id' => $user->id],
                        ['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(30)],
                    );

                    if ($invitation->sent_at !== null) {
                        continue;
                    }

                    $invitation->update([
                        'token_hash' => hash('sha256', $token),
                    ]);

                    $user->notify(new GuestSurveyInvitationNotification($invitation->load('survey.restaurant'), $token));
                    $sent++;
                }
            });

        $dispatch->update([
            'status' => 'dispatched',
            'recipients_count' => $sent,
            'dispatched_at' => now(),
        ]);
    }

    /**
     * @return Builder<User>
     */
    private function recipientsQuery(GuestSurvey $survey): Builder
    {
        $query = User::query()->where('status', UserStatus::Active);

        if ($survey->isRestaurantScoped() && $survey->restaurant_id !== null) {
            $query->whereHas('reservations', function (Builder $reservationQuery) use ($survey): void {
                $reservationQuery->where('restaurant_id', $survey->restaurant_id)
                    ->whereNotIn('status', [ReservationStatus::Cancelled, ReservationStatus::NoShow]);
            });
        }

        return $query;
    }

    private function tokenFor(GuestSurvey $survey, User $user): string
    {
        return hash_hmac(
            'sha256',
            "admin-survey:{$survey->id}:user:{$user->id}",
            (string) config('app.key'),
        );
    }
}
