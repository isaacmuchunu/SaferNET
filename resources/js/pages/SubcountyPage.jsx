import { useEffect } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Building2Icon, GraduationCapIcon, MonitorCogIcon, ShieldAlertIcon, UsersRoundIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import {
    Button,
    Cell,
    DataTable,
    EmptyState,
    ErrorState,
    Pagination,
    Panel,
    PanelHeader,
    Row,
    SearchInput,
    Skeleton,
} from '../components/Primitives';
import { StatusPill } from '../components/StatusPill';
import { useDebouncedValue, useListState } from '../lib/hooks';
import { useScope } from '../lib/scope';
import { useInstitutions, useSubcounty } from '../lib/queries';
import { INSTITUTION_TYPES, institutionStatus } from '../lib/domain';
import { formatNumber, formatPercent } from '../lib/format';

/**
 * One sub-county: its protection figures, and the schools inside it. Choosing a
 * school here sets the working scope, so every register that follows answers
 * for that school alone.
 */
export function SubcountyPage() {
    const { subcountyId } = useParams();
    const navigate = useNavigate();
    const scope = useScope();
    const list = useListState({ search: '' });
    const search = useDebouncedValue(list.values.search, 300);

    const subcounty = useSubcounty(subcountyId);
    const institutions = useInstitutions({ page: list.page, subcounty_id: subcountyId, search: search || undefined });

    // Entering the page is itself the drill-down step.
    useEffect(() => {
        if (subcounty.data && scope.subcounty?.id !== subcounty.data.id) {
            scope.selectSubcounty(subcounty.data);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [subcounty.data?.id]);

    if (subcounty.isPending) {
        return (
            <div className="space-y-4">
                <Skeleton className="h-8 w-72" />
                <Skeleton className="h-28 w-full rounded-xl" />
                <Skeleton className="h-64 w-full rounded-xl" />
            </div>
        );
    }

    if (subcounty.isError) {
        return (
            <Panel>
                <ErrorState error={subcounty.error} onRetry={subcounty.refetch} />
            </Panel>
        );
    }

    const record = subcounty.data;
    const rows = institutions.data?.data ?? [];
    const coverage = record.institutions_count ? (record.protected_institutions_count / record.institutions_count) * 100 : 0;
    const attribution = record.devices_count
        ? ((record.devices_count - record.unattributed_devices_count) / record.devices_count) * 100
        : null;

    const figures = [
        { label: 'Schools', value: record.institutions_count, detail: `${formatNumber(record.protected_institutions_count)} protected`, icon: Building2Icon, to: null },
        { label: 'Learners', value: record.learners_count, detail: 'On the safety register', icon: GraduationCapIcon, to: `/learners?subcounty=${record.id}` },
        { label: 'Devices', value: record.devices_count, detail: `${formatNumber(record.unattributed_devices_count)} need attribution`, icon: MonitorCogIcon, to: `/devices?subcounty=${record.id}` },
        { label: 'Open incidents', value: record.open_incidents_count, detail: 'Awaiting review or action', icon: ShieldAlertIcon, to: `/incidents?subcounty=${record.id}`, emphasis: record.open_incidents_count > 0 },
        { label: 'Officers', value: null, detail: 'Directors, heads and managers', icon: UsersRoundIcon, to: `/administrators?subcounty=${record.id}` },
    ];

    return (
        <div className="animate-fade-up">
            <PageHeader
                breadcrumbs={[{ label: 'Sub-counties', to: '/subcounties' }, { label: record.name }]}
                title={`${record.name} Sub-county`}
                description="Protection coverage across the schools of this sub-county. Open a school to work inside its registers."
                meta={`Code ${record.code}`}
                actions={
                    <Button as={Link} to={`/schools?subcounty=${record.id}`} variant="secondary">
                        All schools in register
                    </Button>
                }
            />

            <div className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border shadow-card lg:grid-cols-5">
                {figures.map((figure) => {
                    const body = (
                        <>
                            <div className="flex items-center gap-2">
                                <figure.icon size={14} className="text-text-muted" />
                                <p className="truncate text-[11px] text-text-secondary">{figure.label}</p>
                            </div>
                            <p className={`mt-1 text-[22px] font-bold tracking-[-.03em] tabular-nums ${figure.emphasis ? 'text-danger' : ''}`}>
                                {figure.value === null ? '—' : formatNumber(figure.value)}
                            </p>
                            <p className="mt-0.5 truncate text-[11px] text-text-muted">{figure.detail}</p>
                        </>
                    );

                    return figure.to ? (
                        <Link key={figure.label} to={figure.to} className="bg-white px-4 py-4 transition-colors hover:bg-brand-soft/40">
                            {body}
                        </Link>
                    ) : (
                        <div key={figure.label} className="bg-white px-4 py-4">
                            {body}
                        </div>
                    );
                })}
            </div>

            <Panel className="mt-4">
                <PanelHeader
                    title="Protection coverage"
                    description={`${record.protected_institutions_count} of ${record.institutions_count} schools fully protected`}
                />
                <div className="px-5 py-4">
                    <div className="h-2 overflow-hidden rounded-full bg-surface-muted">
                        <div
                            className={`h-full rounded-full transition-[width] duration-500 ease-gov ${coverage >= 80 ? 'bg-success' : coverage >= 50 ? 'bg-warning' : 'bg-danger'}`}
                            style={{ width: `${coverage}%` }}
                        />
                    </div>
                    <div className="mt-2 flex flex-wrap justify-between gap-3 text-[11px] text-text-secondary tabular-nums">
                        <span>{formatPercent(coverage, 0)} of schools protected</span>
                        <span>{attribution === null ? 'No devices enrolled' : `${formatPercent(attribution, 0)} of devices attributed`}</span>
                    </div>
                </div>
            </Panel>

            <Panel className="mt-4">
                <PanelHeader
                    title="Schools"
                    description="Open a school to see its learners, devices, officers and incidents"
                    actions={
                        <SearchInput
                            value={list.values.search}
                            onChange={(value) => list.setValue('search', value)}
                            placeholder="Search school or NEMIS code"
                            className="min-w-[220px]"
                        />
                    }
                />
                <DataTable
                    query={institutions}
                    rows={rows}
                    minWidth="820px"
                    columns={[
                        'School',
                        'Type',
                        { key: 'learners', label: 'Learners', align: 'right' },
                        { key: 'devices', label: 'Devices', align: 'right' },
                        'Status',
                    ]}
                    empty={
                        <EmptyState
                            icon={Building2Icon}
                            title="No schools in this sub-county"
                            description="Schools registered here will appear once submitted to the county register."
                        />
                    }
                >
                    {rows.map((school) => (
                        <Row
                            key={school.id}
                            className="text-sm"
                            onClick={() => {
                                scope.selectInstitution({ ...school, subcounty: record });
                                navigate(`/schools/${school.id}`);
                            }}
                        >
                            <Cell>
                                <span className="flex items-center gap-2 font-semibold text-brand">
                                    <Building2Icon size={15} className="shrink-0" />
                                    {school.name}
                                </span>
                                <span className="mt-0.5 block font-mono text-[11px] text-text-muted">NEMIS {school.nemis_code}</span>
                            </Cell>
                            <Cell muted>{INSTITUTION_TYPES[school.institution_type] ?? '—'}</Cell>
                            <Cell align="right" className="tabular-nums">
                                {formatNumber(school.learners_count ?? 0)}
                            </Cell>
                            <Cell align="right" className="tabular-nums">
                                {formatNumber(school.devices_count ?? 0)}
                            </Cell>
                            <Cell>
                                <StatusPill descriptor={institutionStatus(school.status)} />
                            </Cell>
                        </Row>
                    ))}
                </DataTable>

                <Pagination meta={institutions.data?.meta} onChange={list.setPage} unit="schools" />
            </Panel>
        </div>
    );
}
