<?php

namespace App\Models;

use App\Enums\Severity;
use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['institution_id', 'learner_id', 'device_id', 'filtering_policy_id', 'policy_rule_id', 'content_category_id', 'type', 'severity', 'status', 'event_count', 'first_detected_at', 'last_detected_at', 'notified_at', 'assigned_to', 'resolved_by', 'resolved_at', 'resolution_summary'])]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Incident $incident): void {
            $incident->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'content_category_id');
    }

    public function webEvents(): HasMany
    {
        return $this->hasMany(WebEvent::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(IncidentAction::class);
    }

    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
