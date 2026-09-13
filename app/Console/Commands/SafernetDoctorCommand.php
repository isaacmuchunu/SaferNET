<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('safernet:doctor {--json : Emit machine-readable JSON}')]
#[Description('Check database, cache, queue, mail, SMS, and AI deployment readiness')]
class SafernetDoctorCommand extends Command
{
    public function handle(): int
    {
        $checks = [];

        $this->attempt($checks, 'database', function (): string {
            DB::connection()->getPdo();
            if (DB::connection()->getDriverName() !== 'pgsql') {
                throw new \RuntimeException('DB_CONNECTION must be pgsql.');
            }

            return 'PostgreSQL connected';
        });
        $this->attempt($checks, 'cache', function (): string {
            $key = 'safernet:doctor:'.getmypid();
            Cache::put($key, 'ok', 10);
            $ok = Cache::pull($key) === 'ok';

            if (! $ok) {
                throw new \RuntimeException('Cache round-trip failed.');
            }

            return 'Cache round-trip succeeded';
        });

        $checks['application_key'] = [
            'status' => filled(config('app.key')) ? 'ok' : 'fail',
            'message' => filled(config('app.key')) ? 'Configured' : 'APP_KEY is missing',
        ];
        $checks['queue'] = [
            'status' => config('queue.default') === 'sync' && app()->isProduction() ? 'warn' : 'ok',
            'message' => 'Driver: '.config('queue.default'),
        ];
        $checks['mail'] = [
            'status' => in_array(config('mail.default'), ['log', 'array'], true) && app()->isProduction() ? 'warn' : 'ok',
            'message' => 'Mailer: '.config('mail.default'),
        ];
        $checks['sms'] = [
            'status' => in_array(config('notifications.sms.provider'), ['none', 'log'], true) && app()->isProduction() ? 'warn' : 'ok',
            'message' => 'Provider: '.config('notifications.sms.provider'),
        ];
        $checks['ai'] = [
            'status' => collect(config('ai.providers'))->contains(fn ($provider) => filled($provider['api_key'] ?? null)) ? 'ok' : 'warn',
            'message' => 'At least one provider key '.(collect(config('ai.providers'))->contains(fn ($provider) => filled($provider['api_key'] ?? null)) ? 'is configured' : 'is required for AI assistance'),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Status', 'Detail'], collect($checks)->map(
                fn ($check, $name) => [$name, strtoupper($check['status']), $check['message']],
            )->values()->all());
        }

        return collect($checks)->contains(fn ($check) => $check['status'] === 'fail') ? self::FAILURE : self::SUCCESS;
    }

    private function attempt(array &$checks, string $name, callable $callback): void
    {
        try {
            $checks[$name] = ['status' => 'ok', 'message' => $callback()];
        } catch (Throwable $exception) {
            $checks[$name] = ['status' => 'fail', 'message' => $exception->getMessage()];
        }
    }
}
