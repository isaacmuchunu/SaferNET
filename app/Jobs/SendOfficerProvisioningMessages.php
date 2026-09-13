<?php

namespace App\Jobs;

use App\Enums\ProvisioningMessagePart;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\OfficerProvisioningNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class SendOfficerProvisioningMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $userId,
        public string $encryptedTemporaryPassword,
    ) {}

    public function handle(): void
    {
        $user = User::withoutGlobalScopes()->findOrFail($this->userId);
        $temporaryPassword = Crypt::decryptString($this->encryptedTemporaryPassword);

        foreach (ProvisioningMessagePart::cases() as $part) {
            $notification = new OfficerProvisioningNotification(
                $part,
                $part === ProvisioningMessagePart::TemporaryPassword ? $temporaryPassword : null,
            );

            $this->sendUnlessDelivered($user, $notification, $part, 'mail');

            if (filled($user->phone)) {
                $this->sendUnlessDelivered($user, $notification, $part, 'sms');
            }
        }
    }

    private function sendUnlessDelivered(
        User $user,
        OfficerProvisioningNotification $notification,
        ProvisioningMessagePart $part,
        string $channel,
    ): void {
        $alreadyDelivered = NotificationDelivery::withoutGlobalScopes()
            ->where('user_id', $user->getKey())
            ->where('channel', $channel)
            ->where('event', 'officer.provisioned.'.$part->value)
            ->where('status', 'sent')
            ->exists();

        if (! $alreadyDelivered) {
            Notification::sendNow($user, $notification, [$channel]);

            $delivered = NotificationDelivery::withoutGlobalScopes()
                ->where('user_id', $user->getKey())
                ->where('channel', $channel)
                ->where('event', 'officer.provisioned.'.$part->value)
                ->latest('id')
                ->value('status');

            if ($delivered !== 'sent') {
                throw new RuntimeException("The {$channel} provisioning message was not accepted by its provider.");
            }
        }
    }
}
