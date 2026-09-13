import { Link, useNavigate } from 'react-router-dom';
import {
    ArrowUpRightIcon,
    Building2Icon,
    CircleAlertIcon,
    ClipboardCheckIcon,
    GaugeIcon,
    MonitorCogIcon,
    ShieldCheckIcon,
    UsersRoundIcon,
} from 'lucide-react';
import { Cell, DataTable, EmptyState, ErrorState, Panel, PanelHeader, Row } from '../components/Primitives';
import { StatusPill } from '../components/StatusPill';
import { PostureBand } from '../components/dashboard/PostureBand';
import { AttentionQueue } from '../components/dashboard/AttentionQueue';
import { IncidentTrend } from '../components/dashboard/IncidentTrend';
import { AttributionPanel } from '../components/dashboard/AttributionPanel';
import { SeverityDonut } from '../components/dashboard/SeverityDonut';
import { useAuth } from '../lib/auth';
import { useDashboard, useIncidents, useSubcounties } from '../lib/queries';
import { INCIDENT_STATUS, SEVERITY, institutionStatus, roleLabel } from '../lib/domain';
import { capabilitiesFor } from '../lib/permissions';
import { formatNumber, formatRelative } from '../lib/format';

export function DashboardPage() {
    const navigate = useNavigate();
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);

    const dashboard = useDashboard();
    const subcounties = useSubcounties({}, { enabled: can.viewSubcounties });
    const incidents = useIncidents({ status: 'open' }, { enabled: can.viewIncidents });

    const metrics = dashboard.data;
    const rows = subcounties.data?.data ?? [];
    const recentIncidents = (incidents.data?.data ?? []).slice(0, 6);

    const hero = dashboardHeroFor(user, can, metrics);

    const scopeLabel = can.isSchool ? 'school' : can.isSubcounty ? 'sub-county' : 'county';

    return (
        <div className="animate-fade-up pb-10">
            <div className="mx-auto w-full max-w-[1600px]">
                <section className="relative isolate overflow-hidden rounded-2xl border border-brand/15 bg-brand-soft shadow-card">
                        <img
                            src="/images/safernet-county-digital-safety-hero.png"
                            alt=""
                            className="pointer-events-none absolute inset-0 -z-10 h-full w-full object-cover object-center"
                        />
                        <div className="pointer-events-none absolute inset-0 -z-10 bg-gradient-to-r from-white/96 via-white/80 to-transparent sm:via-white/35" />
                        <div className="flex min-h-[260px] max-w-2xl flex-col justify-center px-5 py-6 sm:px-8 lg:px-10 xl:min-h-[305px] xl:max-w-[58%] xl:pr-5">
                            <div className="mb-3 flex flex-wrap items-center gap-2">
                                <span className="inline-flex rounded-full border border-brand/15 bg-white/80 px-2.5 py-1 text-[10px] font-bold tracking-[0.12em] text-brand-dark uppercase shadow-sm backdrop-blur-sm">
                                    {roleLabel(user?.role)}
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-success-soft/90 px-2.5 py-1 text-[10px] font-semibold text-success backdrop-blur-sm">
                                    <span className="h-1.5 w-1.5 rounded-full bg-success" /> Live protection view
                                </span>
                            </div>
                            <h1 className="max-w-xl text-2xl font-bold tracking-[-0.025em] text-brand-deeper sm:text-[30px]">{hero.title}</h1>
                            <p className="mt-2 max-w-xl text-[13px] leading-5 text-text-secondary sm:text-sm sm:leading-6">{hero.description}</p>
                            <div className="mt-5">
                                <Link
                                    to={hero.actionPath}
                                    className="group inline-flex h-9 items-center gap-2 rounded-lg bg-brand px-3.5 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand/30"
                                >
                                    {hero.pending ? <ClipboardCheckIcon size={15} /> : <ShieldCheckIcon size={15} />}
                                    <span>{hero.actionLabel}</span>
                                    <ArrowUpRightIcon size={13} className="opacity-70 transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
                                </Link>
                            </div>
                        </div>

                        <div className="relative z-10 mx-4 mb-4 sm:mx-6 sm:mb-6 xl:absolute xl:top-1/2 xl:right-5 xl:mx-0 xl:mb-0 xl:w-[370px] xl:-translate-y-1/2">
                            <PostureBand metrics={metrics} loading={dashboard.isPending} scopeLabel={scopeLabel} />
                        </div>
                </section>

                {dashboard.isError ? (
                    <Panel className="mt-5">
                        <ErrorState error={dashboard.error} onRetry={dashboard.refetch} />
                    </Panel>
                ) : (
                    <>
                        <DashboardSection
                            className="mt-7"
                            eyebrow="Operations"
                            title="Protection posture"
                            description="Coverage, endpoint health and the items that need action now."
                        >
                            <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,.72fr)]">
                                {can.viewSubcounties ? (
                                    <SubcountyProtectionPanel rows={rows} query={subcounties} onOpen={navigate} />
                                ) : can.registerDevices ? (
                                    <TechnicalStatusPanel metrics={metrics} />
                                ) : (
                                    <InstitutionStatusPanel user={user} metrics={metrics} />
                                )}

                                <div className="xl:sticky xl:top-5">
                                    <AttentionQueue metrics={metrics} capabilities={can} />
                                </div>
                            </div>
                        </DashboardSection>

                        <DashboardSection
                            className="mt-8"
                            eyebrow="Intelligence"
                            title="Safety signals"
                            description="Use the trend first, then severity and attribution to understand what is driving risk."
                        >
                            <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,.72fr)]">
                                <IncidentTrend />
                                <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-1">
                                    <SeverityDonut metrics={metrics} loading={dashboard.isPending} />
                                    <AttributionPanel metrics={metrics} />
                                </div>
                            </div>
                        </DashboardSection>

                        {can.viewIncidents && (
                            <DashboardSection
                                className="mt-8"
                                eyebrow="Safeguarding"
                                title="Open learner safety incidents"
                                description="A focused working queue for the most recent cases still requiring review or action."
                                action={
                                    <Link
                                        to="/incidents"
                                        className="group inline-flex items-center gap-1.5 text-xs font-semibold text-brand transition hover:text-brand-dark"
                                    >
                                        View all incidents
                                        <ArrowUpRightIcon size={14} className="transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
                                    </Link>
                                }
                            >
                                <Panel className="overflow-hidden shadow-sm">
                                    <div className="border-b border-border bg-surface-muted/30 px-5 py-3 sm:px-6">
                                        <p className="text-xs leading-5 text-text-secondary">
                                            {can.recordIncidentActions
                                                ? 'Review the evidence, confirm the learner and device, then record the action taken by the school.'
                                                : 'Visible for technical support. The Head of Institution remains responsible for the safeguarding response.'}
                                        </p>
                                    </div>
                                    <DataTable
                                        query={incidents}
                                        rows={recentIncidents}
                                        minWidth="900px"
                                        columns={['Incident', 'Learner', 'Device', 'Category', 'Severity', 'Events', 'Detected', 'Status']}
                                        empty={
                                            <EmptyState
                                                title="No open incidents"
                                                description="Nothing currently requires safeguarding review in your scope."
                                            />
                                        }
                                    >
                                        {recentIncidents.map((incident) => (
                                            <Row key={incident.id} onClick={() => navigate(`/incidents/${incident.id}`)}>
                                                <Cell mono className="font-semibold text-brand">
                                                    {incident.id.slice(0, 8).toUpperCase()}
                                                </Cell>
                                                <Cell bold>{incident.learner?.name ?? 'Unattributed'}</Cell>
                                                <Cell mono muted>
                                                    {incident.device?.asset_tag ?? '—'}
                                                </Cell>
                                                <Cell>{incident.category?.name ?? 'Uncategorised'}</Cell>
                                                <Cell>
                                                    <StatusPill descriptor={SEVERITY[incident.severity]} />
                                                </Cell>
                                                <Cell muted className="tabular-nums">
                                                    {incident.event_count}
                                                </Cell>
                                                <Cell muted>{formatRelative(incident.last_detected_at)}</Cell>
                                                <Cell>
                                                    <StatusPill descriptor={INCIDENT_STATUS[incident.status]} />
                                                </Cell>
                                            </Row>
                                        ))}
                                    </DataTable>
                                </Panel>
                            </DashboardSection>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

function dashboardHeroFor(user, can, metrics) {
    const pending = can.reviewRegistrations && (metrics?.pending_approvals ?? 0) > 0;

    if (user?.role === 'cde') {
        return {
            title: 'Kiambu Digital Safety Overview',
            description: 'County-wide visibility of school protection, learner safety signals and the operational response across every sub-county.',
            actionPath: pending ? '/approvals' : '/reports',
            actionLabel: pending ? `${metrics.pending_approvals} awaiting approval` : 'Open county report',
            pending,
        };
    }

    if (user?.role === 'scde') {
        return {
            title: `${user?.subcounty?.name ?? 'Sub-county'} Digital Safety Overview`,
            description: 'Supervise school onboarding, protection coverage and incident response without exposing another sub-county’s records.',
            actionPath: '/schools',
            actionLabel: 'Review schools',
            pending: false,
        };
    }

    if (user?.role === 'clm') {
        return {
            title: `${user?.institution?.name ?? 'School'} Protection Operations`,
            description: 'Keep laboratories, learner devices and filtering components enrolled, attributed and reporting safely.',
            actionPath: '/deployment',
            actionLabel: 'Open deployment',
            pending: false,
        };
    }

    return {
        title: `${user?.institution?.name ?? 'School'} Learner Safety Overview`,
        description: 'Review the school’s learner safety signals, filtering decisions and safeguarding actions in one protected view.',
        actionPath: '/incidents',
        actionLabel: 'Review incidents & alerts',
        pending: false,
    };
}

function DashboardSection({ eyebrow, title, description, action, className = '', children }) {
    return (
        <section className={className}>
            <div className="mb-3.5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <div className="mb-1.5 flex items-center gap-2">
                        <span className="h-1.5 w-1.5 rounded-full bg-brand" />
                        <span className="text-[10px] font-bold uppercase tracking-[0.18em] text-text-muted">{eyebrow}</span>
                    </div>
                    <h2 className="text-lg font-semibold tracking-[-0.01em] text-text">{title}</h2>
                    <p className="mt-1 max-w-3xl text-xs leading-5 text-text-secondary">{description}</p>
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function SubcountyProtectionPanel({ rows, query, onOpen }) {
    const totals = rows.reduce(
        (acc, item) => {
            acc.schools += item.institutions_count ?? 0;
            acc.protected += item.protected_institutions_count ?? 0;
            acc.devices += item.devices_count ?? 0;
            acc.unattributed += item.unattributed_devices_count ?? 0;
            acc.incidents += item.open_incidents_count ?? 0;
            return acc;
        },
        { schools: 0, protected: 0, devices: 0, unattributed: 0, incidents: 0 },
    );

    const protectionCoverage = totals.schools ? (totals.protected / totals.schools) * 100 : 0;
    const attributionCoverage = totals.devices ? ((totals.devices - totals.unattributed) / totals.devices) * 100 : 0;

    return (
        <Panel className="min-w-0 overflow-hidden shadow-sm">
            <PanelHeader
                title="Sub-county protection status"
                description="Compare coverage, attribution and open incidents across the county"
                actions={
                    <Link to="/subcounties" className="group inline-flex items-center gap-1 text-xs font-semibold text-brand hover:text-brand-dark">
                        All sub-counties
                        <ArrowUpRightIcon size={13} className="transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
                    </Link>
                }
            />

            <div className="grid gap-px border-y border-border bg-border sm:grid-cols-2 lg:grid-cols-4">
                <SnapshotStat
                    icon={Building2Icon}
                    label="Schools protected"
                    value={`${formatNumber(totals.protected)}/${formatNumber(totals.schools)}`}
                    detail={`${protectionCoverage.toFixed(0)}% coverage`}
                    tone={protectionCoverage >= 80 ? 'success' : protectionCoverage >= 50 ? 'warning' : 'danger'}
                />
                <SnapshotStat
                    icon={MonitorCogIcon}
                    label="Managed devices"
                    value={formatNumber(totals.devices)}
                    detail={`${formatNumber(totals.unattributed)} need attribution`}
                    tone={totals.unattributed > 0 ? 'warning' : 'success'}
                />
                <SnapshotStat
                    icon={UsersRoundIcon}
                    label="Device attribution"
                    value={totals.devices ? `${attributionCoverage.toFixed(0)}%` : '—'}
                    detail="Assigned to known learners"
                    tone={attributionCoverage >= 90 ? 'success' : attributionCoverage >= 70 ? 'warning' : 'danger'}
                />
                <SnapshotStat
                    icon={CircleAlertIcon}
                    label="Open incidents"
                    value={formatNumber(totals.incidents)}
                    detail={totals.incidents ? 'Require safeguarding attention' : 'No open cases'}
                    tone={totals.incidents ? 'danger' : 'success'}
                />
            </div>

            <DataTable
                query={query}
                rows={rows}
                minWidth="760px"
                columns={[
                    'Sub-county',
                    'Schools',
                    'Protection coverage',
                    { key: 'devices', label: 'Devices', align: 'right' },
                    { key: 'attribution', label: 'Attribution', align: 'right' },
                    { key: 'incidents', label: 'Open incidents', align: 'right' },
                ]}
                empty={<EmptyState title="No sub-counties yet" description="County structure has not been defined." />}
            >
                {rows.map((item) => {
                    const coverage = item.institutions_count
                        ? (item.protected_institutions_count / item.institutions_count) * 100
                        : 0;
                    const attributed = item.devices_count
                        ? ((item.devices_count - item.unattributed_devices_count) / item.devices_count) * 100
                        : 0;

                    return (
                        <Row key={item.id} onClick={() => onOpen(`/schools?subcounty=${item.id}`)} className="text-sm">
                            <Cell bold className="text-brand">
                                <span className="inline-flex items-center gap-2">
                                    <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-brand-soft text-brand">
                                        <Building2Icon size={14} />
                                    </span>
                                    {item.name}
                                </span>
                            </Cell>
                            <Cell muted>{item.institutions_count}</Cell>
                            <Cell>
                                <div className="flex items-center gap-2.5">
                                    <div className="h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-surface-muted">
                                        <div
                                            className={`h-full rounded-full ${coverage >= 80 ? 'bg-success' : coverage >= 50 ? 'bg-warning' : 'bg-danger'}`}
                                            style={{ width: `${Math.min(coverage, 100)}%` }}
                                        />
                                    </div>
                                    <span className="min-w-[72px] text-xs font-medium text-text-secondary tabular-nums">
                                        {item.protected_institutions_count}/{item.institutions_count}
                                    </span>
                                </div>
                            </Cell>
                            <Cell align="right" className="font-medium tabular-nums">
                                {formatNumber(item.devices_count)}
                            </Cell>
                            <Cell align="right" className="font-semibold tabular-nums">
                                {item.devices_count ? `${attributed.toFixed(0)}%` : '—'}
                            </Cell>
                            <Cell align="right" className="tabular-nums">
                                <span className={item.open_incidents_count > 0 ? 'font-semibold text-danger' : 'text-text-secondary'}>
                                    {formatNumber(item.open_incidents_count)}
                                </span>
                            </Cell>
                        </Row>
                    );
                })}
            </DataTable>
        </Panel>
    );
}

function SnapshotStat({ icon: Icon, label, value, detail, tone = 'neutral' }) {
    const toneClass =
        tone === 'danger'
            ? 'bg-danger/10 text-danger'
            : tone === 'warning'
              ? 'bg-warning/10 text-warning-strong'
              : tone === 'success'
                ? 'bg-success/10 text-success'
                : 'bg-surface-muted text-text-secondary';

    return (
        <div className="bg-white px-4 py-4 sm:px-5">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[11px] font-medium text-text-secondary">{label}</p>
                    <p className="mt-1 text-xl font-bold tracking-[-0.02em] text-text tabular-nums">{value}</p>
                </div>
                <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${toneClass}`}>
                    <Icon size={15} />
                </span>
            </div>
            <p className="mt-1.5 truncate text-[10px] text-text-muted">{detail}</p>
        </div>
    );
}

/** The accountable officer's view: where the school stands on the platform. */
function InstitutionStatusPanel({ user, metrics }) {
    const status = institutionStatus(user?.institution?.status);

    const facts = [
        ['NEMIS code', user?.institution?.nemis_code],
        ['Sub-county', user?.subcounty?.name],
        ['Learners enrolled', formatNumber(metrics?.learners ?? 0)],
        ['Devices enrolled', formatNumber(metrics?.devices ?? 0)],
        ['Laboratories declared', formatNumber(user?.institution?.laboratories_count ?? 0)],
        ['Open incidents', formatNumber(metrics?.open_incidents ?? 0)],
    ];

    return (
        <Panel className="min-w-0 overflow-hidden shadow-sm">
            <PanelHeader title="Institution standing" description="Registration, enrolment and safeguarding status for your school" />

            <div className="p-5 sm:p-6">
                <div className="rounded-xl border border-border bg-surface-muted/35 p-4 sm:flex sm:items-start sm:justify-between sm:gap-6">
                    <div className="min-w-0">
                        <div className="mb-3 flex items-center gap-2">
                            <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-soft text-brand">
                                <ShieldCheckIcon size={16} />
                            </span>
                            <span className="text-[10px] font-bold uppercase tracking-[0.16em] text-text-muted">Current standing</span>
                        </div>
                        <StatusPill descriptor={status} />
                        <p className="mt-3 max-w-2xl text-sm leading-6 text-text-secondary">{status.note}</p>
                    </div>
                </div>

                <dl className="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {facts.map(([label, value]) => (
                        <div key={label} className="rounded-xl border border-border bg-white px-4 py-3.5">
                            <dt className="text-[10px] font-medium uppercase tracking-[0.08em] text-text-muted">{label}</dt>
                            <dd className="mt-1.5 text-sm font-semibold text-text tabular-nums">{value ?? '—'}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </Panel>
    );
}

/** The laboratory manager's view: what is broken and needs a hand today. */
function TechnicalStatusPanel({ metrics }) {
    const devices = metrics?.devices_by_status ?? {};
    const components = metrics?.components_by_health ?? {};

    const rows = [
        { label: 'Devices enrolled', value: metrics?.devices ?? 0, tone: 'neutral', to: '/devices' },
        { label: 'Devices reporting', value: devices.active ?? 0, tone: 'success', to: '/devices?status=active' },
        { label: 'Devices offline', value: devices.offline ?? 0, tone: 'danger', to: '/devices?status=offline' },
        { label: 'Need attention', value: devices.attention_required ?? 0, tone: 'warning', to: '/devices?status=attention_required' },
        { label: 'Need attribution', value: metrics?.unattributed_devices ?? 0, tone: 'warning', to: '/devices?attribution=required' },
        { label: 'Components healthy', value: components.healthy ?? 0, tone: 'success', to: '/deployment' },
        { label: 'Components degraded', value: components.degraded ?? 0, tone: 'warning', to: '/deployment' },
        { label: 'Components offline', value: components.offline ?? 0, tone: 'danger', to: '/deployment' },
    ];

    return (
        <Panel className="min-w-0 overflow-hidden shadow-sm">
            <PanelHeader
                title="Technical health"
                description="Endpoint enrolment, learner attribution and component availability"
                actions={
                    <Link to="/devices" className="group inline-flex items-center gap-1 text-xs font-semibold text-brand hover:text-brand-dark">
                        Open devices
                        <ArrowUpRightIcon size={13} className="transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
                    </Link>
                }
            />

            <div className="grid gap-px bg-border sm:grid-cols-2 lg:grid-cols-4">
                {rows.map((row) => {
                    const toneClass =
                        row.value === 0
                            ? 'bg-surface-muted text-text-muted'
                            : row.tone === 'danger'
                              ? 'bg-danger/10 text-danger'
                              : row.tone === 'warning'
                                ? 'bg-warning/10 text-warning-strong'
                                : row.tone === 'success'
                                  ? 'bg-success/10 text-success'
                                  : 'bg-brand-soft text-brand';

                    return (
                        <Link
                            key={row.label}
                            to={row.to}
                            className="group bg-white px-4 py-4 transition-colors hover:bg-surface-muted/45"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="text-[11px] font-medium text-text-secondary">{row.label}</p>
                                    <p className="mt-1 text-xl font-bold tracking-[-0.02em] text-text tabular-nums">{formatNumber(row.value)}</p>
                                </div>
                                <span className={`flex h-8 w-8 items-center justify-center rounded-lg ${toneClass}`}>
                                    {row.label.includes('Components') ? <GaugeIcon size={15} /> : <MonitorCogIcon size={15} />}
                                </span>
                            </div>
                            <span className="mt-2 inline-flex items-center gap-1 text-[10px] font-medium text-text-muted transition-colors group-hover:text-brand">
                                Inspect
                                <ArrowUpRightIcon size={11} />
                            </span>
                        </Link>
                    );
                })}
            </div>

            <div className="flex items-start gap-2.5 border-t border-border bg-surface-muted/35 px-5 py-3.5">
                <ShieldCheckIcon size={14} className="mt-0.5 shrink-0 text-brand" />
                <p className="text-[11px] leading-5 text-text-secondary">
                    A learner-use device carries at most two assigned learners and one authenticated learner per session. Correct any
                    device reported above that limit.
                </p>
            </div>
        </Panel>
    );
}
