import { useEffect, useState } from 'react';

import {
    FlaskConicalIcon,
    Layers3Icon,
    MapPinIcon,
    MonitorIcon,
    PencilIcon,
    PlusIcon,
    ShieldCheckIcon,
} from 'lucide-react';

import {
    Button,
    Cell,
    DataTable,
    EmptyState,
    Field,
    Pagination,
    Row,
    TextInput,
    firstError,
} from '../components/Primitives';

import { Modal } from '../components/Overlays';

import { useListState } from '../lib/hooks';
import { useAuth } from '../lib/auth';
import { useScope } from '../lib/scope';

import {
    useDeviceGroups,
    useLaboratories,
    useSaveDeviceGroup,
    useSaveLaboratory,
} from '../lib/queries';

import { capabilitiesFor } from '../lib/permissions';
import { formatNumber } from '../lib/format';
import { ApiError } from '../lib/api';
import { showToast, toastError } from '../lib/toast';

export function LaboratoriesPage() {
    const { user } = useAuth();
    const scope = useScope();
    const list = useListState();

    const [editing, setEditing] = useState(null);
    const [editingGroup, setEditingGroup] = useState(null);

    const laboratories = useLaboratories({
        ...scope.params,
        page: list.page,
    });

    const deviceGroups = useDeviceGroups(scope.params);

    const labs = laboratories.data?.data ?? [];
    const groups = deviceGroups.data?.data ?? [];

    const manageable = capabilitiesFor(user?.role).manageLaboratories;

    const laboratoryTotal = laboratories.data?.meta?.total;

    const visibleDeviceCount = labs.reduce(
        (total, laboratory) =>
            total + Number(laboratory.devices_count ?? 0),
        0,
    );

    return (
        <div className="animate-fade-up space-y-6 pb-10">

            {/* ---------------------------------------------------------
                HERO
            --------------------------------------------------------- */}
            <section className="relative overflow-hidden rounded-[22px] bg-[#173F43] px-6 py-7 text-white shadow-[0_12px_35px_-18px_rgba(15,23,42,0.45)] md:px-8 md:py-8">

                {/* quiet decorative geometry */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute -right-20 -top-28 h-72 w-72 rounded-full border border-white/[0.08]"
                />
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute -right-6 -top-14 h-48 w-48 rounded-full border border-white/[0.06]"
                />

                <div className="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                    <div className="max-w-3xl">

                        <div className="mb-4 flex items-center gap-2">
                            <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-white/10">
                                <ShieldCheckIcon
                                    className="h-4 w-4 text-[#A8DDD4]"
                                    strokeWidth={2}
                                />
                            </div>

                            <span className="text-[11px] font-semibold uppercase tracking-[0.16em] text-[#A8DDD4]">
                                Device infrastructure
                            </span>
                        </div>

                        <h1 className="max-w-2xl text-2xl font-semibold tracking-[-0.035em] text-white sm:text-[30px] sm:leading-[1.15]">
                            Laboratories &amp; device groups
                        </h1>

                        <p className="mt-3 max-w-2xl text-sm leading-6 text-[#C6DAD8]">
                            {manageable
                                ? 'Organize where managed devices are deployed and group them for consistent policy, monitoring, and reporting.'
                                : 'View the laboratories and device groups maintained for this school.'}
                        </p>
                    </div>

                    {manageable && (
                        <button
                            type="button"
                            onClick={() => setEditing({})}
                            className="
                                inline-flex h-10 shrink-0 items-center justify-center
                                gap-2 rounded-xl bg-white px-4
                                text-sm font-semibold text-[#173F43]
                                shadow-[0_1px_2px_rgba(0,0,0,0.08)]
                                ring-1 ring-black/[0.04]
                                transition-[transform,box-shadow,background-color]
                                duration-150 ease-out
                                hover:-translate-y-px hover:bg-[#F7FBFA]
                                hover:shadow-[0_5px_16px_rgba(0,0,0,0.12)]
                                active:translate-y-0
                                focus-visible:outline-none
                                focus-visible:ring-2
                                focus-visible:ring-white
                                focus-visible:ring-offset-2
                                focus-visible:ring-offset-[#173F43]
                            "
                        >
                            <PlusIcon className="h-4 w-4" strokeWidth={2.2} />
                            Add laboratory
                        </button>
                    )}
                </div>
            </section>

            {/* ---------------------------------------------------------
                METRICS
            --------------------------------------------------------- */}
            <div className="grid gap-3 sm:grid-cols-3">

                <MetricCard
                    icon={FlaskConicalIcon}
                    label="Laboratories"
                    value={
                        laboratoryTotal == null
                            ? '—'
                            : formatNumber(laboratoryTotal)
                    }
                    description="Registered computer rooms"
                />

                <MetricCard
                    icon={MonitorIcon}
                    label="Devices shown"
                    value={formatNumber(visibleDeviceCount)}
                    description="Across laboratories on this page"
                />

                <MetricCard
                    icon={Layers3Icon}
                    label="Device groups"
                    value={
                        deviceGroups.isLoading
                            ? '—'
                            : formatNumber(groups.length)
                    }
                    description="Policy and reporting groups"
                />

            </div>

            {/* ---------------------------------------------------------
                MAIN CONTENT
            --------------------------------------------------------- */}
            <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1.55fr)_minmax(360px,0.85fr)]">

                {/* LABORATORIES */}
                <section className="overflow-hidden rounded-[20px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03),0_8px_30px_rgba(15,23,42,0.035)]">

                    <div className="flex flex-col gap-4 border-b border-slate-100 px-5 py-5 sm:flex-row sm:items-center sm:justify-between">

                        <div className="flex items-start gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#EAF4F2] text-[#17675F]">
                                <FlaskConicalIcon
                                    className="h-[19px] w-[19px]"
                                    strokeWidth={2}
                                />
                            </div>

                            <div>
                                <h2 className="text-[15px] font-semibold tracking-[-0.015em] text-slate-900">
                                    Computer laboratories
                                </h2>

                                <p className="mt-1 text-[13px] leading-5 text-slate-500">
                                    Physical rooms where managed learner devices are deployed.
                                </p>
                            </div>
                        </div>

                        {laboratoryTotal != null && (
                            <div className="hidden rounded-lg bg-slate-50 px-2.5 py-1.5 text-xs font-medium tabular-nums text-slate-500 sm:block">
                                {formatNumber(laboratoryTotal)} total
                            </div>
                        )}
                    </div>

                    <DataTable
                        query={laboratories}
                        rows={labs}
                        minWidth="620px"
                        columns={[
                            'Laboratory',
                            'Location',
                            {
                                key: 'devices',
                                label: 'Devices',
                                align: 'right',
                            },
                            {
                                key: 'actions',
                                label: '',
                                align: 'right',
                            },
                        ]}
                        empty={
                            <EmptyState
                                icon={FlaskConicalIcon}
                                title="No laboratories recorded"
                                description={
                                    manageable
                                        ? 'Add your first computer laboratory before enrolling devices.'
                                        : 'No laboratories have been registered for this school.'
                                }
                            />
                        }
                    >
                        {labs.map((laboratory) => (
                            <Row key={laboratory.id} onClick={() => setEditing(laboratory)}>

                                <Cell>
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-50 text-slate-500 ring-1 ring-inset ring-slate-200/70">
                                            <MonitorIcon
                                                className="h-4 w-4"
                                                strokeWidth={2}
                                            />
                                        </div>

                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-semibold text-slate-900">
                                                {laboratory.name}
                                            </div>

                                            <div className="mt-0.5 text-[11px] font-medium uppercase tracking-[0.08em] text-slate-400">
                                                Laboratory
                                            </div>
                                        </div>
                                    </div>
                                </Cell>

                                <Cell>
                                    <div className="flex items-center gap-1.5 text-sm text-slate-500">
                                        <MapPinIcon
                                            className="h-3.5 w-3.5 shrink-0 text-slate-400"
                                            strokeWidth={2}
                                        />

                                        <span className="truncate">
                                            {laboratory.location || 'Location not set'}
                                        </span>
                                    </div>
                                </Cell>

                                <Cell align="right">
                                    <span className="inline-flex min-w-[44px] items-center justify-center rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold tabular-nums text-slate-700">
                                        {formatNumber(
                                            laboratory.devices_count ?? 0,
                                        )}
                                    </span>
                                </Cell>

                                <Cell align="right">
                                    {manageable && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            icon={PencilIcon}
                                            onClick={() =>
                                                setEditing(laboratory)
                                            }
                                        >
                                            Edit
                                        </Button>
                                    )}
                                </Cell>

                            </Row>
                        ))}
                    </DataTable>

                    <div className="border-t border-slate-100">
                        <Pagination
                            meta={laboratories.data?.meta}
                            onChange={list.setPage}
                            unit="laboratories"
                        />
                    </div>

                </section>

                {/* DEVICE GROUPS */}
                <section className="overflow-hidden rounded-[20px] border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.03),0_8px_30px_rgba(15,23,42,0.035)]">

                    <div className="border-b border-slate-100 px-5 py-5">

                        <div className="flex items-start justify-between gap-4">

                            <div className="flex items-start gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#F0F4F4] text-[#315B59]">
                                    <Layers3Icon
                                        className="h-[19px] w-[19px]"
                                        strokeWidth={2}
                                    />
                                </div>

                                <div>
                                    <h2 className="text-[15px] font-semibold tracking-[-0.015em] text-slate-900">
                                        Device groups
                                    </h2>

                                    <p className="mt-1 max-w-xs text-[13px] leading-5 text-slate-500">
                                        Apply the same policy and reporting rules to related devices.
                                    </p>
                                </div>
                            </div>

                            {manageable && (
                                <Button
                                    size="sm"
                                    icon={PlusIcon}
                                    onClick={() => setEditingGroup({})}
                                >
                                    Add group
                                </Button>
                            )}

                        </div>
                    </div>

                    <DataTable
                        query={deviceGroups}
                        rows={groups}
                        minWidth="420px"
                        columns={[
                            'Group',
                            {
                                key: 'devices',
                                label: 'Devices',
                                align: 'right',
                            },
                            {
                                key: 'actions',
                                label: '',
                                align: 'right',
                            },
                        ]}
                        empty={
                            <EmptyState
                                icon={Layers3Icon}
                                title="No device groups"
                                description={
                                    manageable
                                        ? 'Create a group to manage related devices consistently.'
                                        : 'No device groups have been configured.'
                                }
                            />
                        }
                    >
                        {groups.map((group) => (
                            <Row key={group.id}>

                                <Cell>
                                    <div className="flex items-center gap-3">

                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#EAF4F2] text-[#17675F]">
                                            <Layers3Icon
                                                className="h-4 w-4"
                                                strokeWidth={2}
                                            />
                                        </div>

                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-semibold text-slate-900">
                                                {group.name}
                                            </div>

                                            <div className="mt-1 truncate text-xs text-slate-500">
                                                {group.purpose ||
                                                    'No purpose specified'}
                                            </div>
                                        </div>

                                    </div>
                                </Cell>

                                <Cell align="right">
                                    <span className="inline-flex min-w-[44px] items-center justify-center rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold tabular-nums text-slate-700">
                                        {formatNumber(
                                            group.devices_count ?? 0,
                                        )}
                                    </span>
                                </Cell>

                                <Cell align="right">
                                    {manageable && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            icon={PencilIcon}
                                            onClick={() =>
                                                setEditingGroup(group)
                                            }
                                        >
                                            Edit
                                        </Button>
                                    )}
                                </Cell>

                            </Row>
                        ))}
                    </DataTable>

                    {/* explanatory footer */}
                    <div className="border-t border-slate-100 bg-[#FAFCFC] px-5 py-4">
                        <div className="flex gap-3">
                            <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-[#EAF4F2] text-[#17675F]">
                                <ShieldCheckIcon
                                    className="h-3.5 w-3.5"
                                    strokeWidth={2}
                                />
                            </div>

                            <div>
                                <p className="text-xs font-semibold text-slate-700">
                                    Policy follows the group
                                </p>

                                <p className="mt-1 text-xs leading-5 text-slate-500">
                                    Use groups such as learner workstations,
                                    staff devices, or shared machines to keep
                                    policy assignment predictable.
                                </p>
                            </div>
                        </div>
                    </div>

                </section>

            </div>

            {/* ---------------------------------------------------------
                DRAWERS
            --------------------------------------------------------- */}
            <RecordDrawer
                record={editing}
                onClose={() => setEditing(null)}
                title="laboratory"
                mutation={useSaveLaboratory}
                fields={[
                    {
                        key: 'name',
                        label: 'Laboratory name',
                        required: true,
                        placeholder: 'e.g. Computer Laboratory 1',
                    },
                    {
                        key: 'location',
                        label: 'Location',
                        placeholder: 'e.g. Administration Block, Room 4',
                    },
                ]}
            />

            <RecordDrawer
                record={editingGroup}
                onClose={() => setEditingGroup(null)}
                title="device group"
                mutation={useSaveDeviceGroup}
                fields={[
                    {
                        key: 'name',
                        label: 'Group name',
                        required: true,
                        placeholder: 'e.g. Learner Workstations',
                    },
                    {
                        key: 'purpose',
                        label: 'Purpose',
                        placeholder: 'e.g. Learner devices',
                    },
                ]}
            />

        </div>
    );
}


/* ------------------------------------------------------------------
   SMALL METRIC CARD
------------------------------------------------------------------ */

function MetricCard({
    icon: Icon,
    label,
    value,
    description,
}) {
    return (
        <div className="group rounded-[16px] border border-slate-200/80 bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.03)]">
            <div className="flex items-start justify-between gap-4">

                <div>
                    <p className="text-xs font-medium text-slate-500">
                        {label}
                    </p>

                    <p className="mt-2 text-[24px] font-semibold leading-none tracking-[-0.04em] tabular-nums text-slate-950">
                        {value}
                    </p>

                    <p className="mt-2 text-[11px] leading-4 text-slate-400">
                        {description}
                    </p>
                </div>

                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#EAF4F2] text-[#17675F]">
                    <Icon
                        className="h-[17px] w-[17px]"
                        strokeWidth={2}
                    />
                </div>

            </div>
        </div>
    );
}


/* ------------------------------------------------------------------
   SHARED CREATE / EDIT DRAWER
------------------------------------------------------------------ */

function RecordDrawer({
    record,
    onClose,
    title,
    mutation,
    fields,
}) {
    const save = mutation();

    const [form, setForm] = useState({});

    const isEdit = Boolean(record?.id);

    useEffect(() => {
        if (record) {
            setForm(
                Object.fromEntries(
                    fields.map((field) => [
                        field.key,
                        record[field.key] ?? '',
                    ]),
                ),
            );

            save.reset();
        }

        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [record?.id, Boolean(record)]);

    const errors =
        save.error instanceof ApiError
            ? save.error.errors
            : {};

    async function submit(event) {
        event.preventDefault();

        try {
            await save.mutateAsync({
                id: record?.id,
                ...form,
            });

            showToast(
                isEdit
                    ? `The ${title} was updated`
                    : `The ${title} was added`,
            );

            onClose();
        } catch (error) {
            if (
                !(
                    error instanceof ApiError &&
                    error.isValidation
                )
            ) {
                toastError(
                    error,
                    `The ${title} could not be saved`,
                );
            }
        }
    }

    return (
        <Modal
            open={Boolean(record)}
            onClose={onClose}
            title={
                isEdit
                    ? record.name
                    : `Add a ${title}`
            }
            size="sm"
            footer={
                <>
                    <Button
                        className="flex-1"
                        onClick={onClose}
                        disabled={save.isPending}
                    >
                        Cancel
                    </Button>

                    <Button
                        className="flex-1"
                        type="submit"
                        form="record-form"
                        variant="primary"
                        loading={save.isPending}
                    >
                        {isEdit
                            ? 'Save changes'
                            : `Add ${title}`}
                    </Button>
                </>
            }
        >
            <div className="mb-6 rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-3">
                <p className="text-xs leading-5 text-slate-500">
                    {isEdit
                        ? `Update the details for this ${title}. Changes take effect immediately.`
                        : title === 'laboratory'
                            ? 'Create a physical laboratory before assigning managed devices to it.'
                            : 'Create a logical device group for policy assignment and reporting.'}
                </p>
            </div>

            <form
                id="record-form"
                onSubmit={submit}
                className="space-y-5"
                noValidate
            >
                {fields.map((field, index) => (
                    <Field
                        key={field.key}
                        label={field.label}
                        required={field.required}
                        error={firstError(
                            errors,
                            field.key,
                        )}
                    >
                        <TextInput
                            data-autofocus={
                                index === 0
                                    ? ''
                                    : undefined
                            }
                            value={
                                form[field.key] ?? ''
                            }
                            placeholder={
                                field.placeholder
                            }
                            onChange={(event) =>
                                setForm((current) => ({
                                    ...current,
                                    [field.key]:
                                        event.target.value,
                                }))
                            }
                        />
                    </Field>
                ))}
            </form>
        </Modal>
    );
}