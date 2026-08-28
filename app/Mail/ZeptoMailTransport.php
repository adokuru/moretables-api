<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

final class ZeptoMailTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $timeout = 10,
    ) {
        if ($this->token === '') {
            throw new InvalidArgumentException('ZeptoMail API token is not configured.');
        }

        parent::__construct();
    }

    public function __toString(): string
    {
        return 'zeptomail';
    }

    protected function doSend(SentMessage $message): void
    {
        $originalMessage = $message->getOriginalMessage();

        if (! $originalMessage instanceof Message) {
            throw new TransportException('ZeptoMail requires a MIME email message.');
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['Authorization' => 'Zoho-enczapikey '.$this->token])
                ->timeout($this->timeout)
                ->post($this->url, $this->payload(
                    MessageConverter::toEmail($originalMessage),
                    $message->getMessageId(),
                ));
        } catch (Throwable $exception) {
            throw new TransportException('The ZeptoMail API request could not be completed.', 0, $exception);
        }

        if (! $response->successful()) {
            throw new TransportException("The ZeptoMail API returned HTTP {$response->status()}.");
        }

        $requestId = $response->json('request_id');

        if (is_string($requestId) && $requestId !== '') {
            $message->setMessageId($requestId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Email $email, string $clientReference): array
    {
        $payload = [
            'from' => $this->address($email->getFrom()[0]),
            'subject' => (string) $email->getSubject(),
            'client_reference' => $clientReference,
        ];

        foreach (['to', 'cc', 'bcc'] as $type) {
            $recipients = $this->recipients($email->{'get'.ucfirst($type)}());

            if ($recipients !== []) {
                $payload[$type] = $recipients;
            }
        }

        if ($email->getReplyTo() !== []) {
            $payload['reply_to'] = array_map($this->address(...), $email->getReplyTo());
        }

        if ($email->getHtmlBody() !== null) {
            $payload['htmlbody'] = $this->body($email->getHtmlBody());
        }

        if ($email->getTextBody() !== null) {
            $payload['textbody'] = $this->body($email->getTextBody());
        }

        $mimeHeaders = $this->mimeHeaders($email);

        if ($mimeHeaders !== []) {
            $payload['mime_headers'] = $mimeHeaders;
        }

        foreach ($email->getAttachments() as $attachment) {
            $type = $attachment->getDisposition() === 'inline' ? 'inline_images' : 'attachments';
            $payload[$type][] = $this->attachment($attachment, $type === 'inline_images');
        }

        return $payload;
    }

    /**
     * @return array{address: string, name: string}
     */
    private function address(Address $address): array
    {
        return [
            'address' => $address->getAddress(),
            'name' => $address->getName(),
        ];
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<array{email_address: array{address: string, name: string}}>
     */
    private function recipients(array $addresses): array
    {
        return array_map(
            fn (Address $address): array => ['email_address' => $this->address($address)],
            $addresses,
        );
    }

    /**
     * @return array{name?: string, content: string, mime_type: string, cid?: string}
     */
    private function attachment(DataPart $attachment, bool $inline): array
    {
        $payload = [
            'content' => base64_encode($attachment->getBody()),
            'mime_type' => $attachment->getContentType(),
        ];

        if ($inline) {
            $payload['cid'] = $attachment->getContentId();
        } else {
            $payload['name'] = $attachment->getFilename() ?? 'attachment';
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function mimeHeaders(Email $email): array
    {
        $excluded = [
            'bcc', 'cc', 'content-transfer-encoding', 'content-type', 'date', 'from',
            'message-id', 'mime-version', 'reply-to', 'return-path', 'sender', 'subject', 'to',
        ];
        $headers = [];

        foreach ($email->getHeaders()->all() as $header) {
            if (! in_array(strtolower($header->getName()), $excluded, true)) {
                $headers[$header->getName()] = $header->getBodyAsString();
            }
        }

        return $headers;
    }

    /**
     * @param  resource|string  $body
     */
    private function body($body): string
    {
        if (is_resource($body)) {
            rewind($body);

            return stream_get_contents($body) ?: '';
        }

        return $body;
    }
}
