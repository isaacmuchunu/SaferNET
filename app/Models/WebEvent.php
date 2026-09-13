<?php

namespace App\Models;

use App\Enums\EnforcementAction;
use App\Enums\RequestKind;
use App\Enums\Severity;
use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\WebEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['event_uuid', 'institution_id', 'learner_session_id', 'learner_id', 'device_id', 'filtering_policy_id', 'policy_rule_id', 'content_category_id', 'incident_id', 'url', 'domain', 'request_kind', 'action', 'enforcement_source', 'severity', 'reason', 'occurred_at', 'metadata'])]
class WebEvent extends Model
{
    /** @use HasFactory<WebEventFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    public function learnerSession(): BelongsTo
    {
        return $this->belongsTo(LearnerSession::class);
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'content_category_id');
    }

    protected function casts(): array
    {
        return [
            'request_kind' => RequestKind::class,
            'action' => EnforcementAction::class,
            'severity' => Severity::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
