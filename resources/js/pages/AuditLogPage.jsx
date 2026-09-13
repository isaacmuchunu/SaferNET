import { FileClockIcon, LockIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import { Button, Cell, DataTable, EmptyState, Pagination, Panel, Row, SearchInput } from '../components/Primitives';
import { useDebouncedValue, useListState } from '../lib/hooks';
import { useAuditLogs } from '../lib/queries';
import { formatDate, formatNumber, titleCase } from '../lib/format';

export function AuditLogPage() {
    const list = useListState({ event: '' });
    const event = useDebouncedValue(list.values.event, 300);
    const logs = useAuditLogs({ page: list.page, event: event || undefined });
    const rows = logs.data?.data ?? [];

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Audit Log"
                description="Immutable record of administrative decisions taken across SAFERNET."
                meta={logs.data?.meta ? `${formatNumber(logs.data.meta.total)} entries` : undefined}
                actions={
                    <span className="flex items-center gap-1.5 rounded-lg bg-surface-muted px-3 py-2 text-xs font-semibold text-text-secondary">
                        <LockIcon size={13} /> Immutable — entries cannot be edited or deleted
                    </span>
                }
            />

            <Panel>
                <div className="flex flex-wrap items-center gap-2 border-b border-border p-3">
                    <SearchInput
                        value={list.values.event}
                        onChange={(value) => list.setValue('event', value)}
                        placeholder="Filter by exact event name, e.g. institution.reviewed"
                    />
                    {list.isFiltered && (
                        <Button variant="ghost" size="sm" onClick={list.reset}>
                            Clear
                        </Button>
                    )}
                </div>

                <DataTable
                    query={logs}
                    rows={rows}
                    minWidth="980px"
                    columns={['Recorded', 'Event', 'Record', 'Previous value', 'New value', 'IP address']}
                    empty={
                        <EmptyState
                            icon={FileClockIcon}
                            title="No audit entries"
                            description="Administrative decisions in your scope will appear here as they are taken."
                        />
                    }
                >
                    {rows.map((entry) => (
                        <Row key={entry.id}>
                            <Cell muted>{formatDate(entry.created_at, { withTime: true })}</Cell>
                            <Cell bold>{titleCase(entry.event.replace(/^api\.api\.v1\./, '').replaceAll('.', ' '))}</Cell>
                            <Cell mono muted>
                                {entry.auditable_type ? `${entry.auditable_type.split('\\').pop()} #${entry.auditable_id}` : '—'}
                            </Cell>
                            <Cell muted className="max-w-[16rem] truncate">
                                {entry.old_values ? JSON.stringify(entry.old_values) : '—'}
                            </Cell>
                            <Cell className="max-w-[16rem] truncate">{entry.new_values ? JSON.stringify(entry.new_values) : '—'}</Cell>
                            <Cell mono muted>
                                {entry.ip_address ?? '—'}
                            </Cell>
                        </Row>
                    ))}
                </DataTable>

                <Pagination meta={logs.data?.meta} onChange={list.setPage} unit="entries" />
            </Panel>
        </div>
    );
}
