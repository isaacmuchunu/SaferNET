<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\DeviceGroup;
use App\Models\DeviceLearnerAssignment;
use App\Models\ExceptionRequest;
use App\Models\FilteringPolicy;
use App\Models\Incident;
use App\Models\IncidentAction;
use App\Models\Laboratory;
use App\Models\Learner;
use App\Models\LearnerGroup;
use App\Models\LearnerSession;
use App\Models\PolicyRule;
use App\Models\ProtectionComponent;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WebEvent;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\TrackedMailChannel;
use App\Policies\TenantOwnedPolicy;
use App\Policies\UserPolicy;
use App\Services\Ai\ContentClassifier;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Blocklists\SafeFetcher;
use App\Services\Notifications\AfricasTalkingSmsGateway;
use App\Services\Notifications\LogSmsGateway;
use App\Services\Notifications\NullSmsGateway;
use App\Services\Notifications\SmsGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(MailChannel::class, fn ($app) => $app->make(TrackedMailChannel::class));

        $this->app->singleton(SafeFetcher::class, fn (): SafeFetcher => SafeFetcher::fromConfig());

        $this->app->singleton(ContentClassifier::class, function (): ContentClassifier {
            $timeout = (int) config('ai.timeout', 8);
            $providers = config('ai.providers');

            $built = [
                'gemini' => new GeminiProvider(
                    $providers['gemini']['api_key'] ?? null,
                    $providers['gemini']['endpoint'],
                    $providers['gemini']['models'] ?? [],
                    $timeout,
                ),
                'anthropic' => new AnthropicProvider(
                    $providers['anthropic']['api_key'] ?? null,
                    $providers['anthropic']['endpoint'],
                    $providers['anthropic']['version'],
                    $providers['anthropic']['models'] ?? [],
                    $timeout,
                ),
                'openai' => new OpenAiProvider(
                    $providers['openai']['api_key'] ?? null,
                    $providers['openai']['endpoint'],
                    $providers['openai']['models'] ?? [],
                    $timeout,
                ),
            ];

            // A named provider pins the choice; "auto" keeps them all in
            // priority order and the classifier uses the first that answers.
            $order = config('ai.provider', 'auto') === 'auto'
                ? config('ai.priority', array_keys($built))
                : [config('ai.provider')];

            return new ContentClassifier(array_values(array_filter(array_map(
                fn (string $name) => $built[$name] ?? null,
                $order,
            ))));
        });

        $this->app->singleton(SmsGateway::class, function (): SmsGateway {
            $sms = config('notifications.sms');

            return match ($sms['provider']) {
                'africastalking' => new AfricasTalkingSmsGateway(
                    $sms['africastalking']['endpoint'],
                    (string) $sms['africastalking']['username'],
                    (string) $sms['africastalking']['api_key'],
                    $sms['africastalking']['sender_id'],
                    (int) $sms['timeout'],
                ),
                'log' => new LogSmsGateway,
                default => new NullSmsGateway,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Notification::extend('sms', fn ($app) => $app->make(SmsChannel::class));
        Gate::policy(User::class, UserPolicy::class);

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            Str::lower($request->string('email')->toString()).'|'.$request->ip()
        ));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            (string) ($request->user()?->id ?? $request->ip())
        ));
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinute(8)->by(
            'mfa:'.($request->user()?->id ?? 'guest').'|'.$request->ip()
        ));
        RateLimiter::for('telemetry', fn (Request $request) => Limit::perMinute(600)->by(
            'telemetry:'.($request->user()?->currentAccessToken()?->id ?? $request->user()?->id ?? $request->ip())
        ));

        /*
         | A learner PIN is short by design, so the only thing standing between
         | it and a guess-everything attack is how many guesses are allowed.
         | Limited on two axes: one workstation cannot grind through PINs, and
         | one learner's PIN cannot be attacked from a room full of machines.
         */
        RateLimiter::for('workstation-signin', fn (Request $request) => [
            Limit::perMinutes(15, 5)->by('signin-device:'.(
                $request->input('workstation_id')
                ?? $request->input('device_id')
                ?? $request->ip()
            )),
            Limit::perMinutes(15, 5)->by('signin-learner:'.(
                $request->input('learner_number') ?? $request->ip()
            )),
        ]);

        foreach ([
            AuditLog::class,
            DeviceGroup::class,
            DeviceLearnerAssignment::class,
            ExceptionRequest::class,
            FilteringPolicy::class,
            Incident::class,
            IncidentAction::class,
            Laboratory::class,
            Learner::class,
            LearnerGroup::class,
            LearnerSession::class,
            PolicyRule::class,
            ProtectionComponent::class,
            SecurityEvent::class,
            WebEvent::class,
        ] as $model) {
            Gate::policy($model, TenantOwnedPolicy::class);
        }
    }
}
