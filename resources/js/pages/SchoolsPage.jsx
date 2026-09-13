import { Link, useNavigate } from 'react-router-dom';
import { Building2Icon, PlusIcon, XIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import {
    Button,
    Cell,
    DataTable,
    EmptyState,
    FilterSelect,
    Pagination,
    Panel,
    Row,
    SearchInput,
} from '../components/Primitives';
import { StatusPill } from '../components/StatusPill';
import { useDebouncedValue, useListState } from '../lib/hooks';
import { useAuth } from '../lib/auth';
import { useScope } from '../lib/scope';
import { useInstitutions, useSubcounties } from '../lib/queries';
import { INSTITUTION_STATUS, INSTITUTION_TYPES, institutionStatus } from '../lib/domain';
import { capabilitiesFor } from '../lib/permissions';
import { formatNumber } from '../lib/format';

/** Posture bands from the dashboard resolve to a set of lifecycle statuses. */
const BANDS = {
    Protected: 'protected',
    'Attention Required': 'attention_required,attribution_required',
    'Deployment in Progress': 'approved,onboarding,deployment_in_progress',
    'Not Yet Deployed': 'draft,pending_approval,rejected,suspended',
};

export function SchoolsPage() {
    const navigate = useNavigate();
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const scope = useScope();
    const list = useListState({ search: '', status: '', subcounty: '', band: '' });
    const search = useDebouncedValue(list.values.search, 300);

    const status = list.values.band ? BANDS[list.values.band] : list.values.status;
    const institutions = useInstitutions({
        page: list.page,
        search: search || undefined,
        status: status || undefined,
        subcounty_id: list.values.subcounty || scope.subcounty?.id || undefined,
    });

    const subcounties = useSubcounties();
    const rows = institutions.data?.data ?? [];

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Schools"
                description="Onboarding, deployment and protection status for institutions in your scope."
                meta={institutions.data?.meta ? `${formatNumber(institutions.data.meta.total)} in the register` : undefined}
                actions={
                    can.registerSchools && (
                        <Button as={Link} to="/schools/register" variant="primary" icon={PlusIcon}>
                            Register school
                        </Button>
                    )
                }
            />

            <Panel>
                <div className="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <SearchInput
                        value={list.values.search}
                        onChange={(value) => list.setValue('search', value)}
                        placeholder="Search school name or NEMIS code"
                    />

                    <FilterSelect
                        label="Protection status"
                        value={list.values.status}
                        onChange={(value) => {
                            list.setValue('band', '');
                            list.setValue('status', value);
                        }}
                        options={Object.entries(INSTITUTION_STATUS).map(([value, meta]) => [value, meta.label])}
                    />

                    {can.viewSubcounties && (
                        <FilterSelect
                            label="Sub-county"
                            value={list.values.subcounty}
                            onChange={(value) => list.setValue('subcounty', value)}
                            options={(subcounties.data?.data ?? []).map((item) => [String(item.id), item.name])}
                        />
                    )}

                    {list.values.band && (
                        <span className="flex items-center gap-1.5 rounded-lg bg-brand-soft px-2.5 py-1.5 text-[11px] font-semibold text-brand">
                            Band: {list.values.band}
                            <button onClick={() => list.setValue('band', '')} aria-label="Clear band filter">
                                <XIcon size={12} />
                            </button>
                        </span>
                    )}

                    {list.isFiltered && (
                        <Button variant="ghost" size="sm" icon={XIcon} onClick={list.reset}>
                            Clear
                        </Button>
                    )}
                </div>

                <DataTable
                    query={institutions}
                    rows={rows}
                    minWidth="880px"
                    columns={[
                        'School',
                        'Sub-county',
                        'Type',
                        { key: 'learners', label: 'Learners', align: 'right' },
                        { key: 'devices', label: 'Devices', align: 'right' },
                        'Status',
                    ]}
                    empty={
                        <EmptyState
                            icon={Building2Icon}
                            title={list.isFiltered ? 'No schools match these filters' : 'The register is empty'}
                            description={
                                list.isFiltered
                                    ? 'Adjust the search term or clear a filter to widen the results.'
                                    : 'Register the first institution to begin enrolling learners and devices.'
                            }
                            action={
                                list.isFiltered ? (
                                    <Button size="sm" onClick={list.reset}>
                                        Clear filters
                                    </Button>
                                ) : null
                            }
                        />
                    }
                >
                    {rows.map((school) => (
                        <Row
                            key={school.id}
                            className="text-sm"
                            onClick={() => {
                                scope.selectInstitution(school);
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
                            <Cell muted>{school.subcounty?.name ?? '—'}</Cell>
                            <Cell>{INSTITUTION_TYPES[school.institution_type] ?? '—'}</Cell>
                            <Cell align="right" className="tabular-nums">
                                {formatNumber(school.learners_count ?? school.learner_population)}
                            </Cell>
                            <Cell align="right" className="tabular-nums">
                                {formatNumber(school.devices_count ?? school.computing_devices_count)}
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
