<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateIncidentRequest;
use App\Http\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class IncidentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Incident::query()->visibleTo($request->user())->with(['learner:id,first_name,last_name,learner_number', 'device:id,public_id,asset_tag', 'category:id,name', 'institution:id,name'])->latest('last_detected_at')->latest('id');
        $query->when($request->filled('institution_id'), fn ($q) => $q->where('institution_id', $request->integer('institution_id')));
        $query->when($request->filled('subcounty_id'), fn ($q) => $q->whereHas('institution', fn ($institutions) => $institutions->where('subcounty_id', $request->integer('subcounty_id'))));
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        $query->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')));

        return IncidentResource::collection($query->paginate()->withQueryString());
    }

    public function show(Incident $incident): IncidentResource
    {
        Gate::authorize('view', $incident);

        return new IncidentResource($incident->load(['learner', 'device', 'category', 'institution:id,name', 'webEvents', 'actions.actor:id,name']));
    }

    public function update(UpdateIncidentRequest $request, Incident $incident): IncidentResource
    {
        Gate::authorize('update', $incident);
        $data = $request->validated();
        if (isset($data['assigned_to'])) {
            abort_unless(User::query()->whereKey($data['assigned_to'])->where('institution_id', $incident->institution_id)->exists(), 404);
        }
        if (($data['status'] ?? null) === 'resolved') {
            $data['resolved_by'] = $request->user()->id;
            $data['resolved_at'] = now();
        }
        $incident->update($data);

        return new IncidentResource($incident->fresh());
    }
}
