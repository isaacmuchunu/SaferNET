<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveLearnerGroupRequest;
use App\Http\Resources\DomainResource;
use App\Models\LearnerGroup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class LearnerGroupController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = LearnerGroup::query()
            ->visibleTo($request->user())
            ->withCount('learners')
            ->when($request->filled('institution_id'), fn ($groups) => $groups->where('institution_id', $request->integer('institution_id')))
            ->when($request->filled('subcounty_id'), fn ($records) => $records->whereHas('institution', fn ($institutions) => $institutions->where('subcounty_id', $request->integer('subcounty_id'))))
            ->orderBy('name');

        return DomainResource::collection($query->paginate()->withQueryString());
    }

    public function store(SaveLearnerGroupRequest $request): DomainResource
    {
        Gate::authorize('create', LearnerGroup::class);
        $data = $request->safe()->except('institution_id');
        $data['institution_id'] = $this->tenants->mutationInstitutionId($request->integer('institution_id') ?: null);

        return new DomainResource(LearnerGroup::create($data));
    }

    public function show(LearnerGroup $learnerGroup): DomainResource
    {
        Gate::authorize('view', $learnerGroup);

        return new DomainResource($learnerGroup->loadCount('learners'));
    }

    public function update(SaveLearnerGroupRequest $request, LearnerGroup $learnerGroup): DomainResource
    {
        Gate::authorize('update', $learnerGroup);
        $learnerGroup->update($request->safe()->except('institution_id'));

        return new DomainResource($learnerGroup->fresh());
    }

    public function destroy(LearnerGroup $learnerGroup): Response
    {
        Gate::authorize('delete', $learnerGroup);
        $learnerGroup->delete();

        return response()->noContent();
    }
}
