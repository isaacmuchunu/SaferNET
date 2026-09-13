<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InstitutionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreInstitutionRequest;
use App\Http\Resources\InstitutionResource;
use App\Models\Institution;
use App\Services\Officers\OfficerProvisioner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InstitutionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            // One lifecycle state, or a comma-separated band of them.
            'status' => ['sometimes', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                foreach (explode(',', (string) $value) as $status) {
                    if (InstitutionStatus::tryFrom(trim($status)) === null) {
                        $fail('The selected status is not a recognised institution state.');
                    }
                }
            }],
            'subcounty_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        $statuses = collect(explode(',', $filters['status'] ?? ''))
            ->map(fn (string $status): string => trim($status))
            ->filter();

        $query = Institution::query()
            ->visibleTo($request->user())
            ->with('subcounty:id,name,code')
            ->withCount(['learners', 'devices'])
            ->when($statuses->isNotEmpty(), fn ($institutions) => $institutions->whereIn('status', $statuses))
            ->when($filters['subcounty_id'] ?? null, fn ($institutions, $subcountyId) => $institutions->where('subcounty_id', $subcountyId))
            ->when($filters['search'] ?? null, fn ($institutions, $search) => $institutions->where(
                fn ($matches) => $matches
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('nemis_code', 'ilike', "%{$search}%")
            ))
            ->latest('id');

        return InstitutionResource::collection($query->paginate()->withQueryString());
    }

    public function store(StoreInstitutionRequest $request, OfficerProvisioner $provisioner): InstitutionResource
    {
        $user = $request->user();
        $data = $request->safe()->except('status');
        $data['submitted_by'] = $user->id;
        $data['status'] = InstitutionStatus::PendingApproval;
        $data['submitted_at'] = now();

        if ($user->hasRole(UserRole::Scde) && $user->subcounty_id !== $request->integer('subcounty_id')) {
            abort(403, 'SCDE users may only register schools in their assigned subcounty.');
        }

        $institution = DB::transaction(function () use ($data, $provisioner): Institution {
            $institution = Institution::create($data);

            $provisioner->create([
                'subcounty_id' => $institution->subcounty_id,
                'institution_id' => $institution->getKey(),
                'name' => $institution->hoi_name,
                'email' => $institution->hoi_email,
                'phone' => $institution->hoi_phone,
                'role' => UserRole::Hoi,
                'status' => 'active',
            ]);

            return $institution;
        });

        return new InstitutionResource($institution->load('subcounty'));
    }

    public function show(Institution $institution): InstitutionResource
    {
        Gate::authorize('view', $institution);

        return new InstitutionResource($institution->load('subcounty')->loadCount(['learners', 'devices']));
    }
}
