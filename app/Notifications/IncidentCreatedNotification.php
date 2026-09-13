<?php

namespace App\Notifications;

use App\Enums\Severity;
use App\Enums\UserRole;
use App\Models\Incident;
use App\Notifications\Messages\SmsMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class IncidentCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Incident $incident) {}

    /**
     * High and critical incidents escalate beyond the portal: a Head of
     * Institution cannot be assumed to be at a desk when one is raised.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];

        if ($this->warrantsSms() && filled($notifiable->routeNotificationForSms() ?? null)) {
            $channels[] = 'sms';
        }

        return $channels;
    }

    /**
     * The SMS carries a reference and an urgency, never case notes: it travels
     * unencrypted and may be read from a lock screen.
     */
    public function toSms(object $notifiable): SmsMessage
    {
        $this->incident->loadMissing('institution');

        return SmsMessage::make(sprintf(
            'SAFERNET: %s severity internet-safety incident at %s. Reference %s. %s',
            ucfirst($this->incident->severity->value),
            $this->incident->institution?->name ?? 'your institution',
            strtoupper(substr($this->incident->public_id, 0, 8)),
            $this->roleInstruction($notifiable),
        ))->event('incident.created')->severity($this->incident->severity->value);
    }

    private function warrantsSms(): bool
    {
        $threshold = config('notifications.escalate_by_sms_from', 'high');
        $order = [Severity::Low->value => 1, Severity::Medium->value => 2, Severity::High->value => 3, Severity::Critical->value => 4];

        return ($order[$this->incident->severity->value] ?? 0) >= ($order[$threshold] ?? 3);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->incident->loadMissing(['learner.learnerGroup', 'device', 'category']);

        return (new MailMessage)
            ->subject($this->mailSubject($notifiable))
            ->line('SAFERNET created an incident after a policy threshold or immediate-notification rule was met.')
            ->line('Learner: '.$this->incident->learner->full_name)
            ->line('Learner ID: '.$this->incident->learner->learner_number)
            ->line('Device: '.$this->incident->device->asset_tag)
            ->line('Severity: '.$this->incident->severity->value)
            ->line($this->roleInstruction($notifiable))
            ->line('Open the authenticated SAFERNET portal to review the detailed evidence.');
    }

    /** @return array<string, mixed> */
    public function deliveryContext(): array
    {
        return [
            'event' => 'incident.created',
            'severity' => $this->incident->severity->value,
            'subject_type' => $this->incident::class,
            'subject_id' => $this->incident->getKey(),
        ];
    }

    private function mailSubject(object $notifiable): string
    {
        return match ($notifiable->role ?? null) {
            UserRole::Cde => 'SAFERNET: County incident oversight notice',
            UserRole::Scde => 'SAFERNET: Sub-county incident response notice',
            UserRole::Clm => 'SAFERNET: Technical incident action required',
            default => 'SAFERNET: Safeguarding action required',
        };
    }

    private function roleInstruction(object $notifiable): string
    {
        return match ($notifiable->role ?? null) {
            UserRole::Cde => 'Monitor the response and county-level escalation.',
            UserRole::Scde => 'Review the institution response and escalate if it is overdue.',
            UserRole::Clm => 'Verify device attribution and filtering enforcement; do not add safeguarding case notes.',
            default => 'Review the evidence and record the safeguarding action taken.',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'incident_id' => $this->incident->public_id,
            'severity' => $this->incident->severity->value,
            'learner_id' => $this->incident->learner_id,
            'device_id' => $this->incident->device_id,
        ];
    }
}
