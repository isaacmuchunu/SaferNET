import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import clsx from 'clsx';
import { GavelIcon, MailIcon, PhoneIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import {
    Button,
    Cell,
    DataTable,
    DetailRow,
    EmptyState,
    ErrorState,
    Panel,
    PanelHeader,
    Row,
    Skeleton,
} from '../components/Primitives';
import { StatusPill } from '../components/StatusPill';
import { ReviewDrawer } from './approvals/ReviewDrawer';
import { useAuth } from '../lib/auth';
import { useDevices, useIncidents, useInstitution, useLaboratories, useLearners, useUsers } from '../lib/queries';
import {
    DEVICE_STATUS,
    INCIDENT_STATUS,
    INSTITUTION_TYPES,
    OWNERSHIP_TYPES,
    SEVERITY,
    institutionStatus,
    roleLabel,
} from '../lib/domain';
import { capabilitiesFor } from '../lib/permissions';
import { useScope } from '../lib/scope';
import { formatDate, formatNumber, formatRelative } from '../lib/format';

const TABS = ['Overview', 'Learners', 'Devices', 'Laboratories', 'Incidents', 'Administrators'];

export function SchoolPage() {
    const { schoolId } = useParams();
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const scope = useScope();
    const [tab, setTab] = useState('Overview');
    const [reviewOpen, setReviewOpen] = useState(false);

    const school = useInstitution(schoolId);

    // Opening a school is the last drill-down step: every register follows it.
    useEffect(() => {
        if (school.data && scope.adjustable && scope.institution?.id !== school.data.id) {
            scope.selectInstitution(school.data);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [school.data?.id]);
    const scoped = { institution_id: schoolId };

    const learners = useLearners(scoped, { enabled: tab === 'Learners' });
    const devices = useDevices(scoped, { enabled: tab === 'Devices' });
    const laboratories = useLaboratories(scoped, { enabled: tab === 'Laboratories' });
    const incidents = useIncidents(scoped, { enabled: tab === 'Incidents' });
    const administrators = useUsers(scoped, { enabled: tab === 'Administrators' });

    if (school.isPending) {
        return (
            <div className="flex flex-col gap-4">
                <Skeleton className="h-8 w-80" />
                <Skeleton className="h-40 w-full rounded-xl" />
                <Skeleton className="h-64 w-full rounded-xl" />
            </div>
        );
    }

    if (school.isError) {
        return (
            <Panel>
                <ErrorState error={school.error} onRetry={school.refetch} />
            </Panel>
        );
    }

    const record = school.data;
    const status = institutionStatus(record.status);
    const awaitingReview = record.status === 'pending_approval';

    return (
        <div className="animate-fade-up">
            <PageHeader
                breadcrumbs={can.viewSchoolRegister ? [{ label: 'Schools', to: '/schools' }, { label: record.name }] : []}
                title={record.name}
                description={`${record.subcounty?.name ?? 'Kiambu'} sub-county · NEMIS ${record.nemis_code}`}
                meta={status.note}
                actions={
                    <>
                        <StatusPill descriptor={status} />
                        {can.reviewRegistrations && awaitingReview && (
                            <Button variant="primary" icon={GavelIcon} onClick={() => setReviewOpen(true)}>
                                Record decision
                            </Button>
                        )}
                    </>
                }
            />

            <div className="mb-4 flex gap-1 overflow-x-auto border-b border-border pb-px">
                {TABS.map((item) => (
                    <button
                        key={item}
                        onClick={() => setTab(item)}
                        className={clsx(
                            '-mb-px border-b-2 px-3 py-2 text-xs font-semibold whitespace-nowrap transition-colors duration-150 ease-gov',
                            tab === item ? 'border-brand text-brand' : 'border-transparent text-text-secondary hover:text-text',
                        )}
                    >
                        {item}
                    </button>
                ))}
            </div>

            {tab === 'Overview' && (
                <div className="grid gap-4 lg:grid-cols-3">
                    <Panel className="lg:col-span-2">
                        <PanelHeader title="Registration particulars" description="As recorded in the county register." />
                        <div className="grid gap-x-8 gap-y-3 px-5 py-4 sm:grid-cols-2">
                            <DetailRow label="Institution type">{INSTITUTION_TYPES[record.institution_type]}</DetailRow>
                            <DetailRow label="Ownership">{OWNERSHIP_TYPES[record.ownership]}</DetailRow>
                            <DetailRow label="Sub-county">{record.subcounty?.name}</DetailRow>
                            <DetailRow label="Physical location">{record.physical_location}</DetailRow>
                            <DetailRow label="Submitted">{formatDate(record.submitted_at)}</DetailRow>
                            <DetailRow label="Declared learner population">{formatNumber(record.learner_population)}</DetailRow>
                            <DetailRow label="Declared devices">{formatNumber(record.computing_devices_count)}</DetailRow>
                            <DetailRow label="Laboratories">{formatNumber(record.laboratories_count)}</DetailRow>
                        </div>
                    </Panel>

                    <div className="flex flex-col gap-4">
                        <Panel>
                            <PanelHeader title="Head of institution" />
                            <div className="space-y-3 px-5 py-4">
                                <DetailRow label="Name">{record.hoi?.name}</DetailRow>
                                <DetailRow label="Email">
                                    {record.hoi?.email && (
                                        <a href={`mailto:${record.hoi.email}`} className="inline-flex items-center gap-1.5 text-brand hover:underline">
                                            <MailIcon size={12} />
                                            {record.hoi.email}
                                        </a>
                                    )}
                                </DetailRow>
                                <DetailRow label="Telephone">
                                    {record.hoi?.phone && (
                                        <span className="inline-flex items-center gap-1.5">
                                            <PhoneIcon size={12} className="text-text-muted" />
                                            {record.hoi.phone}
                                        </span>
                                    )}
                                </DetailRow>
                            </div>
                        </Panel>

                        <Panel>
                            <PanelHeader title="Enrolled register" />
                            <div className="grid grid-cols-2 gap-px overflow-hidden rounded-b-xl bg-border">
                                {[
                                    ['Learners', record.learners_count],
                                    ['Devices', record.devices_count],
                                ].map(([label, value]) => (
                                    <div key={label} className="bg-white px-4 py-3.5">
                                        <p className="text-[11px] text-text-secondary">{label}</p>
                                        <p className="mt-1 text-[21px] font-bold tabular-nums">{formatNumber(value ?? 0)}</p>
                                    </div>
                                ))}
                            </div>
                        </Panel>
                    </div>
                </div>
            )}

            {tab === 'Learners' && (
                <Panel>
                    <PanelHeader title="Learners" description="Learners enrolled on this school's safety register." />
                    <DataTable
                        query={learners}
                        rows={learners.data?.data ?? []}
                        minWidth="640px"
                        columns={['Learner number', 'Name', 'Group', 'Status']}
                        empty={<EmptyState title="No learners enrolled" description="This school has not imported its learner register yet." />}
                    >
                        {(learners.data?.data ?? []).map((learner) => (
                            <Row key={learner.id}>
                                <Cell mono>{learner.learner_number}</Cell>
                                <Cell bold>
                                    {learner.first_name} {learner.last_name}
                                </Cell>
                                <Cell muted>{learner.learner_group?.name ?? '—'}</Cell>
                                <Cell>{learner.status}</Cell>
                            </Row>
                        ))}
                    </DataTable>
                </Panel>
            )}

            {tab === 'Devices' && (
                <Panel>
                    <PanelHeader title="Devices" description="Managed computers carrying the protection agent." />
                    <DataTable
                        query={devices}
                        rows={devices.data?.data ?? []}
                        minWidth="720px"
                        columns={['Asset tag', 'Hostname', 'Assigned learners', 'Last seen', 'Status']}
                        empty={<EmptyState title="No devices enrolled" description="No computers have been enrolled for this school." />}
                    >
                        {(devices.data?.data ?? []).map((device) => (
                            <Row key={device.id}>
                                <Cell mono bold>
                                    {device.asset_tag}
                                </Cell>
                                <Cell muted mono>
                                    {device.hostname ?? '—'}
                                </Cell>
                                <Cell>
                                    {device.assigned_learners?.length
                                        ? device.assigned_learners.map((entry) => entry.name).join(' / ')
                                        : <span className="text-text-muted">Unassigned</span>}
                                </Cell>
                                <Cell muted>{formatRelative(device.last_seen_at)}</Cell>
                                <Cell>
                                    <StatusPill descriptor={DEVICE_STATUS[device.status]} />
                                </Cell>
                            </Row>
                        ))}
                    </DataTable>
                </Panel>
            )}

            {tab === 'Laboratories' && (
                <Panel>
                    <PanelHeader title="Computer laboratories" description="Rooms where managed devices are deployed." />
                    <DataTable
                        query={laboratories}
                        rows={laboratories.data?.data ?? []}
                        minWidth="520px"
                        columns={['Laboratory', 'Location', { key: 'devices', label: 'Devices', align: 'right' }]}
                        empty={<EmptyState title="No laboratories recorded" description="Add a laboratory before enrolling devices." />}
                    >
                        {(laboratories.data?.data ?? []).map((laboratory) => (
                            <Row key={laboratory.id}>
                                <Cell bold>{laboratory.name}</Cell>
                                <Cell muted>{laboratory.location ?? '—'}</Cell>
                                <Cell align="right" className="tabular-nums">
                                    {formatNumber(laboratory.devices_count ?? 0)}
                                </Cell>
                            </Row>
                        ))}
                    </DataTable>
                </Panel>
            )}

            {tab === 'Incidents' && (
                <Panel>
                    <PanelHeader title="Safety incidents" description="Learner-attributed incidents raised at this school." />
                    <DataTable
                        query={incidents}
                        rows={incidents.data?.data ?? []}
                        minWidth="760px"
                        columns={['Incident', 'Learner', 'Category', 'Severity', 'Detected', 'Status']}
                        empty={<EmptyState title="No incidents raised" description="No learner activity at this school has met an incident threshold." />}
                    >
                        {(incidents.data?.data ?? []).map((incident) => (
                            <Row key={incident.id}>
                                <Cell mono>
                                    <Link to={`/incidents/${incident.id}`} className="font-semibold text-brand hover:underline">
                                        {incident.id.slice(0, 8).toUpperCase()}
                                    </Link>
                                </Cell>
                                <Cell bold>{incident.learner?.name ?? '—'}</Cell>
                                <Cell>{incident.category?.name ?? 'Uncategorised'}</Cell>
                                <Cell>
                                    <StatusPill descriptor={SEVERITY[incident.severity]} />
                                </Cell>
                                <Cell muted>{formatRelative(incident.last_detected_at)}</Cell>
                                <Cell>
                                    <StatusPill descriptor={INCIDENT_STATUS[incident.status]} />
                                </Cell>
                            </Row>
                        ))}
                    </DataTable>
                </Panel>
            )}

            {tab === 'Administrators' && (
                <Panel>
                    <PanelHeader title="Administrators" description="Officers with access to this institution." />
                    <DataTable
                        query={administrators}
                        rows={administrators.data?.data ?? []}
                        minWidth="620px"
                        columns={['Officer', 'Office', 'Email', 'Status']}
                        empty={<EmptyState title="No officers enrolled" description="No Head of Institution or Laboratory Manager has been provisioned." />}
                    >
                        {(administrators.data?.data ?? []).map((officer) => (
                            <Row key={officer.id}>
                                <Cell bold>{officer.name}</Cell>
                                <Cell muted>{roleLabel(officer.role)}</Cell>
                                <Cell>{officer.email}</Cell>
                                <Cell>{officer.status}</Cell>
                            </Row>
                        ))}
                    </DataTable>
                </Panel>
            )}

            <ReviewDrawer institution={reviewOpen ? record : null} onClose={() => setReviewOpen(false)} />
        </div>
    );
}
