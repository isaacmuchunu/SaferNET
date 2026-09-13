import { useMemo, useState } from 'react';
import { GlobeIcon, PrinterIcon } from 'lucide-react';
import { Modal } from '../../components/Overlays';
import { Button, EmptyState, FilterSelect, SearchInput, Skeleton } from '../../components/Primitives';
import { useWebEvents } from '../../lib/queries';
import { api } from '../../lib/api';
import { formatDate, formatNumber, titleCase } from '../../lib/format';
import { showToast, toastError } from '../../lib/toast';

const PERIODS = [
    ['1', 'Day of the incident'],
    ['7', 'Surrounding week'],
    ['30', 'Surrounding month'],
];

const SORTS = [
    ['occurred_at:desc', 'Most recent first'],
    ['occurred_at:asc', 'Earliest first'],
    ['domain:asc', 'Domain A–Z'],
    ['action:asc', 'Action'],
];

/** Days back from now, rounded to the minute so the query key is stable. */
function since(days) {
    const instant = Date.now() - Number(days) * 86_400_000;

    return new Date(Math.floor(instant / 60_000) * 60_000).toISOString();
}

/**
 * Chooses what evidence goes into a printed dossier.
 *
 * The events SAFERNET linked to the incident are always included — they are the
 * incident. Everything here is the learner's surrounding traffic, which the
 * preparing officer may add as context where the automatic thresholds did not
 * link something relevant.
 *
 * The dossier prints the two apart and says who selected the second, because a
 * document that ends in a certification must not let one person's judgement
 * read as the platform's finding.
 */
export function PrepareDossierModal({ incident, open, onClose }) {
    const [period, setPeriod] = useState('7');
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState('occurred_at:desc');
    const [selected, setSelected] = useState(() => new Set());
    const [printing, setPrinting] = useState(false);

    const [sortColumn, sortDirection] = sort.split(':');

    const params = useMemo(
        () => ({
            learner_id: incident?.learner?.id,
            since: since(period),
            ...(search ? { search } : {}),
        }),
        [incident?.learner?.id, period, search],
    );

    const traffic = useWebEvents(params, { enabled: open && Boolean(incident?.learner?.id) });

    const events = useMemo(() => {
        const rows = [...(traffic.data?.data ?? [])];

        // Sorted here so the list reads exactly as the printed order will.
        rows.sort((a, b) => {
            const left = a[sortColumn] ?? '';
            const right = b[sortColumn] ?? '';
            const order = left < right ? -1 : left > right ? 1 : 0;

            return sortDirection === 'asc' ? order : -order;
        });

        return rows;
    }, [traffic.data, sortColumn, sortDirection]);

    function toggle(uuid) {
        setSelected((current) => {
            const next = new Set(current);
            next.has(uuid) ? next.delete(uuid) : next.add(uuid);

            return next;
        });
    }

    async function print() {
        const reportWindow = window.open('about:blank', '_blank');

        if (!reportWindow) {
            showToast('Allow pop-ups to print the dossier', { tone: 'warning' });

            return;
        }

        reportWindow.opener = null;
        reportWindow.document.title = 'Preparing safeguarding dossier…';
        reportWindow.document.body.textContent = 'Preparing safeguarding dossier…';
        setPrinting(true);

        try {
            const pdf = await api.incidentReport(incident.id, {
                events: [...selected],
                sort: sortColumn,
                direction: sortDirection,
            });
            const url = URL.createObjectURL(pdf);
            reportWindow.location.replace(url);
            window.setTimeout(() => URL.revokeObjectURL(url), 120000);
            onClose();
        } catch (error) {
            reportWindow.close();
            toastError(error, 'The incident dossier could not be prepared');
        } finally {
            setPrinting(false);
        }
    }

    return (
        <Modal
            size="lg"
            open={open}
            onClose={onClose}
            title="Prepare the safeguarding dossier"
            subtitle={
                incident?.learner?.name
                    ? `Surrounding activity for ${incident.learner.name}`
                    : 'Surrounding learner activity'
            }
            footer={
                <div className="flex w-full items-center justify-between gap-3">
                    <span className="text-[11px] text-text-secondary">
                        {selected.size === 0
                            ? 'Linked evidence only'
                            : `${formatNumber(selected.size)} additional ${selected.size === 1 ? 'record' : 'records'} selected`}
                    </span>
                    <div className="flex gap-2">
                        <Button type="button" onClick={onClose} disabled={printing}>
                            Cancel
                        </Button>
                        <Button type="button" variant="primary" icon={PrinterIcon} loading={printing} onClick={print}>
                            Open dossier
                        </Button>
                    </div>
                </div>
            }
        >
            <div className="space-y-3">
                <p className="rounded-lg border border-brand/15 bg-brand-soft/50 px-3 py-2.5 text-[11px] leading-4 text-text-secondary">
                    Activity SAFERNET linked to this incident is always included. Tick any surrounding
                    activity you want printed as context — the dossier lists it separately and records
                    that you selected it.
                </p>

                <div className="flex flex-wrap items-center gap-2">
                    <SearchInput
                        value={search}
                        onChange={setSearch}
                        placeholder="Filter by domain"
                        className="min-w-[10rem] flex-1"
                    />
                    <FilterSelect label="Surrounding week" value={period} onChange={setPeriod} options={PERIODS} />
                    <FilterSelect label="Most recent first" value={sort} onChange={setSort} options={SORTS} />
                </div>

                {traffic.isPending ? (
                    <div className="space-y-2">
                        {[1, 2, 3, 4, 5].map((row) => <Skeleton key={row} className="h-11 w-full rounded-lg" />)}
                    </div>
                ) : events.length === 0 ? (
                    <EmptyState
                        icon={GlobeIcon}
                        title="No surrounding activity"
                        description="Nothing else was recorded for this learner in the selected period."
                    />
                ) : (
                    <ul className="max-h-[40vh] divide-y divide-border overflow-y-auto rounded-lg border border-border">
                        {events.map((event) => (
                            <li key={event.id}>
                                <label className="flex cursor-pointer items-start gap-3 p-2.5 hover:bg-surface-muted/60">
                                    <input
                                        type="checkbox"
                                        checked={selected.has(event.id)}
                                        onChange={() => toggle(event.id)}
                                        className="mt-0.5 h-3.5 w-3.5 shrink-0 accent-[var(--color-brand)]"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-baseline justify-between gap-2">
                                            <span className="truncate text-xs font-semibold text-text">{event.domain}</span>
                                            <span className="shrink-0 text-[10px] text-text-muted">
                                                {formatDate(event.occurred_at, { withTime: true })}
                                            </span>
                                        </span>
                                        <span className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[10px] text-text-muted">
                                            <span className={event.action === 'block' ? 'font-semibold text-danger' : ''}>
                                                {titleCase(event.action)}
                                            </span>
                                            {event.category && (
                                                <><span aria-hidden="true">·</span><span>{event.category}</span></>
                                            )}
                                            <span aria-hidden="true">·</span>
                                            <span className="truncate">{event.page_title || event.url}</span>
                                        </span>
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </Modal>
    );
}
