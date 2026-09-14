import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
    AlertTriangleIcon,
    Building2Icon,
    ChevronRightIcon,
    GraduationCapIcon,
    MapPinIcon,
    MonitorCogIcon,
    PlusIcon,
    ShieldAlertIcon,
    ShieldCheckIcon,
    XIcon,
} from 'lucide-react';

import { PageHeader } from '../components/PageHeader';

import {
    Button,
    EmptyState,
    ErrorState,
    Field,
    Panel,
    SearchInput,
    Skeleton,
    TextInput,
    firstError,
} from '../components/Primitives';

import { Modal } from '../components/Overlays';
import { useAuth } from '../lib/auth';
import {
    useCreateSubcounty,
    useSubcounties,
} from '../lib/queries';
import { capabilitiesFor } from '../lib/permissions';
import {
    formatNumber,
    formatPercent,
} from '../lib/format';
import { ApiError } from '../lib/api';
import {
    showToast,
    toastError,
} from '../lib/toast';

export function SubcountiesPage() {
    const navigate = useNavigate();
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);

    const subcounties = useSubcounties();

    const [creating, setCreating] =
        useState(false);

    const [search, setSearch] =
        useState('');

    const rows =
        subcounties.data?.data ?? [];

    const filteredRows = useMemo(() => {
        const query = search
            .trim()
            .toLowerCase();

        if (!query) {
            return rows;
        }

        return rows.filter((item) => {
            return (
                item.name
                    ?.toLowerCase()
                    .includes(query) ||
                item.code
                    ?.toLowerCase()
                    .includes(query)
            );
        });
    }, [rows, search]);

    const totals = useMemo(() => {
        return rows.reduce(
            (current, item) => {
                const institutions =
                    item.institutions_count ??
                    0;

                const protectedInstitutions =
                    item.protected_institutions_count ??
                    0;

                const devices =
                    item.devices_count ??
                    0;

                const unattributedDevices =
                    item.unattributed_devices_count ??
                    0;

                current.schools +=
                    institutions;

                current.protectedSchools +=
                    protectedInstitutions;

                current.learners +=
                    item.learners_count ??
                    0;

                current.devices +=
                    devices;

                current.unattributedDevices +=
                    unattributedDevices;

                current.incidents +=
                    item.open_incidents_count ??
                    0;

                if (
                    institutions >
                        protectedInstitutions ||
                    unattributedDevices > 0 ||
                    (item.open_incidents_count ??
                        0) > 0
                ) {
                    current.attention += 1;
                }

                return current;
            },
            {
                schools: 0,
                protectedSchools: 0,
                learners: 0,
                devices: 0,
                unattributedDevices: 0,
                incidents: 0,
                attention: 0,
            },
        );
    }, [rows]);

    const countyCoverage =
        totals.schools > 0
            ? (totals.protectedSchools /
                  totals.schools) *
              100
            : 0;

    const attributedDevices =
        Math.max(
            0,
            totals.devices -
                totals.unattributedDevices,
        );

    const countyAttribution =
        totals.devices > 0
            ? (attributedDevices /
                  totals.devices) *
              100
            : null;

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Sub-counties"
                description="Protection posture, learner coverage and safeguarding activity across Kiambu County."
                meta={`${formatNumber(
                    rows.length,
                )} in scope`}
                actions={
                    can.manageSubcounties && (
                        <Button
                            variant="primary"
                            icon={PlusIcon}
                            onClick={() =>
                                setCreating(true)
                            }
                        >
                            Add sub-county
                        </Button>
                    )
                }
            />

            {subcounties.isPending ? (
                <SubcountiesSkeleton />
            ) : subcounties.isError ? (
                <Panel>
                    <ErrorState
                        error={
                            subcounties.error
                        }
                        onRetry={
                            subcounties.refetch
                        }
                    />
                </Panel>
            ) : rows.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={MapPinIcon}
                        title="No sub-counties defined"
                        description="Add the county's administrative units before registering institutions."
                        action={
                            can.manageSubcounties && (
                                <Button
                                    size="sm"
                                    variant="primary"
                                    icon={
                                        PlusIcon
                                    }
                                    onClick={() =>
                                        setCreating(
                                            true,
                                        )
                                    }
                                >
                                    Add
                                    sub-county
                                </Button>
                            )
                        }
                    />
                </Panel>
            ) : (
                <>
                    {/* County posture */}
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <OverviewCard
                            icon={
                                Building2Icon
                            }
                            label="Schools"
                            value={
                                totals.schools
                            }
                            detail={`${formatNumber(
                                totals.protectedSchools,
                            )} fully protected`}
                            tone={
                                countyCoverage >=
                                80
                                    ? 'success'
                                    : countyCoverage >=
                                        50
                                      ? 'warning'
                                      : 'danger'
                            }
                        />

                        <OverviewCard
                            icon={
                                GraduationCapIcon
                            }
                            label="Learners"
                            value={
                                totals.learners
                            }
                            detail="On the safety register"
                        />

                        <OverviewCard
                            icon={
                                MonitorCogIcon
                            }
                            label="Devices"
                            value={
                                totals.devices
                            }
                            detail={
                                totals.unattributedDevices >
                                0
                                    ? `${formatNumber(
                                          totals.unattributedDevices,
                                      )} need attribution`
                                    : totals.devices >
                                        0
                                      ? 'All devices attributed'
                                      : 'No devices enrolled'
                            }
                            tone={
                                totals.unattributedDevices >
                                0
                                    ? 'warning'
                                    : 'neutral'
                            }
                        />

                        <OverviewCard
                            icon={
                                ShieldAlertIcon
                            }
                            label="Open incidents"
                            value={
                                totals.incidents
                            }
                            detail={
                                totals.incidents >
                                0
                                    ? `${formatNumber(
                                          totals.attention,
                                      )} sub-counties need attention`
                                    : 'No incidents awaiting action'
                            }
                            tone={
                                totals.incidents >
                                0
                                    ? 'danger'
                                    : 'success'
                            }
                        />
                    </div>

                    {/* County coverage */}
                    <Panel className="mt-4 overflow-hidden">
                        <div className="grid lg:grid-cols-2">
                            <CountyCoverageBlock
                                icon={
                                    ShieldCheckIcon
                                }
                                label="School protection"
                                value={
                                    countyCoverage
                                }
                                detail={`${formatNumber(
                                    totals.protectedSchools,
                                )} of ${formatNumber(
                                    totals.schools,
                                )} schools fully protected`}
                            />

                            <CountyCoverageBlock
                                icon={
                                    MonitorCogIcon
                                }
                                label="Device attribution"
                                value={
                                    countyAttribution
                                }
                                detail={
                                    countyAttribution ===
                                    null
                                        ? 'No devices have been enrolled'
                                        : `${formatNumber(
                                              attributedDevices,
                                          )} of ${formatNumber(
                                              totals.devices,
                                          )} devices attributed`
                                }
                                bordered
                            />
                        </div>
                    </Panel>

                    {/* Sub-county list toolbar */}
                    <div className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="text-sm font-semibold text-text">
                                    Sub-counties
                                </h2>

                                <span className="rounded-full bg-surface-muted px-2 py-0.5 text-[10px] font-semibold tabular-nums text-text-muted">
                                    {formatNumber(
                                        filteredRows.length,
                                    )}
                                </span>
                            </div>

                            <p className="mt-0.5 text-[11px] text-text-secondary">
                                Select a
                                sub-county to
                                review its schools,
                                devices and
                                safeguarding
                                activity.
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <SearchInput
                                value={search}
                                onChange={
                                    setSearch
                                }
                                placeholder="Search name or code"
                                className="min-w-[240px]"
                            />

                            {search && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    icon={XIcon}
                                    onClick={() =>
                                        setSearch(
                                            '',
                                        )
                                    }
                                >
                                    Clear
                                </Button>
                            )}
                        </div>
                    </div>

                    {filteredRows.length ===
                    0 ? (
                        <Panel className="mt-3">
                            <EmptyState
                                icon={
                                    MapPinIcon
                                }
                                title="No sub-counties match your search"
                                description={`No sub-county matched "${search}". Try another name or reference code.`}
                                action={
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            setSearch(
                                                '',
                                            )
                                        }
                                    >
                                        Clear
                                        search
                                    </Button>
                                }
                            />
                        </Panel>
                    ) : (
                        <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {filteredRows.map(
                                (item) => (
                                    <SubcountyCard
                                        key={
                                            item.id
                                        }
                                        item={
                                            item
                                        }
                                        onOpen={() =>
                                            navigate(
                                                `/subcounties/${item.id}`,
                                            )
                                        }
                                    />
                                ),
                            )}
                        </div>
                    )}
                </>
            )}

            <CreateSubcountyModal
                open={creating}
                onClose={() =>
                    setCreating(false)
                }
            />
        </div>
    );
}

function SubcountyCard({
    item,
    onOpen,
}) {
    const schools =
        item.institutions_count ?? 0;

    const protectedSchools =
        item.protected_institutions_count ??
        0;

    const devices =
        item.devices_count ?? 0;

    const unattributed =
        item.unattributed_devices_count ??
        0;

    const incidents =
        item.open_incidents_count ?? 0;

    const coverage = schools
        ? (protectedSchools /
              schools) *
          100
        : 0;

    const attributed =
        Math.max(
            0,
            devices - unattributed,
        );

    const attribution =
        devices > 0
            ? (attributed / devices) *
              100
            : null;

    const unprotected =
        Math.max(
            0,
            schools -
                protectedSchools,
        );

    const posture =
        getSubcountyPosture({
            coverage,
            unattributed,
            incidents,
        });

    return (
        <button
            type="button"
            onClick={onOpen}
            className="group relative overflow-hidden rounded-xl border border-border bg-surface text-left shadow-card transition-all duration-150 ease-gov hover:-translate-y-px hover:border-brand/30 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2"
        >
            {/* Header */}
            <div className="flex items-start justify-between gap-3 px-4 pt-4">
                <div
                    className={`flex h-9 w-9 items-center justify-center rounded-lg ${posture.iconClass}`}
                >
                    <MapPinIcon
                        size={17}
                    />
                </div>

                <div className="flex items-center gap-2">
                    <span
                        className={`rounded-full px-2 py-1 text-[9px] font-semibold ${posture.badgeClass}`}
                    >
                        {
                            posture.label
                        }
                    </span>

                    <ChevronRightIcon
                        size={15}
                        className="text-text-muted transition-all duration-150 group-hover:translate-x-0.5 group-hover:text-brand"
                    />
                </div>
            </div>

            {/* Identity */}
            <div className="px-4 pt-3">
                <h2 className="truncate text-base font-bold tracking-[-0.02em] text-text transition-colors group-hover:text-brand">
                    {item.name}
                </h2>

                <p className="mt-0.5 font-mono text-[10px] text-text-muted">
                    {item.code}
                </p>
            </div>

            {/* Key figures */}
            <div className="mt-4 grid grid-cols-3 border-y border-border bg-surface-muted/25">
                <MiniMetric
                    label="Schools"
                    value={
                        schools
                    }
                />

                <MiniMetric
                    label="Devices"
                    value={
                        devices
                    }
                    bordered
                />

                <MiniMetric
                    label="Learners"
                    value={
                        item.learners_count ??
                        0
                    }
                    bordered
                />
            </div>

            {/* Protection */}
            <div className="space-y-4 px-4 py-4">
                <CompactCoverage
                    label="Protection"
                    value={coverage}
                    detail={`${formatNumber(
                        protectedSchools,
                    )}/${formatNumber(
                        schools,
                    )} schools`}
                />

                <CompactCoverage
                    label="Attribution"
                    value={
                        attribution
                    }
                    detail={
                        attribution ===
                        null
                            ? 'No devices'
                            : `${formatNumber(
                                  attributed,
                              )}/${formatNumber(
                                  devices,
                              )} devices`
                    }
                />
            </div>

            {/* Issues */}
            <div className="flex items-center justify-between gap-3 border-t border-border px-4 py-3">
                <div className="flex min-w-0 items-center gap-3">
                    {incidents > 0 ? (
                        <CardIssue
                            icon={
                                ShieldAlertIcon
                            }
                            value={
                                incidents
                            }
                            label="incidents"
                            tone="danger"
                        />
                    ) : unprotected >
                      0 ? (
                        <CardIssue
                            icon={
                                AlertTriangleIcon
                            }
                            value={
                                unprotected
                            }
                            label="unprotected"
                            tone="warning"
                        />
                    ) : unattributed >
                      0 ? (
                        <CardIssue
                            icon={
                                AlertTriangleIcon
                            }
                            value={
                                unattributed
                            }
                            label="unattributed"
                            tone="warning"
                        />
                    ) : (
                        <CardIssue
                            icon={
                                ShieldCheckIcon
                            }
                            label="No immediate issues"
                            tone="success"
                        />
                    )}
                </div>

                <span className="shrink-0 text-[10px] font-semibold text-brand opacity-0 transition-opacity group-hover:opacity-100">
                    Open
                </span>
            </div>
        </button>
    );
}

function OverviewCard({
    icon: Icon,
    label,
    value,
    detail,
    tone = 'neutral',
}) {
    const tones = {
        neutral: {
            icon: 'bg-surface-muted text-text-secondary',
            value: 'text-text',
        },
        success: {
            icon: 'bg-success-soft text-success',
            value: 'text-text',
        },
        warning: {
            icon: 'bg-warning-soft text-warning',
            value: 'text-text',
        },
        danger: {
            icon: 'bg-danger-soft text-danger',
            value: 'text-danger',
        },
    };

    const style =
        tones[tone] ??
        tones.neutral;

    return (
        <div className="rounded-xl border border-border bg-surface p-4 shadow-card">
            <div
                className={`flex h-8 w-8 items-center justify-center rounded-lg ${style.icon}`}
            >
                <Icon size={15} />
            </div>

            <p
                className={`mt-4 text-[24px] font-bold tracking-[-0.035em] tabular-nums ${style.value}`}
            >
                {formatNumber(
                    value ?? 0,
                )}
            </p>

            <p className="mt-0.5 text-[11px] font-medium text-text-secondary">
                {label}
            </p>

            <p className="mt-2 truncate text-[10px] text-text-muted">
                {detail}
            </p>
        </div>
    );
}

function CountyCoverageBlock({
    icon: Icon,
    label,
    value,
    detail,
    bordered = false,
}) {
    const state =
        value === null
            ? null
            : getCoverageState(value);

    const safeValue =
        value === null
            ? null
            : Math.max(
                  0,
                  Math.min(100, value),
              );

    return (
        <div
            className={`p-4 sm:p-5 ${
                bordered
                    ? 'border-t border-border lg:border-l lg:border-t-0'
                    : ''
            }`}
        >
            <div className="flex items-start justify-between gap-4">
                <div className="flex items-center gap-3">
                    <div
                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
                            state?.iconClass ??
                            'bg-surface-muted text-text-secondary'
                        }`}
                    >
                        <Icon size={16} />
                    </div>

                    <div>
                        <p className="text-[11px] font-semibold text-text">
                            {label}
                        </p>

                        <p className="mt-0.5 text-[10px] text-text-muted">
                            {detail}
                        </p>
                    </div>
                </div>

                <p className="shrink-0 text-xl font-bold tracking-[-0.03em] tabular-nums text-text">
                    {safeValue ===
                    null
                        ? '—'
                        : formatPercent(
                              safeValue,
                              0,
                          )}
                </p>
            </div>

            <div className="mt-4 h-2 overflow-hidden rounded-full bg-surface-muted">
                {safeValue !==
                    null && (
                    <div
                        className={`h-full rounded-full transition-[width] duration-500 ease-gov ${
                            state?.barClass ??
                            'bg-brand'
                        }`}
                        style={{
                            width: `${safeValue}%`,
                        }}
                    />
                )}
            </div>

            <p
                className={`mt-2 text-[10px] font-medium ${
                    state?.textClass ??
                    'text-text-muted'
                }`}
            >
                {safeValue ===
                null
                    ? 'No data yet'
                    : state.label}
            </p>
        </div>
    );
}

function CompactCoverage({
    label,
    value,
    detail,
}) {
    const state =
        value === null
            ? null
            : getCoverageState(value);

    const safeValue =
        value === null
            ? null
            : Math.max(
                  0,
                  Math.min(100, value),
              );

    return (
        <div>
            <div className="mb-1.5 flex items-center justify-between gap-3">
                <span className="text-[10px] font-medium text-text-secondary">
                    {label}
                </span>

                <div className="flex items-center gap-2">
                    <span className="text-[9px] tabular-nums text-text-muted">
                        {detail}
                    </span>

                    <span className="min-w-8 text-right text-[10px] font-bold tabular-nums text-text">
                        {safeValue ===
                        null
                            ? '—'
                            : formatPercent(
                                  safeValue,
                                  0,
                              )}
                    </span>
                </div>
            </div>

            <div className="h-1.5 overflow-hidden rounded-full bg-surface-muted">
                {safeValue !==
                    null && (
                    <div
                        className={`h-full rounded-full transition-[width] duration-500 ease-gov ${
                            state?.barClass ??
                            'bg-brand'
                        }`}
                        style={{
                            width: `${safeValue}%`,
                        }}
                    />
                )}
            </div>
        </div>
    );
}

function MiniMetric({
    label,
    value,
    bordered = false,
}) {
    return (
        <div
            className={`px-3 py-2.5 ${
                bordered
                    ? 'border-l border-border'
                    : ''
            }`}
        >
            <p className="text-sm font-bold tabular-nums text-text">
                {formatNumber(
                    value ?? 0,
                )}
            </p>

            <p className="mt-0.5 text-[9px] uppercase tracking-[0.05em] text-text-muted">
                {label}
            </p>
        </div>
    );
}

function CardIssue({
    icon: Icon,
    value,
    label,
    tone,
}) {
    const styles = {
        success:
            'text-success',
        warning:
            'text-warning',
        danger:
            'text-danger',
    };

    return (
        <div
            className={`flex min-w-0 items-center gap-1.5 text-[10px] font-medium ${
                styles[tone] ??
                'text-text-muted'
            }`}
        >
            <Icon
                size={12}
                className="shrink-0"
            />

            {value !==
                undefined && (
                <span className="font-bold tabular-nums">
                    {formatNumber(
                        value,
                    )}
                </span>
            )}

            <span className="truncate">
                {label}
            </span>
        </div>
    );
}

function getCoverageState(value) {
    if (value >= 80) {
        return {
            label: 'Strong coverage',
            barClass:
                'bg-success',
            iconClass:
                'bg-success-soft text-success',
            textClass:
                'text-success',
        };
    }

    if (value >= 50) {
        return {
            label: 'Needs attention',
            barClass:
                'bg-warning',
            iconClass:
                'bg-warning-soft text-warning',
            textClass:
                'text-warning',
        };
    }

    return {
        label: 'Deployment required',
        barClass: 'bg-danger',
        iconClass:
            'bg-danger-soft text-danger',
        textClass:
            'text-danger',
    };
}

function getSubcountyPosture({
    coverage,
    unattributed,
    incidents,
}) {
    if (incidents > 0) {
        return {
            label: 'Incidents',
            iconClass:
                'bg-danger-soft text-danger',
            badgeClass:
                'bg-danger-soft text-danger',
        };
    }

    if (
        coverage >= 80 &&
        unattributed === 0
    ) {
        return {
            label: 'Healthy',
            iconClass:
                'bg-success-soft text-success',
            badgeClass:
                'bg-success-soft text-success',
        };
    }

    if (coverage >= 50) {
        return {
            label: 'Attention',
            iconClass:
                'bg-warning-soft text-warning',
            badgeClass:
                'bg-warning-soft text-warning',
        };
    }

    return {
        label: 'Deployment',
        iconClass:
            'bg-brand-soft text-brand',
        badgeClass:
            'bg-brand-soft text-brand',
    };
}

function SubcountiesSkeleton() {
    return (
        <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {Array.from({
                    length: 4,
                }).map((_, index) => (
                    <Skeleton
                        key={index}
                        className="h-32 rounded-xl"
                    />
                ))}
            </div>

            <Skeleton className="mt-4 h-36 rounded-xl" />

            <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {Array.from({
                    length: 6,
                }).map((_, index) => (
                    <Skeleton
                        key={index}
                        className="h-72 rounded-xl"
                    />
                ))}
            </div>
        </>
    );
}

function CreateSubcountyModal({
    open,
    onClose,
}) {
    const create =
        useCreateSubcounty();

    const [name, setName] =
        useState('');

    const [code, setCode] =
        useState('');

    const [
        codeEdited,
        setCodeEdited,
    ] = useState(false);

    useEffect(() => {
        if (!open) return;

        setName('');
        setCode('');
        setCodeEdited(false);
        create.reset();

        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const errors =
        create.error instanceof ApiError
            ? create.error.errors
            : {};

    function generateCode(value) {
        const normalized = value
            .trim()
            .toUpperCase()
            .replace(/[^A-Z]/g, '')
            .slice(0, 3);

        return normalized
            ? `KBU-${normalized}`
            : '';
    }

    async function submit(event) {
        event.preventDefault();

        try {
            const subcounty =
                await create.mutateAsync({
                    name: name.trim(),
                    code: code.trim(),
                });

            showToast(
                `${subcounty.data?.name ?? name} added`,
                {
                    description:
                        'The sub-county is now available for school registration.',
                },
            );

            onClose();
        } catch (error) {
            if (
                !(
                    error instanceof
                        ApiError &&
                    error.isValidation
                )
            ) {
                toastError(
                    error,
                    'The sub-county could not be added',
                );
            }
        }
    }

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Add sub-county"
            subtitle="Create an administrative unit within Kiambu County."
            size="sm"
            footer={
                <>
                    <Button
                        className="flex-1"
                        onClick={
                            onClose
                        }
                        disabled={
                            create.isPending
                        }
                    >
                        Cancel
                    </Button>

                    <Button
                        className="flex-1"
                        variant="primary"
                        form="create-subcounty"
                        type="submit"
                        loading={
                            create.isPending
                        }
                        disabled={
                            !name.trim() ||
                            !code.trim()
                        }
                    >
                        Add sub-county
                    </Button>
                </>
            }
        >
            <form
                id="create-subcounty"
                onSubmit={submit}
                className="space-y-4"
                noValidate
            >
                <div className="rounded-lg border border-border bg-surface-muted/35 px-3 py-2.5">
                    <div className="flex gap-2.5">
                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-brand-soft text-brand">
                            <MapPinIcon
                                size={13}
                            />
                        </div>

                        <p className="text-[10px] leading-4 text-text-secondary">
                            Only the
                            County Director
                            of Education
                            may change the
                            county
                            administrative
                            structure.
                        </p>
                    </div>
                </div>

                <Field
                    label="Sub-county name"
                    required
                    error={firstError(
                        errors,
                        'name',
                    )}
                >
                    <TextInput
                        data-autofocus
                        value={name}
                        onChange={(
                            event,
                        ) => {
                            const value =
                                event.target
                                    .value;

                            setName(value);

                            if (
                                !codeEdited
                            ) {
                                setCode(
                                    generateCode(
                                        value,
                                    ),
                                );
                            }
                        }}
                        placeholder="e.g. Githunguri"
                    />
                </Field>

                <Field
                    label="Reference code"
                    required
                    hint="Used in reports and device enrolment records."
                    error={firstError(
                        errors,
                        'code',
                    )}
                >
                    <div className="relative">
                        <TextInput
                            value={code}
                            maxLength={20}
                            className="font-mono uppercase"
                            onChange={(
                                event,
                            ) => {
                                setCodeEdited(
                                    true,
                                );

                                setCode(
                                    event.target.value
                                        .toUpperCase()
                                        .replace(
                                            /[^A-Z0-9-]/g,
                                            '',
                                        ),
                                );
                            }}
                            placeholder="KBU-GIT"
                        />

                        {!codeEdited &&
                            code && (
                                <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[9px] font-medium text-text-muted">
                                    Auto
                                </span>
                            )}
                    </div>
                </Field>

                <p className="text-[10px] leading-4 text-text-muted">
                    You can edit the
                    suggested reference
                    code before saving.
                    Once edited, it will
                    no longer change
                    automatically with the
                    name.
                </p>
            </form>
        </Modal>
    );
}