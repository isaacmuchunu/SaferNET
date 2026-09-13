<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SaferNET Incident Form - {{ $incident->public_id }}</title>
    <style>
        @page { size: A4 portrait; margin: 9mm 11mm 11mm; }
        * { font-family: "Times New Roman", Times, serif; }
        body { margin: 0; color: #111827; font-size: 7.3pt; line-height: 1.22; }
        .letterhead { text-align: center; }
        .logo { width: 76mm; height: auto; }
        .jurisdiction { margin-top: 2mm; padding-top: 2mm; border-top: 1.5pt solid #111827; font: bold 9.5pt "Times New Roman", Times, serif; letter-spacing: .04em; text-transform: uppercase; }
        .school { margin: 1mm 0 3mm; font: bold 11pt "Times New Roman", Times, serif; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .document td { height: 13mm; padding: 2mm; border: 1.2pt solid #111827; vertical-align: middle; }
        .document .title { font: bold 11.5pt "Times New Roman", Times, serif; text-align: center; text-transform: uppercase; }
        .small-label { display: block; margin-bottom: 1mm; font-size: 6.3pt; font-weight: bold; text-transform: uppercase; }
        .notice { margin: 2mm 0; padding: 1.5mm 2mm; border: .7pt solid #334155; background: #ecfeff; }
        .section { margin-top: 2mm; page-break-inside: avoid; }
        .section-title { padding: 1.6mm 2mm; background: #111827; color: white; font: bold 7.5pt "Times New Roman", Times, serif; letter-spacing: .035em; text-transform: uppercase; }
        .fields td { height: 9mm; padding: 1.5mm 2mm; border: .6pt solid #64748b; vertical-align: top; }
        .number { color: #0f766e; font-weight: bold; }
        .label { margin-left: 2mm; color: #475569; font-size: 6.1pt; font-weight: bold; letter-spacing: .025em; text-transform: uppercase; }
        .value { display: block; margin-top: 1mm; font-size: 7.8pt; font-weight: bold; overflow-wrap: anywhere; }
        .mono { font-family: "Times New Roman", Times, serif; }
        .badge { padding: .6mm 1.5mm; border: .7pt solid #991b1b; color: #991b1b; background: #fff1f2; font-weight: bold; text-transform: uppercase; }
        .records th, .records td { padding: 1.3mm 1.5mm; border: .6pt solid #64748b; vertical-align: top; overflow-wrap: anywhere; }
        .records th { background: #f1f5f9; font: bold 6pt "Times New Roman", Times, serif; letter-spacing: .02em; text-align: left; text-transform: uppercase; }
        .empty { padding: 3mm !important; color: #475569; font-style: italic; text-align: center; }
        .note { margin: 2mm 0 0; font-size: 8pt; color: #475569; font-style: italic; }
        .declaration { padding: 1.5mm 2mm; border: .6pt solid #64748b; border-top: 0; }
        .signatures td { height: 20mm; padding: 1.5mm 2mm; border: .6pt solid #64748b; vertical-align: top; font-weight: bold; text-transform: uppercase; }
        .line { margin-top: 11mm; padding-top: 1mm; border-top: .6pt solid #111827; color: #475569; font-size: 5.7pt; font-weight: normal; text-align: center; text-transform: none; }
        .footer { position: fixed; right: 0; bottom: -6mm; left: 0; padding-top: 1.5mm; border-top: 1.2pt solid #111827; color: #475569; font-size: 5.8pt; text-align: center; }
        .footer strong { color: #111827; }
    </style>
</head>
<body>
    @php
        $severity = strtolower($incident->severity instanceof \BackedEnum ? $incident->severity->value : (string) $incident->severity);
        $status = $incident->status instanceof \BackedEnum ? $incident->status->value : (string) $incident->status;
    @endphp
    <header class="letterhead">
        <img class="logo" src="{{ $ministryLogo }}" alt="Republic of Kenya Ministry of Education">
        <div class="jurisdiction">Kiambu County | {{ $incident->institution?->subcounty?->name ?? 'Sub-county not recorded' }}</div>
        <div class="school">{{ $incident->institution?->name ?? 'School not recorded' }}</div>
    </header>

    <table class="document">
        <tr>
            <td style="width: 18%"><span class="small-label">Form</span><strong>SAF-NET 01</strong></td>
            <td class="title" style="width: 58%">Learner Internet Safety Incident Record</td>
            <td style="width: 24%"><span class="small-label">Official use only</span><span class="mono">{{ $incident->public_id }}</span></td>
        </tr>
    </table>
    <div class="notice"><strong>Confidential safeguarding record.</strong> Handle under the school's access controls and retention schedule. Do not circulate through personal accounts.</div>

    <section class="section">
        <div class="section-title">Part I - Filing and institution information</div>
        <table class="fields">
            <tr>
                <td><span class="number">1</span><span class="label">NEMIS code</span><span class="value mono">{{ $incident->institution?->nemis_code ?? 'Not recorded' }}</span></td>
                <td><span class="number">2</span><span class="label">Sub-county</span><span class="value">{{ $incident->institution?->subcounty?->name ?? 'Not recorded' }}</span></td>
                <td><span class="number">3</span><span class="label">Generated</span><span class="value mono">{{ now()->format('d M Y, H:i T') }}</span></td>
            </tr>
            <tr>
                <td><span class="number">4</span><span class="label">Head of institution</span><span class="value">{{ $incident->institution?->hoi_name ?? 'Not recorded' }}</span></td>
                <td><span class="number">5</span><span class="label">Official telephone</span><span class="value">{{ $incident->institution?->hoi_phone ?? 'Not recorded' }}</span></td>
                <td><span class="number">6</span><span class="label">Official email</span><span class="value">{{ $incident->institution?->hoi_email ?? 'Not recorded' }}</span></td>
            </tr>
        </table>
    </section>

    <section class="section">
        <div class="section-title">Part II - Learner and managed device</div>
        <table class="fields">
            <tr>
                <td><span class="number">7</span><span class="label">Learner name</span><span class="value">{{ $incident->learner?->full_name ?: 'Unattributed learner' }}</span></td>
                <td><span class="number">8</span><span class="label">Learner number</span><span class="value mono">{{ $incident->learner?->learner_number ?? 'Not recorded' }}</span></td>
                <td><span class="number">9</span><span class="label">Learner status</span><span class="value">{{ strtoupper($incident->learner?->status ?? 'Not recorded') }}</span></td>
            </tr>
            <tr>
                <td><span class="number">10</span><span class="label">Asset tag</span><span class="value mono">{{ $incident->device?->asset_tag ?? 'Not recorded' }}</span></td>
                <td><span class="number">11</span><span class="label">Hostname</span><span class="value mono">{{ $incident->device?->hostname ?? 'Not recorded' }}</span></td>
                <td><span class="number">12</span><span class="label">Platform / last report</span><span class="value">{{ strtoupper($incident->device?->platform ?? 'Unknown') }} / {{ $incident->device?->last_seen_at?->format('d M Y H:i') ?? 'Not reported' }}</span></td>
            </tr>
        </table>
    </section>

    <section class="section">
        <div class="section-title">Part III - Incident classification</div>
        <table class="fields">
            <tr>
                <td><span class="number">13</span><span class="label">Incident type</span><span class="value">{{ strtoupper(str_replace('_', ' ', $incident->type)) }}</span></td>
                <td><span class="number">14</span><span class="label">Severity</span><span class="value"><span class="badge">{{ $severity }}</span></span></td>
                <td><span class="number">15</span><span class="label">Case status</span><span class="value">{{ strtoupper(str_replace('_', ' ', $status)) }}</span></td>
            </tr>
            <tr>
                <td><span class="number">16</span><span class="label">Content category</span><span class="value">{{ $incident->category?->name ?? 'Unclassified' }}</span></td>
                <td><span class="number">17</span><span class="label">First detected</span><span class="value mono">{{ $incident->first_detected_at?->format('d M Y, H:i:s') ?? 'Not recorded' }}</span></td>
                <td><span class="number">18</span><span class="label">Last detected / count</span><span class="value mono">{{ $incident->last_detected_at?->format('d M Y, H:i:s') ?? 'Not recorded' }} / {{ $incident->event_count }}</span></td>
            </tr>
            <tr><td colspan="3"><span class="number">19</span><span class="label">Resolution or safeguarding summary</span><span class="value">{{ $incident->resolution_summary ?: 'No resolution summary has been recorded.' }}</span></td></tr>
        </table>
    </section>

    <section class="section">
        <div class="section-title">Part IV - Recorded safeguarding actions</div>
        <table class="records">
            <thead><tr><th style="width: 15%">Date and time</th><th style="width: 20%">Officer</th><th style="width: 19%">Action</th><th>Official notes</th></tr></thead>
            <tbody>
            @forelse($incident->actions as $action)
                <tr><td class="mono">{{ $action->created_at->format('d M Y H:i') }}</td><td><strong>{{ $action->actor?->name ?? 'System officer' }}</strong><br>{{ strtoupper($action->actor?->role instanceof \BackedEnum ? $action->actor->role->value : (string) ($action->actor?->role ?? 'Officer')) }}</td><td>{{ strtoupper(str_replace('_', ' ', $action->action)) }}</td><td>{{ $action->notes ?: 'No additional notes recorded.' }}</td></tr>
            @empty
                <tr><td colspan="4" class="empty">No follow-up action has been recorded.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <section class="section">
        <div class="section-title">Part V - Supporting web-event evidence</div>
        <table class="records">
            <thead><tr><th style="width: 14%">Occurred</th><th style="width: 22%">Domain</th><th style="width: 10%">Action</th><th style="width: 12%">Source</th><th>Reason / URL</th></tr></thead>
            <tbody>
            @forelse($incident->webEvents as $event)
                <tr><td class="mono">{{ $event->occurred_at?->format('d M H:i:s') ?? 'Not recorded' }}</td><td class="mono">{{ $event->domain }}</td><td>{{ strtoupper($event->action instanceof \BackedEnum ? $event->action->value : (string) $event->action) }}</td><td>{{ strtoupper($event->enforcement_source) }}</td><td>{{ $event->reason }}<br><span class="mono">{{ $event->url }}</span></td></tr>
            @empty
                <tr><td colspan="5" class="empty">No web-event evidence is linked to this incident.</td></tr>
            @endforelse
            </tbody>
        </table>
        @if($incident->web_events_count > $incident->webEvents->count())
            {{-- An extract must announce itself: a reader signing Part VI has to
                 know the record continues beyond what is printed here. --}}
            <p class="note">
                Extract: the {{ $incident->webEvents->count() }} most recent of
                {{ number_format($incident->web_events_count) }} recorded events are shown.
                The complete record is retained in SAFERNET and available on request.
            </p>
        @elseif($incident->webEvents->count() > 0)
            <p class="note">
                Complete: all {{ number_format($incident->web_events_count) }} recorded events for this
                incident are shown.
            </p>
        @endif
    </section>

    <section class="section">
        <div class="section-title">Part VI - Certification and official sign-off</div>
        <div class="declaration">I certify that I have reviewed this record, that corrections or follow-up actions are entered above, and that access to the learner's information remains limited to authorised safeguarding personnel.</div>
        <table class="signatures"><tr><td>Computer Laboratory Manager<div class="line">Name, signature and date</div></td><td>Head of Institution<div class="line">Name, signature and date</div></td><td>Sub-County Education Office<div class="line">Official stamp and date</div></td></tr></table>
    </section>

    <footer class="footer"><strong>SaferNET - Kiambu County K-12 Web Filtering and Learner Internet Safety Platform</strong><br>Generated by {{ $actor->name }} ({{ strtoupper($actor->role instanceof \BackedEnum ? $actor->role->value : (string) $actor->role) }}). System-generated record; verify signatures where a printed copy is retained.</footer>
</body>
</html>
