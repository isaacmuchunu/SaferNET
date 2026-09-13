<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SavePolicyRuleRequest;
use App\Http\Resources\DomainResource;
use App\Models\FilteringPolicy;
use App\Models\PolicyRule;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PolicyRuleController extends Controller
{
    public function index(FilteringPolicy $filteringPolicy): AnonymousResourceCollection
    {
        Gate::authorize('view', $filteringPolicy);

        return DomainResource::collection($filteringPolicy->rules()->with('category')->orderBy('id')->paginate());
    }

    public function store(SavePolicyRuleRequest $request, FilteringPolicy $filteringPolicy): DomainResource
    {
        Gate::authorize('update', $filteringPolicy);
        $data = $request->validated();
        if (! $request->user()->hasRole(UserRole::Cde)) {
            $data['is_locked'] = false;
        }

        return new DomainResource($filteringPolicy->rules()->create($data)->load('category'));
    }

    public function show(FilteringPolicy $filteringPolicy, PolicyRule $policyRule): DomainResource
    {
        Gate::authorize('view', $policyRule);

        return new DomainResource($policyRule->load('category'));
    }

    public function update(SavePolicyRuleRequest $request, FilteringPolicy $filteringPolicy, PolicyRule $policyRule): DomainResource
    {
        Gate::authorize('update', $policyRule);
        abort_if($policyRule->is_locked && ! $request->user()->hasRole(UserRole::Cde), 403);
        $data = $request->validated();
        if (! $request->user()->hasRole(UserRole::Cde)) {
            unset($data['is_locked']);
        } $policyRule->update($data);

        return new DomainResource($policyRule->fresh()->load('category'));
    }

    public function destroy(FilteringPolicy $filteringPolicy, PolicyRule $policyRule): Response
    {
        Gate::authorize('delete', $policyRule);
        abort_if($policyRule->is_locked && ! request()->user()->hasRole(UserRole::Cde), 403);
        $policyRule->delete();

        return response()->noContent();
    }
}
