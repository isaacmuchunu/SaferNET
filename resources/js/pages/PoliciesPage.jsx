import { useEffect, useState } from 'react';
import { LockIcon, PencilIcon, PlusIcon, SlidersHorizontalIcon, Trash2Icon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import {
    Button,
    Cell,
    DataTable,
    EmptyState,
    ErrorState,
    Field,
    Pagination,
    Panel,
    Row,
    Select,
    TableSkeleton,
    TextInput,
    firstError,
} from '../components/Primitives';
import { ConfirmDialog, Modal } from '../components/Overlays';
import { StatusPill } from '../components/StatusPill';
import { useListState } from '../lib/hooks';
import { useAuth } from '../lib/auth';
import {
    useContentCategories,
    useDeletePolicyRule,
    useFilteringPolicies,
    usePolicyRules,
    useSaveFilteringPolicy,
    useSavePolicyRule,
} from '../lib/queries';
import { ENFORCEMENT_ACTION, POLICY_LEVELS, SEVERITY } from '../lib/domain';
import { capabilitiesFor } from '../lib/permissions';
import { formatDate, formatNumber } from '../lib/format';
import { ApiError } from '../lib/api';
import { showToast, toastError } from '../lib/toast';

export function PoliciesPage() {
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const isCde = user?.role === 'cde';
    const list = useListState();

    const [selected, setSelected] = useState(null);
    const [editing, setEditing] = useState(null);

    const policies = useFilteringPolicies({ page: list.page });
    const rows = policies.data?.data ?? [];

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Filtering Policies"
                description={
                    can.managePolicies
                        ? 'The county baseline and the school policies that inherit from it. Changing a rule changes what learners may reach.'
                        : 'The county baseline and the school policies that inherit from it. Rules are set by the accountable officer.'
                }
                meta={policies.data?.meta ? `${formatNumber(policies.data.meta.total)} policies` : undefined}
                actions={
                    can.managePolicies && (
                        <Button variant="primary" icon={PlusIcon} onClick={() => setEditing({})}>
                            New policy
                        </Button>
                    )
                }
            />

            {!can.managePolicies && (
                <p className="mb-4 rounded-xl border border-info/25 bg-info-soft px-4 py-3 text-xs leading-5 text-info">
                    Filtering rules are an administrative decision. You can read every rule here to investigate why a site was
                    blocked, and submit an exception request where a legitimate resource is caught.
                </p>
            )}

            <Panel>
                <DataTable
                    query={policies}
                    rows={rows}
                    minWidth="860px"
                    columns={['Policy', 'Level', 'Rules', 'Effective from', 'Status', { key: 'actions', label: '', align: 'right' }]}
                    empty={
                        <EmptyState
                            icon={SlidersHorizontalIcon}
                            title="No filtering policies"
                            description="The county baseline policy defines what every school inherits."
                        />
                    }
                >
                    {rows.map((policy) => (
                        <Row key={policy.id} onClick={() => setSelected(policy)}>
                            <Cell bold>{policy.name}</Cell>
                            <Cell muted>{POLICY_LEVELS[policy.level] ?? policy.level}</Cell>
                            <Cell className="tabular-nums">{policy.rules?.length ?? '—'}</Cell>
                            <Cell muted>{formatDate(policy.effective_from)}</Cell>
                            <Cell>
                                <StatusPill
                                    label={policy.status === 'active' ? 'Active' : policy.status === 'draft' ? 'Draft' : 'Archived'}
                                    tone={policy.status === 'active' ? 'success' : policy.status === 'draft' ? 'warning' : 'neutral'}
                                />
                            </Cell>
                            <Cell align="right">
                                <div className="flex justify-end gap-1">
                                    {can.managePolicies && policy.level !== 'county' && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            icon={PencilIcon}
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                setEditing(policy);
                                            }}
                                        >
                                            Edit
                                        </Button>
                                    )}
                                    <Button size="sm" variant="ghost">
                                        {can.managePolicies ? 'Open rules' : 'View rules'}
                                    </Button>
                                </div>
                            </Cell>
                        </Row>
                    ))}
                </DataTable>

                <Pagination meta={policies.data?.meta} onChange={list.setPage} unit="policies" />
            </Panel>

            <PolicyRulesModal policy={selected} onClose={() => setSelected(null)} canManage={can.managePolicies} isCde={isCde} />
            <PolicyModal policy={editing} onClose={() => setEditing(null)} isCde={isCde} />
        </div>
    );
}

/* ----------------------------------------------------------- policy form ---- */

const EMPTY_POLICY = { name: '', level: 'institution', status: 'draft' };

function PolicyModal({ policy, onClose, isCde }) {
    const save = useSaveFilteringPolicy();
    const [form, setForm] = useState(EMPTY_POLICY);
    const isEdit = Boolean(policy?.id);

    useEffect(() => {
        if (policy) {
            setForm({
                name: policy.name ?? '',
                level: policy.level ?? (isCde ? 'county' : 'institution'),
                status: policy.status ?? 'draft',
            });
            save.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [policy?.id, Boolean(policy)]);

    const errors = save.error instanceof ApiError ? save.error.errors : {};

    async function submit(event) {
        event.preventDefault();

        try {
            // The API fixes level and scope at creation; an edit revises the rest.
            await save.mutateAsync(isEdit ? { id: policy.id, name: form.name, status: form.status } : form);
            showToast(isEdit ? 'Policy updated' : 'Policy created', { description: `${form.name} is ready for rules.` });
            onClose();
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The policy could not be saved');
            }
        }
    }

    return (
        <Modal
            open={Boolean(policy)}
            onClose={onClose}
            title={isEdit ? policy.name : 'New filtering policy'}
            subtitle="Policies inherit from the county baseline."
            size="sm"
            footer={
                <>
                    <Button className="flex-1" onClick={onClose} disabled={save.isPending}>
                        Cancel
                    </Button>
                    <Button className="flex-1" type="submit" form="policy-form" variant="primary" loading={save.isPending}>
                        {isEdit ? 'Save changes' : 'Create policy'}
                    </Button>
                </>
            }
        >
            <form id="policy-form" onSubmit={submit} className="space-y-4" noValidate>
                <Field label="Policy name" required error={firstError(errors, 'name')}>
                    <TextInput data-autofocus value={form.name} onChange={(event) => setForm((c) => ({ ...c, name: event.target.value }))} />
                </Field>

                <Field
                    label="Level"
                    required
                    hint={isEdit ? 'The level is fixed once a policy exists.' : undefined}
                    error={firstError(errors, 'level')}
                >
                    <Select
                        value={form.level}
                        disabled={isEdit}
                        onChange={(event) => setForm((c) => ({ ...c, level: event.target.value }))}
                    >
                        {isCde && <option value="county">County — applies to every school</option>}
                        <option value="institution">Institution — applies to one school</option>
                    </Select>
                </Field>

                <Field label="Status" error={firstError(errors, 'status')}>
                    <Select value={form.status} onChange={(event) => setForm((c) => ({ ...c, status: event.target.value }))}>
                        <option value="draft">Draft — not yet enforced</option>
                        <option value="active">Active — enforced on devices</option>
                        <option value="archived">Archived</option>
                    </Select>
                </Field>
            </form>
        </Modal>
    );
}

/* ------------------------------------------------------------- rule form ---- */

const EMPTY_RULE = {
    content_category_id: '',
    action: 'block',
    severity: 'high',
    is_locked: false,
    counts_toward_incidents: true,
    threshold_count: '5',
    threshold_window_minutes: '30',
    notify_immediately: false,
};

function PolicyRulesModal({ policy, onClose, canManage, isCde }) {
    const rules = usePolicyRules(policy?.id);
    const categories = useContentCategories();
    const saveRule = useSavePolicyRule();
    const deleteRule = useDeletePolicyRule();

    const [editingRule, setEditingRule] = useState(null);
    const [pendingDelete, setPendingDelete] = useState(null);
    const [form, setForm] = useState(EMPTY_RULE);

    useEffect(() => {
        setEditingRule(null);
        setForm(EMPTY_RULE);
    }, [policy?.id]);

    useEffect(() => {
        if (editingRule) {
            setForm({
                content_category_id: String(editingRule.content_category_id ?? ''),
                action: editingRule.action ?? 'block',
                severity: editingRule.severity ?? 'high',
                is_locked: Boolean(editingRule.is_locked),
                counts_toward_incidents: editingRule.counts_toward_incidents ?? true,
                threshold_count: String(editingRule.threshold_count ?? 5),
                threshold_window_minutes: String(editingRule.threshold_window_minutes ?? 30),
                notify_immediately: Boolean(editingRule.notify_immediately),
            });
            saveRule.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editingRule?.id, Boolean(editingRule)]);

    const errors = saveRule.error instanceof ApiError ? saveRule.error.errors : {};
    const rows = rules.data?.data ?? [];
    const isNewRule = editingRule !== null && !editingRule?.id;

    async function submit(event) {
        event.preventDefault();

        try {
            await saveRule.mutateAsync({
                policyId: policy.id,
                id: editingRule?.id,
                content_category_id: Number(form.content_category_id),
                action: form.action,
                severity: form.severity,
                is_locked: form.is_locked,
                counts_toward_incidents: form.counts_toward_incidents,
                threshold_count: Number(form.threshold_count || 1),
                threshold_window_minutes: Number(form.threshold_window_minutes || 30),
                notify_immediately: form.notify_immediately,
            });
            showToast(isNewRule ? 'Rule added' : 'Rule updated', { description: 'The policy takes effect on the next device sync.' });
            setEditingRule(null);
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The rule could not be saved');
            }
        }
    }

    async function confirmDelete() {
        try {
            await deleteRule.mutateAsync({ policyId: policy.id, id: pendingDelete.id });
            showToast('Rule removed');
        } catch (error) {
            toastError(error, 'The rule could not be removed');
        } finally {
            setPendingDelete(null);
        }
    }

    return (
        <>
            <Modal
                open={Boolean(policy)}
                onClose={onClose}
                title={policy?.name ?? ''}
                subtitle={policy ? `${POLICY_LEVELS[policy.level] ?? policy.level} policy · version ${policy.version}` : undefined}
                size="lg"
                footer={
                    <Button className="ml-auto" onClick={onClose}>
                        Close
                    </Button>
                }
            >
                {policy && (
                    <div className="space-y-4">
                        {rules.isPending ? (
                            <TableSkeleton rows={4} cols={4} />
                        ) : rules.isError ? (
                            <ErrorState error={rules.error} onRetry={rules.refetch} />
                        ) : rows.length === 0 ? (
                            <EmptyState title="No rules yet" description="Add the content categories this policy enforces." />
                        ) : (
                            <ul className="divide-y divide-border rounded-lg border border-border">
                                {rows.map((rule) => (
                                    <li key={rule.id} className="flex items-center justify-between gap-3 px-3 py-2.5">
                                        <div className="min-w-0">
                                            <p className="flex items-center gap-1.5 text-xs font-semibold">
                                                {rule.category?.name ?? `Category ${rule.content_category_id}`}
                                                {rule.is_locked && <LockIcon size={11} className="text-text-muted" />}
                                            </p>
                                            <p className="mt-0.5 text-[11px] text-text-secondary">
                                                Threshold {rule.threshold_count} in {rule.threshold_window_minutes} minutes
                                                {rule.notify_immediately ? ' · notifies immediately' : ''}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <StatusPill descriptor={ENFORCEMENT_ACTION[rule.action]} />
                                            <StatusPill descriptor={SEVERITY[rule.severity]} />
                                            {canManage && (!rule.is_locked || isCde) && (
                                                <>
                                                    <button
                                                        onClick={() => setEditingRule(rule)}
                                                        aria-label="Edit rule"
                                                        className="rounded-md p-1 text-text-muted hover:bg-surface-muted hover:text-text"
                                                    >
                                                        <PencilIcon size={14} />
                                                    </button>
                                                    <button
                                                        onClick={() => setPendingDelete(rule)}
                                                        aria-label="Remove rule"
                                                        className="rounded-md p-1 text-text-muted hover:bg-danger-soft hover:text-danger"
                                                    >
                                                        <Trash2Icon size={14} />
                                                    </button>
                                                </>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {canManage && editingRule === null && (
                            <Button className="w-full border-dashed" icon={PlusIcon} onClick={() => setEditingRule({})}>
                                Add rule
                            </Button>
                        )}

                        {editingRule !== null && (
                            <form onSubmit={submit} className="space-y-3 rounded-lg border border-border p-3">
                                <p className="text-xs font-semibold">{isNewRule ? 'Add a rule' : `Edit ${editingRule.category?.name ?? 'rule'}`}</p>

                                <Field label="Content category" required error={firstError(errors, 'content_category_id')}>
                                    <Select
                                        data-autofocus
                                        value={form.content_category_id}
                                        disabled={!isNewRule}
                                        onChange={(event) => setForm((c) => ({ ...c, content_category_id: event.target.value }))}
                                    >
                                        <option value="">Select a category</option>
                                        {(categories.data?.data ?? []).map((category) => (
                                            <option key={category.id} value={category.id}>
                                                {category.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Enforcement" required error={firstError(errors, 'action')}>
                                        <Select value={form.action} onChange={(event) => setForm((c) => ({ ...c, action: event.target.value }))}>
                                            {Object.entries(ENFORCEMENT_ACTION)
                                                .filter(([value]) => value !== 'restrict')
                                                .map(([value, meta]) => (
                                                    <option key={value} value={value}>
                                                        {meta.label}
                                                    </option>
                                                ))}
                                        </Select>
                                    </Field>
                                    <Field label="Severity" required error={firstError(errors, 'severity')}>
                                        <Select value={form.severity} onChange={(event) => setForm((c) => ({ ...c, severity: event.target.value }))}>
                                            {Object.entries(SEVERITY).map(([value, meta]) => (
                                                <option key={value} value={value}>
                                                    {meta.label}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Incident threshold" hint="Attempts before an incident is raised." error={firstError(errors, 'threshold_count')}>
                                        <TextInput
                                            type="number"
                                            min="1"
                                            className="tabular-nums"
                                            value={form.threshold_count}
                                            onChange={(event) => setForm((c) => ({ ...c, threshold_count: event.target.value }))}
                                        />
                                    </Field>
                                    <Field label="Window (minutes)" error={firstError(errors, 'threshold_window_minutes')}>
                                        <TextInput
                                            type="number"
                                            min="1"
                                            className="tabular-nums"
                                            value={form.threshold_window_minutes}
                                            onChange={(event) => setForm((c) => ({ ...c, threshold_window_minutes: event.target.value }))}
                                        />
                                    </Field>
                                </div>

                                <div className="space-y-2">
                                    {[
                                        ['counts_toward_incidents', 'Count matches toward incident thresholds'],
                                        ['notify_immediately', 'Notify the Head of Institution immediately'],
                                        ...(isCde ? [['is_locked', 'Lock this rule so schools cannot weaken it']] : []),
                                    ].map(([key, label]) => (
                                        <label key={key} className="flex items-center gap-2 text-xs text-text-secondary">
                                            <input
                                                type="checkbox"
                                                checked={Boolean(form[key])}
                                                onChange={(event) => setForm((c) => ({ ...c, [key]: event.target.checked }))}
                                                className="h-4 w-4 rounded border-border accent-[#0d5c55]"
                                            />
                                            {label}
                                        </label>
                                    ))}
                                </div>

                                <div className="flex gap-2">
                                    <Button className="flex-1" type="button" onClick={() => setEditingRule(null)}>
                                        Cancel
                                    </Button>
                                    <Button
                                        className="flex-1"
                                        type="submit"
                                        variant="primary"
                                        loading={saveRule.isPending}
                                        disabled={!form.content_category_id}
                                    >
                                        {isNewRule ? 'Add rule' : 'Save rule'}
                                    </Button>
                                </div>
                            </form>
                        )}
                    </div>
                )}
            </Modal>

            <ConfirmDialog
                open={Boolean(pendingDelete)}
                tone="danger"
                title="Remove this rule?"
                description={
                    pendingDelete
                        ? `${pendingDelete.category?.name ?? 'This category'} will no longer be enforced by ${policy?.name}. The change is audited.`
                        : ''
                }
                confirmLabel="Remove rule"
                loading={deleteRule.isPending}
                onCancel={() => setPendingDelete(null)}
                onConfirm={confirmDelete}
            />
        </>
    );
}
