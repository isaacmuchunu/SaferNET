<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SendOfficerProvisioningMessages;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendOfficerProvisioningMessagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_records_three_ordered_email_and_sms_deliveries_without_duplicate_retries(): void
    {
        Mail::fake();
        config()->set('notifications.sms.provider', 'log');
        $user = User::factory()->scde()->create([
            'phone' => '0712345678',
            'must_change_password' => true,
            'mfa_required' => true,
        ]);
        $job = new SendOfficerProvisioningMessages($user->id, Crypt::encryptString('Temporary!!234'));

        $job->handle();
        $job->handle();

        $deliveries = NotificationDelivery::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->get();

        $this->assertCount(6, $deliveries);
        $this->assertSame([
            'mail:officer.provisioned.welcome',
            'sms:officer.provisioned.welcome',
            'mail:officer.provisioned.username',
            'sms:officer.provisioned.username',
            'mail:officer.provisioned.temporary_password',
            'sms:officer.provisioned.temporary_password',
        ], $deliveries->map(fn ($delivery): string => $delivery->channel.':'.$delivery->event)->all());
        $this->assertTrue($deliveries->every(fn ($delivery): bool => $delivery->status === 'sent'));
        $credentialReceipts = $deliveries->where('event', 'officer.provisioned.temporary_password');
        $this->assertTrue($credentialReceipts->every(
            fn ($delivery): bool => $delivery->body === '[Sensitive credential redacted]'
        ));
        $this->assertFalse($deliveries->contains(
            fn ($delivery): bool => str_contains((string) $delivery->body, 'Temporary!!234')
        ));
    }
}
