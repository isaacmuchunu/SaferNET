import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { GraduationCapIcon, LayersIcon, PencilIcon, PlusIcon, Trash2Icon, XIcon } from 'lucide-react';
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
    SearchInput,
    Select,
    TextInput,
    firstError,
} from '../components/Primitives';
import { ConfirmDialog, Drawer } from '../components/Overlays';
import { StatusPill } from '../components/StatusPill';
import { useDebouncedValue, useListState } from '../lib/hooks';
import { useAuth } from '../lib/auth';
import { useScope } from '../lib/scope';
import {
    useDeleteLearner,
    useDeleteLearnerGroup,
    useLearnerGroups,
    useLearners,
    useSaveLearner,
    useSaveLearnerGroup,
} from '../lib/queries';
import { LEARNER_STATUS } from '../lib/domain';
import { capabilitiesFor } from '../lib/permissions';
import { formatNumber } from '../lib/format';
import { ApiError } from '../lib/api';
import { showToast, toastError } from '../lib/toast';

export function LearnersPage() {
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const scope = useScope();
    const list = useListState({ search: '', status: '', group: '', tab: '' });
    const search = useDebouncedValue(list.values.search, 300);

    const tab = list.values.tab || 'learners';
    const [editingLearner, setEditingLearner] = useState(null);
    const [editingGroup, setEditingGroup] = useState(null);
    const [pendingDelete, setPendingDelete] = useState(null);
    const [pendingGroupDelete, setPendingGroupDelete] = useState(null);

    const learners = useLearners(
        {
            ...scope.params,
            page: list.page,
            search: search || undefined,
            status: list.values.status || undefined,
            learner_group_id: list.values.group || undefined,
        },
        { enabled: tab === 'learners' },
    );
    const groups = useLearnerGroups({ ...scope.params, page: tab === 'classes' ? list.page : 1 });

    const removeLearner = useDeleteLearner();
    const removeGroup = useDeleteLearnerGroup();

    const rows = learners.data?.data ?? [];
    const groupRows = groups.data?.data ?? [];

    async function confirmDelete() {
        try {
            await removeLearner.mutateAsync(pendingDelete.id);
            showToast('Learner removed', { description: `${pendingDelete.first_name} ${pendingDelete.last_name} left the register.` });
        } catch (error) {
            toastError(error, 'The learner could not be removed');
        } finally {
            setPendingDelete(null);
        }
    }

    async function confirmGroupDelete() {
        try {
            await removeGroup.mutateAsync(pendingGroupDelete.id);
            showToast('Class removed');
        } catch (error) {
            toastError(error, 'The class could not be removed');
        } finally {
            setPendingGroupDelete(null);
        }
    }

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Learners & Classes"
                description={
                    can.deleteLearners
                        ? 'The learner safety register. Accurate records are what make filtering decisions attributable.'
                        : 'The learner safety register and the classes learners are grouped into.'
                }
                meta={learners.data?.meta ? `${formatNumber(learners.data.meta.total)} enrolled` : undefined}
                actions={
                    <>
                        {can.manageLearnerGroups && tab === 'classes' && (
                            <Button variant="primary" icon={PlusIcon} onClick={() => setEditingGroup({})}>
                                Create class
                            </Button>
                        )}
                        {can.createLearners && tab === 'learners' && (
                            <Button variant="primary" icon={PlusIcon} onClick={() => setEditingLearner({})}>
                                Enrol learner
                            </Button>
                        )}
                    </>
                }
            />

            <div className="mb-4 flex gap-1 border-b border-border pb-px">
                {[
                    ['learners', 'Learners', GraduationCapIcon],
                    ['classes', 'Classes', LayersIcon],
                ].map(([value, label, Icon]) => (
                    <button
                        key={value}
                        onClick={() => list.setValue('tab', value === 'learners' ? '' : value)}
                        className={clsx(
                            '-mb-px flex items-center gap-2 border-b-2 px-3 py-2 text-xs font-semibold transition-colors duration-150 ease-gov',
                            tab === value ? 'border-brand text-brand' : 'border-transparent text-text-secondary hover:text-text',
                        )}
                    >
                        <Icon size={14} />
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'learners' ? (
                <Panel>
                    <div className="flex flex-wrap items-center gap-2 border-b border-border p-3">
                        <SearchInput
                            value={list.values.search}
                            onChange={(value) => list.setValue('search', value)}
                            placeholder="Search learner name or number"
                        />
                        <FilterSelect
                            label="Status"
                            value={list.values.status}
                            onChange={(value) => list.setValue('status', value)}
                            options={Object.entries(LEARNER_STATUS).map(([value, meta]) => [value, meta.label])}
                        />
                        <FilterSelect
                            label="Class"
                            value={list.values.group}
                            onChange={(value) => list.setValue('group', value)}
                            options={groupRows.map((group) => [String(group.id), group.name])}
                        />
                        {list.isFiltered && (
                            <Button variant="ghost" size="sm" icon={XIcon} onClick={list.reset}>
                                Clear
                            </Button>
                        )}
                    </div>

                    <DataTable
                        query={learners}
                        rows={rows}
                        minWidth="820px"
                        columns={[
                            'Learner number',
                            'Name',
                            'Class',
                            'External identity',
                            'Status',
                            { key: 'actions', label: '', align: 'right' },
                        ]}
                        empty={
                            <EmptyState
                                icon={GraduationCapIcon}
                                title={list.isFiltered ? 'No learners match these filters' : 'No learners enrolled'}
                                description={
                                    list.isFiltered
                                        ? 'Adjust the search or clear a filter to widen the results.'
                                        : 'Enrol learners before attributing devices to them.'
                                }
                            />
                        }
                    >
                        {rows.map((learner) => (
                            <Row key={learner.id}>
                                <Cell mono>{learner.learner_number}</Cell>
                                <Cell bold>
                                    {learner.first_name} {learner.last_name}
                                </Cell>
                                <Cell muted>{learner.learner_group?.name ?? '—'}</Cell>
                                <Cell muted>{learner.external_identity ?? '—'}</Cell>
                                <Cell>
                                    <StatusPill descriptor={LEARNER_STATUS[learner.status]} />
                                </Cell>
                                <Cell align="right">
                                    <div className="flex justify-end gap-1">
                                        {can.editLearners && (
                                            <Button size="sm" variant="ghost" icon={PencilIcon} onClick={() => setEditingLearner(learner)}>
                                                Edit
                                            </Button>
                                        )}
                                        {can.deleteLearners && (
                                            <Button size="sm" variant="ghost" icon={Trash2Icon} onClick={() => setPendingDelete(learner)}>
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                </Cell>
                            </Row>
                        ))}
                    </DataTable>

                    <Pagination meta={learners.data?.meta} onChange={list.setPage} unit="learners" />
                </Panel>
            ) : (
                <Panel>
                    <DataTable
                        query={groups}
                        rows={groupRows}
                        minWidth="680px"
                        columns={[
                            'Class',
                            'Grade level',
                            'Academic year',
                            { key: 'learners', label: 'Learners', align: 'right' },
                            { key: 'actions', label: '', align: 'right' },
                        ]}
                        empty={
                            <EmptyState
                                icon={LayersIcon}
                                title="No classes created"
                                description={
                                    can.manageLearnerGroups
                                        ? 'Create the classes learners belong to before importing the learner register.'
                                        : 'The laboratory manager creates the classes learners are grouped into.'
                                }
                                action={
                                    can.manageLearnerGroups ? (
                                        <Button size="sm" variant="primary" icon={PlusIcon} onClick={() => setEditingGroup({})}>
                                            Create class
                                        </Button>
                                    ) : null
                                }
                            />
                        }
                    >
                        {groupRows.map((group) => (
                            <Row key={group.id}>
                                <Cell bold>{group.name}</Cell>
                                <Cell muted>{group.grade_level ?? '—'}</Cell>
                                <Cell muted>{group.academic_year ?? '—'}</Cell>
                                <Cell align="right" className="tabular-nums">
                                    {formatNumber(group.learners_count ?? 0)}
                                </Cell>
                                <Cell align="right">
                                    <div className="flex justify-end gap-1">
                                        {can.manageLearnerGroups && (
                                            <Button size="sm" variant="ghost" icon={PencilIcon} onClick={() => setEditingGroup(group)}>
                                                Edit
                                            </Button>
                                        )}
                                        {can.deleteLearners && (
                                            <Button size="sm" variant="ghost" icon={Trash2Icon} onClick={() => setPendingGroupDelete(group)}>
                                                Remove
                                            </Button>
                                        )}
                                    </div>
                                </Cell>
                            </Row>
                        ))}
                    </DataTable>

                    <Pagination meta={groups.data?.meta} onChange={list.setPage} unit="classes" />
                </Panel>
            )}

            <LearnerDrawer learner={editingLearner} onClose={() => setEditingLearner(null)} groups={groupRows} />
            <ClassDrawer group={editingGroup} onClose={() => setEditingGroup(null)} />

            <ConfirmDialog
                open={Boolean(pendingDelete)}
                tone="danger"
                title="Remove this learner?"
                description={
                    pendingDelete
                        ? `${pendingDelete.first_name} ${pendingDelete.last_name} will be removed from the safety register along with their device attribution.`
                        : ''
                }
                confirmLabel="Remove learner"
                loading={removeLearner.isPending}
                onCancel={() => setPendingDelete(null)}
                onConfirm={confirmDelete}
            />

            <ConfirmDialog
                open={Boolean(pendingGroupDelete)}
                tone="danger"
                title="Remove this class?"
                description={
                    pendingGroupDelete
                        ? `${pendingGroupDelete.name} will be removed. Learners in the class stay on the register but lose their class.`
                        : ''
                }
                confirmLabel="Remove class"
                loading={removeGroup.isPending}
                onCancel={() => setPendingGroupDelete(null)}
                onConfirm={confirmGroupDelete}
            />
        </div>
    );
}

const EMPTY_LEARNER = { learner_number: '', first_name: '', last_name: '', learner_group_id: '', status: 'active', pin: '', external_identity: '' };

function LearnerDrawer({ learner, onClose, groups }) {
    const save = useSaveLearner();
    const [form, setForm] = useState(EMPTY_LEARNER);
    const isEdit = Boolean(learner?.id);

    useEffect(() => {
        if (learner) {
            setForm({
                learner_number: learner.learner_number ?? '',
                first_name: learner.first_name ?? '',
                last_name: learner.last_name ?? '',
                learner_group_id: learner.learner_group_id ? String(learner.learner_group_id) : '',
                status: learner.status ?? 'active',
                external_identity: learner.external_identity ?? '',
                pin: '',
            });
            save.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [learner?.id, Boolean(learner)]);

    const errors = save.error instanceof ApiError ? save.error.errors : {};
    const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

    async function submit(event) {
        event.preventDefault();

        try {
            await save.mutateAsync({
                id: learner?.id,
                ...form,
                learner_group_id: form.learner_group_id ? Number(form.learner_group_id) : null,
                external_identity: form.external_identity || null,
                pin: form.pin || undefined,
            });
            showToast(isEdit ? 'Learner updated' : 'Learner enrolled', {
                description: `${form.first_name} ${form.last_name} is on the safety register.`,
            });
            onClose();
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The learner could not be saved');
            }
        }
    }

    return (
        <Drawer
            open={Boolean(learner)}
            onClose={onClose}
            title={isEdit ? `${learner.first_name} ${learner.last_name}` : 'Enrol a learner'}
            subtitle={isEdit ? learner.learner_number : 'The learner is enrolled at your institution.'}
            width="max-w-md"
            footer={
                <>
                    <Button className="flex-1" onClick={onClose} disabled={save.isPending}>
                        Cancel
                    </Button>
                    <Button className="flex-1" type="submit" form="learner-form" variant="primary" loading={save.isPending}>
                        {isEdit ? 'Save changes' : 'Enrol learner'}
                    </Button>
                </>
            }
        >
            <form id="learner-form" onSubmit={submit} className="space-y-4" noValidate>
                <Field label="Learner number" required hint="The identifier used on the school register." error={firstError(errors, 'learner_number')}>
                    <TextInput data-autofocus value={form.learner_number} onChange={set('learner_number')} className="font-mono" />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="First name" required error={firstError(errors, 'first_name')}>
                        <TextInput value={form.first_name} onChange={set('first_name')} />
                    </Field>
                    <Field label="Last name" required error={firstError(errors, 'last_name')}>
                        <TextInput value={form.last_name} onChange={set('last_name')} />
                    </Field>
                </div>
                <Field label="Class" error={firstError(errors, 'learner_group_id')}>
                    <Select value={form.learner_group_id} onChange={set('learner_group_id')}>
                        <option value="">Unassigned</option>
                        {groups.map((group) => (
                            <option key={group.id} value={group.id}>
                                {group.name}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field label="Status" error={firstError(errors, 'status')}>
                    <Select value={form.status} onChange={set('status')}>
                        {Object.entries(LEARNER_STATUS).map(([value, meta]) => (
                            <option key={value} value={value}>
                                {meta.label}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field
                    label="External identity"
                    hint="Optional. A Google Workspace or Microsoft account used to sign in."
                    error={firstError(errors, 'external_identity')}
                >
                    <TextInput value={form.external_identity} onChange={set('external_identity')} />
                </Field>
                <Field
                    label="School PIN"
                    hint={isEdit ? 'Leave blank to keep the current PIN.' : 'Used by the learner to authenticate a session.'}
                    error={firstError(errors, 'pin')}
                >
                    <TextInput type="password" value={form.pin} onChange={set('pin')} maxLength={20} autoComplete="new-password" />
                </Field>
            </form>
        </Drawer>
    );
}

const EMPTY_CLASS = { name: '', grade_level: '', academic_year: String(new Date().getFullYear()) };

function ClassDrawer({ group, onClose }) {
    const save = useSaveLearnerGroup();
    const [form, setForm] = useState(EMPTY_CLASS);
    const isEdit = Boolean(group?.id);

    useEffect(() => {
        if (group) {
            setForm({
                name: group.name ?? '',
                grade_level: group.grade_level ?? '',
                academic_year: group.academic_year ?? String(new Date().getFullYear()),
            });
            save.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [group?.id, Boolean(group)]);

    const errors = save.error instanceof ApiError ? save.error.errors : {};
    const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

    async function submit(event) {
        event.preventDefault();

        try {
            await save.mutateAsync({ id: group?.id, ...form });
            showToast(isEdit ? 'Class updated' : 'Class created', { description: `${form.name} is available for learner enrolment.` });
            onClose();
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The class could not be saved');
            }
        }
    }

    return (
        <Drawer
            open={Boolean(group)}
            onClose={onClose}
            title={isEdit ? group.name : 'Create a class'}
            subtitle="Classes group learners for filtering policy and reporting."
            width="max-w-md"
            footer={
                <>
                    <Button className="flex-1" onClick={onClose} disabled={save.isPending}>
                        Cancel
                    </Button>
                    <Button className="flex-1" type="submit" form="class-form" variant="primary" loading={save.isPending}>
                        {isEdit ? 'Save changes' : 'Create class'}
                    </Button>
                </>
            }
        >
            <form id="class-form" onSubmit={submit} className="space-y-4" noValidate>
                <Field label="Class name" required hint="For example, Grade 7 East or Form 2 North." error={firstError(errors, 'name')}>
                    <TextInput data-autofocus value={form.name} onChange={set('name')} />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Grade level" error={firstError(errors, 'grade_level')}>
                        <TextInput value={form.grade_level} onChange={set('grade_level')} placeholder="7" />
                    </Field>
                    <Field label="Academic year" error={firstError(errors, 'academic_year')}>
                        <TextInput value={form.academic_year} onChange={set('academic_year')} className="tabular-nums" />
                    </Field>
                </div>
            </form>
        </Drawer>
    );
}
