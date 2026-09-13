<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Laboratory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Device::query()->visibleTo($request->user())->with(['activeAssignments.learner.learnerGroup', 'activeSessions.learner'])->latest('id');
        $query->when($request->filled('institution_id'), fn ($q) => $q->where('institution_id', $request->integer('institution_id')));
        $query->when($request->filled('subcounty_id'), fn ($q) => $q->whereHas('institution', fn ($institutions) => $institutions->where('subcounty_id', $request->integer('subcounty_id'))));
        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        $query->when($request->filled('laboratory_id'), fn ($q) => $q->where('laboratory_id', $request->integer('laboratory_id')));
        $query->when($request->filled('search'), function ($q) use ($request): void {
            $search = '%'.$request->string('search')->toString().'%';
            $q->where(fn ($matches) => $matches
                ->where('asset_tag', 'ilike', $search)
                ->orWhere('hostname', 'ilike', $search)
                ->orWhere('serial_number', 'ilike', $search));
        });

        return DeviceResource::collection($query->paginate()->withQueryString());
    }

    public function store(SaveDeviceRequest $request): DeviceResource
    {
        Gate::authorize('create', Device::class);
        $data = $request->safe()->except('institution_id');
        $data['institution_id'] = $this->tenants->mutationInstitutionId($request->integer('institution_id') ?: null);
        $this->validateParents($data, $data['institution_id']);

        return new DeviceResource(Device::create($data)->load(['activeAssignments.learner.learnerGroup', 'activeSessions.learner']));
    }

    public function show(Device $device): DeviceResource
    {
        Gate::authorize('view', $device);

        return new DeviceResource($device->load(['activeAssignments.learner.learnerGroup', 'activeSessions.learner']));
    }

    public function update(SaveDeviceRequest $request, Device $device): DeviceResource
    {
        Gate::authorize('update', $device);
        $data = $request->safe()->except('institution_id');
        $this->validateParents($data, $device->institution_id);
        $device->update($data);

        return new DeviceResource($device->fresh()->load(['activeAssignments.learner.learnerGroup', 'activeSessions.learner']));
    }

    public function destroy(Device $device): Response
    {
        Gate::authorize('delete', $device);
        $device->delete();

        return response()->noContent();
    }

    public function resolve(Request $request): JsonResponse
    {
        $identifier = trim((string) $request->query('identifier', ''));
        abort_if($identifier === '', 422, 'Identifier query parameter is required.');

        $institutionId = $request->user()->institution_id;
        $isUuid = Str::isUuid($identifier);

        $device = Device::withoutGlobalScopes()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->where(function ($query) use ($identifier, $isUuid) {
                $query->where('asset_tag', $identifier)
                    ->orWhere('hostname', $identifier)
                    ->orWhere('serial_number', $identifier);

                if ($isUuid) {
                    $query->orWhere('public_id', $identifier);
                }
            })
            ->first();

        abort_unless($device !== null, 404, 'Device not found for this institution.');

        return response()->json([
            'data' => [
                'id' => $device->id,
                'asset_tag' => $device->asset_tag,
                'public_id' => $device->public_id,
                'hostname' => $device->hostname,
            ],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function validateParents(array $data, int $institutionId): void
    {
        if (isset($data['laboratory_id'])) {
            abort_unless(Laboratory::query()->whereKey($data['laboratory_id'])->where('institution_id', $institutionId)->exists(), 404);
        }
        if (isset($data['device_group_id'])) {
            abort_unless(DeviceGroup::query()->whereKey($data['device_group_id'])->where('institution_id', $institutionId)->exists(), 404);
        }
    }
}
