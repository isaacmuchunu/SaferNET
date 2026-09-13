<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InstitutionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewInstitutionRequest;
use App\Http\Resources\InstitutionResource;
use App\Models\AuditLog;
use App\Models\Institution;
use Illuminate\Validation\ValidationException;

class InstitutionReviewController extends Controller
{
    public function __invoke(ReviewInstitutionRequest $request, Institution $institution): InstitutionResource
    {
        if ($institution->status !== InstitutionStatus::PendingApproval) {
            throw ValidationException::withMessages([
                'decision' => 'Only institutions pending CDE approval may be reviewed.',
            ]);
        }

        $previousStatus = $institution->status->value;
        $status = match ($request->string('decision')->toString()) {
            'approve' => InstitutionStatus::Approved,
            'return' => InstitutionStatus::Draft,
            'reject' => InstitutionStatus::Rejected,
        };

        $institution->update([
            'status' => $status,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $request->input('notes'),
        ]);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'institution_id' => $institution->id,
            'event' => 'institution.reviewed',
            'auditable_type' => Institution::class,
            'auditable_id' => $institution->id,
            'old_values' => ['status' => $previousStatus],
            'new_values' => ['status' => $status->value, 'decision' => $request->string('decision')->toString()],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return new InstitutionResource($institution->fresh()->load('subcounty'));
    }
}
