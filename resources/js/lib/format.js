const numbers = new Intl.NumberFormat('en-KE');

export const formatNumber = (value) => (typeof value === 'number' ? numbers.format(value) : '—');

export function formatPercent(value, fractionDigits = 1) {
    if (typeof value !== 'number' || Number.isNaN(value)) {
        return '—';
    }

    return `${value.toFixed(fractionDigits).replace(/\.0$/, '')}%`;
}

export function formatDate(value, { withTime = false } = {}) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-KE', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        ...(withTime ? { hour: '2-digit', minute: '2-digit', hour12: false } : {}),
    }).format(date);
}

export function formatTime(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '—'
        : new Intl.DateTimeFormat('en-KE', { hour: '2-digit', minute: '2-digit', hour12: false }).format(date);
}

const RELATIVE_UNITS = [
    ['year', 31_536_000_000],
    ['month', 2_592_000_000],
    ['day', 86_400_000],
    ['hour', 3_600_000],
    ['minute', 60_000],
];

export function formatRelative(value) {
    if (!value) {
        return '—';
    }

    const elapsed = new Date(value).getTime() - Date.now();

    if (Number.isNaN(elapsed)) {
        return '—';
    }

    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

    for (const [unit, milliseconds] of RELATIVE_UNITS) {
        if (Math.abs(elapsed) >= milliseconds) {
            return formatter.format(Math.round(elapsed / milliseconds), unit);
        }
    }

    return 'just now';
}

export function initials(name) {
    return (name ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('');
}

export const titleCase = (value) =>
    (value ?? '')
        .toString()
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
