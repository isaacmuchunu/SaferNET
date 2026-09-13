<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Africa's Talking SMS transport.
 *
 * The API answers 201 with a per-recipient `Recipients` array; a message can be
 * accepted for one number and rejected for another in the same response, so the
 * per-recipient status decides the outcome rather than the HTTP code alone.
 */
class AfricasTalkingSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $username,
        private readonly string $apiKey,
        private readonly ?string $senderId,
        private readonly int $timeout,
    ) {}

    public function name(): string
    {
        return 'africastalking';
    }

    public function send(string $to, string $message): SmsResult
    {
        $payload = array_filter([
            'username' => $this->username,
            'to' => $to,
            'message' => $message,
            'from' => $this->senderId,
        ], fn (?string $value): bool => $value !== null && $value !== '');

        try {
            $response = Http::asForm()
                ->timeout($this->timeout)
                ->withHeaders(['apiKey' => $this->apiKey, 'Accept' => 'application/json'])
                ->post($this->endpoint, $payload);
        } catch (ConnectionException $exception) {
            return SmsResult::deferred("Africa's Talking was unreachable: ".$exception->getMessage());
        } catch (Throwable $exception) {
            return SmsResult::deferred("Africa's Talking request failed: ".$exception->getMessage());
        }

        if ($response->serverError() || $response->status() === 429) {
            return SmsResult::deferred("Africa's Talking returned {$response->status()}.");
        }

        if ($response->failed()) {
            return SmsResult::rejected("Africa's Talking returned {$response->status()}: ".$this->summarise($response->body()));
        }

        $recipient = $response->json('SMSMessageData.Recipients.0');

        if (! is_array($recipient)) {
            return SmsResult::rejected(
                "Africa's Talking accepted no recipient: ".$this->summarise((string) $response->json('SMSMessageData.Message', $response->body()))
            );
        }

        // 100 Processed, 101 Sent, 102 Queued are all accepted by the network.
        $statusCode = (int) ($recipient['statusCode'] ?? 0);

        if (in_array($statusCode, [100, 101, 102], true)) {
            return SmsResult::sent($recipient['messageId'] ?? null);
        }

        $reason = sprintf('%s (status %d)', $recipient['status'] ?? 'Rejected', $statusCode);

        // 405/406/407 are throttling and internal faults; the rest are the
        // number, the sender id or the account balance, which a retry cannot fix.
        return in_array($statusCode, [405, 406, 407], true)
            ? SmsResult::deferred("Africa's Talking deferred the message: {$reason}")
            : SmsResult::rejected("Africa's Talking rejected the message: {$reason}");
    }

    private function summarise(string $body): string
    {
        return str($body)->squish()->limit(200)->toString();
    }
}
