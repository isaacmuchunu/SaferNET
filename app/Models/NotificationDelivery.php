<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'institution_id', 'user_id', 'channel', 'provider', 'recipient', 'subject', 'body',
    'status', 'provider_message_id', 'error', 'attempts', 'event', 'severity',
    'notifiable_subject_type', 'notifiable_subject_id',
])]
class NotificationDelivery extends Model
{
    use BelongsToInstitutionTenant;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
}
