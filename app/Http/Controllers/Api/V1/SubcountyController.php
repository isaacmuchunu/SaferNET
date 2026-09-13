<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InstitutionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSubcountyRequest;
use App\Http\Resources\SubcountyResource;
use App\Models\Subcounty;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SubcountyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Subcounty::query()
            ->visibleTo($request->user())
            ->withCount($this->aggregates($request->user()))
            ->orderBy('name');

        return SubcountyResource::collection($query->paginate()->withQueryString());
    }

    public function store(StoreSubcountyRequest $request): SubcountyResource
    {
        return new SubcountyResource(Subcounty::create($request->validated()));
    }

    public function show(Request $request, Subcounty $subcounty): SubcountyResource
    {
        Gate::authorize('view', $subcounty);

        return new SubcountyResource($subcounty->loadCount($this->aggregates($request->user())));
    }

    /**
     * Protection figures for the county overview, each constrained to what the
     * requesting officer may see.
     *
     * @return array<string, callable(Builder): Builder>
     */
    private function aggregates(User $user): array
    {
        $visibleInstitutions = fn (Builder $institutions) => $institutions->visibleTo($user);

        return [
            'institutions' => $visibleInstitutions,
            'institutions as protected_institutions_count' => fn (Builder $institutions) => $institutions
                ->visibleTo($user)
                ->where('status', InstitutionStatus::Protected),
            'learners' => fn (Builder $learners) => $learners->visibleTo($user),
            'devices' => fn (Builder $devices) => $devices->visibleTo($user),
            'devices as unattributed_devices_count' => fn (Builder $devices) => $devices
                ->visibleTo($user)
                ->whereDoesntHave('activeAssignments'),
            'incidents as open_incidents_count' => fn (Builder $incidents) => $incidents
                ->visibleTo($user)
                ->whereIn('incidents.status', ['open', 'under_review']),
        ];
    }
}
