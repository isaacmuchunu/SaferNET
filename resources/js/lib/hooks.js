import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';

/** Delays propagation of a fast-changing value, such as a search field. */
export function useDebouncedValue(value, delay = 300) {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), delay);

        return () => window.clearTimeout(timer);
    }, [value, delay]);

    return debounced;
}

/**
 * List state — search, filters and page — kept in the URL so a filtered view
 * can be shared, bookmarked and returned to with the browser's back button.
 */
export function useListState(defaults = {}) {
    const [searchParams, setSearchParams] = useSearchParams();

    const values = { page: '1', ...defaults };
    Object.keys(values).forEach((key) => {
        values[key] = searchParams.get(key) ?? defaults[key] ?? '';
    });

    const page = Number(searchParams.get('page') ?? 1) || 1;

    function setValue(key, value) {
        const next = new URLSearchParams(searchParams);

        if (value === '' || value === undefined || value === null) {
            next.delete(key);
        } else {
            next.set(key, value);
        }

        if (key !== 'page') {
            next.delete('page');
        }

        setSearchParams(next, { replace: true });
    }

    function reset() {
        setSearchParams(new URLSearchParams(), { replace: true });
    }

    const isFiltered = Object.keys(defaults).some((key) => Boolean(values[key]));

    return { values, page, setValue, setPage: (value) => setValue('page', String(value)), reset, isFiltered };
}
