<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\ProtectionComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * A deployed piece of enforcement — a gateway, an endpoint agent, a browser
 * extension — and what is actually known about it.
 *
 * Three different facts are kept apart on purpose, because conflating them is
 * how a console comes to report protection that is not happening:
 *
 *   `last_seen_at`      the component made contact;
 *   `policy_synced_at`  it last successfully installed a policy;
 *   `health_status`     what it reported about its own enforcement.
 *
 * Contact is not enforcement. A browser whose sync fails every time still
 * heartbeats, and a component that has stopped checking in altogether reports
 * nothing at all — so the status a component last claimed is only believed
 * while it is fresh. See {@see effectiveHealthStatus()}.
 */
#[Fillable(['institution_id', 'device_id', 'type', 'identifier', 'version', 'health_status', 'last_seen_at', 'policy_synced_at', 'metadata'])]
class ProtectionComponent extends Model
{
    /** @use HasFactory<ProtectionComponentFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    public const Healthy = 'healthy';

    public const Degraded = 'degraded';

    public const Offline = 'offline';

    public const Unknown = 'unknown';

    protected $appends = ['effective_health_status', 'is_stale'];

    /**
     * The status to believe, which is not always the one last reported.
     *
     * Silence outranks a stale claim of health: past the offline threshold the
     * component is offline whatever it last said, and past the stale threshold
     * it is at best degraded. A component enforcing a policy it has not
     * refreshed in too long is likewise degraded rather than healthy.
     */
    public function effectiveHealthStatus(): string
    {
        $reported = $this->health_status ?? self::Unknown;

        if ($this->last_seen_at === null) {
            return self::Unknown;
        }

        if ($this->last_seen_at->lt(now()->subMinutes((int) config('deployment.offline_after_minutes')))) {
            return self::Offline;
        }

        $stale = $this->last_seen_at->lt(now()->subMinutes((int) config('deployment.stale_after_minutes')))
            || $this->policy_synced_at === null
            || $this->policy_synced_at->lt(now()->subMinutes((int) config('deployment.policy_stale_after_minutes')));

        if ($stale && $reported === self::Healthy) {
            return self::Degraded;
        }

        return $reported;
    }

    /**
     * The same derivation in SQL, so an aggregate over thousands of rows agrees
     * with what a single row reports rather than being counted from the raw
     * column.
     */
    public static function healthExpression(string $alias = 'health_status'): Expression
    {
        $offline = now()->subMinutes((int) config('deployment.offline_after_minutes'));
        $stale = now()->subMinutes((int) config('deployment.stale_after_minutes'));
        $policyStale = now()->subMinutes((int) config('deployment.policy_stale_after_minutes'));

        return DB::raw(vsprintf(<<<'SQL'
            CASE
                WHEN last_seen_at IS NULL THEN '%s'
                WHEN last_seen_at < '%s' THEN '%s'
                WHEN health_status = '%s' AND (
                    last_seen_at < '%s'
                    OR policy_synced_at IS NULL
                    OR policy_synced_at < '%s'
                ) THEN '%s'
                ELSE health_status
            END AS %s
        SQL, [
            self::Unknown,
            $offline->toDateTimeString(), self::Offline,
            self::Healthy,
            $stale->toDateTimeString(),
            $policyStale->toDateTimeString(), self::Degraded,
            $alias,
        ]));
    }

    /** Components that have gone quiet for longer than the stale threshold. */
    public function scopeStale(Builder $query): Builder
    {
        return $query->where(
            'last_seen_at',
            '<',
            now()->subMinutes((int) config('deployment.stale_after_minutes')),
        );
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'policy_synced_at' => 'datetime', 'metadata' => 'array'];
    }

    protected function getEffectiveHealthStatusAttribute(): string
    {
        return $this->effectiveHealthStatus();
    }

    protected function getIsStaleAttribute(): bool
    {
        return $this->effectiveHealthStatus() !== ($this->health_status ?? self::Unknown);
    }
}
