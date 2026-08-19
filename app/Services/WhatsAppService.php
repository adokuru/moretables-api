<?php

namespace App\Services;

use App\Notifications\WhatsAppMessage;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function isConfigured(): bool
    {
        return is_string(config('services.whatsapp.token'))
            && config('services.whatsapp.token') !== ''
            && is_string(config('services.whatsapp.phone_number_id'))
            && config('services.whatsapp.phone_number_id') !== '';
    }

    public function send(string $recipient, WhatsAppMessage $message): void
    {
        $recipient = $this->normalizeRecipient($recipient);

        if (! $this->isConfigured()) {
            Log::debug('WhatsApp template message skipped: service is not configured.', [
                'template' => $message->templateName,
            ]);

            return;
        }

        if ($recipient === '') {
            Log::warning('WhatsApp template message skipped: recipient has no usable phone number.', [
                'template' => $message->templateName,
            ]);

            return;
        }

        $this->post($message->toPayload($recipient));
    }

    /**
     * Send a free-form text message. Only deliverable inside the 24-hour
     * customer service window Meta opens when the recipient messages us
     * (including quick reply button taps).
     */
    public function sendText(string $recipient, string $body): void
    {
        $recipient = $this->normalizeRecipient($recipient);

        if (! $this->isConfigured()) {
            Log::debug('WhatsApp text message skipped: service is not configured.');

            return;
        }

        if ($recipient === '' || $body === '') {
            Log::warning('WhatsApp text message skipped: empty recipient or body.', [
                'has_recipient' => $recipient !== '',
                'has_body' => $body !== '',
            ]);

            return;
        }

        $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(array $payload): void
    {
        $response = Http::withToken((string) config('services.whatsapp.token'))
            ->acceptJson()
            ->asJson()
            ->timeout(10)
            ->post($this->endpoint(), $payload);

        if ($response->failed()) {
            Log::warning('WhatsApp notification request failed.', [
                'status' => $response->status(),
                'endpoint' => $this->endpoint(),
                'api_version' => config('services.whatsapp.api_version'),
                'phone_number_id' => config('services.whatsapp.phone_number_id'),
                'response' => $response->json(),
            ]);
        }
    }

    protected function endpoint(): string
    {
        return sprintf(
            '%s/%s/%s/messages',
            rtrim((string) config('services.whatsapp.base_url'), '/'),
            (string) config('services.whatsapp.api_version'),
            (string) config('services.whatsapp.phone_number_id'),
        );
    }

    protected function normalizeRecipient(string $recipient): string
    {
        return PhoneNumber::forWhatsApp($recipient);
    }
}
