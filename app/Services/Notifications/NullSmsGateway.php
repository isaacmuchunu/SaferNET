<?php

namespace App\Services\Notifications;

/** SMS is switched off: every attempt is recorded as a failure with the reason. */
class NullSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'none';
    }

    public function send(string $to, string $message): SmsResult
    {
        return SmsResult::rejected('No SMS provider is configured for this deployment.');
    }
}
