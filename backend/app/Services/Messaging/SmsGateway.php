<?php

declare(strict_types=1);

namespace App\Services\Messaging;

/**
 * Outbound SMS integration boundary. The default driver only logs; add a
 * driver for your SMS provider and select it with SMS_DRIVER.
 */
interface SmsGateway
{
    /** @return array{ok: bool, message_id: ?string, error: ?string} */
    public function send(string $to, string $message, string $senderId): array;

    public function name(): string;
}
