<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\AuthenticationContextController;
use App\Http\Controllers\Api\V1\Auth\ChangeTemporaryPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MfaChallengeController;
use App\Http\Controllers\Api\V1\Auth\MfaSetupController;
use App\Http\Controllers\Api\V1\BlocklistSourceController;
use App\Http\Controllers\Api\V1\ClassroomLiveController;
use App\Http\Controllers\Api\V1\ContentCategoryController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeviceAssignmentController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DeviceGroupController;
use App\Http\Controllers\Api\V1\DomainReviewController;
use App\Http\Controllers\Api\V1\ExceptionRequestController;
use App\Http\Controllers\Api\V1\ExceptionRequestReviewController;
use App\Http\Controllers\Api\V1\ExtensionController;
use App\Http\Controllers\Api\V1\FilteringAssessmentController;
use App\Http\Controllers\Api\V1\FilteringPolicyController;
use App\Http\Controllers\Api\V1\IncidentActionController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\IncidentPdfReportController;
use App\Http\Controllers\Api\V1\InstitutionController;
use App\Http\Controllers\Api\V1\InstitutionReviewController;
use App\Http\Controllers\Api\V1\IntegrationStatusController;
use App\Http\Controllers\Api\V1\LaboratoryController;
use App\Http\Controllers\Api\V1\LearnerController;
use App\Http\Controllers\Api\V1\LearnerGroupController;
use App\Http\Controllers\Api\V1\LearnerSessionController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationDeliveryController;
use App\Http\Controllers\Api\V1\PolicyRuleController;
use App\Http\Controllers\Api\V1\ProtectionComponentController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SecurityEventController;
use App\Http\Controllers\Api\V1\SubcountyController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WebEventController;
use App\Http\Controllers\Api\V1\WorkstationSessionController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('auth/login', LoginController::class)->middleware('throttle:login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'account.active', 'tenant'])->group(function (): void {
        Route::get('auth/context', AuthenticationContextController::class)->name('auth.context');
        Route::delete('auth/logout', LogoutController::class)->name('auth.logout');

        Route::post('auth/onboarding/password', ChangeTemporaryPasswordController::class)
            ->middleware(['abilities:onboarding:password', 'throttle:mfa'])
            ->name('auth.onboarding.password');
        Route::post('auth/onboarding/mfa/setup', [MfaSetupController::class, 'store'])
            ->middleware(['abilities:onboarding:mfa', 'throttle:mfa'])
            ->name('auth.onboarding.mfa.setup');
        Route::post('auth/onboarding/mfa/confirm', [MfaSetupController::class, 'confirm'])
            ->middleware(['abilities:onboarding:mfa', 'throttle:mfa'])
            ->name('auth.onboarding.mfa.confirm');
        Route::post('auth/mfa/verify', MfaChallengeController::class)
            ->middleware(['abilities:mfa:verify', 'throttle:mfa'])
            ->name('auth.mfa.verify');

        /*
         | Machine-to-machine telemetry. Protection agents hold a service token
         | limited to the telemetry ability and may only report for a school
         | that is operational on the platform.
         */
        Route::middleware(['abilities:telemetry:write', 'institution.approved', 'throttle:telemetry'])
            ->group(function (): void {
                Route::get('extension/sync', [ExtensionController::class, 'sync'])->name('extension.sync');
                Route::get('agent/policy', [ExtensionController::class, 'agentPolicy'])->name('agent.policy');
                Route::get('agent/resolve-device', [DeviceController::class, 'resolve'])->name('agent.resolve-device');
                Route::get('extension/commands', [ExtensionController::class, 'commands'])->name('extension.commands');
                Route::get('extension/session', [ExtensionController::class, 'session'])->name('extension.session');

                /*
                 | Learner sign-in at the workstation. Throttled far harder than
                 | the rest of this group: a learner PIN is short, so the number
                 | of guesses allowed is the only thing protecting it.
                 */
                Route::post('extension/sign-in', [WorkstationSessionController::class, 'signIn'])
                    ->middleware('throttle:workstation-signin')
                    ->name('extension.sign-in');
                Route::post('extension/sign-out', [WorkstationSessionController::class, 'signOut'])
                    ->name('extension.sign-out');
                Route::post('extension/heartbeat', [ExtensionController::class, 'heartbeat'])->name('extension.heartbeat');
                Route::post('extension/exception-requests', [ExtensionController::class, 'storeExceptionRequest'])
                    ->name('extension.exception-requests.store');
                Route::post('web-events', [WebEventController::class, 'store'])->name('web-events.store');
                Route::post('security-events', [SecurityEventController::class, 'store'])->name('security-events.store');
                Route::post('protection-components', [ProtectionComponentController::class, 'store'])
                    ->name('protection-components.store');
                Route::post('filtering/assess', FilteringAssessmentController::class)->name('filtering.assess');
            });

        /*
         | Officer portal. Every mutation below is written to the audit log by
         | the audit.api middleware; per-record authorisation stays with the
         | policies invoked in the controllers.
         */
        Route::middleware(['abilities:portal:access', 'throttle:api', 'audit.api'])->group(function (): void {
            Route::get('me', fn (Request $request) => new UserResource(
                $request->user()->loadMissing(['subcounty', 'institution'])
            ))->name('me');
            Route::get('auth/sessions', [AuthenticatedSessionController::class, 'index'])->name('auth.sessions.index');
            Route::delete('auth/sessions/others', [AuthenticatedSessionController::class, 'destroyOthers'])
                ->name('auth.sessions.destroy-others');
            Route::delete('auth/sessions/{token}', [AuthenticatedSessionController::class, 'destroy'])
                ->whereNumber('token')
                ->name('auth.sessions.destroy');
            Route::get('dashboard', DashboardController::class)->name('dashboard');
            Route::get('reports/protection-summary', [ReportController::class, 'summary'])->name('reports.protection-summary');
            Route::get('reports/incident-trend', [ReportController::class, 'incidentTrend'])->name('reports.incident-trend');

            Route::apiResource('content-categories', ContentCategoryController::class)->only(['index', 'show']);

            Route::apiResource('subcounties', SubcountyController::class)->only(['index', 'store', 'show']);
            Route::apiResource('institutions', InstitutionController::class)->only(['index', 'store', 'show']);
            Route::post('institutions/{institution}/reviews', InstitutionReviewController::class)
                ->name('institutions.reviews.store');

            Route::apiResource('users', UserController::class);

            /*
             | Institution-scoped registers. These are only writable while the
             | school itself is operational on the platform.
             */
            Route::middleware('institution.approved')->group(function (): void {
                Route::apiResource('learner-groups', LearnerGroupController::class);
                Route::apiResource('learners', LearnerController::class);
                Route::apiResource('laboratories', LaboratoryController::class);
                Route::apiResource('device-groups', DeviceGroupController::class);
                Route::apiResource('devices', DeviceController::class);

                Route::post('devices/{device}/assignments', [DeviceAssignmentController::class, 'store'])
                    ->name('devices.assignments.store');
                Route::delete('devices/{device}/assignments/{assignment}', [DeviceAssignmentController::class, 'destroy'])
                    ->name('devices.assignments.destroy');
                Route::post('devices/{device}/sessions', [LearnerSessionController::class, 'store'])
                    ->name('devices.sessions.store');
                Route::delete('learner-sessions/{learnerSession}', [LearnerSessionController::class, 'destroy'])
                    ->name('learner-sessions.destroy');

                Route::middleware('role:hoi,clm')->group(function (): void {
                    Route::get('classrooms/live', [ClassroomLiveController::class, 'index'])->name('classrooms.live');
                    // Learner browsing history. Recorded since day one, readable now.
                    Route::get('web-events', [WebEventController::class, 'index'])->name('web-events.index');
                    Route::post('classrooms/push-url', [ClassroomLiveController::class, 'pushUrl'])->name('classrooms.push-url');
                    Route::post('classrooms/nudge', [ClassroomLiveController::class, 'nudge'])->name('classrooms.nudge');
                    Route::post('classrooms/focus-mode', [ClassroomLiveController::class, 'focusMode'])->name('classrooms.focus-mode');
                });
            });

            Route::apiResource('filtering-policies', FilteringPolicyController::class);
            Route::apiResource('filtering-policies.rules', PolicyRuleController::class)
                ->parameters(['filtering-policies' => 'filteringPolicy', 'rules' => 'policyRule']);

            Route::apiResource('incidents', IncidentController::class)->only(['index', 'show', 'update']);
            Route::get('incidents/{incident}/report', [IncidentPdfReportController::class, 'show'])
                ->name('incidents.report');
            Route::post('incidents/{incident}/actions', [IncidentActionController::class, 'store'])
                ->name('incidents.actions.store');

            Route::apiResource('exception-requests', ExceptionRequestController::class)->only(['index', 'store', 'show']);
            Route::post('exception-requests/{exceptionRequest}/reviews', ExceptionRequestReviewController::class)
                ->name('exception-requests.reviews.store');

            Route::get('protection-components', [ProtectionComponentController::class, 'index'])
                ->name('protection-components.index');
            Route::get('security-events', [SecurityEventController::class, 'index'])->name('security-events.index');

            Route::apiResource('notifications', NotificationController::class)->only(['index', 'update', 'destroy']);

            Route::get('blocklist-sources', [BlocklistSourceController::class, 'index'])->name('blocklist-sources.index');
            Route::put('blocklist-sources/{blocklistSource}', [BlocklistSourceController::class, 'update'])
                ->name('blocklist-sources.update');
            Route::post('blocklist-sources/{blocklistSource}/sync', [BlocklistSourceController::class, 'sync'])
                ->name('blocklist-sources.sync');

            Route::middleware('role:cde,scde,hoi')->group(function (): void {
                Route::get('integrations', IntegrationStatusController::class)->name('integrations.index');
                Route::get('notification-deliveries', [NotificationDeliveryController::class, 'index'])
                    ->name('notification-deliveries.index');
            });

            /*
             | Domains learners reached that no blocklist covers. A decision here
             | is county-wide rather than a school's own, so these sit outside the
             | institution-scoped registers entirely: a director has no
             | institution to be scoped to. The controller admits directors only.
             */
            Route::get('domain-reviews', [DomainReviewController::class, 'index'])->name('domain-reviews.index');
            Route::put('domain-reviews/{domainReview}', [DomainReviewController::class, 'update'])
                ->name('domain-reviews.update');

            Route::middleware('role:cde,scde,hoi')->group(function (): void {
                Route::apiResource('audit-logs', AuditLogController::class)->only(['index', 'show']);
            });
        });
    });
});
