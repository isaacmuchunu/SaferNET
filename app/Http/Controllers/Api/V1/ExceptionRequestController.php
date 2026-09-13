<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExceptionRequestRequest;
use App\Http\Resources\DomainResource;
use App\Models\ExceptionRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ExceptionRequestController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ExceptionRequest::query()
            ->visibleTo($request->user())
            ->when($request->filled('status'), fn ($requests) => $requests->where('status', $request->string('status')))
            ->when($request->filled('institution_id'), fn ($requests) => $requests->where('institution_id', $request->integer('institution_id')))
            ->when($request->filled('subcounty_id'), fn ($records) => $records->whereHas('institution', fn ($institutions) => $institutions->where('subcounty_id', $request->integer('subcounty_id'))))
            ->latest('id');

        return DomainResource::collection($query->paginate()->withQueryString());
    }

    public function store(StoreExceptionRequestRequest $request): DomainResource
    {
        Gate::authorize('create', ExceptionRequest::class);
        $data = $request->safe()->except('institution_id');
        $data['institution_id'] = $this->tenants->mutationInstitutionId($request->integer('institution_id') ?: null);
        $data['requested_by'] = $request->user()->id;
        $data['status'] = 'pending';

        return new DomainResource(ExceptionRequest::create($data));
    }

    public function show(ExceptionRequest $exceptionRequest): DomainResource
    {
        Gate::authorize('view', $exceptionRequest);

        return new DomainResource($exceptionRequest);
    }
}
