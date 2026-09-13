import { forwardRef } from 'react';
import clsx from 'clsx';
import { ChevronDownIcon, InboxIcon, Loader2Icon, SearchIcon, TriangleAlertIcon } from 'lucide-react';

/* ------------------------------------------------------------------ buttons */

const BUTTON_VARIANTS = {
    primary: 'bg-brand text-white hover:bg-brand-dark disabled:bg-brand/60',
    secondary: 'border border-border bg-white text-text hover:bg-surface-muted',
    danger: 'bg-danger text-white hover:bg-danger/90',
    dangerGhost: 'border border-danger/30 text-danger hover:bg-danger-soft',
    ghost: 'text-text-secondary hover:bg-surface-muted hover:text-text',
    link: 'text-brand hover:underline px-0',
};

const BUTTON_SIZES = { sm: 'h-8 px-2.5 text-[11px] gap-1.5', md: 'h-9 px-3 text-xs gap-2', lg: 'h-11 px-4 text-sm gap-2' };

export const Button = forwardRef(function Button(
    { as: Component = 'button', variant = 'secondary', size = 'md', icon: Icon, loading = false, className, children, disabled, ...props },
    ref,
) {
    return (
        <Component
            ref={ref}
            disabled={Component === 'button' ? disabled || loading : undefined}
            className={clsx(
                'inline-flex shrink-0 items-center justify-center rounded-lg font-semibold whitespace-nowrap',
                'transition-colors duration-150 ease-gov disabled:pointer-events-none disabled:opacity-60',
                BUTTON_VARIANTS[variant],
                BUTTON_SIZES[size],
                className,
            )}
            {...props}
        >
            {loading ? <Loader2Icon size={15} className="animate-spin" /> : Icon && <Icon size={15} />}
            {children}
        </Component>
    );
});

/* ------------------------------------------------------------------- panels */

export function Panel({ className, children, ...props }) {
    return (
        <section className={clsx('rounded-xl border border-border bg-white shadow-card', className)} {...props}>
            {children}
        </section>
    );
}

export function PanelHeader({ title, description, actions, className }) {
    return (
        <div className={clsx('flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4', className)}>
            <div className="min-w-0">
                <h2 className="text-base font-bold">{title}</h2>
                {description && <p className="mt-1 text-xs text-text-secondary">{description}</p>}
            </div>
            {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
        </div>
    );
}

/* -------------------------------------------------------------------- forms */

const CONTROL =
    'w-full rounded-lg border border-border bg-white px-3 text-sm text-text placeholder:text-text-muted ' +
    'transition-colors duration-150 focus:border-brand focus:outline-none disabled:bg-surface-muted disabled:text-text-muted';

export const TextInput = forwardRef(function TextInput({ className, invalid, ...props }, ref) {
    return <input ref={ref} className={clsx(CONTROL, 'h-10', invalid && 'border-danger', className)} {...props} />;
});

export const Textarea = forwardRef(function Textarea({ className, invalid, rows = 4, ...props }, ref) {
    return <textarea ref={ref} rows={rows} className={clsx(CONTROL, 'py-2 leading-6', invalid && 'border-danger', className)} {...props} />;
});

export const Select = forwardRef(function Select({ className, invalid, children, ...props }, ref) {
    return (
        <div className="group relative">
            <select
                ref={ref}
                className={clsx(
                    CONTROL,
                    'h-10 appearance-none rounded-xl pr-11 shadow-[0_1px_2px_rgba(15,61,67,.05)]',
                    'hover:border-brand/40 focus:border-brand focus:ring-4 focus:ring-brand/10',
                    '[&>option]:bg-white [&>option]:text-text',
                    invalid && 'border-danger focus:border-danger focus:ring-danger/10',
                    className,
                )}
                {...props}
            >
                {children}
            </select>
            <span className="pointer-events-none absolute top-1/2 right-1.5 grid h-7 w-7 -translate-y-1/2 place-items-center rounded-lg bg-brand-soft text-brand transition-colors group-focus-within:bg-brand group-focus-within:text-white">
                <ChevronDownIcon size={14} />
            </span>
        </div>
    );
});

export function Field({ label, hint, error, required, className, children }) {
    return (
        <label className={clsx('block', className)}>
            <span className="mb-1.5 flex items-center gap-1 text-xs font-semibold">
                {label}
                {required && <span className="text-danger">*</span>}
            </span>
            {children}
            {error ? (
                <span className="mt-1 flex items-start gap-1 text-[11px] leading-4 text-danger">
                    <TriangleAlertIcon size={12} className="mt-0.5 shrink-0" />
                    {error}
                </span>
            ) : (
                hint && <span className="mt-1 block text-[11px] leading-4 text-text-secondary">{hint}</span>
            )}
        </label>
    );
}

export const firstError = (errors, key) => {
    const message = errors?.[key];

    return Array.isArray(message) ? message[0] : message;
};

/* ------------------------------------------------------------------ filters */

export function SearchInput({ value, onChange, placeholder = 'Search', className }) {
    return (
        <div className={clsx('relative min-w-[200px] flex-1', className)}>
            <SearchIcon size={15} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-text-muted" />
            <input
                value={value}
                onChange={(event) => onChange(event.target.value)}
                type="search"
                aria-label={placeholder}
                placeholder={placeholder}
                className="h-9 w-full rounded-lg border border-border bg-canvas/60 pr-3 pl-8 text-xs transition-colors focus:border-brand focus:bg-white focus:outline-none"
            />
        </div>
    );
}

/**
 * Compact table filter. `options` accepts [value, label] pairs so filters can
 * send enum keys while showing the Ministry's wording.
 */
export function FilterSelect({ label, value, options, onChange, className }) {
    return (
        <label
            className={clsx(
                'group relative flex min-w-[150px] items-center rounded-xl border border-border bg-white pl-3 shadow-[0_1px_2px_rgba(15,61,67,.05)]',
                'transition duration-150 hover:border-brand/40 hover:shadow-sm focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/10',
                className,
            )}
        >
            <span className="sr-only">{label}</span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="h-10 w-full cursor-pointer appearance-none bg-transparent pr-11 text-xs font-semibold text-text focus:outline-none [&>option]:bg-white [&>option]:text-text"
            >
                <option value="">{label}</option>
                {options.map(([optionValue, optionLabel]) => (
                    <option key={optionValue} value={optionValue}>
                        {optionLabel}
                    </option>
                ))}
            </select>
            <span className="pointer-events-none absolute right-1.5 grid h-7 w-7 place-items-center rounded-lg bg-brand-soft text-brand transition-colors group-focus-within:bg-brand group-focus-within:text-white">
                <ChevronDownIcon size={13} />
            </span>
        </label>
    );
}

/* ------------------------------------------------------------------- states */

export function Skeleton({ className }) {
    return <div aria-hidden className={clsx('animate-pulse rounded-md bg-surface-muted', className)} />;
}

export function TableSkeleton({ rows = 6, cols = 6 }) {
    return (
        <div className="divide-y divide-border">
            {Array.from({ length: rows }).map((_, row) => (
                <div key={row} className="flex gap-4 px-5 py-3.5">
                    {Array.from({ length: cols }).map((__, col) => (
                        <Skeleton key={col} className={clsx('h-4', col === 0 ? 'w-1/4' : 'flex-1')} />
                    ))}
                </div>
            ))}
        </div>
    );
}

export function EmptyState({ title, description, icon: Icon = InboxIcon, action, className }) {
    return (
        <div className={clsx('flex flex-col items-center justify-center px-6 py-14 text-center', className)}>
            <div className="grid h-11 w-11 place-items-center rounded-full bg-surface-muted text-text-muted">
                <Icon size={20} />
            </div>
            <h3 className="mt-3 text-sm font-semibold">{title}</h3>
            {description && <p className="mt-1 max-w-sm text-xs leading-5 text-text-secondary">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function ErrorState({ error, onRetry, className }) {
    return (
        <div className={clsx('flex flex-col items-center justify-center px-6 py-14 text-center', className)}>
            <div className="grid h-11 w-11 place-items-center rounded-full bg-danger-soft text-danger">
                <TriangleAlertIcon size={20} />
            </div>
            <h3 className="mt-3 text-sm font-semibold">This section could not be loaded</h3>
            <p className="mt-1 max-w-sm text-xs leading-5 text-text-secondary">
                {error?.message ?? 'An unexpected error occurred while contacting the SAFERNET service.'}
            </p>
            {onRetry && (
                <Button className="mt-4" size="sm" onClick={onRetry}>
                    Try again
                </Button>
            )}
        </div>
    );
}

/* --------------------------------------------------------------- pagination */

export function Pagination({ meta, onChange, unit = 'records' }) {
    if (!meta || meta.total === 0) {
        return null;
    }

    const { current_page: page, last_page: lastPage, from, to, total } = meta;

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
            <p className="text-xs text-text-secondary">
                Showing{' '}
                <strong className="text-text tabular-nums">
                    {from ?? 0}-{to ?? 0}
                </strong>{' '}
                of <strong className="text-text tabular-nums">{total}</strong> {unit}
            </p>
            <div className="flex items-center gap-1">
                <Button size="sm" disabled={page <= 1} onClick={() => onChange(page - 1)}>
                    Previous
                </Button>
                <span className="px-2 text-xs font-medium tabular-nums">
                    Page {page} of {Math.max(lastPage, 1)}
                </span>
                <Button size="sm" disabled={page >= lastPage} onClick={() => onChange(page + 1)}>
                    Next
                </Button>
            </div>
        </div>
    );
}

/* -------------------------------------------------------------------- table */

/**
 * Dense record table with the loading, empty and error states every list in
 * the portal shares. `columns` is a list of headings; `children` renders rows.
 */
export function DataTable({ columns, query, rows, children, empty, minWidth = '860px', cols }) {
    if (query?.isPending) {
        return <TableSkeleton rows={6} cols={cols ?? columns.length} />;
    }

    if (query?.isError) {
        return <ErrorState error={query.error} onRetry={query.refetch} />;
    }

    if (rows && rows.length === 0) {
        return empty ?? <EmptyState title="Nothing to show" description="No records match the current filters." />;
    }

    return (
        <div className="table-scroll overflow-x-auto">
            <table className="w-full text-left" style={{ minWidth }}>
                <thead className="bg-surface-muted/70 text-[10px] tracking-wider text-text-muted uppercase">
                    <tr>
                        {columns.map((column) => (
                            <th
                                key={typeof column === 'string' ? column : column.key}
                                className={clsx('px-4 py-2.5 font-semibold', typeof column !== 'string' && column.align === 'right' && 'text-right')}
                            >
                                {typeof column === 'string' ? column : column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">{children}</tbody>
            </table>
        </div>
    );
}

/**
 * A table row, optionally activatable.
 *
 * A row that opens something must be reachable without a mouse, so a clickable
 * row takes focus and responds to Enter and Space like the button it behaves
 * as. A plain `tr` with a click handler is invisible to keyboard and screen
 * reader users, which for a learner or device profile means the record simply
 * cannot be opened.
 */
export function Row({ onClick, className, children }) {
    function onKeyDown(event) {
        if (!onClick) return;
        if (event.key !== 'Enter' && event.key !== ' ') return;

        // Space scrolls the page by default, which is not what a row press means.
        event.preventDefault();
        onClick(event);
    }

    return (
        <tr
            onClick={onClick}
            onKeyDown={onKeyDown}
            tabIndex={onClick ? 0 : undefined}
            role={onClick ? 'button' : undefined}
            className={clsx(
                'text-xs transition-colors duration-150 ease-gov',
                onClick && 'cursor-pointer hover:bg-brand-soft/60 focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-inset focus-visible:outline-none',
                className,
            )}
        >
            {children}
        </tr>
    );
}

export function Cell({ align, mono, muted, bold, className, children, ...props }) {
    return (
        <td
            className={clsx(
                'px-4 py-3',
                align === 'right' && 'text-right',
                mono && 'font-mono text-[11px]',
                muted && 'text-text-secondary',
                bold && 'font-semibold',
                className,
            )}
            {...props}
        >
            {children}
        </td>
    );
}

/** Label/value pair used inside modals and detail panels. */
export function DetailRow({ label, children }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-border pb-2 last:border-0">
            <span className="text-xs text-text-secondary">{label}</span>
            <span className="text-right text-xs font-semibold">{children ?? '—'}</span>
        </div>
    );
}

/**
 * A proportion bar.
 *
 * The fill carries severity and the unfilled track is a lighter step of that
 * same ramp, so the state reads across the whole bar rather than only the
 * filled part. The value is always announced, so the meter never has to be
 * read by eye alone.
 */
const METER_TONES = {
    brand: { fill: 'bg-viz-series', track: 'bg-viz-track' },
    good: { fill: 'bg-success', track: 'bg-viz-track-good' },
    warning: { fill: 'bg-warning', track: 'bg-viz-track-warning' },
    danger: { fill: 'bg-danger', track: 'bg-viz-track-danger' },
    muted: { fill: 'bg-text-muted', track: 'bg-surface-muted' },
};

export function Meter({ value, label, tone = 'brand', className, barClassName }) {
    const percent = Math.min(100, Math.max(0, Number(value) || 0));
    const { fill, track } = METER_TONES[tone] ?? METER_TONES.brand;

    return (
        <div
            role="progressbar"
            aria-valuenow={Math.round(percent)}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-label={label}
            className={clsx('h-1.5 overflow-hidden rounded-full', track, className)}
        >
            <div
                className={clsx('h-full rounded-full transition-[width] duration-500 ease-gov motion-reduce:transition-none', fill, barClassName)}
                style={{ width: `${percent}%` }}
            />
        </div>
    );
}

/** Picks the meter tone from a coverage percentage, high is good. */
export function coverageTone(percent) {
    if (percent >= 80) return 'good';
    if (percent >= 50) return 'warning';
    return 'danger';
}
