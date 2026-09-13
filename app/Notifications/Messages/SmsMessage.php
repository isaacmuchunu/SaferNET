<?php

namespace App\Notifications\Messages;

final class SmsMessage
{
    public function __construct(
        public string $content,
        public ?string $event = null,
        public ?string $severity = null,
        public bool $sensitive = false,
    ) {}

    public static function make(string $content): self
    {
        return new self($content);
    }

    public function event(string $event): self
    {
        $this->event = $event;

        return $this;
    }

    public function severity(?string $severity): self
    {
        $this->severity = $severity;

        return $this;
    }

    public function sensitive(): self
    {
        $this->sensitive = true;

        return $this;
    }
}
