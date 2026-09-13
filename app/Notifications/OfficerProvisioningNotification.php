<?php

namespace App\Notifications;

use App\Enums\ProvisioningMessagePart;
use App\Enums\UserRole;
use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OfficerProvisioningNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ProvisioningMessagePart $part,
        public ?string $temporaryPassword = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->routeNotificationForSms() ?? null)
            ? ['mail', 'sms']
            : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return match ($this->part) {
            ProvisioningMessagePart::Welcome => (new MailMessage)
                ->subject('Welcome to SAFERNET')
                ->greeting('Hello '.$notifiable->name.',')
                ->line('Your '.$this->roleLabel($notifiable).' account has been added to the Kiambu County SAFERNET platform.')
                ->line('You will receive your username and temporary password in two separate messages.')
                ->line('Sign in only through the official SAFERNET portal.'),
            ProvisioningMessagePart::Username => (new MailMessage)
                ->subject('Your SAFERNET username')
                ->greeting('Hello '.$notifiable->name.',')
                ->line('Your SAFERNET username is:')
                ->line((string) $notifiable->email)
                ->line('A separate message contains your temporary password.'),
            ProvisioningMessagePart::TemporaryPassword => (new MailMessage)
                ->subject('Your temporary SAFERNET password')
                ->greeting('Hello '.$notifiable->name.',')
                ->line('Your one-time temporary password is:')
                ->line((string) $this->temporaryPassword)
                ->line('It expires in '.config('onboarding.temporary_password_hours', 72).' hours.')
                ->line('On first sign-in you must choose your own password and set up multi-factor authentication.'),
        };
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $content = match ($this->part) {
            ProvisioningMessagePart::Welcome => 'SAFERNET: Your '.$this->roleLabel($notifiable).' account has been added. Your username and temporary password follow in separate messages.',
            ProvisioningMessagePart::Username => 'SAFERNET username: '.$notifiable->email,
            ProvisioningMessagePart::TemporaryPassword => 'SAFERNET temporary password: '.$this->temporaryPassword.'. It expires in '.config('onboarding.temporary_password_hours', 72).' hours. Sign in, change it, then set up MFA.',
        };

        $message = SmsMessage::make($content)->event($this->eventName());

        return $this->part === ProvisioningMessagePart::TemporaryPassword
            ? $message->sensitive()
            : $message;
    }

    /** @return array<string, mixed> */
    public function deliveryContext(): array
    {
        return [
            'event' => $this->eventName(),
            'redact_body' => $this->part === ProvisioningMessagePart::TemporaryPassword,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [];
    }

    private function eventName(): string
    {
        return 'officer.provisioned.'.$this->part->value;
    }

    private function roleLabel(object $notifiable): string
    {
        return match ($notifiable->role ?? null) {
            UserRole::Cde => 'County Director of Education',
            UserRole::Scde => 'Sub-County Director of Education',
            UserRole::Hoi => 'Head of Institution',
            UserRole::Clm => 'Computer Laboratory Manager',
            default => 'officer',
        };
    }
}
