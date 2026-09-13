<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveDeviceGroupRequest;
use App\Http\Resources\DomainResource;
use App\Models\DeviceGroup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DeviceGroupController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = DeviceGroup::query()
            ->visibleTo($request->user())
            ->withCount('devices')
            ->when($request->filled('institution_id'), fn ($groups) => $groups->where('institution_id', $request->integer('institution_id')))
            ->when($request->filled('subcounty_id'), fn ($records) => $records->whereHas('institution', fn ($institutions) => $institutions->where('subcounty_id', $request->integer('subcounty_id'))))
            ->orderBy('name');

        return DomainResource::collection($query->paginate()->withQueryString());
    }

    public function store(SaveDeviceGroupRequest $request): DomainResource
    {
        Gate::authorize('create', DeviceGroup::class);
        $data = $request->safe()->except('institution_id');
        $data['institution_id'] = $this->tenants->mutationInstitutionId($request->integer('institution_id') ?: null);

        return new DomainResource(DeviceGroup::create($data));
    }

    public function show(DeviceGroup $deviceGroup): DomainResource
    {
        Gate::authorize('view', $deviceGroup);

        return new DomainResource($deviceGroup->loadCount('devices'));
    }

    public function update(SaveDeviceGroupRequest $request, DeviceGroup $deviceGroup): DomainResource
    {
        Gate::authorize('update', $deviceGroup);
        $deviceGroup->update($request->safe()->except('institution_id'));

        return new DomainResource($deviceGroup->fresh());
    }

    public function destroy(DeviceGroup $deviceGroup): Response
    {
        Gate::authorize('delete', $deviceGroup);
        $deviceGroup->delete();

        return response()->noContent();
    }
}
