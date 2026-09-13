<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProtectionComponentRequest;
use App\Http\Resources\DomainResource;
use App\Models\Device;
use App\Models\ProtectionComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProtectionComponentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return DomainResource::collection(ProtectionComponent::query()->visibleTo($request->user())->latest('last_seen_at')->paginate());
    }

    public function store(StoreProtectionComponentRequest $request): DomainResource
    {
        $data = $request->validated();
        $data['institution_id'] = $request->user()->institution_id;
        if (isset($data['device_id'])) {
            abort_unless(Device::query()->whereKey($data['device_id'])->exists(), 404);
        }
        $data['last_seen_at'] = now();
        $component = ProtectionComponent::query()->updateOrCreate(
            ['institution_id' => $data['institution_id'], 'type' => $data['type'], 'identifier' => $data['identifier']],
            $data,
        );

        return new DomainResource($component);
    }
}
