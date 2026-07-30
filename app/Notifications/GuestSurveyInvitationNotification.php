<?php

namespace App\Notifications;

use App\Models\GuestSurvey;
use App\Models\GuestSurveyInvitation;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Concerns\BuildsFrontendUrls;
use App\Notifications\Concerns\UsesNotificationQueues;
use App\Notifications\Contracts\Unsubscribable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GuestSurveyInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue, Unsubscribable
{
    use BuildsFrontendUrls, Queueable, UsesNotificationQueues;

    public function __construct(
        protected GuestSurveyInvitation $invitation,
        protected string $token,
    ) {
        $this->afterCommit();
    }

    public function guestSurveyInvitation(): GuestSurveyInvitation
    {
        return $this->invitation;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return self::deliveryChannels($this->survey(), $notifiable);
    }

    /** @return list<string> */
    public static function deliveryChannels(GuestSurvey $survey, object $notifiable): array
    {
        $channels = [];
        $configured = $survey->channels ?? [];

        if (in_array('email', $configured, true) && filled($notifiable->email)
            && (! $notifiable instanceof User || $notifiable->notify_dining_rating_emails)) {
            $channels[] = 'mail';
        }

        if (in_array('push', $configured, true)
            && $notifiable instanceof User
            && $notifiable->notify_push_notifications
            && $notifiable->expoPushTokens()->exists()) {
            $channels[] = ExpoPushChannel::class;
        }

        if (in_array('whatsapp', $configured, true)
            && filled($notifiable->phone)
            && (! $notifiable instanceof User || $notifiable->notify_sms_alerts)) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $survey = $this->survey();
        $restaurant = $survey->restaurant;

        if ($restaurant !== null) {
            $message = (new MailMessage)
                ->subject("How was your visit to {$restaurant->name}?")
                ->greeting('We would love your feedback')
                ->line("Tell us about your recent visit to {$restaurant->name}.")
                ->action('Take the survey', $this->surveyUrl())
                ->line('Your feedback helps the restaurant improve.');
        } else {
            $message = (new MailMessage)
                ->subject($survey->title)
                ->greeting('We would love your feedback')
                ->line("Please take a moment to complete: {$survey->title}.")
                ->action('Take the survey', $this->surveyUrl())
                ->line('Your feedback helps us improve.');
        }

        return $this->withUnsubscribeHeaders($message, is_string($notifiable->email ?? null) ? $notifiable->email : null);
    }

    public function toExpoPush(object $notifiable): ExpoPushMessage
    {
        $survey = $this->survey();
        $restaurant = $survey->restaurant;

        return ExpoPushMessage::make(
            title: $restaurant !== null ? 'How was your visit?' : $survey->title,
            body: $restaurant !== null
                ? "Tell {$restaurant->name} how they did."
                : 'We would love your feedback.',
        )->data([
            'type' => 'guest_survey_invitation',
            'restaurant_id' => $restaurant?->id,
            'survey_url' => $this->surveyUrl(),
        ]);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $restaurantName = $this->survey()->restaurant?->name ?? 'MoreTables';

        return WhatsAppMessage::template(
            (string) config('services.whatsapp.guest_survey_invitation_template'),
            [$restaurantName],
        )->urlButton($this->token);
    }

    private function survey(): GuestSurvey
    {
        return $this->invitation->loadMissing('survey.restaurant')->survey;
    }

    private function surveyUrl(): string
    {
        return $this->frontendBaseUrl().'/surveys/'.$this->token;
    }
}
