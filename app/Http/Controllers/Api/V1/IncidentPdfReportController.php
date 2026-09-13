<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\WebEvent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class IncidentPdfReportController extends Controller
{
    /**
     * How many linked events the dossier prints when the preparer selects none.
     *
     * A dossier is signed off and used in a safeguarding process, so where the
     * evidence is longer than this the document says so rather than quietly
     * ending — the reader has to know they are looking at an extract.
     */
    public const EvidenceLimit = 15;

    /** Columns a preparer may order the evidence by. */
    private const Sortable = ['occurred_at', 'domain', 'action'];

    /**
     * Render the safeguarding dossier.
     *
     * Evidence comes in two kinds and the document keeps them apart. Events the
     * platform linked to the incident are the incident itself. Events the
     * preparer adds are context they judged relevant — surrounding browsing the
     * automatic thresholds did not link — and a reader signing Part VI has to be
     * able to tell which is which. Mixing them into one undifferentiated list
     * would let a preparer's judgement read as the system's finding.
     *
     * The selection is made per export rather than stored: two officers
     * preparing the same incident for different purposes will reasonably choose
     * differently, and every export is written to the audit log with what it
     * contained.
     */
    public function show(Request $request, string $incident): Response
    {
        $user = $request->user();

        $validated = $request->validate([
            'events' => ['nullable', 'array', 'max:200'],
            'events.*' => ['uuid'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', self::Sortable)],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $sort = $validated['sort'] ?? 'occurred_at';
        $direction = $validated['direction'] ?? 'desc';

        $record = Incident::query()
            ->visibleTo($user)
            ->with(['institution.subcounty', 'learner', 'device', 'category', 'actions.actor'])
            ->withCount('webEvents')
            ->where('public_id', $incident)
            ->firstOrFail();

        $linked = $this->linkedEvidence($record, $sort, $direction);
        $added = $this->addedEvidence($record, $validated['events'] ?? [], $linked, $sort, $direction);

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
                // What the document actually contained, so an export can be
                // reconstructed from the log rather than guessed at.
                'linked_events' => $linked->count(),
                'added_events' => $added->count(),
                'added_event_uuids' => $added->pluck('event_uuid')->all(),
                'sorted_by' => $sort,
                'direction' => $direction,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $pdf = Pdf::loadView('reports.incident-report-pdf', [
            'incident' => $record,
            'actor' => $user,
            'linkedEvents' => $linked,
            'addedEvents' => $added,
            'sortedBy' => $sort,
            'sortDirection' => $direction,
            'ministryLogo' => 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('images/ministry-of-education.png'))),
        ])->setPaper('a4', 'portrait')
            ->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false]);

        return $pdf->stream('safernet-incident-'.$record->public_id.'.pdf');
    }

    /**
     * The events the platform attached to this incident.
     *
     * @return Collection<int, WebEvent>
     */
    private function linkedEvidence(Incident $record, string $sort, string $direction): Collection
    {
        return $record->webEvents()
            ->with('category:id,name')
            ->orderBy($sort, $direction)
            ->limit(self::EvidenceLimit)
            ->get();
    }

    /**
     * Context the preparer chose to include.
     *
     * Scoped to the learner the incident concerns, so a selection cannot reach
     * another child's browsing, and anything already linked is dropped rather
     * than printed twice.
     *
     * @param  list<string>  $uuids
     * @param  Collection<int, WebEvent>  $linked
     * @return Collection<int, WebEvent>
     */
    private function addedEvidence(Incident $record, array $uuids, Collection $linked, string $sort, string $direction): Collection
    {
        if ($uuids === [] || $record->learner_id === null) {
            return collect();
        }

        return WebEvent::query()
            ->where('institution_id', $record->institution_id)
            ->where('learner_id', $record->learner_id)
            ->whereIn('event_uuid', $uuids)
            ->whereNotIn('id', $linked->pluck('id'))
            ->with('category:id,name')
            ->orderBy($sort, $direction)
            ->get();
    }
}
