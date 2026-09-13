<?php

namespace App\Services\Notifications;

interface SmsGateway
{
    /** The provider name recorded against every delivery. */
    public function name(): string;

    /**
     * Hand one message to the provider.
     *
     * Implementations never throw for a provider fault: they answer with a
     * failed result carrying the reason, so the caller records what happened
     * instead of losing the alert to an exception.
     */
    public function send(string $to, string $message): SmsResult;
}
