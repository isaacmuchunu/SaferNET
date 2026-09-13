<?php

namespace App\Console\Commands;

use App\Models\ProtectionComponent;
use Illuminate\Console\Command;

/**
 * Writes silence back into the stored health of a protection component.
 *
 * The console derives freshness when it reads, so this is not what makes the
 * dashboard correct. It exists so the stored column agrees with what is shown:
 * reports, exports and anything querying `health_status` directly should not
 * keep counting a device that stopped checking in days ago as healthy.
 */
class ExpireStaleComponentsCommand extends Command
{
    protected $signature = 'safernet:expire-stale-components';

    protected $description = 'Downgrade the stored health of protection components that have stopped checking in';

    public function handle(): int
    {
        $changed = 0;

        ProtectionComponent::query()
            ->withoutGlobalScopes()
            ->stale()
            ->whereIn('health_status', [ProtectionComponent::Healthy, ProtectionComponent::Degraded])
            ->chunkById(500, function ($components) use (&$changed): void {
                foreach ($components as $component) {
                    $effective = $component->effectiveHealthStatus();

                    if ($effective === $component->health_status) {
                        continue;
                    }

                    $component->update(['health_status' => $effective]);
                    $changed++;
                }
            });

        $this->info("Downgraded {$changed} component(s) that have stopped checking in.");

        return self::SUCCESS;
    }
}
