<?php

namespace App\Notifications\Channels;

use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/** Sends Laravel notification mail and keeps the same auditable receipt as SMS. */
class TrackedMailChannel extends MailChannel
{
    public function __construct(MailFactory $mailer, Markdown $markdown)
    {
        parent::__construct($mailer, $markdown);
    }

    public function send($notifiable, Notification $notification)
    {
        $recipient = $notifiable->routeNotificationFor('mail', $notification);
        $mail = $notification->toMail($notifiable);
        $context = method_exists($notification, 'deliveryContext')
            ? $notification->deliveryContext()
            : [];

        try {
            $result = parent::send($notifiable, $notification);
            $this->record($notifiable, $recipient, $mail, $context, 'sent');

            return $result;
        } catch (Throwable $exception) {
            $this->record($notifiable, $recipient, $mail, $context, 'failed', $exception->getMessage());
            throw $exception;
        }
    }

    /** @param array<string, mixed> $context */
    private function record(object $notifiable, mixed $recipient, mixed $mail, array $context, string $status, ?string $error = null): void
    {
        $address = is_array($recipient) ? (string) array_key_first($recipient) : (string) $recipient;

        if ($address === '') {
            return;
        }

        NotificationDelivery::create([
            'institution_id' => $notifiable instanceof User ? $notifiable->institution_id : null,
            'user_id' => $notifiable instanceof User ? $notifiable->id : null,
            'channel' => 'mail',
            'provider' => (string) config('mail.default'),
            'recipient' => $address,
            'subject' => $mail instanceof MailMessage ? $mail->subject : null,
            'body' => ($context['redact_body'] ?? false)
                ? '[Sensitive credential redacted]'
                : ($mail instanceof MailMessage ? collect($mail->introLines)->implode("\n") : null),
            'status' => $status,
            'error' => $error,
            'event' => $context['event'] ?? null,
            'severity' => $context['severity'] ?? null,
            'notifiable_subject_type' => $context['subject_type'] ?? null,
            'notifiable_subject_id' => $context['subject_id'] ?? null,
        ]);
    }
}
