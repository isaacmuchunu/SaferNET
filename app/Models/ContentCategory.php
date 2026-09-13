<?php

namespace App\Models;

use App\Enums\Severity;
use Database\Factories\ContentCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'slug', 'default_severity', 'is_high_risk', 'counts_toward_incidents'])]
class ContentCategory extends Model
{
    /** @use HasFactory<ContentCategoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'default_severity' => Severity::class,
            'is_high_risk' => 'boolean',
            'counts_toward_incidents' => 'boolean',
        ];
    }
}
