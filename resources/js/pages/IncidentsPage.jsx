import { useNavigate } from 'react-router-dom';
import { ShieldAlertIcon, XIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import { Button, Cell, DataTable, EmptyState, FilterSelect, Pagination, Panel, Row } from '../components/Primitives';
import { StatusPill } from '../components/StatusPill';
import { useListState } from '../lib/hooks';
import { useScope } from '../lib/scope';
import { useIncidents } from '../lib/queries';
import { INCIDENT_STATUS, SEVERITY } from '../lib/domain';
import { formatNumber, formatRelative } from '../lib/format';

export function IncidentsPage() {
    const navigate = useNavigate();
    const scope = useScope();
    const list = useListState({ status: '', severity: '' });

    const incidents = useIncidents({
        ...scope.params,
        page: list.page,
        status: list.values.status || undefined,
        severity: list.values.severity || undefined,
    });

    const rows = incidents.data?.data ?? [];

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Learner Safety Incidents"
                description="Incidents raised when learner-initiated activity meets a filtering policy threshold."
                meta={incidents.data?.meta ? `${formatNumber(incidents.data.meta.total)} in scope` : undefined}
            />

            <Panel>
                <div className="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <FilterSelect
                        label="Status"
                        value={list.values.status}
                        onChange={(value) => list.setValue('status', value)}
                        options={Object.entries(INCIDENT_STATUS).map(([value, meta]) => [value, meta.label])}
                    />
                    <FilterSelect
                        label="Severity"
                        value={list.values.severity}
                        onChange={(value) => list.setValue('severity', value)}
                        options={Object.entries(SEVERITY).map(([value, meta]) => [value, meta.label])}
                    />
                    {list.isFiltered && (
                        <Button variant="ghost" size="sm" icon={XIcon} onClick={list.reset}>
                            Clear
                        </Button>
                    )}
                </div>

                <DataTable
                    query={incidents}
                    rows={rows}
                    minWidth="980px"
                    columns={['Incident', 'Learner', 'School', 'Device', 'Category', 'Severity', 'Events', 'Last detected', 'Status']}
                    empty={
                        <EmptyState
                            icon={ShieldAlertIcon}
                            title={list.isFiltered ? 'No incidents match these filters' : 'No incidents raised'}
                            description={
                                list.isFiltered
                                    ? 'Clear a filter to widen the results.'
                                    : 'No learner activity in your scope has met an incident threshold.'
                            }
                        />
                    }
                >
                    {rows.map((incident) => (
                        <Row key={incident.id} onClick={() => navigate(`/incidents/${incident.id}`)}>
                            <Cell mono className="font-semibold text-brand">
                                {incident.id.slice(0, 8).toUpperCase()}
                            </Cell>
                            <Cell bold>{incident.learner?.name ?? 'Unattributed'}</Cell>
                            <Cell muted>{incident.institution?.name ?? '—'}</Cell>
                            <Cell mono muted>
                                {incident.device?.asset_tag ?? '—'}
                            </Cell>
                            <Cell>{incident.category?.name ?? 'Uncategorised'}</Cell>
                            <Cell>
                                <StatusPill descriptor={SEVERITY[incident.severity]} />
                            </Cell>
                            <Cell className="tabular-nums">{incident.event_count}</Cell>
                            <Cell muted>{formatRelative(incident.last_detected_at)}</Cell>
                            <Cell>
                                <StatusPill descriptor={INCIDENT_STATUS[incident.status]} />
                            </Cell>
                        </Row>
                    ))}
                </DataTable>

                <Pagination meta={incidents.data?.meta} onChange={list.setPage} unit="incidents" />
            </Panel>
        </div>
    );
}
