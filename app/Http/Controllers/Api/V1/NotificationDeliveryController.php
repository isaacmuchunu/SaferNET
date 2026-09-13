<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Models\NotificationDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The record of what SAFERNET actually sent, and what the provider said. */
class NotificationDeliveryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = NotificationDelivery::query()
            ->visibleTo($request->user())
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('institution_id'), fn ($q) => $q->where('institution_id', $request->integer('institution_id')))
            ->latest('id');

        return DomainResource::collection($query->paginate()->withQueryString());
    }
}
