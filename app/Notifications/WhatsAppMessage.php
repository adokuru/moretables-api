<?php

namespace App\Notifications;

class WhatsAppMessage
{
    /**
     * @param  list<string>  $bodyParameters
     */
    public function __construct(
        public string $templateName,
        public array $bodyParameters = [],
        public ?string $languageCode = null,
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
     * @return array<string, mixed>
     */
    public function toPayload(string $recipient): array
    {
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
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => array_map(
                            fn (string $value): array => ['type' => 'text', 'text' => $value],
                            $this->bodyParameters,
                        ),
                    ],
                ],
            ],
        ];
    }
}
