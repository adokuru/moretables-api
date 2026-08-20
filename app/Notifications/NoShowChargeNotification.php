<?php

namespace App\Notifications;

use App\Models\ReservationCardHold;
use App\Notifications\Concerns\BuildsFrontendUrls;
use App\Notifications\Concerns\UsesNotificationQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NoShowChargeNotification extends Notification implements ShouldQueue
{
    use BuildsFrontendUrls, Queueable, UsesNotificationQueues;

    public function __construct(
        protected ReservationCardHold $cardHold,
        protected bool $forRestaurant = false,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->cardHold->loadMissing(['reservation', 'restaurant']);

        $reservation = $this->cardHold->reservation;
        $restaurant = $this->cardHold->restaurant;
        $amount = $this->formatAmount($this->cardHold->charged_amount ?? $this->cardHold->amount, $this->cardHold->currency);
        $when = $reservation->starts_at?->format('M j, Y g:i A') ?? '—';
        $card = $this->cardHold->last4 ? "•••• {$this->cardHold->last4}" : 'card on file';

        $subject = $this->forRestaurant
            ? "No-show fee charged for {$restaurant->name}"
            : "You were charged a no-show fee at {$restaurant->name}";

        $recipientName = $this->forRestaurant
            ? ($restaurant->name ?? 'there')
            : ($reservation->user?->first_name ?? 'there');

        $bodyPrimary = $this->forRestaurant
            ? "A no-show fee of {$amount} was charged for a reservation that did not show up."
            : "You did not arrive for your reservation at {$restaurant->name}, so the {$amount} no-show fee from the restaurant's cancellation policy was charged to your {$card}.";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.moretables-tabular-layout', [
                'subject' => $subject,
                'recipientName' => $recipientName,
                'greeting' => "Hi {$recipientName},",
                'bodyPrimary' => $bodyPrimary,
                'bodyRaw' => $this->buildDetailsTable($restaurant->name ?? '—', $when, $amount, $card, $reservation->reservation_reference),
                'showCta' => false,
                'signOff' => 'Thanks,',
                'signature' => 'The MoreTables Team',
                'footerLine1' => 'MoreTables',
                'footerLine2' => 'Lagos, Nigeria.',
                'footerLink1Url' => $this->frontendBaseUrl(),
                'footerLink1Label' => 'Visit MoreTables',
                'footerLink2Url' => $this->frontendBaseUrl(),
                'footerLink2Label' => 'Visit MoreTables',
            ]);
    }

    protected function buildDetailsTable(string $restaurantName, string $when, string $amount, string $card, ?string $reference): string
    {
        $fontStyle = 'font-family:Avenir Next,Avenir,Helvetica Neue,Helvetica,Arial,sans-serif;';
        $rows = array_filter([
            ['Restaurant', $restaurantName],
            ['Reservation', $when],
            ['No-show fee', $amount],
            ['Card', $card],
            $reference ? ['Reference', $reference] : null,
        ]);

        $html = '<table style="width:100%;border-collapse:collapse;">';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr style="border-bottom:1px solid #E0E0E0;">'
                .'<td style="padding:10px 0;color:#888888;font-size:14px;'.$fontStyle.'">'.e($label).'</td>'
                .'<td style="padding:10px 0;font-size:14px;text-align:right;font-weight:600;color:#1a1a1a;'.$fontStyle.'">'.e($value).'</td>'
                .'</tr>';
        }
        $html .= '</table>';

        return $html;
    }

    protected function formatAmount(int $amountInMinorUnit, string $currency): string
    {
        $symbol = match (strtoupper($currency)) {
            'NGN' => '₦',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            default => strtoupper($currency).' ',
        };

        return $symbol.number_format($amountInMinorUnit / 100, 2);
    }
}
