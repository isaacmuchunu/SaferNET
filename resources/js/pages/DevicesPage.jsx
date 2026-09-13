import { useEffect, useState } from 'react';
import {
    CheckIcon,
    MinusIcon,
    MonitorIcon,
    PencilIcon,
    PlayIcon,
    PlusIcon,
    SquarePowerIcon,
    Trash2Icon,
    UserPlusIcon,
    XIcon,
} from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import {
    Button,
    Cell,
    DataTable,
    DetailRow,
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
import { Link, useSearchParams } from 'react-router-dom';
import { ConfirmDialog, Drawer } from '../components/Overlays';
import { BrowsingHistory } from '../components/BrowsingHistory';
import { StatusPill } from '../components/StatusPill';
import { useDebouncedValue, useListState } from '../lib/hooks';
import { useAuth } from '../lib/auth';
import { useScope } from '../lib/scope';
import {
    useAssignLearner,
    useDeleteDevice,
    useDeviceGroups,
    useDevices,
    useEndSession,
    useLaboratories,
    useLearners,
    useSaveDevice,
    useStartSession,
    useUnassignLearner,
} from '../lib/queries';
import { DEVICE_STATUS } from '../lib/domain';
import { capabilitiesFor } from '../lib/permissions';
import { formatDate, formatNumber, formatRelative } from '../lib/format';
import { ApiError } from '../lib/api';
import { showToast, toastError } from '../lib/toast';

const MAX_LEARNERS_PER_DEVICE = 2;

const PLATFORMS = [
    ['windows', 'Windows'],
    ['linux', 'Linux'],
    ['chromeos', 'ChromeOS'],
    ['macos', 'macOS'],
    ['android', 'Android'],
    ['ios', 'iOS'],
];

const USAGE_TYPES = [
    ['learner', 'Learner use'],
    ['staff', 'Staff use'],
    ['shared', 'Shared terminal'],
];

export function DevicesPage() {
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const scope = useScope();
    const list = useListState({ search: '', status: '', laboratory: '', attribution: '' });
    const search = useDebouncedValue(list.values.search, 300);

    const [selected, setSelected] = useState(null);
    const [searchParams, setSearchParams] = useSearchParams();
    const [editing, setEditing] = useState(null);

    const devices = useDevices({
        ...scope.params,
        page: list.page,
        search: search || undefined,
        status: list.values.status || undefined,
        laboratory_id: list.values.laboratory || undefined,
    });
    const laboratories = useLaboratories(scope.params);

    const all = devices.data?.data ?? [];
    // The API has no unattributed filter, so this narrows the current page only.
    const rows = list.values.attribution === 'required' ? all.filter((device) => (device.assigned_learners?.length ?? 0) === 0) : all;
    // A school page links here with ?device=<id>; open that record once it is
    // available, then clear the parameter so a refresh is not sticky.
    const requestedDevice = searchParams.get('device');
    useEffect(() => {
        if (!requestedDevice) return;

        const match = rows.find((device) => String(device.id) === requestedDevice);
        if (!match) return;

        setSelected(match);
        searchParams.delete('device');
        setSearchParams(searchParams, { replace: true });
    }, [requestedDevice, rows, searchParams, setSearchParams]);

    const selectedDevice = selected ? (all.find((device) => device.id === selected.id) ?? selected) : null;

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Device Management"
                description={
                    can.registerDevices
                        ? 'Enrol, name and maintain every managed computer, and keep learner attribution correct.'
                        : 'Protection status, learner attribution and endpoint health for every managed device.'
                }
                meta={devices.data?.meta ? `${formatNumber(devices.data.meta.total)} devices` : undefined}
                actions={
                    can.registerDevices && (
                        <Button variant="primary" icon={PlusIcon} onClick={() => setEditing({})}>
                            Register device
                        </Button>
                    )
                }
            />

            <Panel>
                <div className="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <SearchInput
                        value={list.values.search}
                        onChange={(value) => list.setValue('search', value)}
                        placeholder="Search asset tag, hostname or serial"
                    />
                    <FilterSelect
                        label="Protection status"
                        value={list.values.status}
                        onChange={(value) => list.setValue('status', value)}
                        options={Object.entries(DEVICE_STATUS).map(([value, meta]) => [value, meta.label])}
                    />
                    <FilterSelect
                        label="Laboratory"
                        value={list.values.laboratory}
                        onChange={(value) => list.setValue('laboratory', value)}
                        options={(laboratories.data?.data ?? []).map((laboratory) => [String(laboratory.id), laboratory.name])}
                    />
                    <FilterSelect
                        label="Attribution"
                        value={list.values.attribution}
                        onChange={(value) => list.setValue('attribution', value)}
                        options={[['required', 'Needs attribution']]}
                    />
                    {list.isFiltered && (
                        <Button variant="ghost" size="sm" icon={XIcon} onClick={list.reset}>
                            Clear
                        </Button>
                    )}
                </div>

                <DataTable
                    query={devices}
                    rows={rows}
                    minWidth="1020px"
                    columns={['Device', 'Hostname', 'Assigned learners', 'Active session', 'Capacity', 'Use', 'Last seen', 'Status']}
                    empty={
                        <EmptyState
                            icon={MonitorIcon}
                            title={list.isFiltered ? 'No devices match these filters' : 'No devices enrolled'}
                            description={
                                list.isFiltered
                                    ? 'Adjust the search terms or clear a filter to see more results.'
                                    : can.registerDevices
                                      ? 'Register a computer to bring it under SAFERNET protection.'
                                      : 'The laboratory manager enrols computers into SAFERNET.'
                            }
                            action={
                                list.isFiltered ? (
                                    <Button size="sm" onClick={list.reset}>
                                        Clear filters
                                    </Button>
                                ) : can.registerDevices ? (
                                    <Button size="sm" variant="primary" icon={PlusIcon} onClick={() => setEditing({})}>
                                        Register device
                                    </Button>
                                ) : null
                            }
                        />
                    }
                >
                    {rows.map((device) => (
                        <Row key={device.id} onClick={() => setSelected(device)}>
                            <Cell mono bold>
                                {device.asset_tag}
                            </Cell>
                            <Cell mono muted>
                                {device.hostname ?? '—'}
                            </Cell>
                            <Cell>
                                {device.assigned_learners?.length ? (
                                    device.assigned_learners.map((entry) => entry.name).join(' / ')
                                ) : (
                                    <span className="text-text-muted">Unassigned</span>
                                )}
                            </Cell>
                            <Cell>
                                {device.active_session ? (
                                    <span className="inline-flex items-center gap-1.5 text-success">
                                        <span aria-hidden className="h-1.5 w-1.5 rounded-full bg-success" />
                                        {device.active_session.learner}
                                    </span>
                                ) : (
                                    <span className="text-text-muted">None</span>
                                )}
                            </Cell>
                            <Cell className="tabular-nums">
                                {device.assignment_capacity ? `${device.assignment_capacity.used}/${device.assignment_capacity.maximum}` : '—'}
                            </Cell>
                            <Cell muted>{USAGE_TYPES.find(([value]) => value === device.usage_type)?.[1] ?? device.usage_type}</Cell>
                            <Cell muted>{formatRelative(device.last_seen_at)}</Cell>
                            <Cell>
                                <StatusPill descriptor={DEVICE_STATUS[device.status]} />
                            </Cell>
                        </Row>
                    ))}
                </DataTable>

                <Pagination meta={devices.data?.meta} onChange={list.setPage} unit="devices" />
            </Panel>

            <DeviceDrawer
                device={selectedDevice}
                capabilities={can}
                onClose={() => setSelected(null)}
                onEdit={(device) => {
                    setSelected(null);
                    setEditing(device);
                }}
            />

            <DeviceFormDrawer device={editing} onClose={() => setEditing(null)} />
        </div>
    );
}

/* ------------------------------------------------------- enrolment form ---- */

const EMPTY_DEVICE = {
    asset_tag: '',
    serial_number: '',
    hostname: '',
    laboratory_id: '',
    device_group_id: '',
    platform: 'windows',
    usage_type: 'learner',
    status: 'active',
};

function DeviceFormDrawer({ device, onClose }) {
    const save = useSaveDevice();
    const remove = useDeleteDevice();
    const laboratories = useLaboratories();
    const deviceGroups = useDeviceGroups();

    const [form, setForm] = useState(EMPTY_DEVICE);
    const [decommissioning, setDecommissioning] = useState(false);
    const isEdit = Boolean(device?.id);

    useEffect(() => {
        if (device) {
            setForm({
                asset_tag: device.asset_tag ?? '',
                serial_number: device.serial_number ?? '',
                hostname: device.hostname ?? '',
                laboratory_id: device.laboratory_id ? String(device.laboratory_id) : '',
                device_group_id: device.device_group_id ? String(device.device_group_id) : '',
                platform: device.platform ?? 'windows',
                usage_type: device.usage_type ?? 'learner',
                status: device.status ?? 'active',
            });
            save.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [device?.id, Boolean(device)]);

    const errors = save.error instanceof ApiError ? save.error.errors : {};
    const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

    async function submit(event) {
        event.preventDefault();

        try {
            await save.mutateAsync({
                id: device?.id,
                ...form,
                laboratory_id: form.laboratory_id ? Number(form.laboratory_id) : null,
                device_group_id: form.device_group_id ? Number(form.device_group_id) : null,
                serial_number: form.serial_number || null,
                hostname: form.hostname || null,
            });
            showToast(isEdit ? 'Device updated' : 'Device enrolled', {
                description: `${form.asset_tag} is on the school's device register.`,
            });
            onClose();
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The device could not be saved');
            }
        }
    }

    async function decommission() {
        try {
            await remove.mutateAsync(device.id);
            showToast('Device decommissioned', { description: `${device.asset_tag} was removed from the register.` });
            setDecommissioning(false);
            onClose();
        } catch (error) {
            setDecommissioning(false);
            toastError(error, 'The device could not be decommissioned');
        }
    }

    return (
        <>
            <Drawer
                open={Boolean(device)}
                onClose={onClose}
                title={isEdit ? device.asset_tag : 'Register a device'}
                subtitle={isEdit ? 'Update the enrolment record for this computer.' : 'Enrol a computer before a learner uses it.'}
                width="max-w-lg"
                footer={
                    <>
                        {isEdit && (
                            <Button variant="dangerGhost" icon={Trash2Icon} onClick={() => setDecommissioning(true)}>
                                Decommission
                            </Button>
                        )}
                        <Button className="ml-auto" onClick={onClose} disabled={save.isPending}>
                            Cancel
                        </Button>
                        <Button type="submit" form="device-form" variant="primary" loading={save.isPending}>
                            {isEdit ? 'Save changes' : 'Enrol device'}
                        </Button>
                    </>
                }
            >
                <form id="device-form" onSubmit={submit} className="space-y-4" noValidate>
                    <Field label="Asset tag" required hint="The label physically fixed to the computer." error={firstError(errors, 'asset_tag')}>
                        <TextInput data-autofocus value={form.asset_tag} onChange={set('asset_tag')} className="font-mono" placeholder="ICTLAB-PC001" />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Hostname" error={firstError(errors, 'hostname')}>
                            <TextInput value={form.hostname} onChange={set('hostname')} className="font-mono" />
                        </Field>
                        <Field label="Serial number" error={firstError(errors, 'serial_number')}>
                            <TextInput value={form.serial_number} onChange={set('serial_number')} className="font-mono" />
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Laboratory" error={firstError(errors, 'laboratory_id')}>
                            <Select value={form.laboratory_id} onChange={set('laboratory_id')}>
                                <option value="">Unassigned</option>
                                {(laboratories.data?.data ?? []).map((laboratory) => (
                                    <option key={laboratory.id} value={laboratory.id}>
                                        {laboratory.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Device group" error={firstError(errors, 'device_group_id')}>
                            <Select value={form.device_group_id} onChange={set('device_group_id')}>
                                <option value="">Unassigned</option>
                                {(deviceGroups.data?.data ?? []).map((group) => (
                                    <option key={group.id} value={group.id}>
                                        {group.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field label="Platform" error={firstError(errors, 'platform')}>
                            <Select value={form.platform} onChange={set('platform')}>
                                {PLATFORMS.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Use" hint="Learner-use devices require attribution." error={firstError(errors, 'usage_type')}>
                            <Select value={form.usage_type} onChange={set('usage_type')}>
                                {USAGE_TYPES.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Status" error={firstError(errors, 'status')}>
                            <Select value={form.status} onChange={set('status')}>
                                {Object.entries(DEVICE_STATUS).map(([value, meta]) => (
                                    <option key={value} value={value}>
                                        {meta.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>

                    <p className="rounded-lg bg-info-soft p-3 text-[11px] leading-4 text-info">
                        Enrolling a device records it on the school register. The endpoint agent, browser extension and gateway
                        must also be installed before a learner uses the computer.
                    </p>
                </form>
            </Drawer>

            <ConfirmDialog
                open={decommissioning}
                tone="danger"
                title="Decommission this device?"
                description={
                    device
                        ? `${device.asset_tag} will be removed from the register along with its learner attribution. This is written to the audit log.`
                        : ''
                }
                confirmLabel="Decommission device"
                loading={remove.isPending}
                onCancel={() => setDecommissioning(false)}
                onConfirm={decommission}
            />
        </>
    );
}

/* ------------------------------------------------ attribution & sessions ---- */

function DeviceDrawer({ device, capabilities, onClose, onEdit }) {
    const assign = useAssignLearner();
    const unassign = useUnassignLearner();
    const startSession = useStartSession();
    const endSession = useEndSession();

    const [assigning, setAssigning] = useState(false);
    const [learnerId, setLearnerId] = useState('');
    const [pendingRemoval, setPendingRemoval] = useState(null);
    const [sessionFor, setSessionFor] = useState(null);
    const [identitySource, setIdentitySource] = useState('school_pin');
    const [pin, setPin] = useState('');
    const [endingSession, setEndingSession] = useState(false);

    const learners = useLearners({ status: 'active' }, { enabled: assigning });
    const assigned = device?.assigned_learners ?? [];
    const atCapacity = assigned.length >= MAX_LEARNERS_PER_DEVICE;
    const canAssign = capabilities?.assignLearners;

    async function submitAssignment(event) {
        event.preventDefault();

        try {
            await assign.mutateAsync({ deviceId: device.id, learnerId: Number(learnerId) });
            showToast('Learner attributed to device', { description: `${device.asset_tag} now carries an accountable learner.` });
            setAssigning(false);
            setLearnerId('');
        } catch (error) {
            toastError(error, 'The learner could not be assigned');
        }
    }

    async function confirmRemoval() {
        try {
            await unassign.mutateAsync({ deviceId: device.id, assignmentId: pendingRemoval.assignment_id });
            showToast('Attribution removed', { description: 'The change was written to the county audit log.' });
        } catch (error) {
            toastError(error, 'The attribution could not be removed');
        } finally {
            setPendingRemoval(null);
        }
    }

    async function submitSession(event) {
        event.preventDefault();

        try {
            await startSession.mutateAsync({
                deviceId: device.id,
                learnerId: sessionFor.learner_id,
                identitySource,
                pin: identitySource === 'school_pin' ? pin : undefined,
            });
            showToast(`${sessionFor.name} signed in`, { description: `Active learner session opened on ${device.asset_tag}.` });
            setSessionFor(null);
            setPin('');
        } catch (error) {
            const message = error instanceof ApiError && error.isValidation ? Object.values(error.errors)[0]?.[0] : undefined;
            toastError(error, message ?? 'The session could not be started');
        }
    }

    async function closeSession() {
        try {
            await endSession.mutateAsync(device.active_session.id);
            showToast('Session ended', { description: `${device.active_session.learner} was signed out of ${device.asset_tag}.` });
        } catch (error) {
            toastError(error, 'The session could not be ended');
        } finally {
            setEndingSession(false);
        }
    }

    return (
        <>
            <Drawer
                open={Boolean(device)}
                onClose={onClose}
                title={device?.asset_tag ?? ''}
                subtitle={device ? `${device.platform} · ${device.usage_type} device` : undefined}
                footer={
                    <>
                        {capabilities?.registerDevices && (
                            <Button icon={PencilIcon} onClick={() => onEdit(device)}>
                                Edit enrolment
                            </Button>
                        )}
                        <Button className="ml-auto" onClick={onClose}>
                            Close
                        </Button>
                    </>
                }
            >
                {device && (
                    <div className="space-y-5">
                        <div>
                            <h3 className="text-xs font-semibold tracking-wider text-text-muted uppercase">Protection status</h3>
                            <div className="mt-2">
                                <StatusPill descriptor={DEVICE_STATUS[device.status]} />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <DetailRow label="Hostname">{device.hostname}</DetailRow>
                            <DetailRow label="Serial number">{device.serial_number}</DetailRow>
                            <DetailRow label="Last seen">{formatDate(device.last_seen_at, { withTime: true })}</DetailRow>
                            <DetailRow label="Attribution capacity">
                                {device.assignment_capacity
                                    ? `${device.assignment_capacity.used} of ${device.assignment_capacity.maximum} learners`
                                    : '—'}
                            </DetailRow>
                        </div>

                        <div>
                            <h3 className="text-xs font-semibold tracking-wider text-text-muted uppercase">Active session</h3>
                            {device.active_session ? (
                                <div className="mt-2 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-success/30 bg-success-soft px-3 py-2.5">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold">{device.active_session.learner}</p>
                                        <p className="truncate text-[11px] text-text-secondary">
                                            {device.active_session.identity_source.replaceAll('_', ' ')} · opened{' '}
                                            {formatRelative(device.active_session.started_at)}
                                        </p>
                                    </div>
                                    {capabilities?.manageSessions && (
                                        <Button size="sm" icon={SquarePowerIcon} onClick={() => setEndingSession(true)}>
                                            End session
                                        </Button>
                                    )}
                                </div>
                            ) : (
                                <p className="mt-2 rounded-lg border border-dashed border-border px-3 py-3 text-xs text-text-secondary">
                                    No learner is signed in on this device.
                                </p>
                            )}
                        </div>

                        <div>
                            <div className="flex items-center justify-between">
                                <h3 className="text-xs font-semibold tracking-wider text-text-muted uppercase">Assigned learners</h3>
                                <span className="text-[11px] text-text-muted tabular-nums">
                                    {assigned.length}/{MAX_LEARNERS_PER_DEVICE} max
                                </span>
                            </div>

                            <div className="mt-2 space-y-2">
                                {assigned.length === 0 && (
                                    <p className="rounded-lg border border-dashed border-border px-3 py-3 text-xs text-text-secondary">
                                        No learner is attributed to this device yet.
                                    </p>
                                )}

                                {assigned.map((entry) => (
                                    <div key={entry.assignment_id} className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold">{entry.name}</p>
                                            <p className="truncate text-[11px] text-text-secondary">
                                                {entry.learner_number}
                                                {entry.group ? ` · ${entry.group}` : ''} · assigned {formatRelative(entry.assigned_at)}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1">
                                            <Link
                                                to={`/learners?learner=${entry.learner_id}`}
                                                className="rounded-md px-1.5 py-1 text-[11px] font-semibold text-brand hover:bg-brand-soft focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
                                            >
                                                View learner
                                            </Link>
                                        </div>
                                        {canAssign && (
                                            <div className="flex shrink-0 items-center gap-1">
                                                {capabilities?.manageSessions && !device.active_session && (
                                                    <button
                                                        onClick={() => setSessionFor(entry)}
                                                        className="flex items-center gap-1 rounded-md px-1.5 py-1 text-[11px] font-semibold text-brand hover:bg-brand-soft"
                                                    >
                                                        <PlayIcon size={12} /> Start session
                                                    </button>
                                                )}
                                                <button
                                                    onClick={() => setPendingRemoval(entry)}
                                                    aria-label={`Remove ${entry.name}`}
                                                    className="rounded-md p-1 text-text-muted hover:bg-danger-soft hover:text-danger"
                                                >
                                                    <MinusIcon size={14} />
                                                </button>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>

                            {canAssign && !assigning && (
                                <Button className="mt-3 w-full border-dashed" icon={UserPlusIcon} disabled={atCapacity} onClick={() => setAssigning(true)}>
                                    {atCapacity ? 'Maximum learners assigned' : 'Assign learner'}
                                </Button>
                            )}

                            {assigning && (
                                <form onSubmit={submitAssignment} className="mt-3 space-y-3 rounded-lg border border-border p-3">
                                    <Field label="Learner" required>
                                        <Select data-autofocus value={learnerId} onChange={(event) => setLearnerId(event.target.value)}>
                                            <option value="">Select a learner</option>
                                            {(learners.data?.data ?? []).map((learner) => (
                                                <option key={learner.id} value={learner.id}>
                                                    {learner.first_name} {learner.last_name} · {learner.learner_number}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <div className="flex gap-2">
                                        <Button className="flex-1" type="button" onClick={() => setAssigning(false)}>
                                            Cancel
                                        </Button>
                                        <Button className="flex-1" type="submit" variant="primary" loading={assign.isPending} disabled={!learnerId}>
                                            Assign
                                        </Button>
                                    </div>
                                </form>
                            )}

                            <p className="mt-2 text-[11px] leading-4 text-text-secondary">
                                A learner-use device may be attributed to at most two learners. Only the authenticated active
                                learner is treated as the user of a session, so sharing a device never attributes an incident to
                                both learners.
                            </p>
                        </div>

                        {sessionFor && (
                            <form onSubmit={submitSession} className="space-y-3 rounded-lg border border-brand/30 bg-brand-soft/40 p-3">
                                <p className="text-xs font-semibold">Start a session for {sessionFor.name}</p>
                                <Field label="Identity source" required>
                                    <Select value={identitySource} onChange={(event) => setIdentitySource(event.target.value)}>
                                        <option value="school_pin">School PIN</option>
                                        <option value="google_workspace">Google Workspace</option>
                                        <option value="microsoft">Microsoft</option>
                                        <option value="external">External identity</option>
                                    </Select>
                                </Field>
                                {identitySource === 'school_pin' && (
                                    <Field label="Learner PIN" required>
                                        <TextInput type="password" value={pin} onChange={(event) => setPin(event.target.value)} maxLength={20} />
                                    </Field>
                                )}
                                <div className="flex gap-2">
                                    <Button className="flex-1" type="button" onClick={() => setSessionFor(null)}>
                                        Cancel
                                    </Button>
                                    <Button className="flex-1" type="submit" variant="primary" icon={CheckIcon} loading={startSession.isPending}>
                                        Open session
                                    </Button>
                                </div>
                            </form>
                        )}
                    </div>
                )}

                {device && (
                    <section className="mt-5">
                        <h3 className="text-xs font-semibold text-text-secondary">Browsing recorded on this device</h3>
                        <p className="mt-0.5 text-[11px] text-text-muted">
                            Every learner who has used this workstation. Open a learner to see only theirs.
                        </p>
                        <div className="mt-2">
                            <BrowsingHistory
                                deviceId={device.id}
                                emptyHint="No browsing has been reported from this device yet."
                            />
                        </div>
                    </section>
                )}
            </Drawer>

            <ConfirmDialog
                open={Boolean(pendingRemoval)}
                tone="danger"
                title="Remove learner attribution?"
                description={
                    pendingRemoval
                        ? `${pendingRemoval.name} will no longer be attributed to ${device?.asset_tag}. This change is recorded in the audit log.`
                        : ''
                }
                confirmLabel="Remove learner"
                loading={unassign.isPending}
                onCancel={() => setPendingRemoval(null)}
                onConfirm={confirmRemoval}
            />

            <ConfirmDialog
                open={endingSession}
                title="End this learner session?"
                description={
                    device?.active_session
                        ? `${device.active_session.learner} will be signed out of ${device.asset_tag}. Activity after this point is no longer attributed to them.`
                        : ''
                }
                confirmLabel="End session"
                loading={endSession.isPending}
                onCancel={() => setEndingSession(false)}
                onConfirm={closeSession}
            />
        </>
    );
}
