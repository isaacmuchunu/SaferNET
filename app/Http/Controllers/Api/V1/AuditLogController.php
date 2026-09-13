<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AuditLog::query()->visibleTo($request->user())->latest('created_at')->latest('id');
        $query->when($request->filled('event'), fn ($q) => $q->where('event', $request->string('event')));

        return DomainResource::collection($query->paginate());
    }

    public function show(AuditLog $auditLog): DomainResource
    {
        Gate::authorize('view', $auditLog);

        return new DomainResource($auditLog);
    }
}
