import { useState } from 'react';
import clsx from 'clsx';
import { Link } from 'react-router-dom';
import { ShieldCheckIcon } from 'lucide-react';
import { Skeleton } from '../Primitives';
import { formatNumber } from '../../lib/format';

/**
 * Severity is ordinal, so the ring runs one direction of intensity rather than
 * four unrelated hues. Every slice carries a written label and a value, so the
 * reading never rests on colour alone.
 */
const LEVELS = [
    { key: 'critical', label: 'Critical', color: '#8c1c24' },
    { key: 'high', label: 'High', color: '#d1472f' },
    { key: 'medium', label: 'Medium', color: '#c17712' },
    { key: 'low', label: 'Low', color: '#94a3a0' },
];

const RADIUS = 52;
const STROKE = 16;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

export function SeverityDonut({ metrics, loading, className }) {
    const [hover, setHover] = useState(null);

    const counts = metrics?.open_incidents_by_severity ?? {};
    const slices = LEVELS.map((level) => ({ ...level, value: counts[level.key] ?? 0 })).filter((level) => level.value > 0);
    const total = slices.reduce((sum, slice) => sum + slice.value, 0);

    let offset = 0;
    const arcs = slices.map((slice) => {
        const length = (slice.value / total) * CIRCUMFERENCE;
        const arc = { ...slice, length, offset };
        offset += length;

        return arc;
    });

    const focused = hover ?? null;

    return (
        <section className={clsx('rounded-xl border border-border bg-white p-5 shadow-card', className)}>
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-base font-bold">Open Incidents by Severity</h2>
                    <p className="mt-1 text-xs text-text-secondary">Awaiting review or action in your scope</p>
                </div>
            </div>

            {loading ? (
                <Skeleton className="mt-5 h-[150px] w-full rounded-lg" />
            ) : total === 0 ? (
                <div className="flex flex-col items-center justify-center py-10 text-center">
                    <span className="grid h-11 w-11 place-items-center rounded-full bg-success-soft text-success">
                        <ShieldCheckIcon size={20} />
                    </span>
                    <h3 className="mt-3 text-sm font-semibold">No open incidents</h3>
                    <p className="mt-1 max-w-[16rem] text-xs text-text-secondary">
                        Nothing currently requires safeguarding review in your scope.
                    </p>
                </div>
            ) : (
                <div className="mt-4 flex flex-wrap items-center gap-6">
                    <div className="relative shrink-0">
                        <svg width="136" height="136" viewBox="0 0 136 136" role="img" aria-label={arcs.map((arc) => `${arc.value} ${arc.label}`).join(', ')}>
                            <circle cx="68" cy="68" r={RADIUS} fill="none" stroke="#edf2f1" strokeWidth={STROKE} />
                            {arcs.map((arc) => (
                                <circle
                                    key={arc.key}
                                    cx="68"
                                    cy="68"
                                    r={RADIUS}
                                    fill="none"
                                    stroke={arc.color}
                                    strokeWidth={focused === arc.key ? STROKE + 4 : STROKE}
                                    strokeLinecap="butt"
                                    strokeDasharray={`${Math.max(arc.length - 3, 1)} ${CIRCUMFERENCE - Math.max(arc.length - 3, 1)}`}
                                    strokeDashoffset={-arc.offset}
                                    transform="rotate(-90 68 68)"
                                    className="transition-[stroke-width] duration-150 ease-gov"
                                    onPointerEnter={() => setHover(arc.key)}
                                    onPointerLeave={() => setHover(null)}
                                />
                            ))}
                        </svg>

                        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            <span className="text-[26px] leading-none font-bold tracking-[-.03em] tabular-nums">
                                {formatNumber(focused ? (counts[focused] ?? 0) : total)}
                            </span>
                            <span className="mt-1 text-[10px] tracking-wider text-text-muted uppercase">
                                {focused ? LEVELS.find((level) => level.key === focused).label : 'Open'}
                            </span>
                        </div>
                    </div>

                    <ul className="min-w-[9rem] flex-1 space-y-2">
                        {arcs.map((arc) => (
                            <li key={arc.key}>
                                <Link
                                    to={`/incidents?severity=${arc.key}&status=open`}
                                    onPointerEnter={() => setHover(arc.key)}
                                    onPointerLeave={() => setHover(null)}
                                    className="flex items-center gap-2.5 rounded-md px-1.5 py-1 transition-colors hover:bg-surface-muted"
                                >
                                    <span aria-hidden className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ backgroundColor: arc.color }} />
                                    <span className="flex-1 truncate text-xs text-text-secondary">{arc.label}</span>
                                    <span className="text-xs font-bold tabular-nums">{arc.value}</span>
                                    <span className="w-9 text-right text-[11px] text-text-muted tabular-nums">
                                        {Math.round((arc.value / total) * 100)}%
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
