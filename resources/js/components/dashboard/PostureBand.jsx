import { useNavigate } from 'react-router-dom';
import { ArrowUpRightIcon } from 'lucide-react';
import { Skeleton } from '../Primitives';
import { formatNumber, formatPercent } from '../../lib/format';

const BANDS = [
    { label: 'Protected', color: '#167a4a', statuses: ['protected'] },
    { label: 'Attention required', color: '#c17712', statuses: ['attention_required', 'attribution_required'] },
    { label: 'Deploying', color: '#2563a6', statuses: ['approved', 'onboarding', 'deployment_in_progress'] },
    { label: 'Not deployed', color: '#b4232f', statuses: ['draft', 'pending_approval', 'rejected', 'suspended'] },
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

                <div
                    className="mt-3 flex h-2 overflow-hidden rounded-full bg-surface-muted"
                    role="img"
                    aria-label={segments.map((segment) => `${segment.value} ${segment.label}`).join(', ')}
                >
                    {segments
                        .filter((segment) => segment.value > 0)
                        .map((segment) => (
                            <span
                                key={segment.label}
                                className="h-full border-r border-white last:border-0"
                                style={{ width: `${segment.share}%`, backgroundColor: segment.color }}
                            />
                        ))}
                </div>
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
