import { useState } from 'react';
import clsx from 'clsx';
import { CloudDownloadIcon, DatabaseIcon, ExternalLinkIcon, RefreshCwIcon, ShieldCheckIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import { Button, Cell, DataTable, EmptyState, Panel, PanelHeader, Row, Skeleton } from '../components/Primitives';
import { ConfirmDialog } from '../components/Overlays';
import { StatusPill } from '../components/StatusPill';
import { useAuth } from '../lib/auth';
import { useBlocklistSources, useIntegrations, useSyncBlocklistSource, useToggleBlocklistSource } from '../lib/queries';
import { formatDate, formatNumber, formatRelative } from '../lib/format';
import { showToast, toastError } from '../lib/toast';

const SYNC_STATUS = {
    synced: { label: 'Synchronised', tone: 'success' },
    unchanged: { label: 'Up to date', tone: 'info' },
    failed: { label: 'Failed', tone: 'danger' },
};

/**
 * The upstream domain lists the county enforces. Counts shown here are written
 * only by a synchronisation that actually completed, so a source that failed to
 * fetch reports the error rather than protection the schools do not have.
 */
export function BlocklistsPage() {
    const { user } = useAuth();
    const isCde = user?.role === 'cde';

    const sources = useBlocklistSources();
    const integrations = useIntegrations();
    const sync = useSyncBlocklistSource();
    const toggle = useToggleBlocklistSource();

    const [confirming, setConfirming] = useState(null);
    const [syncingId, setSyncingId] = useState(null);

    const rows = sources.data?.data ?? sources.data ?? [];
    const summary = integrations.data?.blocklists;

    async function runSync(source) {
        setSyncingId(source.id);

        try {
            const result = await sync.mutateAsync(source.id);
            showToast(`${source.name} synchronised`, { description: result.message });
        } catch (error) {
            toastError(error, `${source.name} could not be synchronised`);
        } finally {
            setSyncingId(null);
        }
    }

    async function applyToggle() {
        try {
            await toggle.mutateAsync({ id: confirming.source.id, enabled: confirming.enabled });
            showToast(
                confirming.enabled ? `${confirming.source.name} is now enforced` : `${confirming.source.name} is no longer enforced`,
                { description: 'The change applies at the next device policy sync.' },
            );
        } catch (error) {
            toastError(error, 'The source could not be changed');
        } finally {
            setConfirming(null);
        }
    }

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Blocklists"
                description="Published domain lists the county synchronises from source repositories and enforces across every school."
                meta={summary ? `${formatNumber(summary.domains)} domains enforced` : undefined}
            />

            <div className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border shadow-card lg:grid-cols-4">
                {[
                    { label: 'Domains enforced', value: summary?.domains, detail: 'Across enabled sources', icon: DatabaseIcon },
                    { label: 'Sources enabled', value: summary?.enabled, detail: `${formatNumber(summary?.sources ?? 0)} in the catalogue`, icon: ShieldCheckIcon },
                    { label: 'Sources failing', value: summary?.failing, detail: 'Last fetch did not complete', icon: RefreshCwIcon, emphasis: (summary?.failing ?? 0) > 0 },
                    { label: 'Last synchronised', value: null, detail: summary?.last_synced_at ? formatRelative(summary.last_synced_at) : 'Never', icon: CloudDownloadIcon },
                ].map((figure) => (
                    <div key={figure.label} className="bg-white px-4 py-4">
                        <div className="flex items-center gap-2">
                            <figure.icon size={14} className="text-text-muted" />
                            <p className="truncate text-[11px] text-text-secondary">{figure.label}</p>
                        </div>
                        {integrations.isPending ? (
                            <Skeleton className="mt-2 h-6 w-16" />
                        ) : (
                            <p className={clsx('mt-1 text-[22px] font-bold tracking-[-.03em] tabular-nums', figure.emphasis && 'text-danger')}>
                                {figure.value === null || figure.value === undefined ? '—' : formatNumber(figure.value)}
                            </p>
                        )}
                        <p className="mt-0.5 truncate text-[11px] text-text-muted">{figure.detail}</p>
                    </div>
                ))}
            </div>

            <Panel className="mt-4">
                <PanelHeader
                    title="Source catalogue"
                    description={
                        isCde
                            ? 'Synchronising fetches the list from its repository and replaces that source’s domains.'
                            : 'Read-only. The County Director decides which lists the county enforces.'
                    }
                />

                <DataTable
                    query={sources}
                    rows={rows}
                    minWidth="1040px"
                    columns={[
                        'Source',
                        'Category',
                        { key: 'domains', label: 'Domains', align: 'right' },
                        'Last synchronised',
                        'State',
                        { key: 'actions', label: '', align: 'right' },
                    ]}
                    empty={
                        <EmptyState
                            icon={DatabaseIcon}
                            title="No blocklist sources registered"
                            description="Run the blocklist source seeder to register the county catalogue."
                        />
                    }
                >
                    {rows.map((source) => (
                        <Row key={source.id}>
                            <Cell>
                                <span className="font-semibold">{source.name}</span>
                                <span className="mt-0.5 block max-w-[34rem] truncate text-[11px] text-text-secondary">{source.description}</span>
                                <a
                                    href={source.url}
                                    target="_blank"
                                    rel="noreferrer noopener"
                                    className="mt-1 inline-flex items-center gap-1 font-mono text-[10px] text-text-muted hover:text-brand"
                                >
                                    {source.provenance} <ExternalLinkIcon size={10} />
                                </a>
                            </Cell>
                            <Cell muted>{source.category?.name ?? 'Uncategorised'}</Cell>
                            <Cell align="right" className="tabular-nums">
                                {source.domains_count > 0 ? formatNumber(source.domains_count) : <span className="text-text-muted">Never fetched</span>}
                            </Cell>
                            <Cell muted>
                                {source.last_synced_at ? formatDate(source.last_synced_at, { withTime: true }) : '—'}
                                {source.last_error && (
                                    <span className="mt-0.5 block max-w-[18rem] truncate text-[11px] text-danger" title={source.last_error}>
                                        {source.last_error}
                                    </span>
                                )}
                            </Cell>
                            <Cell>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <StatusPill
                                        label={source.is_enabled ? 'Enforced' : 'Not enforced'}
                                        tone={source.is_enabled ? 'success' : 'neutral'}
                                    />
                                    {source.last_status && <StatusPill descriptor={SYNC_STATUS[source.last_status]} />}
                                </div>
                            </Cell>
                            <Cell align="right">
                                {isCde && (
                                    <div className="flex justify-end gap-1">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            icon={RefreshCwIcon}
                                            loading={syncingId === source.id}
                                            onClick={() => runSync(source)}
                                        >
                                            Sync
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setConfirming({ source, enabled: !source.is_enabled })}
                                        >
                                            {source.is_enabled ? 'Disable' : 'Enable'}
                                        </Button>
                                    </div>
                                )}
                            </Cell>
                        </Row>
                    ))}
                </DataTable>

                <p className="border-t border-border bg-surface-muted/40 px-5 py-3 text-[11px] leading-5 text-text-secondary">
                    Lists are fetched over https from an allowlisted repository host only, without following redirects and
                    under a size cap — a blocklist URL is a server-side fetch, and an uncontained one would be a request
                    forgery vector.
                </p>
            </Panel>

            <ConfirmDialog
                open={Boolean(confirming)}
                tone={confirming?.enabled ? 'brand' : 'danger'}
                title={confirming?.enabled ? 'Enforce this list county-wide?' : 'Stop enforcing this list?'}
                description={
                    confirming
                        ? confirming.enabled
                            ? `${formatNumber(confirming.source.domains_count)} domains from ${confirming.source.name} will be blocked across every school at the next policy sync.`
                            : `${confirming.source.name} will no longer block anything. Learners will be able to reach the ${formatNumber(confirming.source.domains_count)} domains it covers.`
                        : ''
                }
                confirmLabel={confirming?.enabled ? 'Enforce list' : 'Stop enforcing'}
                loading={toggle.isPending}
                onCancel={() => setConfirming(null)}
                onConfirm={applyToggle}
            />
        </div>
    );
}
