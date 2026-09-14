import { useMemo, useState } from 'react';
import clsx from 'clsx';
import { BotIcon, CheckIcon, ShieldBanIcon, TelescopeIcon, UsersRoundIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import {
    Button,
    Cell,
    DataTable,
    EmptyState,
    Field,
    FilterSelect,
    Pagination,
    Panel,
    PanelHeader,
    Row,
    SearchInput,
    Select,
    Skeleton,
    Textarea,
    firstError,
} from '../components/Primitives';
import { Modal } from '../components/Overlays';
import { StatusPill } from '../components/StatusPill';
import { ENFORCEMENT_ACTION, SEVERITY } from '../lib/domain';
import { useContentCategories, useDecideDomainReview, useDomainReviews } from '../lib/queries';
import { formatDate, formatNumber, formatRelative } from '../lib/format';
import { ApiError } from '../lib/api';
import { showToast, toastError } from '../lib/toast';

const REVIEW_STATUS = {
    pending: { label: 'Awaiting classifier', tone: 'neutral' },
    classified: { label: 'Awaiting decision', tone: 'warning' },
    blocked: { label: 'Blocked county-wide', tone: 'danger' },
    allowed: { label: 'Allowed', tone: 'success' },
};

const STATUS_FILTER = [
    ['awaiting', 'Awaiting a decision'],
    ['classified', 'Classified, undecided'],
    ['pending', 'Not yet classified'],
    ['blocked', 'Blocked'],
    ['allowed', 'Allowed'],
];

/**
 * Domains learners actually reached that no blocklist knows about.
 *
 * Upstream lists cover what was already known when they were published, which
 * is the one thing a new site never is. This queue closes that gap — and the
 * two columns are deliberately apart: the classifier offers an opinion, an
 * officer makes the decision, and nothing is enforced until they do.
 */
export function DomainReviewPage() {
    const [status, setStatus] = useState('awaiting');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [reviewing, setReviewing] = useState(null);

    const params = useMemo(
        () => ({ status, page, ...(search.trim() ? { search: search.trim() } : {}) }),
        [status, page, search],
    );

    const reviews = useDomainReviews(params);
    const rows = reviews.data?.data ?? [];
    const summary = reviews.data?.summary;

    function filter(setter) {
        return (value) => {
            setter(value);
            setPage(1);
        };
    }

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Domain review"
                description="Sites learners reached that no county blocklist covers. A decision here applies to every school, so only a director may make one."
                meta={summary ? `${formatNumber(summary.awaiting)} awaiting a decision` : undefined}
            />

            <div className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border shadow-card lg:grid-cols-4">
                {[
                    { label: 'Awaiting a decision', value: summary?.awaiting, detail: 'Queued from real learner traffic', icon: TelescopeIcon, emphasis: (summary?.awaiting ?? 0) > 0 },
                    { label: 'Classifier has advised', value: summary?.classified, detail: 'Suggestion recorded, nothing enforced', icon: BotIcon },
                    { label: 'Blocked county-wide', value: summary?.blocked, detail: 'Enforced at the next policy sync', icon: ShieldBanIcon },
                    { label: 'Judged acceptable', value: summary?.allowed, detail: 'Settled; never queued again', icon: CheckIcon },
                ].map((figure) => (
                    <div key={figure.label} className="bg-white px-4 py-4">
                        <div className="flex items-center gap-2">
                            <figure.icon size={14} className="text-text-muted" />
                            <p className="truncate text-[11px] text-text-secondary">{figure.label}</p>
                        </div>
                        {reviews.isPending ? (
                            <Skeleton className="mt-2 h-6 w-16" />
                        ) : (
                            <p className={clsx('mt-1 text-[22px] font-bold tracking-[-.03em] tabular-nums', figure.emphasis && 'text-warning-strong')}>
                                {formatNumber(figure.value ?? 0)}
                            </p>
                        )}
                        <p className="mt-0.5 truncate text-[11px] text-text-muted">{figure.detail}</p>
                    </div>
                ))}
            </div>

            <Panel className="mt-4">
                <PanelHeader
                    title="Review queue"
                    description="Ordered by how widely a domain was seen. A site one learner reached alone is never queued — the queue is about patterns, not individuals."
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <SearchInput value={search} onChange={filter(setSearch)} placeholder="Search domain" />
                            <FilterSelect label="All states" value={status} options={STATUS_FILTER} onChange={filter(setStatus)} />
                        </div>
                    }
                />

                <DataTable
                    query={reviews}
                    rows={rows}
                    minWidth="1000px"
                    columns={[
                        'Domain',
                        { key: 'learners', label: 'Learners', align: 'right' },
                        'Last seen',
                        'Classifier suggests',
                        'State',
                        { key: 'actions', label: '', align: 'right' },
                    ]}
                    empty={
                        <EmptyState
                            icon={TelescopeIcon}
                            title="Nothing awaiting review"
                            description="Domains appear here once more than one learner has reached a site no blocklist covers."
                        />
                    }
                >
                    {rows.map((review) => (
                        <Row key={review.id}>
                            <Cell>
                                <span className="font-mono text-xs font-semibold">{review.domain}</span>
                                <span className="mt-0.5 block text-[11px] text-text-secondary">
                                    {formatNumber(review.events_seen)} visits across {formatNumber(review.institutions_seen)}{' '}
                                    {review.institutions_seen === 1 ? 'school' : 'schools'}
                                </span>
                            </Cell>
                            <Cell align="right" className="tabular-nums">
                                <span className="inline-flex items-center gap-1 font-semibold">
                                    <UsersRoundIcon size={12} className="text-text-muted" />
                                    {formatNumber(review.learners_seen)}
                                </span>
                            </Cell>
                            <Cell muted>{review.last_seen_at ? formatRelative(review.last_seen_at) : '—'}</Cell>
                            <Cell>
                                {review.suggestion ? (
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <StatusPill descriptor={ENFORCEMENT_ACTION[review.suggestion.action]} />
                                        <span className="text-[11px] text-text-secondary">
                                            {review.suggestion.category ?? 'Uncategorised'}
                                            {review.suggestion.risk_score !== null && review.suggestion.risk_score !== undefined
                                                ? ` · risk ${review.suggestion.risk_score}`
                                                : ''}
                                        </span>
                                    </div>
                                ) : (
                                    <span className="text-[11px] text-text-muted">No suggestion</span>
                                )}
                            </Cell>
                            <Cell>
                                <StatusPill descriptor={REVIEW_STATUS[review.status]} />
                                {review.decision && (
                                    <span className="mt-0.5 block text-[11px] text-text-muted">
                                        {review.decision.reviewer ?? 'An officer'} · {formatDate(review.decision.reviewed_at)}
                                    </span>
                                )}
                            </Cell>
                            <Cell align="right">
                                <Button size="sm" variant="ghost" onClick={() => setReviewing(review)}>
                                    {review.decision ? 'View' : 'Review'}
                                </Button>
                            </Cell>
                        </Row>
                    ))}
                </DataTable>

                <Pagination meta={reviews.data?.meta} onChange={setPage} unit="domains" />

                <p className="border-t border-border bg-surface-muted/40 px-5 py-3 text-[11px] leading-5 text-text-secondary">
                    The classifier is not in the enforcement path. It runs behind the request, once per domain, and its
                    verdict is advice only — no device blocks anything here until a director decides.
                </p>
            </Panel>

            <DecisionModal review={reviewing} onClose={() => setReviewing(null)} />
        </div>
    );
}

/**
 * The decision itself. Both the classifier's advice and the officer's choice
 * are shown together, because a reviewer has to be able to disagree knowingly.
 */
function DecisionModal({ review, onClose }) {
    const categories = useContentCategories({ per_page: 100 });
    const decide = useDecideDomainReview();

    const [categoryId, setCategoryId] = useState('');
    const [notes, setNotes] = useState('');
    const [errors, setErrors] = useState({});
    const [pending, setPending] = useState(null);

    const decided = Boolean(review?.decision);
    const categoryOptions = categories.data?.data ?? [];

    /** Re-seed the form whenever a different domain is opened. */
    const [seededFor, setSeededFor] = useState(null);
    if (review && seededFor !== review.id) {
        setSeededFor(review.id);
        setCategoryId(String(review.decision?.category_id ?? review.suggestion?.category_id ?? ''));
        setNotes(review.decision?.notes ?? '');
        setErrors({});
    }

    async function submit(decision) {
        setPending(decision);
        setErrors({});

        try {
            await decide.mutateAsync({
                id: review.id,
                decision,
                content_category_id: categoryId === '' ? null : Number(categoryId),
                review_notes: notes.trim() === '' ? null : notes.trim(),
            });

            showToast(
                decision === 'blocked' ? `${review.domain} is blocked county-wide` : `${review.domain} was judged acceptable`,
                {
                    description:
                        decision === 'blocked'
                            ? 'Every school enforces it at the next policy sync.'
                            : 'It will not be queued for review again.',
                },
            );
            onClose();
        } catch (error) {
            if (error instanceof ApiError && error.status === 422) {
                setErrors(error.errors ?? {});
            } else {
                toastError(error, 'The decision could not be recorded');
            }
        } finally {
            setPending(null);
        }
    }

    return (
        <Modal
            open={Boolean(review)}
            onClose={onClose}
            size="lg"
            title={review?.domain ?? ''}
            subtitle={
                review
                    ? `Reached by ${formatNumber(review.learners_seen)} learners across ${formatNumber(review.institutions_seen)} ${review.institutions_seen === 1 ? 'school' : 'schools'}`
                    : ''
            }
            footer={
                decided ? (
                    <Button className="ml-auto" onClick={onClose}>
                        Close
                    </Button>
                ) : (
                    <>
                        <p className="mr-auto max-w-sm text-[11px] leading-4 text-text-secondary">
                            Either decision is county-wide and is recorded against your name in the audit log.
                        </p>
                        <Button onClick={onClose} disabled={Boolean(pending)}>
                            Cancel
                        </Button>
                        <Button
                            variant="secondary"
                            icon={CheckIcon}
                            loading={pending === 'allowed'}
                            disabled={Boolean(pending)}
                            onClick={() => submit('allowed')}
                        >
                            Allow
                        </Button>
                        <Button
                            variant="danger"
                            icon={ShieldBanIcon}
                            loading={pending === 'blocked'}
                            disabled={Boolean(pending)}
                            onClick={() => submit('blocked')}
                        >
                            Block county-wide
                        </Button>
                    </>
                )
            }
        >
            {review && (
                <div className="space-y-4">
                    <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-4">
                        {[
                            ['Learners', formatNumber(review.learners_seen)],
                            ['Schools', formatNumber(review.institutions_seen)],
                            ['Visits', formatNumber(review.events_seen)],
                            ['First seen', review.first_seen_at ? formatDate(review.first_seen_at) : '—'],
                        ].map(([label, value]) => (
                            <div key={label} className="bg-white px-3 py-2.5">
                                <dt className="text-[11px] text-text-secondary">{label}</dt>
                                <dd className="mt-0.5 text-sm font-bold tabular-nums">{value}</dd>
                            </div>
                        ))}
                    </dl>

                    {review.suggestion ? (
                        <section className="rounded-xl border border-border bg-surface-muted/40 p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <BotIcon size={14} className="text-text-muted" />
                                <h3 className="text-xs font-bold">What the classifier suggests</h3>
                                <StatusPill descriptor={ENFORCEMENT_ACTION[review.suggestion.action]} />
                                {review.suggestion.severity && <StatusPill descriptor={SEVERITY[review.suggestion.severity]} />}
                            </div>
                            <p className="mt-2 text-sm leading-5 text-text-secondary">
                                {review.suggestion.rationale ?? 'No rationale was returned.'}
                            </p>
                            <p className="mt-2 font-mono text-[10px] text-text-muted">
                                {review.suggestion.category ?? 'Uncategorised'}
                                {review.suggestion.risk_score !== null && review.suggestion.risk_score !== undefined
                                    ? ` · risk ${review.suggestion.risk_score}/100`
                                    : ''}{' '}
                                · {review.suggestion.provider ?? 'unknown'}/{review.suggestion.model ?? 'unknown'} ·{' '}
                                {formatDate(review.suggestion.classified_at, { withTime: true })}
                            </p>
                            <p className="mt-2 text-[11px] leading-4 text-text-muted">
                                This is advice, not policy. Nothing is enforced until you decide.
                            </p>
                        </section>
                    ) : (
                        <p className="rounded-xl border border-border bg-surface-muted/40 p-4 text-[11px] leading-4 text-text-secondary">
                            No classifier suggestion is available for this domain — either no provider is configured or the
                            classification did not complete. Decide on the traffic alone.
                        </p>
                    )}

                    {decided ? (
                        <section className="rounded-xl border border-border p-4">
                            <h3 className="text-xs font-bold">The decision</h3>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <StatusPill descriptor={REVIEW_STATUS[review.status]} />
                                <span className="text-[11px] text-text-secondary">
                                    {review.decision.category ?? 'Uncategorised'} · {review.decision.reviewer ?? 'An officer'} ·{' '}
                                    {formatDate(review.decision.reviewed_at, { withTime: true })}
                                </span>
                            </div>
                            {review.decision.notes && (
                                <p className="mt-2 text-sm leading-5 text-text-secondary">{review.decision.notes}</p>
                            )}
                            <p className="mt-2 text-[11px] leading-4 text-text-muted">
                                A domain is decided once. Reverse it by editing the county review blocklist directly.
                            </p>
                        </section>
                    ) : (
                        <div className="space-y-3">
                            <Field
                                label="Content category"
                                hint="Defaults to the classifier's category. Change it if the classifier read the site wrongly — both are kept on the record."
                                error={firstError(errors, 'content_category_id')}
                            >
                                <Select value={categoryId} onChange={(event) => setCategoryId(event.target.value)}>
                                    <option value="">Uncategorised</option>
                                    {categoryOptions.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>

                            <Field
                                label="Reasoning"
                                hint="Why this decision. Read by anyone auditing county filtering later."
                                error={firstError(errors, 'review_notes')}
                            >
                                <Textarea
                                    rows={3}
                                    value={notes}
                                    onChange={(event) => setNotes(event.target.value)}
                                    placeholder="Confirmed betting site with account registration."
                                />
                            </Field>

                            {firstError(errors, 'decision') && (
                                <p className="text-[11px] text-danger">{firstError(errors, 'decision')}</p>
                            )}
                        </div>
                    )}
                </div>
            )}
        </Modal>
    );
}
