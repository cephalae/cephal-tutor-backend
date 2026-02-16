<?php

namespace App\Notifications\Channels;

use App\Services\MicrosoftGraphMailService;

class MicrosoftGraphMailChannel
{
    public function __construct(private readonly MicrosoftGraphMailService $graphMail) {}

    public function send($notifiable, $notification): void
    {
        if (!method_exists($notification, 'toMicrosoftGraphMail')) {
            throw new \RuntimeException('Notification missing toMicrosoftGraphMail()');
        }

        $data = $notification->toMicrosoftGraphMail($notifiable);

        $this->graphMail->sendMail(
            to: $data['to'],
            subject: $data['subject'],
            html: $data['html'],
            from: $data['from'] ?? null
        );
    }
}
