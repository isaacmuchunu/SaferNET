import { useState } from 'react';
import clsx from 'clsx';
import { Link } from 'react-router-dom';
import { ShieldCheckIcon } from 'lucide-react';
import { Skeleton } from '../Primitives';
import { formatNumber } from '../../lib/format';

/**
 * Severity is ordinal, so the ring is one hue running light → dark rather than
 * four unrelated colours: the reader sees the order in the colour itself.
 *
 * Nothing rests on that colour. Every slice carries a written label and a
 * value, the legend is always present, and the same numbers are available as a
 * table — so the ring is the quick read, never the only one.
 */
const LEVELS = [
    { key: 'critical', label: 'Critical', color: 'var(--color-viz-sev-critical)' },
    { key: 'high', label: 'High', color: 'var(--color-viz-sev-high)' },
    { key: 'medium', label: 'Medium', color: 'var(--color-viz-sev-medium)' },
    { key: 'low', label: 'Low', color: 'var(--color-viz-sev-low)' },
];

const SIZE = 148;
const CENTRE = SIZE / 2;
const RADIUS = 56;
const STROKE = 14;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

// White doing the separating: a 2px surface gap between segments, never a
// stroke drawn around them.
const GAP = 2;

export function SeverityDonut({ metrics, loading, className }) {
    const [hover, setHover] = useState(null);
    const [showTable, setShowTable] = useState(false);

    const counts = metrics?.open_incidents_by_severity ?? {};
    const slices = LEVELS.map((level) => ({ ...level, value: counts[level.key] ?? 0 })).filter((level) => level.value > 0);
    const total = slices.reduce((sum, slice) => sum + slice.value, 0);

    let offset = 0;
    const arcs = slices.map((slice) => {
        const length = (slice.value / total) * CIRCUMFERENCE;
        const arc = { ...slice, length, offset, share: Math.round((slice.value / total) * 100) };
        offset += length;

        return arc;
    });

    const focusedArc = arcs.find((arc) => arc.key === hover) ?? null;

    return (
        <section className={clsx('min-w-0 rounded-xl border border-border bg-white p-5 shadow-card', className)}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-base font-bold tracking-[-.01em]">Open incidents by severity</h2>
                    <p className="mt-1 text-xs text-text-secondary">Awaiting review or action in your scope</p>
                </div>

                {total > 0 && (
                    <button
                        type="button"
                        onClick={() => setShowTable((open) => !open)}
                        aria-pressed={showTable}
                        className="shrink-0 rounded-lg px-2 py-1 text-[11px] font-semibold text-text-secondary transition-colors hover:bg-surface-muted hover:text-text focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
                    >
                        {showTable ? 'Chart' : 'Table'}
                    </button>
                )}
            </div>

            {loading ? (
                <Skeleton className="mt-5 h-[148px] w-full rounded-lg" />
            ) : total === 0 ? (
                <div className="flex flex-col items-center justify-center py-10 text-center">
                    <span className="grid h-11 w-11 place-items-center rounded-full bg-success-soft text-success">
                        <ShieldCheckIcon size={20} aria-hidden="true" />
                    </span>
                    <h3 className="mt-3 text-sm font-semibold">No open incidents</h3>
                    <p className="mt-1 max-w-[16rem] text-xs text-text-secondary">
                        Nothing currently requires safeguarding review in your scope.
                    </p>
                </div>
            ) : showTable ? (
                <table className="mt-4 w-full text-left text-xs">
                    <caption className="sr-only">Open incidents by severity</caption>
                    <thead>
                        <tr className="border-b border-border text-[11px] text-text-muted">
                            <th scope="col" className="pb-2 font-medium">Severity</th>
                            <th scope="col" className="pb-2 text-right font-medium">Incidents</th>
                            <th scope="col" className="pb-2 text-right font-medium">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        {arcs.map((arc) => (
                            <tr key={arc.key} className="border-b border-border/60 last:border-0">
                                <th scope="row" className="py-2 font-medium text-text-secondary">{arc.label}</th>
                                <td className="py-2 text-right font-bold tabular-nums">{arc.value}</td>
                                <td className="py-2 text-right text-text-muted tabular-nums">{arc.share}%</td>
                            </tr>
                        ))}
                        <tr>
                            <th scope="row" className="pt-2 font-semibold">Total</th>
                            <td className="pt-2 text-right font-bold tabular-nums">{total}</td>
                            <td className="pt-2 text-right text-text-muted tabular-nums">100%</td>
                        </tr>
                    </tbody>
                </table>
            ) : (
                <div className="mt-4 flex flex-wrap items-center gap-6">
                    <div className="relative shrink-0">
                        <svg
                            width={SIZE}
                            height={SIZE}
                            viewBox={`0 0 ${SIZE} ${SIZE}`}
                            role="img"
                            aria-label={`${total} open incidents: ${arcs.map((arc) => `${arc.value} ${arc.label}`).join(', ')}`}
                        >
                            <circle cx={CENTRE} cy={CENTRE} r={RADIUS} fill="none" stroke="var(--color-viz-track)" strokeWidth={STROKE} />

                            {arcs.map((arc) => {
                                // The gap is taken out of the segment itself, so the
                                // surface shows through between neighbours.
                                const drawn = Math.max(arc.length - GAP, 0.75);

                                return (
                                    <circle
                                        key={arc.key}
                                        cx={CENTRE}
                                        cy={CENTRE}
                                        r={RADIUS}
                                        fill="none"
                                        stroke={arc.color}
                                        strokeWidth={hover === arc.key ? STROKE + 3 : STROKE}
                                        strokeLinecap="butt"
                                        strokeDasharray={`${drawn} ${CIRCUMFERENCE - drawn}`}
                                        strokeDashoffset={-arc.offset}
                                        transform={`rotate(-90 ${CENTRE} ${CENTRE})`}
                                        className="transition-[stroke-width] duration-150 ease-gov motion-reduce:transition-none"
                                        onPointerEnter={() => setHover(arc.key)}
                                        onPointerLeave={() => setHover(null)}
                                    />
                                );
                            })}
                        </svg>

                        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            {/* Proportional figures: tabular-nums makes a number like
                                121 look loose at this size. */}
                            <span className="text-[27px] leading-none font-bold tracking-[-.03em]">
                                {formatNumber(focusedArc ? focusedArc.value : total)}
                            </span>
                            <span className="mt-1 text-[10px] tracking-wider text-text-muted uppercase">
                                {focusedArc ? focusedArc.label : 'Open'}
                            </span>
                        </div>
                    </div>

                    <ul className="min-w-[10rem] flex-1 space-y-1">
                        {arcs.map((arc) => (
                            <li key={arc.key}>
                                <Link
                                    to={`/incidents?severity=${arc.key}&status=open`}
                                    onPointerEnter={() => setHover(arc.key)}
                                    onPointerLeave={() => setHover(null)}
                                    onFocus={() => setHover(arc.key)}
                                    onBlur={() => setHover(null)}
                                    className="flex items-center gap-2.5 rounded-md px-1.5 py-1.5 transition-colors hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
                                >
                                    <span aria-hidden="true" className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ backgroundColor: arc.color }} />
                                    <span className="flex-1 truncate text-xs text-text-secondary">{arc.label}</span>
                                    <span className="text-xs font-bold tabular-nums">{arc.value}</span>
                                    <span className="w-9 text-right text-[11px] text-text-muted tabular-nums">{arc.share}%</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
