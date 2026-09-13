<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitutionTenant;
use Database\Factories\ExceptionRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['institution_id', 'requested_by', 'reviewed_by', 'content_category_id', 'domain', 'reason', 'status', 'expires_at', 'reviewed_at', 'review_notes'])]
class ExceptionRequest extends Model
{
    /** @use HasFactory<ExceptionRequestFactory> */
    use BelongsToInstitutionTenant, HasFactory;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }
}
