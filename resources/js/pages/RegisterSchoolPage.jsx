import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { InfoIcon, SendIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import { Button, Field, Panel, PanelHeader, Select, TextInput, firstError } from '../components/Primitives';
import { useAuth } from '../lib/auth';
import { useCreateInstitution, useSubcounties } from '../lib/queries';
import { INSTITUTION_TYPES, OWNERSHIP_TYPES } from '../lib/domain';
import { ApiError } from '../lib/api';
import { showToast, toastError } from '../lib/toast';

const EMPTY = {
    subcounty_id: '',
    name: '',
    nemis_code: '',
    institution_type: 'primary',
    ownership: 'public',
    physical_location: '',
    hoi_name: '',
    hoi_email: '',
    hoi_phone: '',
    learner_population: '',
    computing_devices_count: '',
    laboratories_count: '',
    connectivity_type: '',
};

export function RegisterSchoolPage() {
    const navigate = useNavigate();
    const { user } = useAuth();
    const create = useCreateInstitution();
    const subcounties = useSubcounties();

    const [form, setForm] = useState(() => ({
        ...EMPTY,
        subcounty_id: user?.subcounty?.id ? String(user.subcounty.id) : '',
    }));

    const errors = create.error instanceof ApiError ? create.error.errors : {};
    const lockedToSubcounty = user?.role === 'scde' && Boolean(user?.subcounty?.id);

    const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));

    async function submit(event) {
        event.preventDefault();

        try {
            const payload = await create.mutateAsync({
                ...form,
                subcounty_id: Number(form.subcounty_id),
                learner_population: Number(form.learner_population || 0),
                computing_devices_count: Number(form.computing_devices_count || 0),
                laboratories_count: Number(form.laboratories_count || 0),
                connectivity_type: form.connectivity_type || null,
            });

            showToast(`${payload.data.name} submitted`, { description: 'The HOI account is provisioned and its three-part email/SMS credentials are queued.' });
            navigate(`/schools/${payload.data.id}`);
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'The registration could not be submitted');
            }
        }
    }

    return (
        <div className="animate-fade-up">
            <PageHeader
                breadcrumbs={[{ label: 'Schools', to: '/schools' }, { label: 'Register' }]}
                title="Register a school"
                description="Submit a basic education institution for enrolment into the SAFERNET filtering service. The County Director reviews every submission before deployment begins."
            />

            <form onSubmit={submit} className="space-y-4" noValidate>
                <Panel>
                    <PanelHeader title="Identification" description="Details must match the institution's NEMIS record." />
                    <div className="grid gap-4 px-5 py-4 sm:grid-cols-2">
                        <Field label="School name" required error={firstError(errors, 'name')} className="sm:col-span-2">
                            <TextInput value={form.name} onChange={set('name')} placeholder="e.g. Kikuyu Township Primary School" />
                        </Field>

                        <Field label="NEMIS code" required error={firstError(errors, 'nemis_code')}>
                            <TextInput value={form.nemis_code} onChange={set('nemis_code')} className="font-mono" />
                        </Field>

                        <Field
                            label="Sub-county"
                            required
                            error={firstError(errors, 'subcounty_id')}
                            hint={lockedToSubcounty ? 'Sub-County Directors may only register schools in their own sub-county.' : undefined}
                        >
                            <Select value={form.subcounty_id} onChange={set('subcounty_id')} disabled={lockedToSubcounty}>
                                <option value="">Select a sub-county</option>
                                {(subcounties.data?.data ?? []).map((subcounty) => (
                                    <option key={subcounty.id} value={subcounty.id}>
                                        {subcounty.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>

                        <Field label="Institution type" required error={firstError(errors, 'institution_type')}>
                            <Select value={form.institution_type} onChange={set('institution_type')}>
                                {Object.entries(INSTITUTION_TYPES).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                        </Field>

                        <Field label="Ownership" required error={firstError(errors, 'ownership')}>
                            <Select value={form.ownership} onChange={set('ownership')}>
                                {Object.entries(OWNERSHIP_TYPES).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                        </Field>

                        <Field
                            label="Physical location"
                            required
                            hint="Ward, town or nearest landmark."
                            error={firstError(errors, 'physical_location')}
                            className="sm:col-span-2"
                        >
                            <TextInput value={form.physical_location} onChange={set('physical_location')} />
                        </Field>
                    </div>
                </Panel>

                <Panel>
                    <PanelHeader title="Head of institution" description="SAFERNET creates this officer's account and sends the welcome notice, username and temporary password separately by email and SMS." />
                    <div className="grid gap-4 px-5 py-4 sm:grid-cols-3">
                        <Field label="Full name" required error={firstError(errors, 'hoi_name')}>
                            <TextInput value={form.hoi_name} onChange={set('hoi_name')} />
                        </Field>
                        <Field label="Official email" required error={firstError(errors, 'hoi_email')}>
                            <TextInput type="email" value={form.hoi_email} onChange={set('hoi_email')} />
                        </Field>
                        <Field label="Telephone" required error={firstError(errors, 'hoi_phone')}>
                            <TextInput type="tel" value={form.hoi_phone} onChange={set('hoi_phone')} placeholder="+254" />
                        </Field>
                    </div>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Scale of deployment"
                        description="Declared figures size the rollout and are reconciled against enrolled records after deployment."
                    />
                    <div className="grid gap-4 px-5 py-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Field label="Learner population" required error={firstError(errors, 'learner_population')}>
                            <TextInput type="number" min="0" inputMode="numeric" value={form.learner_population} onChange={set('learner_population')} />
                        </Field>
                        <Field label="Computing devices" required error={firstError(errors, 'computing_devices_count')}>
                            <TextInput type="number" min="0" inputMode="numeric" value={form.computing_devices_count} onChange={set('computing_devices_count')} />
                        </Field>
                        <Field label="Laboratories" required error={firstError(errors, 'laboratories_count')}>
                            <TextInput type="number" min="0" inputMode="numeric" value={form.laboratories_count} onChange={set('laboratories_count')} />
                        </Field>
                        <Field label="Connectivity" hint="Optional" error={firstError(errors, 'connectivity_type')}>
                            <TextInput value={form.connectivity_type} onChange={set('connectivity_type')} placeholder="e.g. Fibre, LTE" />
                        </Field>
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-surface-muted/40 px-5 py-3">
                        <p className="flex items-start gap-2 text-[11px] leading-4 text-text-secondary">
                            <InfoIcon size={13} className="mt-0.5 shrink-0" />
                            Submitting records your name against this registration in the county audit log.
                        </p>
                        <div className="flex gap-2">
                            <Button as={Link} to="/schools">
                                Cancel
                            </Button>
                            <Button type="submit" variant="primary" icon={SendIcon} loading={create.isPending}>
                                Submit for approval
                            </Button>
                        </div>
                    </div>
                </Panel>
            </form>
        </div>
    );
}
