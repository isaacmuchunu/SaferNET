<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('safernet:reset-mfa {email : Officer email address} {--force : Reset without confirmation}')]
#[Description('Clear an officer MFA enrolment, revoke all sessions, and require setup at next login')]
class ResetMfaCommand extends Command
{
    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user === null) {
            $this->components->error('No account has that email address.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Reset MFA and revoke every session for {$user->email}?")) {
            return self::INVALID;
        }

        DB::transaction(function () use ($user): void {
            $revoked = $user->tokens()->count();
            $user->forceFill([
                'mfa_secret' => null,
                'mfa_enabled_at' => null,
                'mfa_recovery_codes' => null,
                'mfa_required' => true,
            ])->save();
            $user->tokens()->delete();

            AuditLog::create([
                'actor_id' => null,
                'institution_id' => $user->institution_id,
                'event' => 'user.mfa.cli_reset',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'new_values' => ['sessions_revoked' => $revoked, 'reset_via' => 'break_glass_cli'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'SAFERNET Artisan CLI',
            ]);
        });

        $this->components->info("MFA reset for {$user->email}. Setup is required at the next login.");

        return self::SUCCESS;
    }
}
