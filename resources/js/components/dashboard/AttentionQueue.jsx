import { useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowRightIcon, CheckCircle2Icon } from 'lucide-react';
import { formatNumber } from '../../lib/format';

/**
 * The county's work queue, derived from live counts rather than a fixed list:
 * the highest-severity item is promoted, the rest are ranked beneath it.
 */
export function AttentionQueue({ metrics, capabilities }) {
    const navigate = useNavigate();

    const critical = metrics?.open_incidents_by_severity?.critical ?? 0;
    const high = metrics?.open_incidents_by_severity?.high ?? 0;
    const unattributed = metrics?.unattributed_devices ?? 0;
    const offlineComponents = metrics?.components_by_health?.offline ?? 0;
    const degradedComponents = metrics?.components_by_health?.degraded ?? 0;
    const attentionSchools = metrics?.institutions_by_status?.attention_required ?? 0;
    const pendingApprovals = metrics?.pending_approvals ?? 0;
    const pendingExceptions = metrics?.pending_exception_requests ?? 0;

    const items = [
        {
            count: capabilities?.viewIncidents ? critical + high : 0,
            title: `${formatNumber(critical + high)} high-risk learner incidents`,
            body: capabilities?.recordIncidentActions
                ? 'Repeated prohibited or circumvention activity requires school review today.'
                : 'Repeated prohibited or circumvention activity — the Head of Institution decides what action follows.',
            action: capabilities?.recordIncidentActions ? 'Review incidents' : 'View incidents',
            path: '/incidents?severity=critical',
            tone: 'danger',
        },
        {
            count: unattributed,
            title: `${formatNumber(unattributed)} devices without learner attribution`,
            body: 'Learner-use computers must be attributed to no more than two learners.',
            action: 'View devices',
            path: '/devices?attribution=required',
            tone: 'warning',
        },
        {
            count: offlineComponents + degradedComponents,
            title: `${formatNumber(offlineComponents + degradedComponents)} protection components degraded`,
            body: 'Gateways, endpoint agents or extensions have stopped reporting as healthy.',
            action: 'Investigate',
            path: '/deployment',
            tone: 'warning',
        },
        {
            count: capabilities?.viewSchoolRegister ? attentionSchools : 0,
            title: `${formatNumber(attentionSchools)} schools need protection attention`,
            body: 'Filtering coverage has degraded at these institutions.',
            action: 'View schools',
            path: '/schools?status=attention_required',
            tone: 'info',
        },
        {
            count: capabilities?.reviewRegistrations ? pendingApprovals : 0,
            title: `${formatNumber(pendingApprovals)} registrations await approval`,
            body: 'Sub-County Directors have submitted schools for county review.',
            action: 'Open approvals',
            path: '/approvals',
            tone: 'info',
        },
        {
            count: capabilities?.reviewExceptions ? pendingExceptions : 0,
            title: `${formatNumber(pendingExceptions)} exception requests pending`,
            body: 'Schools have asked for a domain to be released for classroom use.',
            action: 'Review exceptions',
            path: '/exceptions?status=pending',
            tone: 'info',
        },
    ].filter((item) => item.count > 0);

    const [primary, ...secondary] = items;

    return (
        <section className="flex flex-col rounded-xl border border-border bg-white p-5 shadow-card">
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-base font-bold">Requires Attention</h2>
                <span className="shrink-0 rounded-lg bg-surface-muted px-2 py-1 text-[11px] font-semibold text-text-secondary">
                    {items.length} queued
                </span>
            </div>

            {!primary && (
                <div className="flex flex-1 flex-col items-center justify-center py-10 text-center">
                    <span className="grid h-11 w-11 place-items-center rounded-full bg-success-soft text-success">
                        <CheckCircle2Icon size={20} />
                    </span>
                    <h3 className="mt-3 text-sm font-semibold">Nothing needs your attention</h3>
                    <p className="mt-1 max-w-xs text-xs text-text-secondary">
                        Every institution in your scope is protected, attributed and reporting.
                    </p>
                </div>
            )}

            {primary && (
                <article
                    className={clsx(
                        'mt-4 rounded-lg p-4',
                        primary.tone === 'danger' ? 'border border-danger/25 bg-danger-soft' : 'border border-warning/25 bg-warning-soft',
                    )}
                >
                    <p className={clsx('text-[10px] font-bold tracking-[.12em] uppercase', primary.tone === 'danger' ? 'text-danger' : 'text-warning-strong')}>
                        Highest priority
                    </p>
                    <h3 className="mt-1.5 text-sm leading-5 font-bold">{primary.title}</h3>
                    <p className="mt-1.5 text-xs leading-4 text-text-secondary">{primary.body}</p>
                    <button
                        onClick={() => navigate(primary.path)}
                        className={clsx(
                            'mt-3 flex h-8 items-center gap-1.5 rounded-lg px-3 text-xs font-semibold text-white transition-colors duration-150 ease-gov',
                            primary.tone === 'danger' ? 'bg-danger hover:bg-danger/90' : 'bg-warning-strong hover:bg-warning',
                        )}
                    >
                        {primary.action} <ArrowRightIcon size={14} />
                    </button>
                </article>
            )}

            <div className="mt-1 divide-y divide-border">
                {secondary.map((item) => (
                    <article key={item.title} className="flex gap-3 py-3.5">
                        <span
                            aria-hidden
                            className={clsx('mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full', item.tone === 'warning' ? 'bg-warning' : 'bg-info')}
                        />
                        <div className="min-w-0">
                            <h3 className="text-xs leading-4 font-semibold">{item.title}</h3>
                            <p className="mt-1 text-[11px] leading-4 text-text-secondary">{item.body}</p>
                            <button onClick={() => navigate(item.path)} className="mt-1.5 text-[11px] font-bold text-brand hover:underline">
                                {item.action}
                            </button>
                        </div>
                    </article>
                ))}
            </div>
        </section>
    );
}
