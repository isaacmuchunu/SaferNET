import { useState } from 'react';
import { Link } from 'react-router-dom';
import { CalendarIcon, ClipboardCheckIcon, GavelIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import { Button, EmptyState, ErrorState, Pagination, Panel, Skeleton } from '../components/Primitives';
import { ReviewModal } from './approvals/ReviewModal';
import { useListState } from '../lib/hooks';
import { useInstitutions } from '../lib/queries';
import { INSTITUTION_TYPES, OWNERSHIP_TYPES } from '../lib/domain';
import { formatDate, formatNumber } from '../lib/format';

export function ApprovalsPage() {
    const list = useListState();
    const [underReview, setUnderReview] = useState(null);
    const pending = useInstitutions({ status: 'pending_approval', page: list.page });

    const rows = pending.data?.data ?? [];
    const total = pending.data?.meta?.total ?? 0;

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="School Approvals"
                description="Review new school registrations submitted by Sub-County Directors of Education."
                meta={`${formatNumber(total)} pending`}
            />

            {pending.isPending ? (
                <div className="space-y-3">
                    {Array.from({ length: 3 }).map((_, index) => (
                        <Skeleton key={index} className="h-28 w-full rounded-xl" />
                    ))}
                </div>
            ) : pending.isError ? (
                <Panel>
                    <ErrorState error={pending.error} onRetry={pending.refetch} />
                </Panel>
            ) : rows.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={ClipboardCheckIcon}
                        title="No pending approvals"
                        description="New school registrations submitted by Sub-County Directors will appear here."
                    />
                </Panel>
            ) : (
                <div className="space-y-3">
                    {rows.map((request) => (
                        <article
                            key={request.id}
                            className="flex flex-col justify-between gap-4 rounded-xl border border-border bg-white p-4 shadow-card sm:flex-row sm:items-center"
                        >
                            <div className="min-w-0">
                                <Link to={`/schools/${request.id}`} className="text-sm font-bold hover:text-brand hover:underline">
                                    {request.name}
                                </Link>
                                <p className="mt-1 text-xs text-text-secondary">
                                    {request.subcounty?.name ?? 'Kiambu'} sub-county · NEMIS {request.nemis_code}
                                </p>
                                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-text-secondary">
                                    <span>{INSTITUTION_TYPES[request.institution_type]}</span>
                                    <span>{OWNERSHIP_TYPES[request.ownership]}</span>
                                    <span>Declared devices: {formatNumber(request.computing_devices_count)}</span>
                                    <span>Declared learners: {formatNumber(request.learner_population)}</span>
                                    <span className="flex items-center gap-1">
                                        <CalendarIcon size={11} /> {formatDate(request.submitted_at, { withTime: true })}
                                    </span>
                                </div>
                            </div>

                            <div className="flex shrink-0 gap-2">
                                <Button as={Link} to={`/schools/${request.id}`}>
                                    Open school
                                </Button>
                                <Button variant="primary" icon={GavelIcon} onClick={() => setUnderReview(request)}>
                                    Review
                                </Button>
                            </div>
                        </article>
                    ))}

                    <Panel>
                        <Pagination meta={pending.data?.meta} onChange={list.setPage} unit="registrations" />
                    </Panel>
                </div>
            )}

            <ReviewModal institution={underReview} onClose={() => setUnderReview(null)} />
        </div>
    );
}
