import { BarChart3Icon, DownloadIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import { Button, Cell, DataTable, EmptyState, Panel, PanelHeader, Row, Skeleton } from '../components/Primitives';
import { IncidentTrend } from '../components/dashboard/IncidentTrend';
import { useDashboard, useProtectionSummary, useSubcounties } from '../lib/queries';
import { useAuth } from '../lib/auth';
import { capabilitiesFor } from '../lib/permissions';
import { formatDate, formatNumber, formatPercent } from '../lib/format';
import { showToast } from '../lib/toast';

/** Builds a CSV from the figures on screen so officers can file the report. */
function downloadCsv(rows, filename) {
    const csv = rows.map((row) => row.map((cell) => `"${String(cell ?? '').replaceAll('"', '""')}"`).join(',')).join('\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
}

export function ReportsPage() {
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const summary = useProtectionSummary();
    const dashboard = useDashboard();
    const subcounties = useSubcounties({}, { enabled: can.viewSubcounties });

    const rows = subcounties.data?.data ?? [];
    const metrics = dashboard.data;

    const figures = [
        ['Learners on the register', summary.data?.learners],
        ['Managed devices', summary.data?.devices],
        ['Healthy protection components', summary.data?.protected_components],
        ['Learner-initiated blocked requests', summary.data?.blocked_top_level_requests],
        ['Open incidents', summary.data?.open_incidents],
    ];

    function exportCounty() {
        downloadCsv(
            [
                ['Sub-county', 'Schools', 'Protected', 'Learners', 'Devices', 'Unattributed devices', 'Open incidents'],
                ...rows.map((item) => [
                    item.name,
                    item.institutions_count,
                    item.protected_institutions_count,
                    item.learners_count,
                    item.devices_count,
                    item.unattributed_devices_count,
                    item.open_incidents_count,
                ]),
            ],
            `safernet-county-report-${new Date().toISOString().slice(0, 10)}.csv`,
        );
        showToast('Report exported', { description: 'The county protection figures were saved as a CSV file.' });
    }

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Reports"
                description="Protection figures for the institutions in your scope, as at the moment of reporting."
                meta={summary.data ? `Generated ${formatDate(summary.data.generated_at, { withTime: true })}` : undefined}
                actions={
                    can.viewSubcounties && (
                        <Button variant="primary" icon={DownloadIcon} onClick={exportCounty} disabled={rows.length === 0}>
                            Export CSV
                        </Button>
                    )
                }
            />

            <div className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border shadow-card lg:grid-cols-5">
                {figures.map(([label, value]) => (
                    <div key={label} className="bg-white px-4 py-4">
                        <p className="text-[11px] text-text-secondary">{label}</p>
                        {summary.isPending ? (
                            <Skeleton className="mt-2 h-6 w-14" />
                        ) : (
                            <p className="mt-1 text-[22px] font-bold tracking-[-.03em] tabular-nums">{formatNumber(value)}</p>
                        )}
                    </div>
                ))}
            </div>

            <div className="mt-4">
                <IncidentTrend />
            </div>

            <Panel className="mt-4">
                <PanelHeader
                    title="Protection by sub-county"
                    description="Coverage, attribution and open incidents for each administrative unit."
                />
                <DataTable
                    query={subcounties}
                    rows={rows}
                    minWidth="820px"
                    columns={[
                        'Sub-county',
                        { key: 'schools', label: 'Schools', align: 'right' },
                        { key: 'protected', label: 'Protected', align: 'right' },
                        { key: 'learners', label: 'Learners', align: 'right' },
                        { key: 'devices', label: 'Devices', align: 'right' },
                        { key: 'attribution', label: 'Attribution', align: 'right' },
                        { key: 'incidents', label: 'Open incidents', align: 'right' },
                    ]}
                    empty={<EmptyState icon={BarChart3Icon} title="Nothing to report" description="No sub-counties are in your scope." />}
                >
                    {rows.map((item) => (
                        <Row key={item.id}>
                            <Cell bold>{item.name}</Cell>
                            <Cell align="right" className="tabular-nums">
                                {formatNumber(item.institutions_count)}
                            </Cell>
                            <Cell align="right" className="tabular-nums">
                                {formatNumber(item.protected_institutions_count)}
                            </Cell>
                            <Cell align="right" className="tabular-nums">
                                {formatNumber(item.learners_count)}
                            </Cell>
                            <Cell align="right" className="tabular-nums">
                                {formatNumber(item.devices_count)}
                            </Cell>
                            <Cell align="right" className="tabular-nums">
                                {item.devices_count
                                    ? formatPercent(((item.devices_count - item.unattributed_devices_count) / item.devices_count) * 100, 0)
                                    : '—'}
                            </Cell>
                            <Cell align="right" className="tabular-nums">
                                {formatNumber(item.open_incidents_count)}
                            </Cell>
                        </Row>
                    ))}
                </DataTable>
            </Panel>

            <p className="mt-4 rounded-xl border border-border bg-white px-4 py-3 text-[11px] leading-5 text-text-secondary shadow-card">
                Figures are drawn live from the county register and reflect only the institutions your office may see.
                {metrics ? ` Protection health currently stands at ${formatNumber(metrics.institutions_by_status?.protected ?? 0)} of ${formatNumber(metrics.institutions)} institutions.` : ''}
            </p>
        </div>
    );
}
