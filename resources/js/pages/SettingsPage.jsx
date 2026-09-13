import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import {
    BellIcon,
    BrainCircuitIcon,
    CameraIcon,
    CheckIcon,
    DatabaseIcon,
    KeyRoundIcon,
    LogOutIcon,
    MailIcon,
    MessageSquareIcon,
    MonitorSmartphoneIcon,
    PlugZapIcon,
    ShieldCheckIcon,
    UserRoundIcon,
    XIcon,
} from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import {
    Button,
    Cell,
    DataTable,
    EmptyState,
    Field,
    Panel,
    PanelHeader,
    Row,
    Skeleton,
    TextInput,
    firstError,
} from '../components/Primitives';
import { ConfirmDialog } from '../components/Overlays';
import { StatusPill } from '../components/StatusPill';
import { UserAvatar } from '../components/UserAvatar';
import { useAuth } from '../lib/auth';
import {
    useIntegrations,
    useNotificationDeliveries,
    useRevokeOtherSessions,
    useRevokeSession,
    useSaveUser,
    useSessions,
} from '../lib/queries';
import { ROLES, roleLabel } from '../lib/domain';
import { ROLE_REMIT, capabilitiesFor } from '../lib/permissions';
import { formatDate, formatNumber } from '../lib/format';
import { ApiError } from '../lib/api';
import { showToast, toastError } from '../lib/toast';

const TABS = [
    { id: 'profile', label: 'Profile', icon: UserRoundIcon },
    { id: 'security', label: 'Security', icon: KeyRoundIcon },
    { id: 'access', label: 'Access & remit', icon: ShieldCheckIcon },
    { id: 'notifications', label: 'Notifications', icon: BellIcon },
    { id: 'integrations', label: 'Integrations', icon: PlugZapIcon, capability: 'viewAudit' },
];

export function SettingsPage() {
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const [tab, setTab] = useState('profile');
    const tabs = TABS.filter((entry) => !entry.capability || can[entry.capability]);

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Account Settings"
                description="Your officer profile, credentials and the scope SAFERNET applies to every register you open."
                meta={roleLabel(user?.role)}
            />

            <div className="grid gap-4 lg:grid-cols-[minmax(0,260px)_minmax(0,1fr)]">
                <div className="flex flex-col gap-4">
                    <Panel className="p-5">
                        <div className="flex items-center gap-3">
                            <UserAvatar user={user} size="lg" />
                            <div className="min-w-0">
                                <p className="truncate text-sm font-bold">{user?.name}</p>
                                <p className="truncate text-xs text-text-secondary">{user?.email}</p>
                            </div>
                        </div>
                        <div className="mt-4 flex flex-wrap items-center gap-2">
                            <StatusPill
                                label={user?.status === 'active' ? 'Active account' : 'Suspended'}
                                tone={user?.status === 'active' ? 'success' : 'danger'}
                            />
                            <span className="rounded-full bg-surface-muted px-2.5 py-1 text-xs font-semibold text-text-secondary">
                                {ROLES[user?.role]?.short ?? '—'}
                            </span>
                        </div>
                        <p className="mt-4 border-t border-border pt-4 text-[11px] leading-5 text-text-secondary">
                            {ROLE_REMIT[user?.role] ?? 'Your access scope is enforced by the service on every request.'}
                        </p>
                    </Panel>

                    <nav aria-label="Settings sections" className="overflow-hidden rounded-xl border border-border bg-white shadow-card">
                        {tabs.map(({ id, label, icon: Icon }) => (
                            <button
                                key={id}
                                onClick={() => setTab(id)}
                                aria-current={tab === id}
                                className={clsx(
                                    'flex w-full items-center gap-2.5 border-b border-border px-4 py-3 text-left text-[13px] font-medium transition-colors duration-150 ease-gov last:border-0',
                                    tab === id ? 'bg-brand-soft/60 text-brand' : 'text-text-secondary hover:bg-surface-muted hover:text-text',
                                )}
                            >
                                <Icon size={16} className={tab === id ? 'text-brand' : 'text-text-muted'} />
                                {label}
                            </button>
                        ))}
                    </nav>
                </div>

                <div className="min-w-0">
                    {tab === 'profile' && <ProfilePanel />}
                    {tab === 'security' && <SecurityPanel />}
                    {tab === 'access' && <AccessPanel />}
                    {tab === 'notifications' && <NotificationsPanel />}
                    {tab === 'integrations' && can.viewAudit && <IntegrationsPanel />}
                </div>
            </div>
        </div>
    );
}

function ProfilePanel() {
    const { user, refresh } = useAuth();
    const save = useSaveUser();
    const [form, setForm] = useState({ name: user?.name ?? '', email: user?.email ?? '', phone: user?.phone ?? '', avatar: null });

    const errors = save.error instanceof ApiError ? save.error.errors : {};
    const dirty = form.name !== user?.name || form.email !== user?.email || form.phone !== (user?.phone ?? '') || Boolean(form.avatar);

    async function submit(event) {
        event.preventDefault();

        try {
            await save.mutateAsync({ id: user.id, name: form.name, email: form.email, phone: form.phone || null, avatar: form.avatar });
            await refresh();
            showToast('Profile updated', { description: 'Your officer details were saved.' });
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'Your profile could not be saved');
            }
        }
    }

    return (
        <Panel>
            <PanelHeader title="Officer profile" description="The name and email recorded against every decision you take." />
            <form onSubmit={submit} className="space-y-4 px-5 py-5" noValidate>
                <ProfileAvatarInput
                    user={user}
                    value={form.avatar}
                    onChange={(avatar) => setForm((current) => ({ ...current, avatar }))}
                    error={firstError(errors, 'avatar')}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Full name" required error={firstError(errors, 'name')}>
                        <TextInput value={form.name} onChange={(event) => setForm((c) => ({ ...c, name: event.target.value }))} />
                    </Field>
                    <Field
                        label="Official email"
                        required
                        hint="Used to sign in and to receive safeguarding alerts."
                        error={firstError(errors, 'email')}
                    >
                        <TextInput
                            type="email"
                            value={form.email}
                            onChange={(event) => setForm((c) => ({ ...c, email: event.target.value }))}
                        />
                    </Field>
                </div>

                <Field
                    label="Mobile number"
                    hint="Receives high-severity safeguarding SMS alerts when this office is the routed recipient."
                    error={firstError(errors, 'phone')}
                >
                    <TextInput
                        type="tel"
                        value={form.phone}
                        placeholder="0712 345 678"
                        onChange={(event) => setForm((current) => ({ ...current, phone: event.target.value }))}
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Office" hint="Only the County Director may change an officer's office.">
                        <TextInput value={roleLabel(user?.role)} disabled readOnly />
                    </Field>
                    <Field label="Assigned scope" hint="Changed by the officer who provisioned your account.">
                        <TextInput
                            value={user?.institution?.name ?? user?.subcounty?.name ?? 'Kiambu County'}
                            disabled
                            readOnly
                        />
                    </Field>
                </div>

                <div className="flex items-center justify-between gap-3 border-t border-border pt-4">
                    <p className="text-[11px] text-text-secondary">Changes to your profile are written to the county audit log.</p>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            disabled={!dirty}
                            onClick={() => setForm({ name: user?.name ?? '', email: user?.email ?? '', phone: user?.phone ?? '', avatar: null })}
                        >
                            Reset
                        </Button>
                        <Button type="submit" variant="primary" icon={CheckIcon} loading={save.isPending} disabled={!dirty}>
                            Save profile
                        </Button>
                    </div>
                </div>
            </form>
        </Panel>
    );
}

function ProfileAvatarInput({ user, value, onChange, error }) {
    const [preview, setPreview] = useState(user?.avatar_url ?? null);

    useEffect(() => {
        if (!value) {
            setPreview(user?.avatar_url ?? null);
            return undefined;
        }

        const objectUrl = URL.createObjectURL(value);
        setPreview(objectUrl);

        return () => URL.revokeObjectURL(objectUrl);
    }, [value, user?.avatar_url]);

    return (
        <Field label="Profile image" hint="JPG, PNG or WebP. Maximum 2 MB." error={error}>
            <div className="flex items-center gap-4 rounded-xl border border-border bg-surface-muted/35 p-3">
                <UserAvatar user={{ ...user, avatar_url: preview }} size="xl" />
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

function SecurityPanel() {
    const { user, signOut } = useAuth();
    const navigate = useNavigate();
    const save = useSaveUser();
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [signingOut, setSigningOut] = useState(false);
    const sessions = useSessions();
    const revokeSession = useRevokeSession();
    const revokeOthers = useRevokeOtherSessions();

    const errors = save.error instanceof ApiError ? save.error.errors : {};
    const mismatch = confirmation.length > 0 && password !== confirmation;
    const tooShort = password.length > 0 && password.length < 12;

    async function submit(event) {
        event.preventDefault();

        if (mismatch || tooShort) {
            return;
        }

        try {
            await save.mutateAsync({ id: user.id, password });
            setPassword('');
            setConfirmation('');
            showToast('Password changed', { description: 'Use your new password the next time you sign in.' });
        } catch (error) {
            if (!(error instanceof ApiError && error.isValidation)) {
                toastError(error, 'Your password could not be changed');
            }
        }
    }

    return (
        <div className="flex flex-col gap-4">
            <Panel>
                <PanelHeader title="Change password" description="Minimum twelve characters. Choose a passphrase you do not use elsewhere." />
                <form onSubmit={submit} className="space-y-4 px-5 py-5" noValidate>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="New password"
                            required
                            error={firstError(errors, 'password') ?? (tooShort ? 'Use at least 12 characters.' : undefined)}
                        >
                            <TextInput
                                type="password"
                                autoComplete="new-password"
                                value={password}
                                onChange={(event) => setPassword(event.target.value)}
                            />
                        </Field>
                        <Field label="Confirm new password" required error={mismatch ? 'The two passwords do not match.' : undefined}>
                            <TextInput
                                type="password"
                                autoComplete="new-password"
                                value={confirmation}
                                onChange={(event) => setConfirmation(event.target.value)}
                            />
                        </Field>
                    </div>

                    <div className="flex items-center justify-between gap-3 border-t border-border pt-4">
                        <p className="text-[11px] text-text-secondary">
                            Unauthorised use of this system is an offence under the Computer Misuse and Cybercrimes Act, 2018.
                        </p>
                        <Button
                            type="submit"
                            variant="primary"
                            icon={KeyRoundIcon}
                            loading={save.isPending}
                            disabled={!password || mismatch || tooShort}
                        >
                            Change password
                        </Button>
                    </div>
                </form>
            </Panel>

            <Panel>
                <PanelHeader title="Signed-in devices" description="Review and revoke SAFERNET portal sessions issued to your account." />
                <div className="divide-y divide-border">
                    {(sessions.data ?? []).map((session) => (
                        <div key={session.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <p className="flex items-start gap-2.5 text-xs leading-5 text-text-secondary">
                                <MonitorSmartphoneIcon size={16} className="mt-0.5 shrink-0 text-text-muted" />
                                <span>
                                    <strong className="block text-text">{session.name}{session.is_current ? ' · This device' : ''}</strong>
                                    Last used {session.last_used_at ? formatDate(session.last_used_at, { withTime: true }) : 'not yet'}
                                </span>
                            </p>
                            {session.is_current ? (
                                <Button variant="dangerGhost" icon={LogOutIcon} onClick={() => setSigningOut(true)}>Sign out</Button>
                            ) : (
                                <Button
                                    variant="dangerGhost"
                                    icon={XIcon}
                                    loading={revokeSession.isPending}
                                    onClick={() => revokeSession.mutate(session.id)}
                                >
                                    Revoke
                                </Button>
                            )}
                        </div>
                    ))}
                    {sessions.isPending && <div className="px-5 py-4 text-xs text-text-secondary">Loading active sessions…</div>}
                    {sessions.isError && <div className="px-5 py-4 text-xs text-danger">Active sessions could not be loaded.</div>}
                </div>
                <div className="flex justify-end border-t border-border px-5 py-4">
                    <Button
                        variant="dangerGhost"
                        disabled={(sessions.data ?? []).filter((session) => !session.is_current).length === 0}
                        loading={revokeOthers.isPending}
                        onClick={() => revokeOthers.mutate()}
                    >
                        Sign out other devices
                    </Button>
                </div>
            </Panel>

            <ConfirmDialog
                open={signingOut}
                tone="danger"
                title="Sign out of SAFERNET?"
                description="Your access token for this browser will be revoked. You will need your credentials to sign in again."
                confirmLabel="Sign out"
                onCancel={() => setSigningOut(false)}
                onConfirm={async () => {
                    await signOut();
                    navigate('/signin', { replace: true });
                }}
            />
        </div>
    );
}

/** The duties this office carries, read straight from the capability map. */
const DUTY_LABELS = [
    ['manageSubcounties', 'Maintain the county structure'],
    ['registerSchools', 'Register schools into the county register'],
    ['reviewRegistrations', 'Approve or reject school registrations'],
    ['manageOfficers', 'Provision and manage officer accounts'],
    ['createLearners', 'Maintain the learner register'],
    ['manageLearnerGroups', 'Create and maintain classes'],
    ['registerDevices', 'Enrol, name and decommission devices'],
    ['manageLaboratories', 'Create laboratories and device groups'],
    ['assignLearners', 'Attribute learners to devices'],
    ['manageSessions', 'Open and close learner sessions'],
    ['managePolicies', 'Author school filtering rules'],
    ['recordIncidentActions', 'Record action taken on a learner incident'],
    ['reviewExceptions', 'Approve filtering exceptions'],
    ['submitExceptions', 'Request a filtering exception'],
    ['viewAudit', 'Read the county audit log'],
];

function AccessPanel() {
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);

    return (
        <div className="flex flex-col gap-4">
            <Panel>
                <PanelHeader title="Access scope" description="Enforced by the service on every request, not by this portal." />
                <div className="grid gap-px overflow-hidden bg-border sm:grid-cols-3">
                    {[
                        ['County', 'Kiambu'],
                        ['Sub-county', user?.subcounty?.name ?? (user?.role === 'cde' ? 'All sub-counties' : '—')],
                        ['Institution', user?.institution?.name ?? '—'],
                    ].map(([label, value]) => (
                        <div key={label} className="bg-white px-4 py-4">
                            <p className="text-[11px] text-text-secondary">{label}</p>
                            <p className="mt-1 text-sm font-semibold break-words">{value}</p>
                        </div>
                    ))}
                </div>
            </Panel>

            <Panel>
                <PanelHeader title="What this office may do" description={ROLE_REMIT[user?.role]} />
                <ul className="grid gap-x-8 gap-y-2 px-5 py-5 sm:grid-cols-2">
                    {DUTY_LABELS.map(([capability, label]) => (
                        <li key={capability} className="flex items-start gap-2.5 text-xs leading-5">
                            {can[capability] ? (
                                <CheckIcon size={15} className="mt-0.5 shrink-0 text-success" />
                            ) : (
                                <XIcon size={15} className="mt-0.5 shrink-0 text-text-muted" />
                            )}
                            <span className={can[capability] ? 'text-text' : 'text-text-muted line-through decoration-border'}>{label}</span>
                        </li>
                    ))}
                </ul>
                <p className="border-t border-border bg-surface-muted/40 px-5 py-3 text-[11px] leading-5 text-text-secondary">
                    County-mandated filtering cannot be disabled by any office. Every review decision, learner assignment and
                    session you record is written to the audit log with your name and IP address.
                </p>
            </Panel>
        </div>
    );
}

function NotificationsPanel() {
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);

    const routes = [
        {
            title: 'Repeated or high-risk learner violations',
            body: 'Raised as an incident and emailed to the Head of Institution when a policy threshold is met.',
            active: can.recordIncidentActions || can.isSchool,
            primary: user?.role === 'hoi',
        },
        {
            title: 'Protection component degraded or offline',
            body: 'Gateways, endpoint agents and browser extensions that stop reporting appear on the deployment board.',
            active: can.viewDeployment,
            primary: user?.role === 'clm',
        },
        {
            title: 'Tamper and circumvention events',
            body: 'Security events recorded by the endpoint agent, for technical response and administrative follow-up.',
            active: can.viewDeployment,
            primary: user?.role === 'clm',
        },
        {
            title: 'Registrations awaiting approval',
            body: 'Schools submitted by Sub-County Directors appear in the approvals queue.',
            active: can.reviewRegistrations,
            primary: user?.role === 'cde',
        },
        {
            title: 'Filtering exception requests',
            body: 'Requests to release a blocked domain for classroom use await your decision.',
            active: can.reviewExceptions,
            primary: can.reviewExceptions,
        },
    ];

    return (
        <Panel>
            <PanelHeader
                title="Alert routing"
                description="Which SAFERNET alerts reach this office. Routing follows your duties and is set by the county."
            />
            <ul className="divide-y divide-border">
                {routes.map((route) => (
                    <li key={route.title} className="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                        <div className="min-w-0 flex-1">
                            <p className="text-[13px] font-semibold">{route.title}</p>
                            <p className="mt-1 text-xs leading-5 text-text-secondary">{route.body}</p>
                        </div>
                        {route.primary ? (
                            <StatusPill label="Primary recipient" tone="success" />
                        ) : route.active ? (
                            <StatusPill label="Visible to you" tone="info" />
                        ) : (
                            <StatusPill label="Not routed here" tone="neutral" />
                        )}
                    </li>
                ))}
            </ul>
            <p className="border-t border-border bg-surface-muted/40 px-5 py-3 text-[11px] leading-5 text-text-secondary">
                Alert routing is a county configuration. Ask the County Director of Education ICT desk to change who receives an
                alert.
            </p>
        </Panel>
    );
}

/* ------------------------------------------------------------- integrations */

const PROVIDER_LABELS = {
    gemini: 'Google Gemini',
    anthropic: 'Anthropic Claude',
    openai: 'OpenAI',
};

/**
 * What this deployment is actually connected to. "Configured" and "working" are
 * different things, and the board says which each one is.
 */
function IntegrationsPanel() {
    const integrations = useIntegrations();
    const deliveries = useNotificationDeliveries({});

    const data = integrations.data;
    const rows = deliveries.data?.data ?? [];

    return (
        <div className="flex flex-col gap-4">
            <Panel>
                <PanelHeader
                    title="Content classification"
                    description="Used only where the county blocklists are silent. A model never softens a county-mandated block."
                />
                <div className="px-5 py-4">
                    {integrations.isPending ? (
                        <Skeleton className="h-20 w-full" />
                    ) : (
                        <>
                            <div className="mb-4 flex flex-wrap items-center gap-2">
                                <StatusPill
                                    label={data?.ai?.enabled ? 'Enabled' : 'Not configured'}
                                    tone={data?.ai?.enabled ? 'success' : 'neutral'}
                                />
                                <span className="text-xs text-text-secondary">
                                    Selection: <strong className="font-semibold text-text">{data?.ai?.selection}</strong>
                                    {data?.ai?.active_provider && (
                                        <>
                                            {' · answering with '}
                                            <strong className="font-semibold text-text">
                                                {PROVIDER_LABELS[data.ai.active_provider] ?? data.ai.active_provider}
                                            </strong>
                                        </>
                                    )}
                                </span>
                            </div>

                            <ul className="divide-y divide-border rounded-lg border border-border">
                                {(data?.ai?.providers ?? []).map((provider) => (
                                    <li key={provider.name} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
                                        <div className="min-w-0">
                                            <p className="flex items-center gap-2 text-xs font-semibold">
                                                <BrainCircuitIcon size={14} className="text-text-muted" />
                                                {PROVIDER_LABELS[provider.name] ?? provider.name}
                                            </p>
                                            <p className="mt-0.5 truncate font-mono text-[11px] text-text-muted">
                                                {provider.models.join(', ') || 'No models configured'}
                                            </p>
                                        </div>
                                        <StatusPill
                                            label={provider.configured ? 'Key present' : 'No API key'}
                                            tone={provider.configured ? 'success' : 'neutral'}
                                        />
                                    </li>
                                ))}
                            </ul>

                            <p className="mt-3 text-[11px] leading-5 text-text-secondary">
                                Providers are tried in the order shown, so the first one holding a key answers. When none is
                                configured — or the one in use times out or returns something unusable — filtering falls back to
                                the deterministic policy rules.
                            </p>
                        </>
                    )}
                </div>
            </Panel>

            <div className="grid gap-4 md:grid-cols-2">
                <Panel>
                    <PanelHeader title="SMS" description="Urgent safeguarding escalation." />
                    <div className="space-y-3 px-5 py-4 text-xs">
                        {integrations.isPending ? (
                            <Skeleton className="h-16 w-full" />
                        ) : (
                            <>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="flex items-center gap-2 text-text-secondary">
                                        <MessageSquareIcon size={14} className="text-text-muted" /> Provider
                                    </span>
                                    <StatusPill
                                        label={data?.sms?.live ? "Africa's Talking · live" : data?.sms?.provider === 'log' ? 'Log simulator' : 'Not configured'}
                                        tone={data?.sms?.live ? 'success' : data?.sms?.configured ? 'warning' : 'neutral'}
                                    />
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-text-secondary">Escalates from</span>
                                    <span className="font-semibold capitalize">{data?.sms?.escalates_from} severity</span>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-text-secondary">Sent (30 days)</span>
                                    <span className="font-semibold tabular-nums">{formatNumber(data?.sms?.sent_30_days ?? 0)}</span>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-text-secondary">Failed (30 days)</span>
                                    <span
                                        className={`font-semibold tabular-nums ${(data?.sms?.failed_30_days ?? 0) > 0 ? 'text-danger' : ''}`}
                                    >
                                        {formatNumber(data?.sms?.failed_30_days ?? 0)}
                                    </span>
                                </div>
                            </>
                        )}
                    </div>
                </Panel>

                <Panel>
                    <PanelHeader title="Email" description="Incident notices and officer correspondence." />
                    <div className="space-y-3 px-5 py-4 text-xs">
                        {integrations.isPending ? (
                            <Skeleton className="h-16 w-full" />
                        ) : (
                            <>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="flex items-center gap-2 text-text-secondary">
                                        <MailIcon size={14} className="text-text-muted" /> Transport
                                    </span>
                                    <StatusPill
                                        label={data?.mail?.live ? `${data.mail.provider} · live` : `${data?.mail?.provider ?? 'log'} · local only`}
                                        tone={data?.mail?.live ? 'success' : 'warning'}
                                    />
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-text-secondary">From</span>
                                    <span className="truncate font-semibold">{data?.mail?.from ?? '—'}</span>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-text-secondary">Sent (30 days)</span>
                                    <span className="font-semibold tabular-nums">{formatNumber(data?.mail?.sent_30_days ?? 0)}</span>
                                </div>
                                <p className="border-t border-border pt-3 text-[11px] leading-5 text-text-secondary">
                                    With the log transport nothing leaves the server: messages are written to the application log
                                    so a pilot can see exactly what would have been sent.
                                </p>
                            </>
                        )}
                    </div>
                </Panel>
            </div>

            <Panel>
                <PanelHeader
                    title="Delivery record"
                    description="What was actually sent, and what the provider said about it."
                />
                <DataTable
                    query={deliveries}
                    rows={rows}
                    minWidth="760px"
                    columns={['Sent', 'Channel', 'Recipient', 'Event', 'Provider', 'Outcome']}
                    empty={
                        <EmptyState
                            icon={DatabaseIcon}
                            title="Nothing sent yet"
                            description="Outbound safeguarding messages appear here once an incident escalates."
                        />
                    }
                >
                    {rows.map((delivery) => (
                        <Row key={delivery.id}>
                            <Cell muted>{formatDate(delivery.created_at, { withTime: true })}</Cell>
                            <Cell className="uppercase">{delivery.channel}</Cell>
                            <Cell mono muted>
                                {delivery.recipient}
                            </Cell>
                            <Cell muted>{delivery.event ?? '—'}</Cell>
                            <Cell muted>{delivery.provider}</Cell>
                            <Cell>
                                <StatusPill
                                    label={delivery.status === 'sent' ? 'Accepted' : 'Failed'}
                                    tone={delivery.status === 'sent' ? 'success' : 'danger'}
                                />
                                {delivery.error && (
                                    <span className="mt-0.5 block max-w-[18rem] truncate text-[11px] text-danger" title={delivery.error}>
                                        {delivery.error}
                                    </span>
                                )}
                            </Cell>
                        </Row>
                    ))}
                </DataTable>
            </Panel>
        </div>
    );
}
