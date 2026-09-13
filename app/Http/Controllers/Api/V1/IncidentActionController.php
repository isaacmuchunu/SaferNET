<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreIncidentActionRequest;
use App\Http\Resources\DomainResource;
use App\Models\Incident;
use Illuminate\Support\Facades\Gate;

class IncidentActionController extends Controller
{
    public function store(StoreIncidentActionRequest $request, Incident $incident): DomainResource
    {
        Gate::authorize('update', $incident);
        $action = $incident->actions()->create($request->validated() + ['institution_id' => $incident->institution_id, 'actor_id' => $request->user()->id]);
        if (in_array($request->string('action')->toString(), ['resolved', 'dismissed'], true)) {
            $incident->update(['status' => $request->string('action'), 'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'resolution_summary' => $request->input('notes')]);
        }

        return new DomainResource($action);
    }
}
