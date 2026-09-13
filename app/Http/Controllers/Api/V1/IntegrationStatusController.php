<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BlocklistSource;
use App\Models\NotificationDelivery;
use App\Services\Ai\ClassifierProvider;
use App\Services\Ai\ContentClassifier;
use App\Services\Notifications\SmsGateway;
use Illuminate\Http\JsonResponse;

/**
 * What this deployment is actually connected to. Configured is not the same as
 * working, so each entry says which it is.
 */
class IntegrationStatusController extends Controller
{
    public function __invoke(ContentClassifier $classifier, SmsGateway $sms): JsonResponse
    {
        $active = $classifier->activeProvider();

        return response()->json(['data' => [
            'ai' => [
                'enabled' => $classifier->isEnabled(),
                'selection' => config('ai.provider'),
                'active_provider' => $active?->name(),
                'active_models' => $active?->models() ?? [],
                'providers' => collect(config('ai.priority', []))
                    ->map(fn (string $name): array => [
                        'name' => $name,
                        'configured' => collect($classifier->available())
                            ->contains(fn (ClassifierProvider $provider): bool => $provider->name() === $name),
                        'models' => config("ai.providers.{$name}.models", []),
                    ])
                    ->values(),
            ],
            'sms' => [
                'provider' => $sms->name(),
                'configured' => $sms->name() !== 'none',
                'live' => $sms->name() === 'africastalking',
                'escalates_from' => config('notifications.escalate_by_sms_from'),
                'sent_30_days' => NotificationDelivery::query()
                    ->where('channel', 'sms')
                    ->where('status', 'sent')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
                'failed_30_days' => NotificationDelivery::query()
                    ->where('channel', 'sms')
                    ->where('status', 'failed')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
            ],
            'mail' => [
                'provider' => config('mail.default'),
                'configured' => config('mail.default') !== 'log',
                'live' => ! in_array(config('mail.default'), ['log', 'array'], true),
                'from' => config('mail.from.address'),
                'sent_30_days' => NotificationDelivery::query()
                    ->where('channel', 'mail')
                    ->where('status', 'sent')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
            ],
            'blocklists' => [
                'sources' => BlocklistSource::query()->count(),
                'enabled' => BlocklistSource::query()->enabled()->count(),
                'domains' => (int) BlocklistSource::query()->enabled()->sum('domains_count'),
                'last_synced_at' => BlocklistSource::query()->max('last_synced_at'),
                'failing' => BlocklistSource::query()->where('last_status', 'failed')->count(),
            ],
        ]]);
    }
}
