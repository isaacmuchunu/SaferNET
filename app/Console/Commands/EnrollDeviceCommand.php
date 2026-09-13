<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Institution;
use App\Models\Laboratory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('safernet:enroll-device {institution : Institution NEMIS code} {asset_tag : County or school asset tag} {--laboratory= : Laboratory name} {--hostname=} {--serial=} {--platform=windows} {--usage=learner}')]
#[Description('Enrol or update a managed device in one institution')]
class EnrollDeviceCommand extends Command
{
    public function handle(): int
    {
        $institution = Institution::withoutGlobalScopes()->where('nemis_code', $this->argument('institution'))->first();

        if ($institution === null) {
            $this->components->error('No institution has that NEMIS code.');

            return self::FAILURE;
        }

        $platform = (string) $this->option('platform');
        $usage = (string) $this->option('usage');
        if (! in_array($platform, ['windows', 'linux', 'chromeos', 'android', 'ios', 'macos'], true)
            || ! in_array($usage, ['learner', 'staff', 'shared'], true)) {
            $this->components->error('Invalid platform or usage type.');

            return self::INVALID;
        }

        $laboratory = null;
        if (filled($this->option('laboratory'))) {
            $laboratory = Laboratory::withoutGlobalScopes()
                ->where('institution_id', $institution->id)
                ->where('name', $this->option('laboratory'))
                ->first();
            if ($laboratory === null) {
                $this->components->error('That laboratory does not belong to the selected institution.');

                return self::FAILURE;
            }
        }

        $device = DB::transaction(function () use ($institution, $laboratory, $platform, $usage): Device {
            $device = Device::withoutGlobalScopes()->updateOrCreate(
                ['institution_id' => $institution->id, 'asset_tag' => trim((string) $this->argument('asset_tag'))],
                [
                    'laboratory_id' => $laboratory?->id,
                    'hostname' => $this->option('hostname') ?: null,
                    'serial_number' => $this->option('serial') ?: null,
                    'platform' => $platform,
                    'usage_type' => $usage,
                    'status' => 'active',
                ],
            );

            AuditLog::create([
                'actor_id' => null,
                'institution_id' => $institution->id,
                'event' => $device->wasRecentlyCreated ? 'device.enrolled.cli' : 'device.updated.cli',
                'auditable_type' => Device::class,
                'auditable_id' => $device->id,
                'new_values' => ['public_id' => $device->public_id, 'asset_tag' => $device->asset_tag],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'SAFERNET Artisan CLI',
            ]);

            return $device;
        });

        $this->components->info(($device->wasRecentlyCreated ? 'Enrolled ' : 'Updated ').$device->asset_tag.'.');
        $this->components->twoColumnDetail('Managed Device ID (int)', (string) $device->id);
        $this->components->twoColumnDetail('Device UUID', $device->public_id);
        $this->components->twoColumnDetail('Institution', $institution->name);

        return self::SUCCESS;
    }
}
