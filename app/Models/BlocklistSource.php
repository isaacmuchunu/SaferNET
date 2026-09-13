<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug', 'name', 'url', 'content_category_id', 'description', 'provenance',
    'is_enabled', 'domains_count', 'bytes_fetched', 'checksum', 'last_synced_at',
    'last_status', 'last_error',
])]
class BlocklistSource extends Model
{
    public function domains(): HasMany
    {
        return $this->hasMany(BlockedDomain::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ContentCategory::class, 'content_category_id');
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'last_synced_at' => 'datetime'];
    }
}
