<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewExceptionRequestRequest;
use App\Http\Resources\DomainResource;
use App\Models\ExceptionRequest;
use Illuminate\Validation\ValidationException;

class ExceptionRequestReviewController extends Controller
{
    public function __invoke(ReviewExceptionRequestRequest $request, ExceptionRequest $exceptionRequest): DomainResource
    {
        $reviewer = $request->user();

        // County and sub-county directors review anywhere in their scope; a Head
        // of Institution approves school-level exceptions for their own school.
        abort_unless(
            $reviewer->hasRole(UserRole::Cde, UserRole::Scde)
                || ($reviewer->hasRole(UserRole::Hoi) && $reviewer->institution_id === $exceptionRequest->institution_id),
            403,
        );

        if ($exceptionRequest->status !== 'pending') {
            throw ValidationException::withMessages(['decision' => 'Only pending exception requests may be reviewed.']);
        }
        $exceptionRequest->update(['status' => $request->string('decision'), 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_notes' => $request->input('review_notes'), 'expires_at' => $request->input('expires_at', $exceptionRequest->expires_at)]);

        return new DomainResource($exceptionRequest->fresh());
    }
}
