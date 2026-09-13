import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { MapPinIcon, PlusIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import { Button, EmptyState, ErrorState, Field, Meter, Panel, Skeleton, TextInput, coverageTone, firstError } from '../components/Primitives';
import { Modal } from '../components/Overlays';
import { StatusPill } from '../components/StatusPill';
import { useAuth } from '../lib/auth';
import { useCreateSubcounty, useSubcounties } from '../lib/queries';
import { capabilitiesFor } from '../lib/permissions';
import { formatNumber, formatPercent } from '../lib/format';
import { ApiError } from '../lib/api';
import { showToast, toastError } from '../lib/toast';

export function SubcountiesPage() {
    const navigate = useNavigate();
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const subcounties = useSubcounties();
    const [creating, setCreating] = useState(false);

    const rows = subcounties.data?.data ?? [];

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Sub-counties"
                description="Protection coverage and school compliance across the administrative units of Kiambu County."
                meta={`${formatNumber(rows.length)} in scope`}
                actions={
                    can.manageSubcounties && (
                        <Button variant="primary" icon={PlusIcon} onClick={() => setCreating(true)}>
                            Add sub-county
                        </Button>
                    )
                }
            />

            {subcounties.isPending ? (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {Array.from({ length: 4 }).map((_, index) => (
                        <Skeleton key={index} className="h-44 rounded-xl" />
                    ))}
                </div>
            ) : subcounties.isError ? (
                <Panel>
                    <ErrorState error={subcounties.error} onRetry={subcounties.refetch} />
                </Panel>
            ) : rows.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={MapPinIcon}
                        title="No sub-counties defined"
                        description="Add the county's administrative units before registering institutions."
                        action={
                            can.manageSubcounties && (
                                <Button size="sm" variant="primary" icon={PlusIcon} onClick={() => setCreating(true)}>
                                    Add sub-county
                                </Button>
                            )
                        }
                    />
                </Panel>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {rows.map((item) => {
                        const coverage = item.institutions_count ? (item.protected_institutions_count / item.institutions_count) * 100 : 0;
                        const attribution = item.devices_count
                            ? ((item.devices_count - item.unattributed_devices_count) / item.devices_count) * 100
                            : null;

                        return (
                            <button
                                key={item.id}
                                onClick={() => navigate(`/subcounties/${item.id}`)}
                                className="rounded-xl border border-border bg-white p-5 text-left shadow-card transition-colors duration-150 ease-gov hover:border-brand/30"
                            >
                                <div className="flex items-start justify-between">
                                    <div className="grid h-9 w-9 place-items-center rounded-lg bg-brand-soft text-brand">
                                        <MapPinIcon size={18} />
                                    </div>
                                    <StatusPill
                                        label={coverage >= 80 ? 'Healthy' : coverage >= 50 ? 'Attention' : 'Deployment'}
                                        tone={coverage >= 80 ? 'success' : coverage >= 50 ? 'warning' : 'info'}
                                    />
                                </div>

                                <h2 className="mt-4 text-lg font-bold">{item.name}</h2>
                                <p className="text-xs text-text-secondary tabular-nums">
                                    {formatNumber(item.institutions_count)} schools · {formatNumber(item.devices_count)} devices ·{' '}
                                    {formatNumber(item.learners_count)} learners
                                </p>

                                <Meter
                                    value={coverage}
                                    tone={coverageTone(coverage)}
                                    label={`${item.name} protection coverage`}
                                    className="mt-4"
                                />
                                <div className="mt-2 flex justify-between text-[11px] text-text-secondary tabular-nums">
                                    <span>{item.protected_institutions_count} protected</span>
                                    <span>{attribution === null ? 'No devices' : `${formatPercent(attribution, 0)} attributed`}</span>
                                </div>

                                <p className="mt-3 border-t border-border pt-3 text-[11px] text-text-secondary tabular-nums">
                                    {formatNumber(item.open_incidents_count)} open incidents · code {item.code}
                                </p>
                            </button>
                        );
                    })}
                </div>
            )}

            <CreateSubcountyDrawer open={creating} onClose={() => setCreating(false)} />
        </div>
    );
}

function CreateSubcountyDrawer({ open, onClose }) {
    const create = useCreateSubcounty();
    const [name, setName] = useState('');
    const [code, setCode] = useState('');
    const [codeEdited, setCodeEdited] = useState(false);

    useEffect(() => {
        if (open) {
            setName('');
            setCode('');
            setCodeEdited(false);
            create.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const errors = create.error instanceof ApiError ? create.error.errors : {};

    async function submit(event) {
        event.preventDefault();

        try {
            const subcounty = await create.mutateAsync({ name, code });
            showToast(`${subcounty.data?.name ?? name} added`, { description: 'The sub-county is now available for school registration.' });
            onClose();
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The sub-county could not be added');
            }
        }
    }

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Add a sub-county"
            subtitle="Only the County Director of Education may change the county structure."
            size="sm"
            footer={
                <>
                    <Button className="flex-1" onClick={onClose} disabled={create.isPending}>
                        Cancel
                    </Button>
                    <Button className="flex-1" variant="primary" form="create-subcounty" type="submit" loading={create.isPending}>
                        Add sub-county
                    </Button>
                </>
            }
        >
            <form id="create-subcounty" onSubmit={submit} className="space-y-4" noValidate>
                <Field label="Sub-county name" required error={firstError(errors, 'name')}>
                    <TextInput
                        data-autofocus
                        value={name}
                        onChange={(event) => {
                            setName(event.target.value);

                            if (!codeEdited) {
                                setCode(
                                    `KBU-${event.target.value
                                        .trim()
                                        .toUpperCase()
                                        .replace(/[^A-Z]/g, '')
                                        .slice(0, 3)}`,
                                );
                            }
                        }}
                        placeholder="e.g. Githunguri"
                    />
                </Field>

                <Field
                    label="Reference code"
                    required
                    hint="Used in reports and device enrolment records. Letters, numbers and dashes only."
                    error={firstError(errors, 'code')}
                >
                    <TextInput
                        value={code}
                        maxLength={20}
                        className="font-mono uppercase"
                        onChange={(event) => {
                            setCodeEdited(true);
                            setCode(event.target.value.toUpperCase());
                        }}
                    />
                </Field>
            </form>
        </Modal>
    );
}
