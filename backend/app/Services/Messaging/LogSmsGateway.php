<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Log;

/** Writes messages to the application log instead of sending them. */
final class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $message, string $senderId): array
    {
        Log::info('SMS (log driver - not sent)', ['to' => $to, 'sender' => $senderId, 'message' => $message]);

        return ['ok' => true, 'message_id' => null, 'error' => null];
    }

    public function name(): string
    {
        return 'log';
    }
}
