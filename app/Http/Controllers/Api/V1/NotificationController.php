<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return DomainResource::collection($request->user()->notifications()->latest()->paginate());
    }

    public function update(Request $request, DatabaseNotification $notification): DomainResource
    {
        abort_unless($notification->notifiable_type === $request->user()->getMorphClass() && (int) $notification->notifiable_id === $request->user()->id, 404);
        $notification->markAsRead();

        return new DomainResource($notification->fresh());
    }

    public function destroy(Request $request, DatabaseNotification $notification): Response
    {
        abort_unless($notification->notifiable_type === $request->user()->getMorphClass() && (int) $notification->notifiable_id === $request->user()->id, 404);
        $notification->delete();

        return response()->noContent();
    }
}
