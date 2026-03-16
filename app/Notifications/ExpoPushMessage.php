<?php

namespace App\Notifications;

class ExpoPushMessage
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
        public string $sound = 'default',
    ) {
    }

    public static function make(string $title, string $body): self
    {
        return new self($title, $body);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function data(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(string $token): array
    {
        return [
            'to' => $token,
            'title' => $this->title,
            'body' => $this->body,
            'sound' => $this->sound,
            'data' => $this->data,
        ];
    }
}
