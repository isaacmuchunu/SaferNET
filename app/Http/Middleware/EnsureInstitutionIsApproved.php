<?php

namespace App\Http\Middleware;

use App\Enums\InstitutionStatus;
use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstitutionIsApproved
{
    /**
     * Statuses an institution may hold and still operate on the platform. A
     * school stays operational from county approval through to steady-state
     * protection; only unapproved, rejected and suspended schools are barred.
     *
     * @var list<InstitutionStatus>
     */
    private const OPERATIONAL_STATUSES = [
        InstitutionStatus::Approved,
        InstitutionStatus::Onboarding,
        InstitutionStatus::DeploymentInProgress,
        InstitutionStatus::AttributionRequired,
        InstitutionStatus::Protected,
        InstitutionStatus::AttentionRequired,
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! $user->hasRole(UserRole::Cde, UserRole::Scde)) {
            abort_unless(
                in_array($user->institution?->status, self::OPERATIONAL_STATUSES, true),
                403,
                'The institution is not approved for platform operations.',
            );
        }

        return $next($request);
    }
}
