<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('safernet:reset-password {email? : Email address of the user to reset} {--password= : Explicit password to set} {--list : List accounts registered on the system} {--no-force-change : Do not require the user to change password at next login}')]
#[Description('Manual password reset for officers and administrators (CDE administrative CLI tool)')]
class ResetPasswordCommand extends Command
{
    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listUsers();
        }

        $email = $this->argument('email') ?: $this->ask('Enter the email address of the account to reset');

        if (blank($email)) {
            $this->components->error('Email address is required.');

            return self::FAILURE;
        }

        $user = User::withoutGlobalScopes()
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])
            ->first();

        if (! $user) {
            $this->components->error("No account found with email: {$email}");

            return self::FAILURE;
        }

        $explicitPassword = $this->option('password');
        $generated = false;

        if (filled($explicitPassword)) {
            if (strlen((string) $explicitPassword) < 10) {
                $this->components->error('Password must be at least 10 characters.');

                return self::FAILURE;
            }
            $password = $explicitPassword;
        } else {
            $password = Str::password(16);
            $generated = true;
        }

        $forceChange = ! $this->option('no-force-change');
        $oldMustChange = $user->must_change_password;

        $user->password = $password;
        $user->must_change_password = $forceChange;
        if ($forceChange) {
            $user->temporary_password_expires_at = now()->addDays(3);
        } else {
            $user->temporary_password_expires_at = null;
        }
        $user->save();

        // Invalidate any active access tokens
        $tokensCount = $user->tokens()->count();
        $user->tokens()->delete();

        // Audit the manual administrative password reset
        AuditLog::create([
            'actor_id' => null,
            'institution_id' => $user->institution_id,
            'event' => 'user.password.manual_reset',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => ['must_change_password' => $oldMustChange],
            'new_values' => [
                'must_change_password' => $forceChange,
                'tokens_revoked' => $tokensCount,
                'reset_via' => 'break_glass_cli',
            ],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'SaferNET CDE Administrative CLI',
        ]);

        $this->components->info("Password successfully reset for {$user->name} ({$user->email}).");
        $this->line('');
        $this->components->twoColumnDetail('User Name', $user->name);
        $this->components->twoColumnDetail('Email', $user->email);
        $this->components->twoColumnDetail('Role', strtoupper($user->role instanceof UserRole ? $user->role->value : (string) $user->role));
        $this->components->twoColumnDetail('Tokens Revoked', (string) $tokensCount);
        $this->components->twoColumnDetail('Must Change on Login', $forceChange ? 'Yes (expires in 72h)' : 'No');
        $this->components->twoColumnDetail('New Password', $password);
        $this->line('');

        if ($generated) {
            $this->components->warn('This temporary password was generated once and will not be displayed again. Transmit it securely to the officer.');
        }

        return self::SUCCESS;
    }

    private function listUsers(): int
    {
        $users = User::withoutGlobalScopes()
            ->with(['subcounty', 'institution'])
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $rows = $users->map(fn (User $user) => [
            $user->id,
            $user->name,
            $user->email,
            strtoupper($user->role instanceof UserRole ? $user->role->value : (string) $user->role),
            $user->status ?? 'active',
            $user->institution?->name ?? $user->subcounty?->name ?? 'County-wide (CDE)',
            $user->must_change_password ? 'Yes' : 'No',
        ])->toArray();

        $this->table(
            ['ID', 'Name', 'Email', 'Role', 'Status', 'Scope / Institution', 'Must Change Pwd'],
            $rows
        );

        return self::SUCCESS;
    }
}
