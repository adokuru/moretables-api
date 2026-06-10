<?php

namespace App\Notifications;

use Illuminate\Support\Str;

class WhatsAppMessage
{
    /**
     * @param  list<string>  $bodyParameters
     */
    public function __construct(
        public string $templateName,
        public array $bodyParameters = [],
        public ?string $languageCode = null,
        public ?string $urlButtonSuffix = null,
        public ?string $quickReplyPayload = null,
        public ?int $quickReplyIndex = null,
    ) {}

    /**
     * @param  list<string>  $bodyParameters
     */
    public static function template(string $templateName, array $bodyParameters = []): self
    {
        return new self($templateName, $bodyParameters);
    }

    public function language(string $languageCode): self
    {
        $this->languageCode = $languageCode;

        return $this;
    }

    /**
     * Supply the dynamic suffix for a template URL button defined as
     * `https://domain.com/{{1}}` in Meta. Only set this when the template
     * actually has a dynamic URL button, otherwise Meta rejects the send.
     */
    public function urlButton(string $suffix): self
    {
        $this->urlButtonSuffix = $suffix;

        return $this;
    }

    /**
     * Supply the payload echoed back via the webhook when the recipient taps a
     * quick reply button on the template. The index must match the button's
     * position among the template's buttons in Meta.
     */
    public function quickReplyButton(string $payload, int $index): self
    {
        $this->quickReplyPayload = $payload;
        $this->quickReplyIndex = $index;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(string $recipient): array
    {
        $components = [
            [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value): array => ['type' => 'text', 'text' => $this->sanitizeParameter($value)],
                    $this->bodyParameters,
                ),
            ],
        ];

        if ($this->urlButtonSuffix !== null && $this->urlButtonSuffix !== '') {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [
                    ['type' => 'text', 'text' => $this->urlButtonSuffix],
                ],
            ];
        }

        if ($this->quickReplyPayload !== null && $this->quickReplyPayload !== '') {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => (string) ($this->quickReplyIndex ?? 0),
                'parameters' => [
                    ['type' => 'payload', 'payload' => $this->quickReplyPayload],
                ],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $this->templateName,
                'language' => [
                    'code' => $this->languageCode ?: (string) config('services.whatsapp.template_language', 'en'),
                ],
                'components' => $components,
            ],
        ];
    }

    /**
     * Meta rejects body parameters containing newlines, tabs, or more than four
     * consecutive spaces, empty values, and values longer than 1024 characters.
     */
    protected function sanitizeParameter(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return Str::limit($value === '' ? '—' : $value, 1024, '');
    }
}
