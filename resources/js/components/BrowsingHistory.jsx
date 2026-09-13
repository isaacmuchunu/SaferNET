import { useMemo, useState } from 'react';
import { GlobeIcon } from 'lucide-react';
import { EmptyState, FilterSelect, SearchInput, Skeleton } from './Primitives';
import { useWebEvents } from '../lib/queries';
import { formatNumber, formatRelative, titleCase } from '../lib/format';

const ACTIONS = [
    ['block', 'Blocked only'],
    ['allow', 'Allowed only'],
    ['restrict', 'Restricted only'],
];

const PERIODS = [
    ['1', 'Last 24 hours'],
    ['7', 'Last 7 days'],
    ['30', 'Last 30 days'],
];

/**
 * Days back, as the ISO instant the API expects, rounded down to the minute.
 *
 * The rounding is what makes it usable as part of a query key. Computed to the
 * millisecond it changes on every render, so the key changes, which refetches,
 * which re-renders — a loop that never settles and hammers the API. A minute's
 * granularity is far finer than any period offered here.
 */
function since(days) {
    if (!days) return undefined;

    const instant = Date.now() - Number(days) * 86_400_000;

    return new Date(Math.floor(instant / 60_000) * 60_000).toISOString();
}

/**
 * One learner's browsing, newest first.
 *
 * Every row is a real page a named child visited, so blocks are called out and
 * the filters exist to answer a specific question — "what was blocked this
 * week?" — rather than to scroll an undifferentiated log.
 *
 * Shared by the classroom monitor and the learner profile so both read the
 * same way; the query is only issued when one of them is actually open.
 */
export function BrowsingHistory({ learnerId, sessionId, deviceId, emptyHint }) {
    const [action, setAction] = useState('');
    const [period, setPeriod] = useState('7');
    const [search, setSearch] = useState('');

    // Memoised so the query key is stable between renders: an unstable key is
    // what turned this panel into a request loop.
    const params = useMemo(() => {
        const from = since(period);

        return {
            ...(learnerId ? { learner_id: learnerId } : {}),
            ...(sessionId ? { learner_session_id: sessionId } : {}),
            ...(deviceId ? { device_id: deviceId } : {}),
            ...(action ? { action } : {}),
            ...(search ? { search } : {}),
            ...(from ? { since: from } : {}),
        };
    }, [learnerId, sessionId, deviceId, action, search, period]);

    const history = useWebEvents(params);

    const events = history.data?.data ?? [];
    const total = history.data?.meta?.total ?? 0;
    const isFiltered = Boolean(action || search || period);

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <SearchInput
                    value={search}
                    onChange={setSearch}
                    placeholder="Filter by domain"
                    className="min-w-[10rem] flex-1"
                />
                <FilterSelect label="Any period" value={period} onChange={setPeriod} options={PERIODS} />
                <FilterSelect label="All activity" value={action} onChange={setAction} options={ACTIONS} />
            </div>

            <p className="text-[11px] text-text-muted">
                {history.isPending
                    ? 'Loading…'
                    : `${formatNumber(total)} recorded ${total === 1 ? 'page' : 'pages'}${isFiltered ? ' matching these filters' : ''}`}
            </p>

            {history.isPending ? (
                <div className="space-y-2">
                    {[1, 2, 3, 4, 5].map((row) => <Skeleton key={row} className="h-14 w-full rounded-lg" />)}
                </div>
            ) : events.length === 0 ? (
                <EmptyState
                    icon={GlobeIcon}
                    title={isFiltered ? 'Nothing matches these filters' : 'Nothing recorded yet'}
                    description={
                        isFiltered
                            ? 'Widen the period or clear the activity filter to see more.'
                            : (emptyHint ?? 'No browsing has been reported for this learner yet.')
                    }
                />
            ) : (
                <ul className="divide-y divide-border rounded-lg border border-border">
                    {events.map((event) => (
                        <li key={event.id} className="flex items-start gap-3 p-2.5">
                            <span
                                aria-hidden="true"
                                className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${
                                    event.action === 'block'
                                        ? 'bg-danger'
                                        : event.action === 'restrict'
                                          ? 'bg-warning'
                                          : 'bg-success'
                                }`}
                            />
                            <div className="min-w-0 flex-1">
                                <div className="flex items-baseline justify-between gap-2">
                                    <span className="truncate text-xs font-semibold text-text" title={event.domain}>
                                        {event.domain}
                                    </span>
                                    <span className="shrink-0 text-[10px] text-text-muted">
                                        {formatRelative(event.occurred_at)}
                                    </span>
                                </div>
                                <p className="mt-0.5 truncate text-[11px] text-text-secondary" title={event.url}>
                                    {event.page_title || event.url}
                                </p>
                                <div className="mt-1 flex flex-wrap items-center gap-x-2 text-[10px] text-text-muted">
                                    <span className={event.action === 'block' ? 'font-semibold text-danger' : ''}>
                                        {titleCase(event.action)}
                                    </span>
                                    {event.category && (
                                        <><span aria-hidden="true">·</span><span>{event.category}</span></>
                                    )}
                                    {event.enforcement_source && (
                                        <><span aria-hidden="true">·</span><span>{titleCase(event.enforcement_source)}</span></>
                                    )}
                                    {event.reason && (
                                        <><span aria-hidden="true">·</span><span className="truncate">{event.reason}</span></>
                                    )}
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
