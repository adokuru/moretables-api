<?php

namespace App\Notifications;

use App\Models\MerchantInvoice;
use App\Notifications\Concerns\BuildsFrontendUrls;
use App\Notifications\Concerns\UsesNotificationQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MerchantPaymentConfirmationNotification extends Notification implements ShouldQueue
{
    use BuildsFrontendUrls, Queueable, UsesNotificationQueues;

    public function __construct(
        protected MerchantInvoice $invoice,
        protected bool $isUpgrade = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->invoice->loadMissing(['restaurant.organization', 'plan']);

        $restaurant = $this->invoice->restaurant;
        $recipientName = $restaurant->organization?->name ?? $restaurant->name ?? 'there';
        $planName = $this->invoice->plan?->name ?? 'MoreTables Plan';
        $amount = $this->formatAmount($this->invoice->amount, $this->invoice->currency);

        $billingStart = $this->invoice->billing_period_start?->format('M j, Y') ?? '—';
        $billingEnd = $this->invoice->billing_period_end?->format('M j, Y') ?? '—';
        $billingPeriod = "{$billingStart} – {$billingEnd}";

        $subject = $this->isUpgrade
            ? 'Your MoreTables plan has been upgraded'
            : 'Your MoreTables subscription is confirmed';

        $bodyPrimary = $this->isUpgrade
            ? "Your plan has been upgraded successfully. Here's a summary of your new subscription:"
            : "Your subscription has been confirmed. Here's a summary:";

        $ctaUrl = $this->frontendBaseUrl().'/admin/billing';

        return (new MailMessage)
            ->subject($subject)
            ->view(
                'emails.moretables-tabular-layout',
                [
                    'subject' => $subject,
                    'recipientName' => $recipientName,
                    'greeting' => "Hi {$recipientName},",
                    'bodyPrimary' => $bodyPrimary,
                    'bodyRaw' => $this->buildDetailsTable($planName, $amount, $billingPeriod, $this->invoice->invoice_number),
                    'ctaUrl' => $ctaUrl,
                    'ctaLabel' => 'View Billing',
                    'showCta' => true,
                    'signOff' => 'Thanks,',
                    'signature' => 'The MoreTables Team',
                    'footerLine1' => 'MoreTables Ltd.',
                    'footerLine2' => 'Lagos, Nigeria',
                    'footerLink1Url' => $ctaUrl,
                    'footerLink1Label' => 'View Billing',
                    'footerLink2Url' => $this->frontendBaseUrl(),
                    'footerLink2Label' => 'Visit MoreTables',
                ],
            );
    }

    protected function buildDetailsTable(string $planName, string $amount, string $billingPeriod, string $invoiceNumber): string
    {
        $fontStyle = 'font-family:Avenir Next,Avenir,Helvetica Neue,Helvetica,Arial,sans-serif;';
        $rows = [
            ['Plan', $planName],
            ['Amount', $amount],
            ['Billing period', $billingPeriod],
            ['Invoice', $invoiceNumber],
        ];

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
