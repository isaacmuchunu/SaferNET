<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\LearnerSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['institution_id', 'device_id', 'learner_id', 'started_by', 'identity_source', 'started_at', 'last_activity_at', 'ended_at', 'end_reason', 'ip_address', 'user_agent'])]
class LearnerSession extends Model
{
    /** @use HasFactory<LearnerSessionFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (LearnerSession $session): void {
            $session->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    public function webEvents(): HasMany
    {
        return $this->hasMany(WebEvent::class);
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
