<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Filtering\RecordWebEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWebEventRequest;
use App\Http\Resources\WebEventResource;
use App\Models\WebEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WebEventController extends Controller
{
    /**
     * A learner's browsing history, newest first.
     *
     * Every event has been recorded since the platform started ingesting them;
     * until now there was no way to read one back, so the history existed but
     * could not be used for the safeguarding conversation it is collected for.
     *
     * Scoped by the caller's tenancy like every other officer query, so a
     * school only ever sees its own learners.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'learner_id' => ['nullable', 'integer'],
            'learner_session_id' => ['nullable', 'integer'],
            'device_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'in:allow,block,restrict,warn'],
            'search' => ['nullable', 'string', 'max:253'],
            'since' => ['nullable', 'date'],
        ]);

        $events = WebEvent::query()
            ->visibleTo($request->user())
            ->with(['category:id,name', 'learner:id,first_name,last_name,learner_number'])
            ->when($request->filled('learner_id'), fn ($q) => $q->where('learner_id', $request->integer('learner_id')))
            ->when($request->filled('learner_session_id'), fn ($q) => $q->where('learner_session_id', $request->integer('learner_session_id')))
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('since'), fn ($q) => $q->where('occurred_at', '>=', $request->date('since')))
            ->when($request->filled('search'), fn ($q) => $q->where('domain', 'like', '%'.$request->string('search').'%'))
            ->latest('occurred_at')
            ->latest('id');

        return WebEventResource::collection($events->paginate()->withQueryString());
    }

    public function store(StoreWebEventRequest $request, RecordWebEvent $recordWebEvent): WebEventResource
    {
        return new WebEventResource($recordWebEvent->handle($request->validated(), $request->user()));
    }
}
