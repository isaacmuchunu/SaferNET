import clsx from 'clsx';

const TONES = {
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning-strong',
    danger: 'bg-danger-soft text-danger',
    info: 'bg-info-soft text-info',
    neutral: 'bg-surface-muted text-text-secondary',
};

/**
 * The single status treatment used across every table, card and modal.
 * `descriptor` accepts the {label, tone} objects exported from lib/domain.
 */
export function StatusPill({ descriptor, label, tone = 'neutral', className }) {
    const text = descriptor?.label ?? label;

    return (
        <span
            className={clsx(
                'inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold',
                TONES[descriptor?.tone ?? tone] ?? TONES.neutral,
                className,
            )}
        >
            {text}
        </span>
    );
}

export function ToneDot({ tone = 'neutral', className }) {
    const dots = {
        success: 'bg-success',
        warning: 'bg-warning',
        danger: 'bg-danger',
        info: 'bg-info',
        neutral: 'bg-text-muted',
    };

    return <span aria-hidden className={clsx('h-2 w-2 shrink-0 rounded-full', dots[tone] ?? dots.neutral, className)} />;
}
