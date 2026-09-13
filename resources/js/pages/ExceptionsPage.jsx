import { useEffect, useState } from 'react';
import { FileCheck2Icon, PlusIcon, XIcon } from 'lucide-react';
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
    Row,
    Select,
    TextInput,
    Textarea,
    firstError,
} from '../components/Primitives';
import { Modal } from '../components/Overlays';
import { StatusPill } from '../components/StatusPill';
import { useListState } from '../lib/hooks';
import { useAuth } from '../lib/auth';
import { useScope } from '../lib/scope';
import { useContentCategories, useCreateExceptionRequest, useExceptionRequests, useReviewExceptionRequest } from '../lib/queries';
import { EXCEPTION_STATUS } from '../lib/domain';
import { capabilitiesFor } from '../lib/permissions';
import { formatDate, formatNumber } from '../lib/format';
import { ApiError } from '../lib/api';
import { showToast, toastError } from '../lib/toast';

export function ExceptionsPage() {
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const scope = useScope();
    const list = useListState({ status: '' });
    const [requesting, setRequesting] = useState(false);
    const [reviewing, setReviewing] = useState(null);

    const requests = useExceptionRequests({ ...scope.params, page: list.page, status: list.values.status || undefined });
    const rows = requests.data?.data ?? [];

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Filtering Exceptions"
                description={
                    can.reviewExceptions
                        ? 'Requests to release a blocked domain for classroom use, and the decision on each.'
                        : 'Ask for a legitimate educational resource to be released, and follow the decision.'
                }
                meta={requests.data?.meta ? `${formatNumber(requests.data.meta.total)} requests` : undefined}
                actions={
                    <Button variant="primary" icon={PlusIcon} onClick={() => setRequesting(true)}>
                        Request exception
                    </Button>
                }
            />

            <Panel>
                <div className="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <FilterSelect
                        label="Status"
                        value={list.values.status}
                        onChange={(value) => list.setValue('status', value)}
                        options={Object.entries(EXCEPTION_STATUS).map(([value, meta]) => [value, meta.label])}
                    />
                    {list.isFiltered && (
                        <Button variant="ghost" size="sm" icon={XIcon} onClick={list.reset}>
                            Clear
                        </Button>
                    )}
                </div>

                <DataTable
                    query={requests}
                    rows={rows}
                    minWidth="880px"
                    columns={['Domain', 'Reason', 'Requested', 'Expires', 'Status', { key: 'actions', label: '', align: 'right' }]}
                    empty={
                        <EmptyState
                            icon={FileCheck2Icon}
                            title="No exception requests"
                            description="Schools can ask for a blocked domain to be released for classroom instruction."
                        />
                    }
                >
                    {rows.map((request) => (
                        <Row key={request.id}>
                            <Cell mono bold>
                                {request.domain}
                            </Cell>
                            <Cell muted className="max-w-[24rem] truncate" title={request.reason}>
                                {request.reason}
                            </Cell>
                            <Cell muted>{formatDate(request.created_at)}</Cell>
                            <Cell muted>{request.expires_at ? formatDate(request.expires_at) : '—'}</Cell>
                            <Cell>
                                <StatusPill descriptor={EXCEPTION_STATUS[request.status]} />
                            </Cell>
                            <Cell align="right">
                                {can.reviewExceptions && request.status === 'pending' && (
                                    <Button size="sm" variant="primary" onClick={() => setReviewing(request)}>
                                        Review
                                    </Button>
                                )}
                            </Cell>
                        </Row>
                    ))}
                </DataTable>

                <Pagination meta={requests.data?.meta} onChange={list.setPage} unit="requests" />
            </Panel>

            <RequestDrawer open={requesting} onClose={() => setRequesting(false)} />
            <ReviewExceptionDrawer request={reviewing} onClose={() => setReviewing(null)} />
        </div>
    );
}

function RequestDrawer({ open, onClose }) {
    const create = useCreateExceptionRequest();
    const categories = useContentCategories();
    const [form, setForm] = useState({ domain: '', reason: '', content_category_id: '', expires_at: '' });

    useEffect(() => {
        if (open) {
            setForm({ domain: '', reason: '', content_category_id: '', expires_at: '' });
            create.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const errors = create.error instanceof ApiError ? create.error.errors : {};
    const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

    async function submit(event) {
        event.preventDefault();

        try {
            await create.mutateAsync({
                domain: form.domain,
                reason: form.reason,
                content_category_id: form.content_category_id ? Number(form.content_category_id) : null,
                expires_at: form.expires_at || null,
            });
            showToast('Exception requested', { description: 'Your Sub-County Director will review the request.' });
            onClose();
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The request could not be submitted');
            }
        }
    }

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Request a filtering exception"
            subtitle="Ask for a blocked domain to be released for classroom instruction."
            size="sm"
            footer={
                <>
                    <Button className="flex-1" onClick={onClose} disabled={create.isPending}>
                        Cancel
                    </Button>
                    <Button className="flex-1" type="submit" form="exception-form" variant="primary" loading={create.isPending}>
                        Submit request
                    </Button>
                </>
            }
        >
            {/* Two columns so the request is visible without scrolling. */}
            <form id="exception-form" onSubmit={submit} className="grid gap-x-5 gap-y-4 sm:grid-cols-2" noValidate>
                <Field label="Domain" required error={firstError(errors, 'domain')} hint="Without https:// — for example khanacademy.org">
                    <TextInput data-autofocus value={form.domain} onChange={set('domain')} className="font-mono" />
                </Field>
                <Field label="Content category" error={firstError(errors, 'content_category_id')}>
                    <Select value={form.content_category_id} onChange={set('content_category_id')}>
                        <option value="">Not specified</option>
                        {(categories.data?.data ?? []).map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field className="sm:col-span-2" label="Reason" required error={firstError(errors, 'reason')} hint="Explain the curriculum need this domain serves.">
                    <Textarea value={form.reason} maxLength={2000} onChange={set('reason')} />
                </Field>
                <Field label="Requested expiry" error={firstError(errors, 'expires_at')} hint="Optional. Exceptions should be time-bound.">
                    <TextInput type="date" value={form.expires_at} onChange={set('expires_at')} />
                </Field>
            </form>
        </Modal>
    );
}

function ReviewExceptionDrawer({ request, onClose }) {
    const review = useReviewExceptionRequest();
    const [decision, setDecision] = useState('approved');
    const [notes, setNotes] = useState('');
    const [expiresAt, setExpiresAt] = useState('');

    useEffect(() => {
        if (request) {
            setDecision('approved');
            setNotes('');
            setExpiresAt('');
            review.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [request?.id]);

    const errors = review.error instanceof ApiError ? review.error.errors : {};

    async function submit(event) {
        event.preventDefault();

        try {
            await review.mutateAsync({
                id: request.id,
                decision,
                review_notes: notes || null,
                expires_at: expiresAt || null,
            });
            showToast(`${request.domain} ${decision}`, { description: 'The decision was written to the county record.' });
            onClose();
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The decision could not be recorded');
            }
        }
    }

    return (
        <Modal
            open={Boolean(request)}
            onClose={onClose}
            title={request?.domain ?? ''}
            subtitle="Exception request review"
            size="sm"
            footer={
                <>
                    <Button className="flex-1" onClick={onClose} disabled={review.isPending}>
                        Cancel
                    </Button>
                    <Button
                        className="flex-1"
                        type="submit"
                        form="exception-review"
                        variant={decision === 'rejected' ? 'danger' : 'primary'}
                        loading={review.isPending}
                    >
                        Record decision
                    </Button>
                </>
            }
        >
            {request && (
                <form id="exception-review" onSubmit={submit} className="grid gap-x-5 gap-y-4 sm:grid-cols-2" noValidate>
                    <p className="rounded-lg bg-surface-muted px-3 py-2.5 text-xs leading-5 text-text-secondary">{request.reason}</p>

                    <Field label="Decision" required error={firstError(errors, 'decision')}>
                        <Select value={decision} onChange={(event) => setDecision(event.target.value)} data-autofocus>
                            <option value="approved">Approve — release the domain</option>
                            <option value="rejected">Reject — keep the domain blocked</option>
                        </Select>
                    </Field>

                    {decision === 'approved' && (
                        <Field label="Expiry" error={firstError(errors, 'expires_at')} hint="Optional. Leave blank to keep the requested expiry.">
                            <TextInput type="date" value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} />
                        </Field>
                    )}

                    <Field className="sm:col-span-2" label="Review notes" error={firstError(errors, 'review_notes')}>
                        <Textarea value={notes} maxLength={2000} onChange={(event) => setNotes(event.target.value)} />
                    </Field>
                </form>
            )}
        </Modal>
    );
}
