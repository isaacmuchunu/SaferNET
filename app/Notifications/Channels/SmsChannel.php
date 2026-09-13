<?php

namespace App\Notifications\Channels;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\Messages\SmsMessage;
use App\Services\Notifications\PhoneNumber;
use App\Services\Notifications\SmsGateway;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Sends a notification by SMS and records what the provider said.
 *
 * The delivery row is written after the provider answers, never before, so the
 * record reflects delivery rather than intent.
 */
class SmsChannel
{
    public function __construct(private readonly SmsGateway $gateway) {}

    public function send(object $notifiable, Notification $notification): ?NotificationDelivery
    {
        if (! method_exists($notification, 'toSms')) {
            return null;
        }

        $message = $notification->toSms($notifiable);

        if (! $message instanceof SmsMessage) {
            return null;
        }

        $recipient = PhoneNumber::toE164(
            method_exists($notifiable, 'routeNotificationForSms')
                ? $notifiable->routeNotificationForSms($notification)
                : ($notifiable->phone ?? null)
        );

        if ($recipient === null) {
            Log::warning('SAFERNET SMS not sent: the recipient has no usable telephone number.', [
                'notifiable' => $notifiable::class,
                'notifiable_id' => $notifiable->getKey() ?? null,
                'event' => $message->event,
            ]);

            return null;
        }

        $content = str($message->content)->squish()->limit(config('notifications.sms.max_length', 320))->toString();
        $result = $this->gateway->send($recipient, $content);

        return NotificationDelivery::create([
            'institution_id' => $notifiable instanceof User ? $notifiable->institution_id : null,
            'user_id' => $notifiable instanceof User ? $notifiable->id : null,
            'channel' => 'sms',
            'provider' => $this->gateway->name(),
            'recipient' => $recipient,
            'body' => $message->sensitive ? '[Sensitive credential redacted]' : $content,
            'status' => $result->sent ? 'sent' : 'failed',
            'provider_message_id' => $result->providerMessageId,
            'error' => $result->error,
            'event' => $message->event,
            'severity' => $message->severity,
        ]);
    }
}
