<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('safernet:issue-service-token {institution : Institution NEMIS code} {--name=filtering-gateway : Token name}')]
#[Description('Create an institution-bound machine identity and issue its telemetry token')]
class IssueServiceTokenCommand extends Command
{
    public function handle(): int
    {
        $institution = Institution::query()
            ->where('nemis_code', $this->argument('institution'))
            ->first();

        if ($institution === null) {
            $this->components->error('No institution has that NEMIS code.');

            return self::FAILURE;
        }

        $token = DB::transaction(function () use ($institution): string {
            $identity = User::create([
                'subcounty_id' => $institution->subcounty_id,
                'institution_id' => $institution->id,
                'name' => $this->option('name').' · '.$institution->name,
                'email' => 'service+'.Str::uuid().'@safernet.internal',
                'password' => Str::password(64),
                'role' => UserRole::Service,
                'status' => 'active',
            ]);

            return $identity->createToken((string) $this->option('name'), ['telemetry:write'])->plainTextToken;
        });

        $this->components->info('Service token issued for '.$institution->name.'.');
        $this->line($token);
        $this->components->warn('Store this token securely; it will not be shown again.');

        return self::SUCCESS;
    }
}
