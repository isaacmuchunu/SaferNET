<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SaferNET Incident Form - {{ $incident->public_id }}</title>
    <style>
        @page { size: A4 portrait; margin: 11mm 12mm 13mm; }
        :root { --ink: #111827; --muted: #475569; --line: #64748b; --paper: #fff; --wash: #f1f5f9; --brand: #0f766e; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #dbe4e8; color: var(--ink); font: 9.5pt/1.28 Arial, Helvetica, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .toolbar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 11px 20px; background: #0f3d43; color: #fff; box-shadow: 0 4px 16px rgba(15, 61, 67, .2); }
        .toolbar strong { display: block; font-size: 10pt; letter-spacing: .04em; text-transform: uppercase; }
        .toolbar span { color: #ccfbf1; font-size: 8pt; }
        .button { border: 1px solid rgba(255,255,255,.45); border-radius: 5px; padding: 7px 12px; background: #fff; color: #0f3d43; font: 700 8.5pt Arial, sans-serif; cursor: pointer; }
        .button.secondary { margin-right: 6px; background: transparent; color: #fff; }
        .form { width: 210mm; min-height: 297mm; margin: 16px auto; padding: 10mm 11mm 12mm; background: var(--paper); box-shadow: 0 10px 30px rgba(15, 61, 67, .12); }
        .letterhead { text-align: center; }
        .ministry-logo { display: block; width: 82mm; max-width: 100%; height: auto; margin: 0 auto 5px; }
        .jurisdiction { padding-top: 5px; border-top: 2px solid var(--ink); font-size: 10pt; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
        .school-name { margin-top: 3px; font-size: 12.5pt; font-weight: 800; letter-spacing: .02em; text-transform: uppercase; }
        .document-header { display: grid; grid-template-columns: 27mm 1fr 42mm; margin-top: 9px; border: 2px solid var(--ink); }
        .form-number, .document-title, .official-use { min-height: 17mm; padding: 5px 7px; }
        .form-number { border-right: 1px solid var(--ink); font-size: 7.5pt; font-weight: 700; }
        .form-number strong { display: block; margin-top: 2px; font-size: 10pt; }
        .document-title { display: flex; align-items: center; justify-content: center; text-align: center; font-size: 13pt; font-weight: 900; letter-spacing: .02em; text-transform: uppercase; }
        .official-use { border-left: 1px solid var(--ink); background: var(--wash); font-size: 7pt; font-weight: 700; text-transform: uppercase; }
        .official-use strong { display: block; margin-top: 3px; font: 800 8.5pt Consolas, monospace; text-transform: none; word-break: break-all; }
        .notice { margin-top: 5px; padding: 5px 7px; border: 1px solid var(--ink); background: #ecfeff; font-size: 7.6pt; }
        .section { margin-top: 7px; break-inside: avoid; }
        .section-title { padding: 4px 7px; background: var(--ink); color: #fff; font-size: 8.3pt; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
        .field-grid { display: grid; border-left: 1px solid var(--line); border-top: 1px solid var(--line); }
        .field-grid.two { grid-template-columns: 1fr 1fr; }
        .field-grid.three { grid-template-columns: 1fr 1fr 1fr; }
        .field { min-height: 11.5mm; padding: 3px 6px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .field.wide { grid-column: 1 / -1; }
        .field-label { display: block; color: var(--muted); font-size: 6.9pt; font-weight: 700; letter-spacing: .045em; text-transform: uppercase; }
        .field-value { display: block; margin-top: 3px; font-size: 9pt; font-weight: 700; overflow-wrap: anywhere; }
        .field-number { display: inline-block; min-width: 16px; color: var(--brand); font-weight: 900; }
        .badge { display: inline-block; padding: 2px 6px; border: 1px solid currentColor; font-size: 7pt; font-weight: 900; letter-spacing: .05em; text-transform: uppercase; }
        .critical, .high { color: #991b1b; background: #fff1f2; }
        .medium { color: #92400e; background: #fffbeb; }
        .low { color: #334155; background: #f8fafc; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 7.6pt; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th, td { padding: 4px 5px; border: 1px solid var(--line); vertical-align: top; overflow-wrap: anywhere; }
        th { background: var(--wash); font-size: 6.8pt; font-weight: 800; letter-spacing: .035em; text-align: left; text-transform: uppercase; }
        .mono { font-family: Consolas, "Courier New", monospace; }
        .empty { padding: 10px; color: var(--muted); text-align: center; font-style: italic; }
        .declaration { padding: 6px 7px; border: 1px solid var(--line); border-top: 0; font-size: 7.5pt; }
        .signatures { display: grid; grid-template-columns: repeat(3, 1fr); border-left: 1px solid var(--line); }
        .signature { min-height: 25mm; padding: 5px 7px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
        .signature strong { font-size: 7.3pt; text-transform: uppercase; }
        .signature-line { margin-top: 12mm; border-top: 1px solid var(--ink); padding-top: 2px; color: var(--muted); font-size: 6.5pt; text-align: center; }
        .footer { margin-top: 7px; padding-top: 5px; border-top: 2px solid var(--ink); color: var(--muted); font-size: 6.7pt; text-align: center; }
        .footer strong { color: var(--ink); }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .form { width: auto; min-height: auto; margin: 0; padding: 0; box-shadow: none; }
        }
        body.pdf { background: #fff; }
        body.pdf .form { width: auto; min-height: auto; margin: 0; padding: 0; box-shadow: none; }
        body.pdf .document-header { display: block; height: 17mm; }
        body.pdf .document-header::after, body.pdf .field-grid::after, body.pdf .signatures::after { display: block; clear: both; content: ""; }
        body.pdf .form-number { float: left; width: 25mm; }
        body.pdf .document-title { float: left; display: block; width: 112mm; padding-top: 7mm; }
        body.pdf .official-use { float: left; width: 41mm; }
        body.pdf .field-grid { display: block; width: 100%; }
        body.pdf .field { float: left; width: 32%; min-height: 11.5mm; }
        body.pdf .field-grid.three .field:nth-child(3n+1) { clear: left; }
        body.pdf .field-grid.two .field { width: 48.5%; }
        body.pdf .field-grid.two .field:nth-child(2n+1) { clear: left; }
        body.pdf .field.wide { float: none; clear: both; width: 100%; }
        body.pdf .signatures { display: block; width: 100%; }
        body.pdf .signature { float: left; width: 32%; }
    </style>
</head>
<body class="{{ ($pdfMode ?? false) ? 'pdf' : '' }}">
    @unless($pdfMode ?? false)
    <div class="toolbar">
        <div>
            <strong>SaferNET confidential incident form</strong>
            <span>Review the record before printing or saving it as PDF.</span>
        </div>
        <div>
            <button class="button secondary" type="button" onclick="window.close()">Close</button>
            <button class="button" type="button" onclick="window.print()">Print / Save PDF</button>
        </div>
    </div>
    @endunless

    <main class="form">
        <header class="letterhead">
            <img class="ministry-logo" src="{{ $ministryLogo }}" alt="Republic of Kenya Ministry of Education">
            <div class="jurisdiction">Kiambu County | {{ $incident->institution?->subcounty?->name ?? 'Sub-county not recorded' }}</div>
            <div class="school-name">{{ $incident->institution?->name ?? 'School not recorded' }}</div>
        </header>

        <section class="document-header">
            <div class="form-number">Form<strong>SAF-NET 01</strong></div>
            <div class="document-title">Learner Internet Safety Incident Record</div>
            <div class="official-use">Official use only<strong>{{ $incident->public_id }}</strong></div>
        </section>

        <div class="notice"><strong>Confidential safeguarding record.</strong> Handle according to the school's access controls, retention schedule, and applicable Kenyan data-protection requirements. Do not circulate through personal accounts.</div>

        <section class="section">
            <div class="section-title">Part I - Filing and institution information</div>
            <div class="field-grid three">
                <div class="field"><span class="field-label"><span class="field-number">1</span> NEMIS code</span><span class="field-value mono">{{ $incident->institution?->nemis_code ?? 'Not recorded' }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">2</span> Sub-county</span><span class="field-value">{{ $incident->institution?->subcounty?->name ?? 'Not recorded' }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">3</span> Date generated</span><span class="field-value mono">{{ now()->format('d M Y, H:i T') }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">4</span> Head of institution</span><span class="field-value">{{ $incident->institution?->hoi_name ?? 'Not recorded' }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">5</span> Official telephone</span><span class="field-value">{{ $incident->institution?->hoi_phone ?? 'Not recorded' }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">6</span> Official email</span><span class="field-value">{{ $incident->institution?->hoi_email ?? 'Not recorded' }}</span></div>
            </div>
        </section>

        <section class="section">
            <div class="section-title">Part II - Learner and managed device</div>
            <div class="field-grid three">
                <div class="field"><span class="field-label"><span class="field-number">7</span> Learner name</span><span class="field-value">{{ $incident->learner?->full_name ?: 'Unattributed learner' }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">8</span> Learner number</span><span class="field-value mono">{{ $incident->learner?->learner_number ?? 'Not recorded' }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">9</span> Learner status</span><span class="field-value">{{ strtoupper($incident->learner?->status ?? 'Not recorded') }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">10</span> Asset tag</span><span class="field-value mono">{{ $incident->device?->asset_tag ?? 'Not recorded' }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">11</span> Hostname</span><span class="field-value mono">{{ $incident->device?->hostname ?? 'Not recorded' }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">12</span> Platform / last report</span><span class="field-value">{{ strtoupper($incident->device?->platform ?? 'Unknown') }} / {{ $incident->device?->last_seen_at?->format('d M Y H:i') ?? 'Not reported' }}</span></div>
            </div>
        </section>

        @php
            $severity = strtolower($incident->severity instanceof \BackedEnum ? $incident->severity->value : (string) $incident->severity);
            $status = $incident->status instanceof \BackedEnum ? $incident->status->value : (string) $incident->status;
        @endphp
        <section class="section">
            <div class="section-title">Part III - Incident classification</div>
            <div class="field-grid three">
                <div class="field"><span class="field-label"><span class="field-number">13</span> Incident type</span><span class="field-value">{{ strtoupper(str_replace('_', ' ', $incident->type)) }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">14</span> Severity</span><span class="field-value"><span class="badge {{ $severity }}">{{ $severity }}</span></span></div>
                <div class="field"><span class="field-label"><span class="field-number">15</span> Case status</span><span class="field-value">{{ strtoupper(str_replace('_', ' ', $status)) }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">16</span> Content category</span><span class="field-value">{{ $incident->category?->name ?? 'Unclassified' }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">17</span> First detected</span><span class="field-value mono">{{ $incident->first_detected_at?->format('d M Y, H:i:s') ?? 'Not recorded' }}</span></div>
                <div class="field"><span class="field-label"><span class="field-number">18</span> Last detected / event count</span><span class="field-value mono">{{ $incident->last_detected_at?->format('d M Y, H:i:s') ?? 'Not recorded' }} / {{ $incident->event_count }}</span></div>
                <div class="field wide"><span class="field-label"><span class="field-number">19</span> Resolution or safeguarding summary</span><span class="field-value">{{ $incident->resolution_summary ?: 'No resolution summary has been recorded.' }}</span></div>
            </div>
        </section>

        <section class="section">
            <div class="section-title">Part IV - Recorded safeguarding actions</div>
            <table>
                <thead><tr><th style="width:16%">Date and time</th><th style="width:20%">Officer</th><th style="width:19%">Action</th><th>Official notes</th></tr></thead>
                <tbody>
                    @forelse($incident->actions as $action)
                        <tr>
                            <td class="mono">{{ $action->created_at->format('d M Y H:i') }}</td>
                            <td><strong>{{ $action->actor?->name ?? 'System officer' }}</strong><br>{{ strtoupper($action->actor?->role instanceof \BackedEnum ? $action->actor->role->value : (string) ($action->actor?->role ?? 'Officer')) }}</td>
                            <td>{{ strtoupper(str_replace('_', ' ', $action->action)) }}</td>
                            <td>{{ $action->notes ?: 'No additional notes recorded.' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty">No follow-up action has been recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="section">
            <div class="section-title">Part V - Supporting web-event evidence</div>
            <table>
                <thead><tr><th style="width:15%">Occurred</th><th style="width:24%">Domain</th><th style="width:11%">Action</th><th style="width:13%">Source</th><th>Reason / URL</th></tr></thead>
                <tbody>
                    @forelse($incident->webEvents as $event)
                        @php
                            $eventAction = $event->action instanceof \BackedEnum ? $event->action->value : (string) $event->action;
                        @endphp
                        <tr>
                            <td class="mono">{{ $event->occurred_at?->format('d M H:i:s') ?? 'Not recorded' }}</td>
                            <td class="mono">{{ $event->domain }}</td>
                            <td>{{ strtoupper($eventAction) }}</td>
                            <td>{{ strtoupper($event->enforcement_source) }}</td>
                            <td>{{ $event->reason }}<br><span class="mono">{{ $event->url }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="empty">No web-event evidence is linked to this incident.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="section">
            <div class="section-title">Part VI - Certification and official sign-off</div>
            <div class="declaration">I certify that I have reviewed this record, that corrections or follow-up actions are entered above, and that access to the learner's information remains limited to authorised safeguarding personnel.</div>
            <div class="signatures">
                <div class="signature"><strong>Computer Laboratory Manager</strong><div class="signature-line">Name, signature and date</div></div>
                <div class="signature"><strong>Head of Institution</strong><div class="signature-line">Name, signature and date</div></div>
                <div class="signature"><strong>Sub-County Education Office</strong><div class="signature-line">Official stamp and date</div></div>
            </div>
        </section>

        <footer class="footer"><strong>SaferNET - Kiambu County K-12 Web Filtering and Learner Internet Safety Platform</strong><br>Generated by {{ $actor->name }} ({{ strtoupper($actor->role instanceof \BackedEnum ? $actor->role->value : (string) $actor->role) }}). System-generated record; verify signatures where a printed copy is retained.</footer>
    </main>

    @unless($pdfMode ?? false)
    <script>
        if (window.location.hash === '#print') {
            window.addEventListener('load', () => window.setTimeout(() => window.print(), 400));
        }
    </script>
    @endunless
</body>
</html>
