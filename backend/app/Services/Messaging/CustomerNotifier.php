<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Support\Tenancy\Tenant;
use Throwable;

/**
 * Customer-facing messages (receipts, approvals, reminders). Each one is
 * recorded in customer_messages whether or not the gateway accepts it.
 */
final class CustomerNotifier
{
    public function __construct(private readonly SmsGateway $gateway) {}

    public function sms(Customer $customer, string $event, string $body): CustomerMessage
    {
        $message = CustomerMessage::query()->create([
            'customer_id' => $customer->id,
            'channel' => 'sms',
            'recipient' => $customer->phone,
            'event' => $event,
            'body' => $body,
            'status' => 'queued',
        ]);

        try {
            $sender = (string) config('services.sms.sender_id', Tenant::company()->name);
            $result = $this->gateway->send($customer->phone, $body, substr($sender, 0, 11));
            $message->forceFill([
                'status' => $result['ok'] ? ($this->gateway->name() === 'log' ? 'logged' : 'sent') : 'failed',
                'provider_message_id' => $result['message_id'],
                'error' => $result['error'],
                'sent_at' => $result['ok'] ? now() : null,
            ])->save();
        } catch (Throwable $e) {
            report($e);
            $message->forceFill(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 250)])->save();
        }

        return $message;
    }
}
