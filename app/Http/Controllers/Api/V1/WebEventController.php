<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Filtering\RecordWebEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWebEventRequest;
use App\Http\Resources\WebEventResource;

class WebEventController extends Controller
{
    public function store(StoreWebEventRequest $request, RecordWebEvent $recordWebEvent): WebEventResource
    {
        return new WebEventResource($recordWebEvent->handle($request->validated(), $request->user()));
    }
}
