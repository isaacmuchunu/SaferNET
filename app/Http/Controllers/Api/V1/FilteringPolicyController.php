<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveFilteringPolicyRequest;
use App\Http\Resources\DomainResource;
use App\Models\FilteringPolicy;
use App\Models\LearnerGroup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class FilteringPolicyController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return DomainResource::collection(FilteringPolicy::query()->visibleTo($request->user())->with(['rules.category'])->latest('id')->paginate());
    }

    public function store(SaveFilteringPolicyRequest $request): DomainResource
    {
        Gate::authorize('createPolicy', FilteringPolicy::class);
        $data = $request->validated();
        if ($request->user()->hasRole(UserRole::Cde) && $data['level'] === 'county') {
            $data['institution_id'] = $data['learner_group_id'] = null;
        } else {
            abort_if($data['level'] === 'county', 403);
            $data['institution_id'] = $this->tenants->mutationInstitutionId($request->integer('institution_id') ?: null);
            if ($data['level'] === 'institution') {
                $data['learner_group_id'] = null;
            }
            if ($data['level'] === 'group') {
                abort_unless(LearnerGroup::query()->whereKey($data['learner_group_id'] ?? null)->where('institution_id', $data['institution_id'])->exists(), 404);
            }
        }
        $data['created_by'] = $request->user()->id;

        return new DomainResource(FilteringPolicy::create($data)->load('rules.category'));
    }

    public function show(FilteringPolicy $filteringPolicy): DomainResource
    {
        Gate::authorize('view', $filteringPolicy);

        return new DomainResource($filteringPolicy->load('rules.category'));
    }

    public function update(SaveFilteringPolicyRequest $request, FilteringPolicy $filteringPolicy): DomainResource
    {
        Gate::authorize('update', $filteringPolicy);
        $filteringPolicy->update($request->safe()->except(['institution_id', 'learner_group_id', 'level', 'parent_id']));

        return new DomainResource($filteringPolicy->fresh()->load('rules.category'));
    }

    public function destroy(FilteringPolicy $filteringPolicy): Response
    {
        Gate::authorize('delete', $filteringPolicy);
        $filteringPolicy->delete();

        return response()->noContent();
    }
}
