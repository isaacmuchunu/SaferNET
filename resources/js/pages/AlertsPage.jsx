import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { BellRingIcon, CheckIcon, Trash2Icon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import { Button, EmptyState, ErrorState, Pagination, Panel, Skeleton } from '../components/Primitives';
import { StatusPill, ToneDot } from '../components/StatusPill';
import { useListState } from '../lib/hooks';
import { useDeleteNotification, useMarkNotificationRead, useNotifications } from '../lib/queries';
import { SEVERITY } from '../lib/domain';
import { formatDate, formatNumber, formatRelative } from '../lib/format';
import { showToast, toastError } from '../lib/toast';

export function AlertsPage() {
    const list = useListState();
    const alerts = useNotifications({ page: list.page });
    const markRead = useMarkNotificationRead();
    const remove = useDeleteNotification();

    const rows = alerts.data?.data ?? [];
    const unread = rows.filter((alert) => !alert.read_at).length;

    async function dismiss(alert) {
        try {
            await remove.mutateAsync(alert.id);
            showToast('Alert dismissed');
        } catch (error) {
            toastError(error, 'The alert could not be dismissed');
        }
    }

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Alerts"
                description="Safeguarding notifications routed to your office as incidents are raised."
                meta={alerts.data?.meta ? `${formatNumber(alerts.data.meta.total)} alerts · ${unread} unread on this page` : undefined}
            />

            <Panel>
                {alerts.isPending ? (
                    <div className="space-y-3 p-5">
                        {Array.from({ length: 5 }).map((_, index) => (
                            <Skeleton key={index} className="h-16 w-full" />
                        ))}
                    </div>
                ) : alerts.isError ? (
                    <ErrorState error={alerts.error} onRetry={alerts.refetch} />
                ) : rows.length === 0 ? (
                    <EmptyState
                        icon={BellRingIcon}
                        title="No alerts"
                        description="Safeguarding notifications for your office will appear here."
                    />
                ) : (
                    <ul className="divide-y divide-border">
                        {rows.map((alert) => {
                            const severity = SEVERITY[alert.data?.severity];

                            return (
                                <li
                                    key={alert.id}
                                    className={clsx('flex flex-wrap items-start gap-3 px-5 py-4', !alert.read_at && 'bg-brand-soft/30')}
                                >
                                    <ToneDot tone={severity?.tone ?? 'info'} className="mt-2" />

                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="text-sm font-semibold">{severity?.label ?? 'Safety'} incident raised</p>
                                            {severity && <StatusPill descriptor={severity} />}
                                            {!alert.read_at && (
                                                <span className="rounded-full bg-brand px-2 py-0.5 text-[10px] font-bold text-white">New</span>
                                            )}
                                        </div>
                                        <p className="mt-1 text-xs text-text-secondary">
                                            A learner-attributed incident met a filtering policy threshold and was routed to your office.
                                        </p>
                                        <p className="mt-1 text-[11px] text-text-muted">
                                            {formatRelative(alert.created_at)} · {formatDate(alert.created_at, { withTime: true })}
                                        </p>
                                    </div>

                                    <div className="flex shrink-0 items-center gap-2">
                                        {alert.data?.incident_id && (
                                            <Button as={Link} to={`/incidents/${alert.data.incident_id}`} size="sm">
                                                Open incident
                                            </Button>
                                        )}
                                        {!alert.read_at && (
                                            <Button size="sm" variant="ghost" icon={CheckIcon} onClick={() => markRead.mutate(alert.id)}>
                                                Mark read
                                            </Button>
                                        )}
                                        <Button size="sm" variant="ghost" icon={Trash2Icon} onClick={() => dismiss(alert)}>
                                            Dismiss
                                        </Button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}

                <Pagination meta={alerts.data?.meta} onChange={list.setPage} unit="alerts" />
            </Panel>
        </div>
    );
}
