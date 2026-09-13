import {
    ActivityIcon,
    CheckCircle2Icon,
    ChromeIcon,
    CloudIcon,
    MonitorIcon,
    NetworkIcon,
    PuzzleIcon,
    ShieldAlertIcon,
    TriangleAlertIcon,
} from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import { Cell, DataTable, EmptyState, Panel, PanelHeader, Row, Skeleton } from '../components/Primitives';
import { StatusPill } from '../components/StatusPill';
import { useDashboard, useProtectionComponents, useSecurityEvents } from '../lib/queries';
import { COMPONENT_TYPES, HEALTH_STATUS, SEVERITY } from '../lib/domain';
import { formatNumber, formatRelative, titleCase } from '../lib/format';

const TYPE_ICONS = {
    gateway: NetworkIcon,
    endpoint_agent: MonitorIcon,
    browser_extension: PuzzleIcon,
    dns_filter: CloudIcon,
};

export function DeploymentPage() {
    const dashboard = useDashboard();
    const components = useProtectionComponents();
    const securityEvents = useSecurityEvents();

    const rows = components.data?.data ?? [];
    const events = securityEvents.data?.data ?? [];
    const health = dashboard.data?.components_by_health ?? {};
    const byType = dashboard.data?.components_by_type ?? {};
    const healthy = health.healthy ?? 0;
    const attention = (health.degraded ?? 0) + (health.offline ?? 0);
    const total = Object.values(health).reduce((sum, value) => sum + Number(value ?? 0), 0);
    const healthRate = total > 0 ? Math.round((healthy / total) * 100) : 0;
    const urgentEvents = events.filter((event) => ['critical', 'high'].includes(event.severity)).length;

    return (
        <div className="animate-fade-up pb-8">
            <PageHeader
                title="Protection deployment"
                description="Live component coverage, policy synchronisation and endpoint-security signals across your authorised scope."
                meta={`${formatNumber(total)} reporting components`}
            />

            <section className="grid gap-4 xl:grid-cols-[minmax(300px,.72fr)_minmax(0,1.45fr)]">
                <div className="relative isolate overflow-hidden rounded-2xl bg-brand-deeper p-5 text-white shadow-panel sm:p-6">
                    <div className="pointer-events-none absolute -top-24 -right-20 -z-10 h-64 w-64 rounded-full bg-white/10 blur-2xl" />
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-[10px] font-bold tracking-[0.16em] text-white/60 uppercase">Network posture</p>
                            <h2 className="mt-1.5 text-lg font-bold">Deployment health</h2>
                        </div>
                        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold ${attention ? 'bg-warning-soft text-warning-strong' : 'bg-success-soft text-success'}`}>
                            {attention ? <TriangleAlertIcon size={12} /> : <CheckCircle2Icon size={12} />}
                            {attention ? `${attention} need attention` : 'All healthy'}
                        </span>
                    </div>

                    {dashboard.isPending ? (
                        <Skeleton className="mt-8 h-20 w-full bg-white/15" />
                    ) : (
                        <>
                            <div className="mt-8 flex items-end justify-between gap-5">
                                <div>
                                    <p className="text-[54px] leading-none font-bold tracking-[-.05em]">{healthRate}%</p>
                                    <p className="mt-2 text-xs text-white/65">components reporting healthy</p>
                                </div>
                                <ActivityIcon size={46} strokeWidth={1.4} className="text-white/35" />
                            </div>
                            <div className="mt-6 h-2 overflow-hidden rounded-full bg-white/15">
                                <div className="h-full rounded-full bg-[#65c5b7]" style={{ width: `${healthRate}%` }} />
                            </div>
                            <div className="mt-5 grid grid-cols-3 gap-3 border-t border-white/10 pt-4">
                                <HealthFact label="Healthy" value={healthy} tone="text-[#83dfce]" />
                                <HealthFact label="Degraded" value={health.degraded ?? 0} tone="text-[#ffd27d]" />
                                <HealthFact label="Offline" value={health.offline ?? 0} tone="text-[#ff9ca5]" />
                            </div>
                        </>
                    )}
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    {Object.entries(COMPONENT_TYPES).map(([type, label]) => (
                        <ComponentTypeCard
                            key={type}
                            type={type}
                            label={label}
                            counts={{ healthy: 0, degraded: 0, offline: 0, unknown: 0, ...(byType[type] ?? {}) }}
                            loading={dashboard.isPending}
                        />
                    ))}
                </div>
            </section>

            <section className="mt-5 grid items-start gap-5 xl:grid-cols-[minmax(0,1.55fr)_minmax(320px,.65fr)]">
                <Panel className="min-w-0 overflow-hidden">
                    <PanelHeader
                        title="Component inventory"
                        description="Version, last check-in and policy-sync state for every enrolled protection component."
                    />
                    <DataTable
                        query={components}
                        rows={rows}
                        minWidth="820px"
                        columns={['Component', 'Identifier', 'Version', 'Last seen', 'Policy synced', 'Health']}
                        empty={
                            <EmptyState
                                icon={ChromeIcon}
                                title="No components reporting"
                                description="Protection components appear here once an agent or gateway is installed."
                            />
                        }
                    >
                        {rows.map((component) => (
                            <Row key={component.id}>
                                <Cell bold>{COMPONENT_TYPES[component.type] ?? component.type}</Cell>
                                <Cell mono muted>{component.identifier}</Cell>
                                <Cell muted>{component.version ?? '—'}</Cell>
                                <Cell muted>{formatRelative(component.last_seen_at)}</Cell>
                                <Cell muted>{formatRelative(component.policy_synced_at)}</Cell>
                                <Cell><StatusPill descriptor={HEALTH_STATUS[component.health_status]} /></Cell>
                            </Row>
                        ))}
                    </DataTable>
                </Panel>

                <Panel className="min-w-0 overflow-hidden xl:sticky xl:top-24">
                    <PanelHeader
                        title="Security signal stream"
                        description={`${urgentEvents} high-priority event${urgentEvents === 1 ? '' : 's'} in the current view`}
                    />
                    {securityEvents.isPending ? (
                        <div className="space-y-3 p-4">
                            {[1, 2, 3, 4].map((item) => <Skeleton key={item} className="h-16 w-full" />)}
                        </div>
                    ) : events.length === 0 ? (
                        <EmptyState icon={ShieldAlertIcon} title="No security events" description="No protection tampering has been reported." />
                    ) : (
                        <ul className="divide-y divide-border">
                            {events.slice(0, 8).map((event) => (
                                <li key={event.id} className="px-4 py-3.5 transition-colors hover:bg-surface-muted/50">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="truncate text-xs font-semibold text-text">{titleCase(event.type)}</p>
                                            <p className="mt-1 line-clamp-2 text-[11px] leading-4 text-text-secondary">{event.description}</p>
                                        </div>
                                        <StatusPill descriptor={SEVERITY[event.severity]} />
                                    </div>
                                    <div className="mt-2 flex items-center justify-between gap-3 text-[10px] text-text-muted">
                                        <span className="truncate">{event.response ?? 'Awaiting response'}</span>
                                        <span className="shrink-0">{formatRelative(event.occurred_at)}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </section>
        </div>
    );
}

function ComponentTypeCard({ type, label, counts, loading }) {
    const Icon = TYPE_ICONS[type];
    const total = Object.values(counts).reduce((sum, value) => sum + Number(value ?? 0), 0);
    const healthyShare = total > 0 ? (counts.healthy / total) * 100 : 0;
    const degradedShare = total > 0 ? (counts.degraded / total) * 100 : 0;
    const offlineShare = total > 0 ? (counts.offline / total) * 100 : 0;

    return (
        <Panel className="overflow-hidden p-4 sm:p-5">
            <div className="flex items-start justify-between gap-4">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand">
                        <Icon size={18} />
                    </span>
                    <div className="min-w-0">
                        <h3 className="truncate text-sm font-bold">{label}</h3>
                        <p className="mt-0.5 text-[10px] text-text-muted">Enrolled components</p>
                    </div>
                </div>
                {loading ? <Skeleton className="h-7 w-10" /> : <strong className="text-2xl tracking-[-.03em] tabular-nums">{total}</strong>}
            </div>

            <div className="mt-5 flex h-2 overflow-hidden rounded-full bg-surface-muted">
                {healthyShare > 0 && <span className="bg-success" style={{ width: `${healthyShare}%` }} />}
                {degradedShare > 0 && <span className="bg-warning" style={{ width: `${degradedShare}%` }} />}
                {offlineShare > 0 && <span className="bg-danger" style={{ width: `${offlineShare}%` }} />}
            </div>
            <div className="mt-3 grid grid-cols-3 gap-2 text-center">
                <MiniFact label="Healthy" value={counts.healthy} className="text-success" />
                <MiniFact label="Degraded" value={counts.degraded} className="text-warning-strong" />
                <MiniFact label="Offline" value={counts.offline} className="text-danger" />
            </div>
        </Panel>
    );
}

function HealthFact({ label, value, tone }) {
    return (
        <div>
            <p className={`text-xl font-bold tabular-nums ${tone}`}>{formatNumber(value)}</p>
            <p className="mt-1 text-[10px] text-white/55">{label}</p>
        </div>
    );
}

function MiniFact({ label, value, className }) {
    return (
        <div className="rounded-lg bg-surface-muted/60 px-2 py-2">
            <p className={`text-sm font-bold tabular-nums ${className}`}>{formatNumber(value)}</p>
            <p className="mt-0.5 truncate text-[9px] text-text-muted">{label}</p>
        </div>
    );
}
