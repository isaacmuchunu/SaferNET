<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveLaboratoryRequest;
use App\Http\Resources\DomainResource;
use App\Models\Laboratory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class LaboratoryController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Laboratory::query()
            ->visibleTo($request->user())
            ->withCount('devices')
            ->when($request->filled('institution_id'), fn ($laboratories) => $laboratories->where('institution_id', $request->integer('institution_id')))
            ->when($request->filled('subcounty_id'), fn ($records) => $records->whereHas('institution', fn ($institutions) => $institutions->where('subcounty_id', $request->integer('subcounty_id'))))
            ->orderBy('name');

        return DomainResource::collection($query->paginate()->withQueryString());
    }

    public function store(SaveLaboratoryRequest $request): DomainResource
    {
        Gate::authorize('create', Laboratory::class);
        $data = $request->safe()->except('institution_id');
        $data['institution_id'] = $this->tenants->mutationInstitutionId($request->integer('institution_id') ?: null);

        return new DomainResource(Laboratory::create($data));
    }

    public function show(Laboratory $laboratory): DomainResource
    {
        Gate::authorize('view', $laboratory);

        return new DomainResource($laboratory->loadCount('devices'));
    }

    public function update(SaveLaboratoryRequest $request, Laboratory $laboratory): DomainResource
    {
        Gate::authorize('update', $laboratory);
        $laboratory->update($request->safe()->except('institution_id'));

        return new DomainResource($laboratory->fresh());
    }

    public function destroy(Laboratory $laboratory): Response
    {
        Gate::authorize('delete', $laboratory);
        $laboratory->delete();

        return response()->noContent();
    }
}
