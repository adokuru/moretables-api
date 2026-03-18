<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;

class MoreTablesMailChannel extends MailChannel
{
    /**
     * @param  MailMessage  $message
     * @return \Closure
     */
    protected function buildMarkdownText($message)
    {
        return fn (array $data) => $this->markdownRenderer($message)->renderText(
            'notifications::email-text',
            array_merge($data, $message->data()),
        );
    }
}
