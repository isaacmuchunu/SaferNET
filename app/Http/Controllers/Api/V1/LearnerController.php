<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveLearnerRequest;
use App\Http\Resources\DomainResource;
use App\Models\Learner;
use App\Models\LearnerGroup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

class LearnerController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Learner::query()->visibleTo($request->user())->with('learnerGroup:id,name')->orderBy('last_name')->orderBy('id');
        $query->when($request->filled('institution_id'), fn ($q) => $q->where('institution_id', $request->integer('institution_id')));
        $query->when($request->filled('subcounty_id'), fn ($q) => $q->whereHas('institution', fn ($institutions) => $institutions->where('subcounty_id', $request->integer('subcounty_id'))));
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        $query->when($request->filled('learner_group_id'), fn ($q) => $q->where('learner_group_id', $request->integer('learner_group_id')));
        $query->when($request->filled('search'), function ($q) use ($request): void {
            $search = '%'.$request->string('search')->toString().'%';
            $q->where(fn ($matches) => $matches
                ->where('first_name', 'ilike', $search)
                ->orWhere('last_name', 'ilike', $search)
                ->orWhere('learner_number', 'ilike', $search));
        });

        return DomainResource::collection($query->paginate()->withQueryString());
    }

    public function store(SaveLearnerRequest $request): DomainResource
    {
        Gate::authorize('create', Learner::class);
        $data = $request->safe()->except(['institution_id', 'pin']);
        $data['institution_id'] = $this->tenants->mutationInstitutionId($request->integer('institution_id') ?: null);
        $this->validateGroup($data['learner_group_id'] ?? null, $data['institution_id']);
        $data['pin_hash'] = $request->filled('pin') ? Hash::make($request->string('pin')) : null;

        return new DomainResource(Learner::create($data)->load('learnerGroup:id,name'));
    }

    public function show(Learner $learner): DomainResource
    {
        Gate::authorize('view', $learner);

        return new DomainResource($learner->load('learnerGroup:id,name'));
    }

    public function update(SaveLearnerRequest $request, Learner $learner): DomainResource
    {
        Gate::authorize('update', $learner);
        $data = $request->safe()->except(['institution_id', 'pin']);
        $this->validateGroup($data['learner_group_id'] ?? null, $learner->institution_id);
        if ($request->filled('pin')) {
            $data['pin_hash'] = Hash::make($request->string('pin'));
        }
        $learner->update($data);

        return new DomainResource($learner->fresh()->load('learnerGroup:id,name'));
    }

    public function destroy(Learner $learner): Response
    {
        Gate::authorize('delete', $learner);
        $learner->delete();

        return response()->noContent();
    }

    private function validateGroup(?int $groupId, int $institutionId): void
    {
        if ($groupId !== null) {
            abort_unless(LearnerGroup::query()->whereKey($groupId)->where('institution_id', $institutionId)->exists(), 404);
        }
    }
}
