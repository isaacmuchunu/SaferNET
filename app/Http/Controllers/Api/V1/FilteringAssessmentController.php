<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AssessDomainRequest;
use App\Services\Filtering\DomainAdvisor;
use Illuminate\Http\JsonResponse;

/**
 * Advice for a gateway or endpoint agent about a domain it does not recognise.
 *
 * The answer is advisory: the device still applies the county policy it holds.
 * What this adds is the county blocklists and, where they are silent, the
 * classifier.
 */
class FilteringAssessmentController extends Controller
{
    public function __invoke(AssessDomainRequest $request, DomainAdvisor $advisor): JsonResponse
    {
        $assessment = $advisor->assess(
            $request->string('url')->toString(),
            $request->user()->institution_id,
            $request->string('query')->toString(),
        );

        return response()->json(['data' => $assessment]);
    }
}
