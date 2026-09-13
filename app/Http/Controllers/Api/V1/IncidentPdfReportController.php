<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Incident;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IncidentPdfReportController extends Controller
{
    /**
     * How many web events the dossier prints.
     *
     * A dossier is signed off and used in a safeguarding process, so where the
     * evidence is longer than this the document must say so rather than quietly
     * ending — the reader has to know they are looking at an extract.
     */
    public const EvidenceLimit = 15;

    public function show(Request $request, string $incident): Response
    {
        $user = $request->user();

        $record = Incident::query()
            ->visibleTo($user)
            ->with([
                'institution.subcounty',
                'learner',
                'device',
                'category',
                'actions.actor',
                'webEvents' => fn ($query) => $query->latest('occurred_at')->limit(self::EvidenceLimit),
            ])
            ->withCount('webEvents')
            ->where('public_id', $incident)
            ->firstOrFail();

        // Audit export of incident record
        AuditLog::create([
            'actor_id' => $user->id,
            'institution_id' => $record->institution_id,
            'event' => 'incident.dossier.exported',
            'auditable_type' => Incident::class,
            'auditable_id' => $record->id,
            'old_values' => null,
            'new_values' => [
                'incident_public_id' => $record->public_id,
                'format' => 'pdf',
                'learner_id' => $record->learner_id,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $pdf = Pdf::loadView('reports.incident-report-pdf', [
            'incident' => $record,
            'actor' => $user,
            'ministryLogo' => 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('images/ministry-of-education.png'))),
        ])->setPaper('a4', 'portrait')
            ->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);

        return $pdf->stream('safernet-incident-'.$record->public_id.'.pdf');
    }
}
