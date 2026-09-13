<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['blocklist_source_id', 'domain'])]
class BlockedDomain extends Model
{
    public function source(): BelongsTo
    {
        return $this->belongsTo(BlocklistSource::class, 'blocklist_source_id');
    }
}
