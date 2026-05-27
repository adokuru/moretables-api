<?php

namespace App\Notifications;

use App\Models\OnboardingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OnboardingRequestSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected OnboardingRequest $onboardingRequest,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ownerName = $this->onboardingRequest->owner_name ?: trim("{$this->onboardingRequest->first_name} {$this->onboardingRequest->last_name}");

        return (new MailMessage)
            ->subject('New MoreTables onboarding request')
            ->greeting('Hello!')
            ->line("{$ownerName} submitted an onboarding request for {$this->onboardingRequest->restaurant_name}.")
            ->line('Email: '.$this->onboardingRequest->email)
            ->line('Phone: '.$this->onboardingRequest->phone)
            ->line('Reason: '.$this->onboardingRequest->contact_reason->value)
            ->line('Review it in the MoreTables admin dashboard.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'onboarding_request_submitted',
            'onboarding_request_id' => $this->onboardingRequest->id,
            'restaurant_name' => $this->onboardingRequest->restaurant_name,
            'owner_name' => $this->onboardingRequest->owner_name,
            'first_name' => $this->onboardingRequest->first_name,
            'last_name' => $this->onboardingRequest->last_name,
            'email' => $this->onboardingRequest->email,
            'phone' => $this->onboardingRequest->phone,
            'status' => $this->onboardingRequest->status->value,
            'contact_reason' => $this->onboardingRequest->contact_reason->value,
        ];
    }
}
