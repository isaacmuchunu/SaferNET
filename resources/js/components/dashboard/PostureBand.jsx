import { useNavigate } from 'react-router-dom';
import { ArrowUpRightIcon } from 'lucide-react';
import { Skeleton } from '../Primitives';
import { formatNumber, formatPercent } from '../../lib/format';

/**
 * Deployment posture is state, not identity, so these are the reserved status
 * colours rather than a categorical palette.
 *
 * The worst adjacent pair here (protected ↔ attention required) measures ΔE 7.9
 * under simulated protanopia — inside the 6–8 floor band, which is legal only
 * with secondary encoding. Every segment therefore carries a written label and
 * a value in the legend below, and the segments are separated by a surface gap
 * rather than by hue alone.
 */
const BANDS = [
    { label: 'Protected', color: 'var(--color-success)', statuses: ['protected'] },
    { label: 'Attention required', color: 'var(--color-warning)', statuses: ['attention_required', 'attribution_required'] },
    { label: 'Deploying', color: 'var(--color-info)', statuses: ['approved', 'onboarding', 'deployment_in_progress'] },
    { label: 'Not deployed', color: 'var(--color-danger)', statuses: ['draft', 'pending_approval', 'rejected', 'suspended'] },
];

export function PostureBand({ metrics, loading, scopeLabel = 'county' }) {
    const navigate = useNavigate();
    const institutions = metrics?.institutions ?? 0;
    const subcounties = metrics?.subcounties ?? 0;
    const byStatus = metrics?.institutions_by_status ?? {};
    const total = Object.values(byStatus).reduce((sum, value) => sum + value, 0);
    const protectedSchools = byStatus.protected ?? 0;
    const protectedShare = total === 0 ? 0 : (protectedSchools / total) * 100;
    const componentAttention =
        (metrics?.components_by_health?.degraded ?? 0) + (metrics?.components_by_health?.offline ?? 0);

    const segments = BANDS.map((band) => {
        const value = band.statuses.reduce((sum, status) => sum + (byStatus[status] ?? 0), 0);

        return { ...band, value, share: total === 0 ? 0 : (value / total) * 100 };
    });

    const highlights = [
        {
            label: 'Schools protected',
            value: `${formatNumber(protectedSchools)}/${formatNumber(institutions)}`,
            path: '/schools',
        },
        {
            label: 'Open incidents',
            value: formatNumber(metrics?.open_incidents),
            path: '/incidents',
            urgent: (metrics?.open_incidents ?? 0) > 0,
        },
        {
            label: 'Components at risk',
            value: formatNumber(componentAttention),
            path: '/deployment',
            urgent: componentAttention > 0,
        },
    ];

    return (
        <section
            aria-label="Protection overview"
            className="overflow-hidden rounded-2xl border border-white/70 bg-white/92 shadow-panel backdrop-blur-md"
        >
            <div className="px-4 pt-4 pb-3">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="h-2 w-2 rounded-full bg-success shadow-[0_0_0_4px_rgba(22,122,74,.12)]" />
                            <p className="text-[10px] font-bold tracking-[0.14em] text-brand uppercase">Live posture</p>
                        </div>
                        <h2 className="mt-2 text-sm font-bold text-text">Protection overview</h2>
                        <p className="mt-0.5 text-[10px] text-text-secondary">
                            {formatNumber(institutions)} institutions
                            {subcounties > 0 ? ` · ${formatNumber(subcounties)} sub-counties` : ''}
                        </p>
                    </div>
                    <div className="text-right">
                        {loading ? (
                            <Skeleton className="h-8 w-14" />
                        ) : (
                            <p className="text-[28px] leading-none font-bold tracking-[-.04em] text-brand-deeper">
                                {formatPercent(protectedShare, 0)}
                            </p>
                        )}
                        <p className="mt-1 text-[9px] text-text-muted">{scopeLabel} health</p>
                    </div>
                </div>

                {/* A 2px surface gap does the separating — never a border drawn
                    around the marks. */}
                <div
                    className="mt-3 flex h-2 gap-[2px] overflow-hidden rounded-full"
                    role="img"
                    aria-label={segments.map((segment) => `${segment.value} ${segment.label}`).join(', ')}
                >
                    {segments
                        .filter((segment) => segment.value > 0)
                        .map((segment) => (
                            <span
                                key={segment.label}
                                className="h-full first:rounded-l-full last:rounded-r-full"
                                style={{ width: `${segment.share}%`, backgroundColor: segment.color }}
                            />
                        ))}
                </div>

                {/* The legend is the dependable channel: identity never rests on
                    hue, which is what the 6–8 CVD band obliges. */}
                <ul className="mt-2.5 flex flex-wrap gap-x-3 gap-y-1">
                    {segments
                        .filter((segment) => segment.value > 0)
                        .map((segment) => (
                            <li key={segment.label} className="flex min-w-0 items-center gap-1.5">
                                <span
                                    aria-hidden="true"
                                    className="h-2 w-2 shrink-0 rounded-sm"
                                    style={{ backgroundColor: segment.color }}
                                />
                                <span className="truncate text-[10px] text-text-secondary">{segment.label}</span>
                                <span className="text-[10px] font-bold text-text tabular-nums">{segment.value}</span>
                            </li>
                        ))}
                </ul>
            </div>

            <div className="grid grid-cols-3 gap-px border-y border-border bg-border">
                {highlights.map((item) => (
                    <button
                        key={item.label}
                        onClick={() => navigate(item.path)}
                        className="min-w-0 bg-white px-3 py-3 text-left transition hover:bg-brand-soft/55"
                    >
                        <p className="truncate text-[9px] font-medium text-text-muted">{item.label}</p>
                        {loading ? (
                            <Skeleton className="mt-1.5 h-5 w-12" />
                        ) : (
                            <p className={`mt-1 text-base font-bold tabular-nums ${item.urgent ? 'text-danger' : 'text-text'}`}>
                                {item.value}
                            </p>
                        )}
                    </button>
                ))}
            </div>

            <button
                onClick={() => navigate('/reports')}
                className="flex w-full items-center justify-between px-4 py-2.5 text-[10px] font-semibold text-brand transition hover:bg-brand-soft/45"
            >
                Full protection report
                <ArrowUpRightIcon size={13} />
            </button>
        </section>
    );
}
