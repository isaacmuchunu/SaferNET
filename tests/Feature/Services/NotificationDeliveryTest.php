<?php

namespace Tests\Feature\Services;

use App\Enums\UserRole;
use App\Models\Incident;
use App\Models\Institution;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\IncidentCreatedNotification;
use App\Services\Notifications\AfricasTalkingSmsGateway;
use App\Services\Notifications\PhoneNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function gateway(): AfricasTalkingSmsGateway
    {
        return new AfricasTalkingSmsGateway(
            'https://api.sandbox.africastalking.com/version1/messaging',
            'sandbox',
            'test-key',
            'SAFERNET',
            5,
        );
    }

    public function test_local_kenyan_numbers_are_normalised_before_sending(): void
    {
        $this->assertSame('+254712345678', PhoneNumber::toE164('0712 345 678'));
        $this->assertSame('+254712345678', PhoneNumber::toE164('+254 712 345 678'));
        $this->assertSame('+254712345678', PhoneNumber::toE164('712345678'));
        $this->assertNull(PhoneNumber::toE164('not a number'));
        $this->assertNull(PhoneNumber::toE164(null));
    }

    public function test_africas_talking_accepts_a_queued_message(): void
    {
        Http::fake([
            '*' => Http::response([
                'SMSMessageData' => [
                    'Message' => 'Sent to 1/1 Total Cost: KES 0.8000',
                    'Recipients' => [[
                        'statusCode' => 101,
                        'number' => '+254712345678',
                        'status' => 'Success',
                        'cost' => 'KES 0.8000',
                        'messageId' => 'ATXid_abc123',
                    ]],
                ],
            ], 201),
        ]);

        $result = $this->gateway()->send('+254712345678', 'SAFERNET: high severity incident.');

        $this->assertTrue($result->sent);
        $this->assertSame('ATXid_abc123', $result->providerMessageId);

        Http::assertSent(fn ($request) => $request['username'] === 'sandbox'
            && $request['to'] === '+254712345678'
            && $request['from'] === 'SAFERNET');
    }

    public function test_a_rejected_recipient_is_not_reported_as_sent(): void
    {
        Http::fake([
            '*' => Http::response([
                'SMSMessageData' => [
                    'Message' => 'Sent to 0/1',
                    'Recipients' => [['statusCode' => 403, 'number' => '+254700000000', 'status' => 'InvalidPhoneNumber']],
                ],
            ], 201),
        ]);

        $result = $this->gateway()->send('+254700000000', 'SAFERNET: high severity incident.');

        $this->assertFalse($result->sent);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('InvalidPhoneNumber', $result->error);
    }

    public function test_a_provider_outage_is_deferred_rather_than_discarded(): void
    {
        Http::fake(['*' => Http::response('upstream unavailable', 503)]);

        $result = $this->gateway()->send('+254712345678', 'SAFERNET: high severity incident.');

        $this->assertFalse($result->sent);
        $this->assertTrue($result->retryable);
    }

    public function test_a_high_severity_incident_reaches_the_head_of_institution_by_sms(): void
    {
        config()->set('notifications.sms.provider', 'log');
        Mail::fake();

        $institution = Institution::factory()->create();
        $hoi = User::factory()->hoi($institution)->create(['phone' => '0712345678']);
        $incident = Incident::factory()->forInstitution($institution)->create(['severity' => 'critical']);

        $hoi->notify(new IncidentCreatedNotification($incident));

        $delivery = NotificationDelivery::query()->where('channel', 'sms')->latest('id')->first();

        $this->assertNotNull($delivery, 'No SMS delivery was recorded.');
        $this->assertSame('sent', $delivery->status);
        $this->assertSame('+254712345678', $delivery->recipient);
        $this->assertSame('incident.created', $delivery->event);
        $this->assertStringContainsString('SAFERNET', $delivery->body);

        // The message carries a reference and an urgency, never case notes.
        $this->assertStringNotContainsString($incident->learner->full_name, $delivery->body);
    }

    public function test_incident_email_is_sent_and_recorded_for_audit(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $hoi = User::factory()->hoi($institution)->create();
        $incident = Incident::factory()->forInstitution($institution)->create(['severity' => 'medium']);

        $hoi->notify(new IncidentCreatedNotification($incident));

        $delivery = NotificationDelivery::query()->where('channel', 'mail')->latest('id')->first();

        $this->assertNotNull($delivery, 'No email delivery was recorded.');
        $this->assertSame('sent', $delivery->status);
        $this->assertSame($hoi->email, $delivery->recipient);
        $this->assertSame('incident.created', $delivery->event);
        $this->assertSame('SAFERNET: Safeguarding action required', $delivery->subject);
    }

    public function test_incident_instructions_change_with_the_recipient_role(): void
    {
        $institution = Institution::factory()->create();
        $incident = Incident::factory()->forInstitution($institution)->create(['severity' => 'high']);
        $clm = User::factory()->clm($institution)->make(['role' => UserRole::Clm]);
        $notification = new IncidentCreatedNotification($incident);

        $mail = $notification->toMail($clm);
        $sms = $notification->toSms($clm);

        $this->assertSame('SAFERNET: Technical incident action required', $mail->subject);
        $this->assertStringContainsString('Verify device attribution', collect($mail->introLines)->implode(' '));
        $this->assertStringContainsString('Verify device attribution', $sms->content);
    }

    public function test_a_low_severity_incident_does_not_escalate_to_sms(): void
    {
        config()->set('notifications.sms.provider', 'log');
        Mail::fake();

        $institution = Institution::factory()->create();
        $hoi = User::factory()->hoi($institution)->create(['phone' => '0712345678']);
        $incident = Incident::factory()->forInstitution($institution)->create(['severity' => 'low']);

        $hoi->notify(new IncidentCreatedNotification($incident));

        $this->assertSame(0, NotificationDelivery::query()->where('channel', 'sms')->count());
    }

    public function test_an_officer_without_a_number_is_not_silently_dropped_from_the_mail_route(): void
    {
        config()->set('notifications.sms.provider', 'log');
        Notification::fake();

        $institution = Institution::factory()->create();
        $hoi = User::factory()->hoi($institution)->create(['phone' => null]);
        $incident = Incident::factory()->forInstitution($institution)->create(['severity' => 'critical']);

        $hoi->notify(new IncidentCreatedNotification($incident));

        Notification::assertSentTo($hoi, IncidentCreatedNotification::class, function ($notification, array $channels) {
            return in_array('mail', $channels, true) && ! in_array('sms', $channels, true);
        });
    }
}
