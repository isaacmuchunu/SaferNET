import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { CheckCircle2Icon, ClockIcon, GlobeIcon, PrinterIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import {
    Button,
    Cell,
    DataTable,
    DetailRow,
    EmptyState,
    ErrorState,
    Field,
    Panel,
    PanelHeader,
    Row,
    Select,
    Skeleton,
    Textarea,
    firstError,
} from '../components/Primitives';
import { StatusPill } from '../components/StatusPill';
import { PrepareDossierModal } from './incidents/PrepareDossierModal';
import { useIncident, useRecordIncidentAction, useUpdateIncident, useUsers } from '../lib/queries';
import { capabilitiesFor } from '../lib/permissions';
import { useAuth } from '../lib/auth';
import { ENFORCEMENT_ACTION, INCIDENT_STATUS, SEVERITY } from '../lib/domain';
import { formatDate, formatRelative } from '../lib/format';
import { ApiError } from '../lib/api';
import { showToast, toastError } from '../lib/toast';

const ACTIONS = [
    ['acknowledged', 'Acknowledged'],
    ['assigned', 'Assigned for follow-up'],
    ['contacted_guardian', 'Contacted guardian'],
    ['counselled', 'Learner counselled'],
    ['monitored', 'Placed under monitoring'],
    ['resolved', 'Resolved'],
    ['dismissed', 'Dismissed'],
];

export function IncidentPage() {
    const [preparing, setPreparing] = useState(false);
    const { incidentId } = useParams();
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);

    const incident = useIncident(incidentId);
    const record = useRecordIncidentAction();
    const updateIncident = useUpdateIncident();
    const officers = useUsers({}, { enabled: can.recordIncidentActions });

    const [action, setAction] = useState('acknowledged');
    const [notes, setNotes] = useState('');
    const [status, setStatus] = useState('');
    const [assignee, setAssignee] = useState('');

    if (incident.isPending) {
        return (
            <div className="space-y-4">
                <Skeleton className="h-8 w-72" />
                <Skeleton className="h-48 w-full rounded-xl" />
            </div>
        );
    }

    if (incident.isError) {
        return (
            <Panel>
                <ErrorState error={incident.error} onRetry={incident.refetch} />
            </Panel>
        );
    }

    const data = incident.data;
    const errors = record.error instanceof ApiError ? record.error.errors : {};
    const events = data.web_events ?? [];
    const history = data.actions ?? [];
    const closed = ['resolved', 'dismissed'].includes(data.status);

    async function submitCaseChange(event) {
        event.preventDefault();

        try {
            await updateIncident.mutateAsync({
                id: data.id,
                ...(status ? { status } : {}),
                assigned_to: assignee ? Number(assignee) : null,
            });
            showToast('Incident updated', { description: 'The case state was saved.' });
        } catch (error) {
            toastError(error, 'The incident could not be updated');
        }
    }

    async function submit(event) {
        event.preventDefault();

        try {
            await record.mutateAsync({ id: data.id, action, notes: notes || null });
            showToast('Action recorded', { description: 'The safeguarding step was written to the incident history.' });
            setNotes('');
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The action could not be recorded');
            }
        }
    }

    return (
        <div className="animate-fade-up">
            <PageHeader
                breadcrumbs={[{ label: 'Incidents', to: '/incidents' }, { label: data.id.slice(0, 8).toUpperCase() }]}
                title={`${data.category?.name ?? 'Filtering'} incident`}
                description={`${data.learner?.name ?? 'Unattributed learner'} · ${data.institution?.name ?? 'Institution'}`}
                meta={`First detected ${formatDate(data.first_detected_at, { withTime: true })}`}
                actions={
                    <>
                        <Button
                            type="button"
                            variant="secondary"
                            icon={PrinterIcon}
                            onClick={() => setPreparing(true)}
                        >
                            Prepare dossier
                        </Button>
                        <StatusPill descriptor={SEVERITY[data.severity]} />
                        <StatusPill descriptor={INCIDENT_STATUS[data.status]} />
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="flex flex-col gap-4 lg:col-span-2">
                    <Panel>
                        <PanelHeader title="Evidence" description="Web events that contributed to this incident." />
                        <DataTable
                            query={{ isPending: false, isError: false }}
                            rows={events}
                            minWidth="720px"
                            columns={['Domain', 'Request', 'Action', 'Severity', 'Occurred']}
                            empty={<EmptyState icon={GlobeIcon} title="No linked events" description="No web events are attached to this incident." />}
                        >
                            {events.map((event) => (
                                <Row key={event.id}>
                                    <Cell bold>
                                        {event.domain}
                                        <span className="mt-0.5 block max-w-[26rem] truncate font-mono text-[10px] text-text-muted" title={event.url}>
                                            {event.url}
                                        </span>
                                    </Cell>
                                    <Cell muted>{event.request_kind === 'top_level' ? 'Learner initiated' : 'Background'}</Cell>
                                    <Cell>
                                        <StatusPill descriptor={ENFORCEMENT_ACTION[event.action]} />
                                    </Cell>
                                    <Cell>
                                        <StatusPill descriptor={SEVERITY[event.severity]} />
                                    </Cell>
                                    <Cell muted>{formatDate(event.occurred_at, { withTime: true })}</Cell>
                                </Row>
                            ))}
                        </DataTable>
                    </Panel>

                    <Panel>
                        <PanelHeader title="Safeguarding history" description="Every step recorded against this incident." />
                        <div className="px-5 py-4">
                            {history.length === 0 ? (
                                <p className="text-xs text-text-secondary">No action has been recorded yet.</p>
                            ) : (
                                <ol className="space-y-4">
                                    {history.map((entry) => (
                                        <li key={entry.id} className="flex gap-3">
                                            <span className="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-soft text-brand">
                                                <CheckCircle2Icon size={15} />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="text-xs font-semibold">
                                                    {ACTIONS.find(([value]) => value === entry.action)?.[1] ?? entry.action}
                                                </p>
                                                {entry.notes && <p className="mt-0.5 text-[11px] leading-4 text-text-secondary">{entry.notes}</p>}
                                                <p className="mt-1 flex items-center gap-1 text-[10px] text-text-muted">
                                                    <ClockIcon size={10} />
                                                    {entry.actor ?? 'SAFERNET'} · {formatDate(entry.recorded_at, { withTime: true })}
                                                </p>
                                            </div>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>
                    </Panel>
                </div>

                <div className="flex flex-col gap-4">
                    <Panel>
                        <PanelHeader title="Incident record" />
                        <div className="space-y-2 px-5 py-4">
                            <DetailRow label="Reference">
                                <span className="font-mono">{data.id.slice(0, 8).toUpperCase()}</span>
                            </DetailRow>
                            <DetailRow label="Learner">{data.learner?.name}</DetailRow>
                            <DetailRow label="Learner number">
                                <span className="font-mono">{data.learner?.learner_number}</span>
                            </DetailRow>
                            <DetailRow label="Device">
                                <span className="font-mono">{data.device?.asset_tag}</span>
                            </DetailRow>
                            <DetailRow label="School">
                                {data.institution?.name && <Link to="/schools" className="text-brand hover:underline">{data.institution.name}</Link>}
                            </DetailRow>
                            <DetailRow label="Events">{data.event_count}</DetailRow>
                            <DetailRow label="Last detected">{formatRelative(data.last_detected_at)}</DetailRow>
                            <DetailRow label="HOI notified">{data.notified_at ? formatDate(data.notified_at, { withTime: true }) : 'Not notified'}</DetailRow>
                            {data.resolved_at && <DetailRow label="Resolved">{formatDate(data.resolved_at, { withTime: true })}</DetailRow>}
                        </div>
                        {data.resolution_summary && (
                            <p className="border-t border-border bg-surface-muted/40 px-5 py-3 text-[11px] leading-4 text-text-secondary">
                                {data.resolution_summary}
                            </p>
                        )}
                    </Panel>

                    {can.recordIncidentActions ? (
                        <>
                            <Panel>
                                <PanelHeader
                                    title="Record an action"
                                    description={
                                        closed
                                            ? 'This incident is closed; further steps remain on the record.'
                                            : 'Every step is written to the audit log against your name.'
                                    }
                                />
                                <form onSubmit={submit} className="space-y-4 px-5 py-4" noValidate>
                                    <Field label="Action taken" required error={firstError(errors, 'action')}>
                                        <Select value={action} onChange={(event) => setAction(event.target.value)}>
                                            {ACTIONS.map(([value, label]) => (
                                                <option key={value} value={value}>
                                                    {label}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field label="Notes" error={firstError(errors, 'notes')} hint="Recorded against your name.">
                                        <Textarea
                                            rows={3}
                                            value={notes}
                                            maxLength={4000}
                                            onChange={(event) => setNotes(event.target.value)}
                                            placeholder="What was done, and by whom."
                                        />
                                    </Field>
                                    <Button type="submit" variant="primary" className="w-full" loading={record.isPending}>
                                        Record action
                                    </Button>
                                </form>
                            </Panel>

                            <Panel>
                                <PanelHeader title="Case management" description="Set the state of the incident and who is handling it." />
                                <form onSubmit={submitCaseChange} className="space-y-4 px-5 py-4" noValidate>
                                    <Field label="Status" hint="Resolving records you as the officer who closed it.">
                                        <Select value={status || data.status} onChange={(event) => setStatus(event.target.value)}>
                                            {Object.entries(INCIDENT_STATUS).map(([value, meta]) => (
                                                <option key={value} value={value}>
                                                    {meta.label}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field label="Assigned to" hint="An officer at this institution.">
                                        <Select
                                            value={assignee || (data.assigned_to ? String(data.assigned_to) : '')}
                                            onChange={(event) => setAssignee(event.target.value)}
                                        >
                                            <option value="">Unassigned</option>
                                            {(officers.data?.data ?? []).map((officer) => (
                                                <option key={officer.id} value={officer.id}>
                                                    {officer.name}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Button type="submit" className="w-full" loading={updateIncident.isPending}>
                                        Update incident
                                    </Button>
                                </form>
                            </Panel>
                        </>
                    ) : (
                        <Panel>
                            <PanelHeader
                                title="Technical support"
                                description="Verify the device, agent and session were functioning when this was recorded."
                            />
                            <div className="space-y-3 px-5 py-4">
                                <p className="text-xs leading-5 text-text-secondary">
                                    The Head of Institution decides what action follows a learner incident. Your part is to
                                    confirm the evidence is sound: that the endpoint agent and extension were healthy, that the
                                    right learner was authenticated, and that the device was correctly attributed.
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button as={Link} to={`/devices?search=${encodeURIComponent(data.device?.asset_tag ?? '')}`} size="sm">
                                        Inspect device
                                    </Button>
                                    <Button as={Link} to="/deployment" size="sm">
                                        Check components
                                    </Button>
                                    <Button as={Link} to="/exceptions" size="sm">
                                        Submit exception
                                    </Button>
                                </div>
                            </div>
                        </Panel>
                    )}
                </div>
            </div>

            <PrepareDossierModal
                incident={data}
                open={preparing}
                onClose={() => setPreparing(false)}
            />
        </div>
    );
}
