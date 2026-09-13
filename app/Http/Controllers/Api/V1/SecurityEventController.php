<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSecurityEventRequest;
use App\Http\Resources\DomainResource;
use App\Models\Device;
use App\Models\Incident;
use App\Models\Learner;
use App\Models\LearnerSession;
use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SecurityEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SecurityEvent::query()->visibleTo($request->user())->latest('occurred_at')->latest('id');
        $query->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')));

        return DomainResource::collection($query->paginate());
    }

    public function store(StoreSecurityEventRequest $request): DomainResource
    {
        $data = $request->validated();
        $data['institution_id'] = $request->user()->institution_id;
        $this->assertScoped(Device::class, $data['device_id']);
        foreach (['learner_session_id' => LearnerSession::class, 'learner_id' => Learner::class, 'incident_id' => Incident::class] as $field => $model) {
            if (isset($data[$field])) {
                $this->assertScoped($model, $data[$field]);
            }
        }
        $event = SecurityEvent::query()->firstOrCreate(['event_uuid' => $data['event_uuid']], $data);

        return new DomainResource($event);
    }

    /** @param class-string<Model> $model */
    private function assertScoped(string $model, int $id): void
    {
        abort_unless($model::query()->whereKey($id)->exists(), 404);
    }
}
