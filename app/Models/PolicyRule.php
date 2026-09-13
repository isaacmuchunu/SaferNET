<?php

namespace App\Models;

use App\Enums\EnforcementAction;
use App\Enums\Severity;
use App\Support\Tenancy\TenantContext;
use Database\Factories\PolicyRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['filtering_policy_id', 'content_category_id', 'action', 'severity', 'is_locked', 'counts_toward_incidents', 'threshold_count', 'threshold_window_minutes', 'notify_immediately'])]
class PolicyRule extends Model
{
    /** @use HasFactory<PolicyRuleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $tenantContext = app(TenantContext::class);

            if ($tenantContext->resolved()) {
                $query->visibleTo($tenantContext->principal());
            }
        });
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(FilteringPolicy::class, 'filtering_policy_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'content_category_id');
    }

    public function scopeVisibleTo(Builder $query, User $principal): Builder
    {
        return $query->whereHas('policy', fn (Builder $policy) => $policy->visibleTo($principal));
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $principal = request()->user();

        return $principal instanceof User
            ? $query->visibleTo($principal)
            : $query->whereRaw('1 = 0');
    }

    protected function casts(): array
    {
        return [
            'action' => EnforcementAction::class,
            'severity' => Severity::class,
            'is_locked' => 'boolean',
            'counts_toward_incidents' => 'boolean',
            'notify_immediately' => 'boolean',
        ];
    }
}
