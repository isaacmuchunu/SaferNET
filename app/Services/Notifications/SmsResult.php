<?php

namespace App\Services\Notifications;

/**
 * What the provider said. `sent` means the provider accepted the message, not
 * that SAFERNET attempted one.
 */
final readonly class SmsResult
{
    private function __construct(
        public bool $sent,
        public ?string $providerMessageId = null,
        public ?string $error = null,
        public bool $retryable = false,
    ) {}

    public static function sent(?string $providerMessageId = null): self
    {
        return new self(sent: true, providerMessageId: $providerMessageId);
    }

    /** A fault that will not improve on retry: a bad number, a rejected sender. */
    public static function rejected(string $error): self
    {
        return new self(sent: false, error: $error);
    }

    /** A transport fault worth another attempt: a timeout, a 5xx, rate limiting. */
    public static function deferred(string $error): self
    {
        return new self(sent: false, error: $error, retryable: true);
    }
}
