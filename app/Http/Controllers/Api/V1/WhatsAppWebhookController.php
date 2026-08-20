<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppCancelRequest;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\ExcludeAllRoutesFromDocs;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[ExcludeAllRoutesFromDocs]
class WhatsAppWebhookController extends Controller
{
    /**
     * Meta's one-time endpoint verification handshake. PHP rewrites the
     * `hub.mode` style query keys to `hub_mode`.
     */
    public function verify(Request $request): Response
    {
        $verifyToken = (string) config('services.whatsapp.webhook_verify_token');

        if ($verifyToken !== ''
            && $request->query('hub_mode') === 'subscribe'
            && hash_equals($verifyToken, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'));
        }

        abort(403);
    }

    public function handle(Request $request): Response
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('WhatsApp webhook rejected: signature verification failed.', [
                'has_app_secret' => (string) config('services.whatsapp.app_secret') !== '',
                'has_signature_header' => $request->hasHeader('X-Hub-Signature-256'),
            ]);

            abort(403);
        }

        $messages = collect(Arr::wrap($request->input('entry', [])))
            ->flatMap(static fn ($entry): array => Arr::wrap($entry['changes'] ?? []))
            ->flatMap(static fn ($change): array => Arr::wrap($change['value']['messages'] ?? []))
            ->all();

        Log::log($messages === [] ? 'debug' : 'info', 'WhatsApp webhook received.', [
            'message_count' => count($messages),
            'message_types' => array_values(array_unique(array_map(
                static fn ($message): string => (string) ($message['type'] ?? 'unknown'),
                $messages,
            ))),
        ]);

        foreach ($messages as $message) {
            $this->handleInboundMessage($message);
        }

        return response('');
    }

    /**
     * Meta signs the raw request body with the app secret and sends the HMAC
     * in the X-Hub-Signature-256 header. Reject everything when no app secret
     * is configured rather than accepting unsigned payloads.
     */
    protected function hasValidSignature(Request $request): bool
    {
        $appSecret = (string) config('services.whatsapp.app_secret');

        if ($appSecret === '') {
            return false;
        }

        $signature = (string) $request->header('X-Hub-Signature-256');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, Str::after($signature, 'sha256='));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function handleInboundMessage(array $message): void
    {
        if (($message['type'] ?? null) !== 'button') {
            Log::debug('WhatsApp webhook skipped a non-button message.', [
                'type' => $message['type'] ?? 'unknown',
            ]);

            return;
        }

        $payload = (string) ($message['button']['payload'] ?? '');
        $from = (string) ($message['from'] ?? '');

        if ($from === '' || ! str_starts_with($payload, 'cancel_reservation:')) {
            Log::warning('WhatsApp webhook ignored a button reply it could not route.', [
                'payload' => $payload,
                'has_sender' => $from !== '',
            ]);

            return;
        }

        $reservationId = (int) Str::after($payload, 'cancel_reservation:');

        if ($reservationId <= 0) {
            Log::warning('WhatsApp webhook received a malformed cancel payload.', ['payload' => $payload]);

            return;
        }

        Log::info('WhatsApp cancel request queued.', [
            'reservation_id' => $reservationId,
            'from' => PhoneNumber::mask($from),
            'queue_connection' => config('queue.default'),
        ]);

        ProcessWhatsAppCancelRequest::dispatch($reservationId, $from);
    }
}
