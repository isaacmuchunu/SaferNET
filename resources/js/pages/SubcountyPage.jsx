import { useEffect } from 'react';
import {
    Link,
    useNavigate,
    useParams,
} from 'react-router-dom';
import {
    Building2Icon,
    ChevronRightIcon,
    GraduationCapIcon,
    MonitorCogIcon,
    ShieldAlertIcon,
    ShieldCheckIcon,
    UsersRoundIcon,
    XIcon,
} from 'lucide-react';

import { PageHeader } from '../components/PageHeader';

import {
    Button,
    Cell,
    DataTable,
    EmptyState,
    ErrorState,
    Pagination,
    Panel,
    Row,
    SearchInput,
    Skeleton,
} from '../components/Primitives';

import { StatusPill } from '../components/StatusPill';
import {
    useDebouncedValue,
    useListState,
} from '../lib/hooks';
import { useScope } from '../lib/scope';
import {
    useInstitutions,
    useSubcounty,
} from '../lib/queries';
import {
    INSTITUTION_TYPES,
    institutionStatus,
} from '../lib/domain';
import {
    formatNumber,
    formatPercent,
} from '../lib/format';

/**
 * One sub-county: protection posture, operational issues
 * and the institutions within its scope.
 *
 * Selecting a school establishes that institution as the
 * working scope for learner, device and safeguarding registers.
 */
export function SubcountyPage() {
    const { subcountyId } = useParams();
    const navigate = useNavigate();
    const scope = useScope();

    const list = useListState({
        search: '',
    });

    const search = useDebouncedValue(
        list.values.search,
        300,
    );

    const subcounty =
        useSubcounty(subcountyId);

    const institutions =
        useInstitutions({
            page: list.page,
            subcounty_id: subcountyId,
            search: search || undefined,
        });

    /*
     * Entering the sub-county page establishes the
     * current sub-county scope.
     */
    useEffect(() => {
        if (
            subcounty.data &&
            scope.subcounty?.id !==
                subcounty.data.id
        ) {
            scope.selectSubcounty(
                subcounty.data,
            );
        }

        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [subcounty.data?.id]);

    if (subcounty.isPending) {
        return (
            <div className="space-y-4">
                <Skeleton className="h-8 w-72" />

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    {Array.from({
                        length: 5,
                    }).map((_, index) => (
                        <Skeleton
                            key={index}
                            className="h-28 rounded-xl"
                        />
                    ))}
                </div>

                <Skeleton className="h-44 w-full rounded-xl" />
                <Skeleton className="h-72 w-full rounded-xl" />
            </div>
        );
    }

    if (subcounty.isError) {
        return (
            <Panel>
                <ErrorState
                    error={subcounty.error}
                    onRetry={
                        subcounty.refetch
                    }
                />
            </Panel>
        );
    }

    const record = subcounty.data;

    const rows =
        institutions.data?.data ?? [];

    const totalSchools =
        record.institutions_count ?? 0;

    const protectedSchools =
        record.protected_institutions_count ??
        0;

    const unprotectedSchools =
        Math.max(
            0,
            totalSchools -
                protectedSchools,
        );

    const totalDevices =
        record.devices_count ?? 0;

    const unattributedDevices =
        record.unattributed_devices_count ??
        0;

    const attributedDevices =
        Math.max(
            0,
            totalDevices -
                unattributedDevices,
        );

    const coverage = totalSchools
        ? (protectedSchools /
              totalSchools) *
          100
        : 0;

    const attribution =
        totalDevices > 0
            ? (attributedDevices /
                  totalDevices) *
              100
            : null;

    const coverageState =
        getCoverageState(coverage);

    const attributionState =
        attribution === null
            ? null
            : getCoverageState(
                  attribution,
              );

    const figures = [
        {
            label: 'Schools',
            value: totalSchools,
            detail:
                unprotectedSchools > 0
                    ? `${formatNumber(
                          unprotectedSchools,
                      )} need protection`
                    : 'All schools protected',
            icon: Building2Icon,
            to: null,
            tone:
                unprotectedSchools > 0
                    ? 'warning'
                    : 'success',
        },
        {
            label: 'Learners',
            value:
                record.learners_count ??
                0,
            detail:
                'On the safety register',
            icon: GraduationCapIcon,
            to: `/learners?subcounty=${record.id}`,
            tone: 'neutral',
        },
        {
            label: 'Devices',
            value: totalDevices,
            detail:
                unattributedDevices > 0
                    ? `${formatNumber(
                          unattributedDevices,
                      )} need attribution`
                    : totalDevices > 0
                      ? 'All devices attributed'
                      : 'No devices enrolled',
            icon: MonitorCogIcon,
            to: `/devices?subcounty=${record.id}`,
            tone:
                unattributedDevices > 0
                    ? 'warning'
                    : 'neutral',
        },
        {
            label: 'Open incidents',
            value:
                record.open_incidents_count ??
                0,
            detail:
                record.open_incidents_count >
                0
                    ? 'Awaiting review or action'
                    : 'No open incidents',
            icon: ShieldAlertIcon,
            to: `/incidents?subcounty=${record.id}`,
            tone:
                record.open_incidents_count >
                0
                    ? 'danger'
                    : 'success',
        },
        {
            label: 'Officers',
            value: 'View',
            detail:
                'Directors, heads and managers',
            icon: UsersRoundIcon,
            to: `/administrators?subcounty=${record.id}`,
            tone: 'neutral',
        },
    ];

    function openSchool(school) {
        scope.selectInstitution({
            ...school,
            subcounty: record,
        });

        navigate(
            `/schools/${school.id}`,
        );
    }

    return (
        <div className="animate-fade-up">
            <PageHeader
                breadcrumbs={[
                    {
                        label: 'Sub-counties',
                        to: '/subcounties',
                    },
                    {
                        label: record.name,
                    },
                ]}
                title={`${record.name} Sub-county`}
                description="Protection posture, learner attribution and safeguarding activity across schools in this sub-county."
                meta={`Code ${record.code}`}
                actions={
                    <Button
                        as={Link}
                        to={`/schools?subcounty=${record.id}`}
                        variant="secondary"
                    >
                        School register
                    </Button>
                }
            />

            {/* ─────────────────────────────────────────────
                OPERATIONAL SUMMARY
            ───────────────────────────────────────────── */}
            <section className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                {figures.map(
                    (figure) => (
                        <MetricCard
                            key={
                                figure.label
                            }
                            {...figure}
                        />
                    ),
                )}
            </section>

            {/* ─────────────────────────────────────────────
                PROTECTION POSTURE
            ───────────────────────────────────────────── */}
            <Panel className="mt-4 overflow-hidden">
                <div className="grid lg:grid-cols-[1fr_1fr_280px]">
                    {/* School protection */}
                    <CoverageBlock
                        icon={
                            ShieldCheckIcon
                        }
                        label="School protection"
                        value={coverage}
                        numerator={
                            protectedSchools
                        }
                        denominator={
                            totalSchools
                        }
                        denominatorLabel="schools"
                        state={
                            coverageState
                        }
                        detail={
                            unprotectedSchools >
                            0
                                ? `${formatNumber(
                                      unprotectedSchools,
                                  )} ${
                                      unprotectedSchools ===
                                      1
                                          ? 'school is'
                                          : 'schools are'
                                  } not yet fully protected`
                                : totalSchools >
                                    0
                                  ? 'Every school is fully protected'
                                  : 'No schools registered'
                        }
                    />

                    {/* Device attribution */}
                    <CoverageBlock
                        icon={
                            MonitorCogIcon
                        }
                        label="Device attribution"
                        value={attribution}
                        numerator={
                            attributedDevices
                        }
                        denominator={
                            totalDevices
                        }
                        denominatorLabel="devices"
                        state={
                            attributionState
                        }
                        detail={
                            totalDevices ===
                            0
                                ? 'No devices enrolled'
                                : unattributedDevices >
                                    0
                                  ? `${formatNumber(
                                        unattributedDevices,
                                    )} ${
                                        unattributedDevices ===
                                        1
                                            ? 'device needs'
                                            : 'devices need'
                                    } learner attribution`
                                  : 'Every enrolled device is attributed'
                        }
                        bordered
                    />

                    {/* Attention rail */}
                    <div className="border-t border-border bg-surface-muted/35 p-4 lg:border-l lg:border-t-0">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.08em] text-text-muted">
                            Attention
                        </p>

                        <div className="mt-3 space-y-2.5">
                            <AttentionItem
                                label="Unprotected schools"
                                value={
                                    unprotectedSchools
                                }
                                tone={
                                    unprotectedSchools >
                                    0
                                        ? 'warning'
                                        : 'success'
                                }
                            />

                            <AttentionItem
                                label="Unattributed devices"
                                value={
                                    unattributedDevices
                                }
                                tone={
                                    unattributedDevices >
                                    0
                                        ? 'warning'
                                        : 'success'
                                }
                            />

                            <AttentionItem
                                label="Open incidents"
                                value={
                                    record.open_incidents_count ??
                                    0
                                }
                                tone={
                                    record.open_incidents_count >
                                    0
                                        ? 'danger'
                                        : 'success'
                                }
                            />
                        </div>
                    </div>
                </div>
            </Panel>

            {/* ─────────────────────────────────────────────
                SCHOOL REGISTER
            ───────────────────────────────────────────── */}
            <Panel className="mt-4 overflow-hidden">
                <div className="flex flex-col gap-3 border-b border-border px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <h2 className="text-sm font-semibold text-text">
                                Schools
                            </h2>

                            {institutions
                                .data
                                ?.meta?.total !==
                                undefined && (
                                <span className="rounded-full bg-surface-muted px-2 py-0.5 text-[10px] font-semibold tabular-nums text-text-muted">
                                    {formatNumber(
                                        institutions
                                            .data
                                            .meta
                                            .total,
                                    )}
                                </span>
                            )}
                        </div>

                        <p className="mt-0.5 text-[11px] text-text-secondary">
                            Open a school
                            to work with its
                            learners,
                            devices,
                            officers and
                            safeguarding
                            records.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <SearchInput
                            value={
                                list.values
                                    .search
                            }
                            onChange={(
                                value,
                            ) =>
                                list.setValue(
                                    'search',
                                    value,
                                )
                            }
                            placeholder="Search school or NEMIS code"
                            className="min-w-[240px]"
                        />

                        {list.values
                            .search && (
                            <Button
                                size="sm"
                                variant="ghost"
                                icon={XIcon}
                                onClick={() =>
                                    list.setValue(
                                        'search',
                                        '',
                                    )
                                }
                            >
                                Clear
                            </Button>
                        )}
                    </div>
                </div>

                <DataTable
                    query={institutions}
                    rows={rows}
                    minWidth="860px"
                    columns={[
                        'School',
                        'Type',
                        {
                            key: 'learners',
                            label: 'Learners',
                            align: 'right',
                        },
                        {
                            key: 'devices',
                            label: 'Devices',
                            align: 'right',
                        },
                        'Status',
                        {
                            key: 'open',
                            label: '',
                            align: 'right',
                        },
                    ]}
                    empty={
                        <EmptyState
                            icon={
                                Building2Icon
                            }
                            title={
                                search
                                    ? 'No schools match your search'
                                    : 'No schools in this sub-county'
                            }
                            description={
                                search
                                    ? `No school matched "${search}". Try a school name or NEMIS code.`
                                    : 'Schools registered in this sub-county will appear here.'
                            }
                            action={
                                search ? (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            list.setValue(
                                                'search',
                                                '',
                                            )
                                        }
                                    >
                                        Clear
                                        search
                                    </Button>
                                ) : null
                            }
                        />
                    }
                >
                    {rows.map(
                        (school) => (
                            <Row
                                key={
                                    school.id
                                }
                                className="group text-sm"
                                onClick={() =>
                                    openSchool(
                                        school,
                                    )
                                }
                            >
                                <Cell>
                                    <div className="flex min-w-0 items-center gap-3">
                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand">
                                            <Building2Icon
                                                size={
                                                    15
                                                }
                                            />
                                        </div>

                                        <div className="min-w-0">
                                            <p className="truncate font-semibold text-text transition-colors group-hover:text-brand">
                                                {
                                                    school.name
                                                }
                                            </p>

                                            <p className="mt-0.5 truncate font-mono text-[10px] text-text-muted">
                                                NEMIS{' '}
                                                {school.nemis_code ??
                                                    '—'}
                                            </p>
                                        </div>
                                    </div>
                                </Cell>

                                <Cell muted>
                                    {INSTITUTION_TYPES[
                                        school
                                            .institution_type
                                    ] ??
                                        '—'}
                                </Cell>

                                <Cell
                                    align="right"
                                    className="font-medium tabular-nums"
                                >
                                    {formatNumber(
                                        school.learners_count ??
                                            0,
                                    )}
                                </Cell>

                                <Cell
                                    align="right"
                                    className="font-medium tabular-nums"
                                >
                                    {formatNumber(
                                        school.devices_count ??
                                            0,
                                    )}
                                </Cell>

                                <Cell>
                                    <StatusPill
                                        descriptor={institutionStatus(
                                            school.status,
                                        )}
                                    />
                                </Cell>

                                <Cell align="right">
                                    <span className="inline-flex h-7 w-7 items-center justify-center rounded-md text-text-muted transition-all group-hover:bg-brand-soft group-hover:text-brand">
                                        <ChevronRightIcon
                                            size={
                                                15
                                            }
                                        />
                                    </span>
                                </Cell>
                            </Row>
                        ),
                    )}
                </DataTable>

                <Pagination
                    meta={
                        institutions.data
                            ?.meta
                    }
                    onChange={
                        list.setPage
                    }
                    unit="schools"
                />
            </Panel>
        </div>
    );
}

function MetricCard({
    label,
    value,
    detail,
    icon: Icon,
    to,
    tone = 'neutral',
}) {
    const styles = {
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
        styles[tone] ??
        styles.neutral;

    const body = (
        <div className="relative h-full overflow-hidden rounded-xl border border-border bg-surface p-4 shadow-card transition-all duration-150">
            <div className="flex items-start justify-between gap-3">
                <div
                    className={`flex h-8 w-8 items-center justify-center rounded-lg ${style.icon}`}
                >
                    <Icon size={15} />
                </div>

                {to && (
                    <ChevronRightIcon
                        size={14}
                        className="mt-1 text-text-muted transition-transform duration-150 group-hover:translate-x-0.5 group-hover:text-brand"
                    />
                )}
            </div>

            <p
                className={`mt-4 text-[24px] font-bold tracking-[-0.035em] tabular-nums ${style.value}`}
            >
                {typeof value ===
                'number'
                    ? formatNumber(
                          value,
                      )
                    : value}
            </p>

            <p className="mt-0.5 text-[11px] font-medium text-text-secondary">
                {label}
            </p>

            <p className="mt-2 truncate text-[10px] text-text-muted">
                {detail}
            </p>
        </div>
    );

    if (!to) {
        return body;
    }

    return (
        <Link
            to={to}
            className="group block rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2"
        >
            {body}
        </Link>
    );
}

function CoverageBlock({
    icon: Icon,
    label,
    value,
    numerator,
    denominator,
    denominatorLabel,
    detail,
    state,
    bordered = false,
}) {
    const percentage =
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
                <div className="flex min-w-0 items-center gap-3">
                    <div
                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
                            state?.iconClass ??
                            'bg-surface-muted text-text-secondary'
                        }`}
                    >
                        <Icon size={16} />
                    </div>

                    <div className="min-w-0">
                        <p className="text-[11px] font-semibold text-text">
                            {label}
                        </p>

                        <p className="mt-0.5 truncate text-[10px] text-text-muted">
                            {detail}
                        </p>
                    </div>
                </div>

                <div className="shrink-0 text-right">
                    <p className="text-xl font-bold tracking-[-.03em] tabular-nums text-text">
                        {percentage ===
                        null
                            ? '—'
                            : formatPercent(
                                  percentage,
                                  0,
                              )}
                    </p>

                    <p className="text-[9px] tabular-nums text-text-muted">
                        {formatNumber(
                            numerator,
                        )}{' '}
                        /{' '}
                        {formatNumber(
                            denominator,
                        )}{' '}
                        {
                            denominatorLabel
                        }
                    </p>
                </div>
            </div>

            <div className="mt-4 h-2 overflow-hidden rounded-full bg-surface-muted">
                {percentage !==
                    null && (
                    <div
                        className={`h-full rounded-full transition-[width] duration-500 ease-gov ${
                            state?.barClass ??
                            'bg-brand'
                        }`}
                        style={{
                            width: `${percentage}%`,
                        }}
                    />
                )}
            </div>

            <div className="mt-2 flex items-center justify-between gap-3">
                <span
                    className={`text-[10px] font-medium ${
                        state?.textClass ??
                        'text-text-muted'
                    }`}
                >
                    {percentage === null
                        ? 'No data yet'
                        : state?.label}
                </span>

                {percentage !==
                    null && (
                    <span className="text-[9px] tabular-nums text-text-muted">
                        {formatPercent(
                            percentage,
                            0,
                        )}{' '}
                        complete
                    </span>
                )}
            </div>
        </div>
    );
}

function AttentionItem({
    label,
    value,
    tone,
}) {
    const styles = {
        success:
            'bg-success-soft text-success',
        warning:
            'bg-warning-soft text-warning',
        danger:
            'bg-danger-soft text-danger',
    };

    return (
        <div className="flex items-center justify-between gap-3">
            <span className="text-[10px] text-text-secondary">
                {label}
            </span>

            <span
                className={`min-w-7 rounded-md px-2 py-1 text-center text-[10px] font-bold tabular-nums ${
                    styles[tone] ??
                    'bg-surface-muted text-text'
                }`}
            >
                {formatNumber(
                    value ?? 0,
                )}
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
            label: 'Needs improvement',
            barClass:
                'bg-warning',
            iconClass:
                'bg-warning-soft text-warning',
            textClass:
                'text-warning',
        };
    }

    return {
        label: 'Action required',
        barClass: 'bg-danger',
        iconClass:
            'bg-danger-soft text-danger',
        textClass:
            'text-danger',
    };
}