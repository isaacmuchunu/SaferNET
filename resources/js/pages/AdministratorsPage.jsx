import { useEffect, useState } from 'react';
import { CameraIcon, PencilIcon, PlusIcon, Trash2Icon, UsersRoundIcon, XIcon } from 'lucide-react';
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
import { ConfirmDialog, Modal } from '../components/Overlays';
import { StatusPill } from '../components/StatusPill';
import { UserAvatar } from '../components/UserAvatar';
import { useDebouncedValue, useListState } from '../lib/hooks';
import { useAuth } from '../lib/auth';
import { useScope } from '../lib/scope';
import { useDeleteUser, useInstitutions, useSaveUser, useUsers } from '../lib/queries';
import { ROLES, roleLabel } from '../lib/domain';
import { capabilitiesFor } from '../lib/permissions';
import { formatNumber } from '../lib/format';
import { ApiError } from '../lib/api';
import { showToast, toastError } from '../lib/toast';

export function AdministratorsPage() {
    const { user } = useAuth();
    const actorCapabilities = capabilitiesFor(user?.role);
    const scope = useScope();
    const list = useListState({ search: '', role: '' });
    const search = useDebouncedValue(list.values.search, 300);
    const [editing, setEditing] = useState(null);
    const [pendingDelete, setPendingDelete] = useState(null);

    const users = useUsers({ ...scope.params, page: list.page, search: search || undefined, role: list.values.role || undefined });
    const remove = useDeleteUser();
    const rows = users.data?.data ?? [];

    async function confirmDelete() {
        try {
            await remove.mutateAsync(pendingDelete.id);
            showToast('Officer account removed', { description: `${pendingDelete.name} can no longer sign in to SAFERNET.` });
        } catch (error) {
            toastError(error, 'The account could not be removed');
        } finally {
            setPendingDelete(null);
        }
    }

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Administrators"
                description={
                    actorCapabilities.assignableRoles.length === 1
                        ? 'Laboratory managers you have appointed, and the access each one carries.'
                        : 'Officer accounts and the scope of access each one carries.'
                }
                meta={users.data?.meta ? `${formatNumber(users.data.meta.total)} accounts` : undefined}
                actions={
                    <Button variant="primary" icon={PlusIcon} onClick={() => setEditing({})}>
                        {actorCapabilities.assignableRoles.length === 1 ? 'Appoint laboratory manager' : 'Provision officer'}
                    </Button>
                }
            />

            <Panel>
                <div className="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <SearchInput value={list.values.search} onChange={(value) => list.setValue('search', value)} placeholder="Search name or email" />
                    <FilterSelect
                        label="Office"
                        value={list.values.role}
                        onChange={(value) => list.setValue('role', value)}
                        options={Object.entries(ROLES)
                            .filter(([value]) => value !== 'service')
                            .map(([value, meta]) => [value, meta.label])}
                        className={actorCapabilities.assignableRoles.length > 1 ? '' : 'hidden'}
                    />
                    {list.isFiltered && (
                        <Button variant="ghost" size="sm" icon={XIcon} onClick={list.reset}>
                            Clear
                        </Button>
                    )}
                </div>

                <DataTable
                    query={users}
                    rows={rows}
                    minWidth="860px"
                    columns={['Officer', 'Office', 'Scope', 'Status', { key: 'actions', label: '', align: 'right' }]}
                    empty={<EmptyState icon={UsersRoundIcon} title="No officer accounts" description="Provision the officers who will administer SAFERNET." />}
                >
                    {rows.map((officer) => (
                        <Row key={officer.id} onClick={() => setEditing(officer)}>
                            <Cell>
                                <span className="flex items-center gap-3">
                                    <UserAvatar user={officer} size="md" />
                                    <span className="min-w-0">
                                        <span className="block truncate font-semibold">{officer.name}</span>
                                        <span className="mt-0.5 block truncate text-[11px] text-text-secondary">{officer.email}</span>
                                        {officer.phone && <span className="mt-0.5 block text-[10px] text-text-muted">{officer.phone}</span>}
                                    </span>
                                </span>
                            </Cell>
                            <Cell muted>{roleLabel(officer.role)}</Cell>
                            <Cell muted>{officer.institution?.name ?? officer.subcounty?.name ?? 'Kiambu County'}</Cell>
                            <Cell>
                                <StatusPill
                                    label={officer.status === 'active' ? 'Active' : 'Suspended'}
                                    tone={officer.status === 'active' ? 'success' : 'danger'}
                                />
                            </Cell>
                            <Cell align="right">
                                <div className="flex justify-end gap-1" onClick={(event) => event.stopPropagation()}>
                                    <Button size="sm" variant="ghost" icon={PencilIcon} onClick={() => setEditing(officer)}>
                                        Edit
                                    </Button>
                                    {officer.id !== user?.id && (
                                        <Button size="sm" variant="ghost" icon={Trash2Icon} onClick={() => setPendingDelete(officer)}>
                                            Remove
                                        </Button>
                                    )}
                                </div>
                            </Cell>
                        </Row>
                    ))}
                </DataTable>

                <Pagination meta={users.data?.meta} onChange={list.setPage} unit="accounts" />
            </Panel>

            <OfficerModal officer={editing} onClose={() => setEditing(null)} actor={user} />

            <ConfirmDialog
                open={Boolean(pendingDelete)}
                tone="danger"
                title="Remove this officer account?"
                description={
                    pendingDelete
                        ? `${pendingDelete.name} will immediately lose access to SAFERNET. The removal is written to the audit log.`
                        : ''
                }
                confirmLabel="Remove account"
                loading={remove.isPending}
                onCancel={() => setPendingDelete(null)}
                onConfirm={confirmDelete}
            />
        </div>
    );
}

const EMPTY = { name: '', email: '', phone: '', avatar: null, role: 'clm', status: 'active', institution_id: '' };

function OfficerModal({ officer, onClose, actor }) {
    const save = useSaveUser();
    const [form, setForm] = useState(EMPTY);
    const isEdit = Boolean(officer?.id);
    const assignable = capabilitiesFor(actor?.role).assignableRoles;
    const needsInstitution = ['hoi', 'clm'].includes(form.role) && actor?.role !== 'hoi';

    const institutions = useInstitutions({}, { enabled: needsInstitution });

    useEffect(() => {
        if (officer) {
            setForm({
                name: officer.name ?? '',
                email: officer.email ?? '',
                phone: officer.phone ?? '',
                avatar: null,
                role: officer.role ?? assignable[0] ?? 'clm',
                status: officer.status ?? 'active',
                institution_id: officer.institution?.id ? String(officer.institution.id) : '',
            });
            save.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [officer?.id, Boolean(officer)]);

    const errors = save.error instanceof ApiError ? save.error.errors : {};
    const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

    async function submit(event) {
        event.preventDefault();

        try {
            await save.mutateAsync({
                id: officer?.id,
                name: form.name,
                email: form.email,
                phone: form.phone || null,
                avatar: form.avatar,
                role: form.role,
                status: form.status,
                institution_id: needsInstitution && form.institution_id ? Number(form.institution_id) : undefined,
            });
            showToast(isEdit ? 'Officer updated' : 'Officer provisioned', {
                description: isEdit
                    ? `${form.name} holds the ${roleLabel(form.role)} office.`
                    : 'Three separate email and SMS messages are queued: welcome, username and temporary password.',
            });
            onClose();
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The officer account could not be saved');
            }
        }
    }

    return (
        <Modal
            open={Boolean(officer)}
            onClose={onClose}
            title={isEdit ? officer.name : 'Provision an officer'}
            subtitle={isEdit ? officer.email : 'SAFERNET generates and sends secure first-sign-in credentials.'}
            size="sm"
            footer={
                <>
                    <Button className="flex-1" onClick={onClose} disabled={save.isPending}>
                        Cancel
                    </Button>
                    <Button className="flex-1" type="submit" form="officer-form" variant="primary" loading={save.isPending}>
                        {isEdit ? 'Save changes' : 'Provision officer'}
                    </Button>
                </>
            }
        >
            {/* Two columns so the whole form is visible without scrolling. */}
            <form id="officer-form" onSubmit={submit} className="grid gap-x-5 gap-y-4 sm:grid-cols-2" noValidate>
                <AvatarPicker
                    officer={officer}
                    value={form.avatar}
                    onChange={(avatar) => setForm((current) => ({ ...current, avatar }))}
                    error={firstError(errors, 'avatar')}
                />
                <Field label="Full name" required error={firstError(errors, 'name')}>
                    <TextInput data-autofocus value={form.name} onChange={set('name')} />
                </Field>
                <Field label="Official email" required error={firstError(errors, 'email')}>
                    <TextInput type="email" value={form.email} onChange={set('email')} />
                </Field>
                <Field
                    label="Mobile number"
                    required={!isEdit}
                    hint={isEdit ? 'Used for MFA recovery and urgent alerts.' : 'Required for the three-part credential delivery. Kenyan local or +254 format.'}
                    error={firstError(errors, 'phone')}
                >
                    <TextInput type="tel" value={form.phone} onChange={set('phone')} placeholder="0712 345 678" />
                </Field>
                <Field label="Office" required error={firstError(errors, 'role')}>
                    <Select value={form.role} onChange={set('role')} disabled={isEdit && actor?.role !== 'cde'}>
                        {assignable.map((role) => (
                            <option key={role} value={role}>
                                {roleLabel(role)}
                            </option>
                        ))}
                    </Select>
                </Field>

                {needsInstitution && (
                    <Field label="Institution" required error={firstError(errors, 'institution_id')}>
                        <Select value={form.institution_id} onChange={set('institution_id')}>
                            <option value="">Select an institution</option>
                            {(institutions.data?.data ?? []).map((institution) => (
                                <option key={institution.id} value={institution.id}>
                                    {institution.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                )}

                <Field label="Account status" error={firstError(errors, 'status')}>
                    <Select value={form.status} onChange={set('status')}>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                    </Select>
                </Field>
                {!isEdit && (
                    <p className="rounded-xl border border-brand/15 bg-brand-soft/50 px-3 py-2.5 text-[11px] leading-4 text-text-secondary sm:col-span-2">
                        The officer will receive three separate emails and three SMS messages. Their temporary password expires automatically; first access is blocked until they set a private password and authenticator MFA.
                    </p>
                )}
            </form>
        </Modal>
    );
}

function AvatarPicker({ officer, value, onChange, error }) {
    const [preview, setPreview] = useState(officer?.avatar_url ?? null);

    useEffect(() => {
        if (!value) {
            setPreview(officer?.avatar_url ?? null);
            return undefined;
        }

        const objectUrl = URL.createObjectURL(value);
        setPreview(objectUrl);

        return () => URL.revokeObjectURL(objectUrl);
    }, [value, officer?.avatar_url]);

    return (
        <Field label="Profile image" hint="JPG, PNG or WebP. Maximum 2 MB." error={error}>
            <div className="flex items-center gap-4 rounded-xl border border-border bg-surface-muted/35 p-3">
                <UserAvatar user={{ ...officer, avatar_url: preview }} size="xl" />
                <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-border bg-white px-3 py-2 text-xs font-semibold text-text-secondary shadow-sm transition hover:border-brand/30 hover:text-brand">
                    <CameraIcon size={15} />
                    {preview ? 'Replace image' : 'Upload image'}
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="sr-only"
                        onChange={(event) => onChange(event.target.files?.[0] ?? null)}
                    />
                </label>
            </div>
        </Field>
    );
}
