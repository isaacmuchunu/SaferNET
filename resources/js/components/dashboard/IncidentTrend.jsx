import { useEffect, useId, useMemo, useRef, useState } from 'react';
import clsx from 'clsx';
import { Skeleton } from '../Primitives';
import { useIncidentTrend } from '../../lib/queries';
import { formatDate, formatNumber } from '../../lib/format';

const FILTERS = [
    ['', 'All'],
    ['critical', 'Critical'],
    ['high', 'High'],
    ['medium', 'Medium'],
    ['low', 'Low'],
];

const HEIGHT = 208;
const PAD = { top: 18, right: 12, bottom: 26, left: 34 };
const BRAND = 'var(--color-brand, #0d5c55)';
const GRID = 'var(--color-border, #dbe4e2)';

const clamp = (value, low, high) => Math.min(Math.max(value, low), high);

/** Round the axis up to a readable, halvable number so the labels sit on whole values. */
function axisCeiling(value) {
    const peak = Math.max(value, 1);
    if (peak <= 2) return 2;
    if (peak <= 6) return 6;
    if (peak <= 10) return 10;

    const magnitude = 10 ** Math.floor(Math.log10(peak));
    const scaled = peak / magnitude;
    const step = scaled <= 1.2 ? 1.2 : scaled <= 2 ? 2 : scaled <= 4 ? 4 : scaled <= 6 ? 6 : scaled <= 8 ? 8 : 10;

    return Math.round(step * magnitude);
}

/**
 * Catmull-Rom through the points as a cubic path. Control points are clamped
 * inside each segment so a spike never makes the curve dip below the values it
 * joins — an overshoot would read as incidents that never happened.
 */
function smoothPath(points) {
    if (points.length < 2) {
        return points.length === 1 ? `M ${points[0][0]},${points[0][1]}` : '';
    }

    return points.reduce((path, point, index) => {
        if (index === 0) {
            return `M ${point[0]},${point[1]}`;
        }

        const previous = points[index - 1];
        const beforePrevious = points[index - 2] ?? previous;
        const next = points[index + 1] ?? point;

        const low = Math.min(previous[1], point[1]);
        const high = Math.max(previous[1], point[1]);

        const control1 = [previous[0] + (point[0] - beforePrevious[0]) / 6, clamp(previous[1] + (point[1] - beforePrevious[1]) / 6, low, high)];
        const control2 = [point[0] - (next[0] - previous[0]) / 6, clamp(point[1] - (next[1] - previous[1]) / 6, low, high)];

        return `${path} C ${control1[0]},${control1[1]} ${control2[0]},${control2[1]} ${point[0]},${point[1]}`;
    }, '');
}

/** Real pixel width, so the SVG never has to be stretched to fit. */
function useMeasuredWidth() {
    const ref = useRef(null);
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const node = ref.current;
        if (!node || typeof ResizeObserver === 'undefined') return undefined;

        const observer = new ResizeObserver(([entry]) => {
            const next = entry.contentRect.width;
            setWidth((current) => (Math.abs(current - next) < 0.5 ? current : next));
        });

        observer.observe(node);
        return () => observer.disconnect();
    }, []);

    return [ref, width];
}

function useReducedMotion() {
    const [reduced, setReduced] = useState(false);

    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const sync = () => setReduced(query.matches);

        sync();
        query.addEventListener('change', sync);
        return () => query.removeEventListener('change', sync);
    }, []);

    return reduced;
}

export function IncidentTrend({ className }) {
    const gradientId = useId();
    const [severity, setSeverity] = useState('');
    const [hover, setHover] = useState(null);
    const [plotRef, plotWidth] = useMeasuredWidth();
    const reducedMotion = useReducedMotion();
    const filterRefs = useRef([]);

    const trend = useIncidentTrend({ days: 30, severity: severity || undefined });

    const series = trend.data?.series ?? [];
    const categories = trend.data?.categories ?? [];
    const total = trend.data?.total ?? 0;
    const days = trend.data?.days ?? 30;

    const width = plotWidth || 720;
    const innerWidth = Math.max(width - PAD.left - PAD.right, 1);
    const innerHeight = HEIGHT - PAD.top - PAD.bottom;

    const ceiling = axisCeiling(Math.max(...series.map((point) => point.count), 0));
    const leading = categories[0]?.count ?? 1;
    const average = series.length > 0 ? total / series.length : 0;

    const toX = (index) => PAD.left + (series.length > 1 ? (index * innerWidth) / (series.length - 1) : innerWidth / 2);
    const toY = (count) => PAD.top + innerHeight - (count / ceiling) * innerHeight;

    const points = useMemo(
        () => series.map((point, index) => [toX(index), toY(point.count)]),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [series, width, ceiling],
    );

    const line = smoothPath(points);
    const area = points.length > 1 ? `${line} L ${toX(series.length - 1)},${PAD.top + innerHeight} L ${PAD.left},${PAD.top + innerHeight} Z` : '';
    const active = hover === null ? null : points[hover];

    // Last week against the week before it — the comparison a safeguarding lead actually makes.
    const window = Math.min(7, Math.floor(series.length / 2));
    const sum = (from, to) => series.slice(from, to).reduce((count, point) => count + point.count, 0);
    const recent = window > 0 ? sum(series.length - window, series.length) : 0;
    const prior = window > 0 ? sum(series.length - window * 2, series.length - window) : 0;
    const change = window > 0 && prior > 0 ? Math.round(((recent - prior) / prior) * 100) : null;

    // Reveal the line once per filter change, so switching severity shows what moved.
    const [revealed, setRevealed] = useState(false);
    useEffect(() => {
        setRevealed(false);
        const frame = requestAnimationFrame(() => setRevealed(true));
        return () => cancelAnimationFrame(frame);
    }, [severity, series.length]);

    function moveHoverTo(clientX, element) {
        if (series.length === 0) return;

        const bounds = element.getBoundingClientRect();
        const ratio = (clientX - bounds.left - PAD.left) / innerWidth;

        setHover(clamp(Math.round(ratio * (series.length - 1)), 0, series.length - 1));
    }

    function onChartKeyDown(event) {
        if (series.length === 0) return;

        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault();
            const step = event.key === 'ArrowRight' ? 1 : -1;
            setHover((current) => clamp((current === null ? series.length - 1 : current) + step, 0, series.length - 1));
        } else if (event.key === 'Home') {
            event.preventDefault();
            setHover(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            setHover(series.length - 1);
        } else if (event.key === 'Escape') {
            setHover(null);
        }
    }

    function onFilterKeyDown(event, index) {
        const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
        if (step === 0) return;

        event.preventDefault();
        const next = (index + step + FILTERS.length) % FILTERS.length;
        setSeverity(FILTERS[next][0]);
        filterRefs.current[next]?.focus();
    }

    const selectedFilter = FILTERS.findIndex(([value]) => value === severity);
    const ticks = [ceiling, ceiling / 2, 0];
    const labelIndices = [0, Math.floor((series.length - 1) / 2), series.length - 1].filter(
        (index, position, all) => series[index] && all.indexOf(index) === position,
    );

    return (
        <section className={clsx('min-w-0 rounded-2xl border border-border bg-white shadow-card', className)}>
            <header className="flex flex-col gap-4 p-5 pb-0 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-base font-bold tracking-[-.01em]">Internet safety incidents</h2>
                    <p className="mt-1 text-xs text-text-secondary">Learner-attributed incidents raised in the last {days} days</p>
                </div>

                <div className="flex shrink-0 items-end gap-4">
                    <div>
                        <p className="text-[28px] leading-none font-bold tracking-[-.03em] tabular-nums">{formatNumber(total)}</p>
                        <p className="mt-1.5 text-[11px] text-text-muted tabular-nums">{average.toFixed(1)} a day on average</p>
                    </div>
                    {change !== null && (
                        <span
                            className={clsx(
                                'mb-0.5 inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-semibold tabular-nums',
                                change > 0 ? 'bg-rose-50 text-rose-700' : change < 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-surface-muted text-text-secondary',
                            )}
                            title={`${recent} in the last ${window} days, ${prior} in the ${window} days before`}
                        >
                            <svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true" className={clsx(change < 0 && 'rotate-180')}>
                                <path d="M5 1.5 L9 7 L1 7 Z" fill="currentColor" />
                            </svg>
                            {change > 0 ? '+' : ''}
                            {change}%
                            <span className="sr-only"> compared with the previous {window} days</span>
                        </span>
                    )}
                </div>
            </header>

            <div className="px-5 pt-4">
                <div className="relative grid grid-cols-5 gap-1 rounded-xl bg-surface-muted p-1" role="radiogroup" aria-label="Filter by severity">
                    <span
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-y-1 left-1 rounded-lg bg-white shadow-sm transition-transform duration-200 ease-gov motion-reduce:transition-none"
                        style={{
                            width: `calc((100% - 0.5rem) / ${FILTERS.length})`,
                            transform: `translateX(calc(${Math.max(selectedFilter, 0)} * 100%))`,
                        }}
                    />
                    {FILTERS.map(([value, label], index) => (
                        <button
                            key={label}
                            ref={(node) => {
                                filterRefs.current[index] = node;
                            }}
                            type="button"
                            role="radio"
                            aria-checked={severity === value}
                            tabIndex={severity === value ? 0 : -1}
                            onClick={() => setSeverity(value)}
                            onKeyDown={(event) => onFilterKeyDown(event, index)}
                            className={clsx(
                                'relative rounded-lg px-2 py-1.5 text-[11px] font-semibold transition-colors duration-150 ease-gov',
                                'focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-1 focus-visible:outline-none',
                                severity === value ? 'text-brand' : 'text-text-secondary hover:text-text',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="px-5 pt-4">
                {trend.isPending ? (
                    <Skeleton className="h-[208px] w-full rounded-xl" />
                ) : trend.isError ? (
                    <div className="flex h-[208px] flex-col items-center justify-center gap-3 rounded-xl bg-surface-muted text-center">
                        <p className="text-xs text-text-secondary">The trend data didn&rsquo;t load.</p>
                        {trend.refetch && (
                            <button
                                type="button"
                                onClick={() => trend.refetch()}
                                className="rounded-lg bg-brand px-3 py-1.5 text-[11px] font-semibold text-white transition-opacity hover:opacity-90"
                            >
                                Try again
                            </button>
                        )}
                    </div>
                ) : series.length === 0 ? (
                    <div className="flex h-[208px] items-center justify-center rounded-xl bg-surface-muted">
                        <p className="text-xs text-text-secondary">No incidents recorded at this severity in the last {days} days.</p>
                    </div>
                ) : (
                    <div
                        ref={plotRef}
                        className="relative"
                        onPointerMove={(event) => moveHoverTo(event.clientX, event.currentTarget)}
                        onPointerDown={(event) => moveHoverTo(event.clientX, event.currentTarget)}
                        onPointerLeave={() => setHover(null)}
                    >
                        <svg
                            width={width}
                            height={HEIGHT}
                            viewBox={`0 0 ${width} ${HEIGHT}`}
                            className="w-full rounded-lg focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
                            tabIndex={0}
                            role="img"
                            aria-label={`Incidents a day over the last ${days} days. Use the arrow keys to read each day.`}
                            onKeyDown={onChartKeyDown}
                            onFocus={() => setHover((current) => current ?? series.length - 1)}
                            onBlur={() => setHover(null)}
                        >
                            <defs>
                                <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor={BRAND} stopOpacity="0.22" />
                                    <stop offset="60%" stopColor={BRAND} stopOpacity="0.06" />
                                    <stop offset="100%" stopColor={BRAND} stopOpacity="0" />
                                </linearGradient>
                            </defs>

                            {ticks.map((tick) => (
                                <g key={tick}>
                                    <line
                                        x1={PAD.left}
                                        y1={toY(tick)}
                                        x2={width - PAD.right}
                                        y2={toY(tick)}
                                        stroke={GRID}
                                        strokeOpacity={tick === 0 ? 1 : 0.45}
                                        strokeWidth="1"
                                    />
                                    <text x={PAD.left - 8} y={toY(tick) + 3} textAnchor="end" className="fill-text-muted text-[10px] tabular-nums">
                                        {tick}
                                    </text>
                                </g>
                            ))}

                            {labelIndices.map((index) => (
                                <text
                                    key={index}
                                    x={toX(index)}
                                    y={HEIGHT - 8}
                                    textAnchor={index === 0 ? 'start' : index === series.length - 1 ? 'end' : 'middle'}
                                    className="fill-text-muted text-[10px]"
                                >
                                    {formatDate(series[index].date).slice(0, 6)}
                                </text>
                            ))}

                            {area && (
                                <path
                                    d={area}
                                    fill={`url(#${gradientId})`}
                                    style={{
                                        opacity: revealed || reducedMotion ? 1 : 0,
                                        transition: reducedMotion ? undefined : 'opacity 600ms 200ms ease-out',
                                    }}
                                />
                            )}

                            {line && (
                                <path
                                    d={line}
                                    fill="none"
                                    stroke={BRAND}
                                    strokeWidth="2.5"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    pathLength="1"
                                    strokeDasharray="1"
                                    style={{
                                        strokeDashoffset: revealed || reducedMotion ? 0 : 1,
                                        transition: reducedMotion ? undefined : 'stroke-dashoffset 700ms cubic-bezier(.22,.61,.36,1)',
                                    }}
                                />
                            )}

                            {active && (
                                <g>
                                    <line x1={active[0]} y1={PAD.top} x2={active[0]} y2={PAD.top + innerHeight} stroke={BRAND} strokeWidth="1" strokeOpacity="0.35" />
                                    <circle cx={active[0]} cy={active[1]} r="9" fill={BRAND} fillOpacity="0.12" />
                                    <circle cx={active[0]} cy={active[1]} r="4.5" fill="#ffffff" stroke={BRAND} strokeWidth="2.5" />
                                </g>
                            )}

                            {!active && points.length > 0 && (
                                <circle cx={points.at(-1)[0]} cy={points.at(-1)[1]} r="3.5" fill={BRAND} stroke="#ffffff" strokeWidth="2" />
                            )}
                        </svg>

                        {hover !== null && series[hover] && (
                            <div
                                className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-xl border border-border bg-white px-3 py-2 shadow-panel"
                                style={{
                                    // Keep the card inside the plot at both ends; 72px is half its widest state.
                                    left: clamp(points[hover][0], 72, Math.max(width - 72, 72)),
                                    transition: reducedMotion ? undefined : 'left 120ms ease-out',
                                }}
                            >
                                <p className="text-[13px] font-bold tabular-nums">
                                    {series[hover].count} {series[hover].count === 1 ? 'incident' : 'incidents'}
                                </p>
                                <p className="mt-0.5 text-[11px] whitespace-nowrap text-text-secondary">{formatDate(series[hover].date)}</p>
                            </div>
                        )}

                        <p aria-live="polite" className="sr-only">
                            {hover !== null && series[hover] ? `${formatDate(series[hover].date)}: ${series[hover].count} incidents` : ''}
                        </p>
                    </div>
                )}
            </div>

            {categories.length > 0 && (
                <div className="mt-5 border-t border-border px-5 py-4">
                    <h3 className="mb-3 text-xs font-semibold text-text-secondary">Leading categories</h3>
                    <ul className="grid gap-x-8 gap-y-3 sm:grid-cols-2">
                        {categories.map((category) => (
                            <li key={category.label} className="min-w-0">
                                <div className="flex items-baseline justify-between gap-3">
                                    <span className="truncate text-[11px] text-text-secondary" title={category.label}>
                                        {category.label}
                                    </span>
                                    <span className="shrink-0 text-xs font-bold tabular-nums">
                                        {category.count}
                                        {total > 0 && (
                                            <span className="ml-1.5 font-medium text-text-muted">{Math.round((category.count / total) * 100)}%</span>
                                        )}
                                    </span>
                                </div>
                                <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-muted">
                                    <div
                                        className="h-full rounded-full bg-brand transition-[width] duration-500 ease-gov motion-reduce:transition-none"
                                        style={{ width: `${(category.count / leading) * 100}%` }}
                                    />
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <p className="mx-5 mb-5 rounded-xl border-l-2 border-brand bg-surface-muted px-3 py-2.5 text-[11px] leading-4 text-text-secondary">
                <strong className="font-semibold text-text">A blocked request is not a learner incident.</strong> Only learner-initiated activity that meets
                a policy threshold is raised as an incident.
            </p>
        </section>
    );
}