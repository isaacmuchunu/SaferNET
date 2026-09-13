<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Records a safe receipt in the application log instead of sending it.
 *
 * This is the default so a deployment without an SMS account still records what
 * would have gone out and when, without copying message content or temporary
 * credentials into a general-purpose log.
 */
class LogSmsGateway implements SmsGateway
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $to, string $message): SmsResult
    {
        $reference = (string) Str::uuid();

        Log::channel(config('logging.default'))->info('SAFERNET SMS (log driver)', [
            'reference' => $reference,
            'to' => $to,
            'message_length' => mb_strlen($message),
        ]);

        return SmsResult::sent($reference);
    }
}
