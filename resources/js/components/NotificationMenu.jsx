import { useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowRightIcon, MonitorCogIcon } from 'lucide-react';
import { ToneDot } from './StatusPill';
import { SEVERITY } from '../lib/domain';
import { formatRelative } from '../lib/format';
import { useMarkNotificationRead } from '../lib/queries';

/** Bell dropdown over the officer's own database notifications. */
export function NotificationMenu({ query, componentAttention = 0, onNavigate }) {
    const navigate = useNavigate();
    const markRead = useMarkNotificationRead();
    const alerts = query.data?.data ?? [];
    const unread = alerts.filter((alert) => !alert.read_at).length;

    async function open(alert) {
        onNavigate();

        if (!alert.read_at) {
            markRead.mutate(alert.id);
        }

        navigate(alert.data?.incident_id ? `/incidents/${alert.data.incident_id}` : '/alerts');
    }

    return (
        <div className="absolute top-12 right-0 z-50 w-[350px] animate-scale-in overflow-hidden rounded-2xl border border-brand/15 bg-white/98 shadow-[0_22px_55px_rgba(12,65,72,.18)] backdrop-blur-xl">
            <div className="flex items-center justify-between border-b border-border bg-gradient-to-r from-brand-soft/80 to-cyan-50/60 px-4 py-3">
                <div>
                    <p className="text-sm font-bold">Attention center</p>
                    <p className="mt-0.5 text-[10px] text-text-secondary">Live safety and deployment updates</p>
                </div>
                <span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold', unread > 0 ? 'bg-danger-soft text-danger' : 'bg-surface-muted text-text-secondary')}>
                    {unread} unread
                </span>
            </div>

            {componentAttention > 0 && (
                <button
                    onClick={() => {
                        onNavigate();
                        navigate('/deployment');
                    }}
                    className="flex w-full items-center gap-3 border-b border-border bg-warning-soft/55 px-4 py-3 text-left transition hover:bg-warning-soft"
                >
                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-warning-soft text-warning-strong">
                        <MonitorCogIcon size={16} />
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block text-xs font-semibold text-text">Protection components</span>
                        <span className="mt-0.5 block text-[11px] text-text-secondary">
                            {componentAttention} need operational attention
                        </span>
                    </span>
                    <ArrowRightIcon size={14} className="text-warning-strong" />
                </button>
            )}

            <ul className="max-h-[320px] overflow-y-auto">
                {alerts.slice(0, 5).map((alert) => (
                    <li key={alert.id}>
                        <button
                            onClick={() => open(alert)}
                            className="flex w-full gap-3 border-b border-border px-4 py-3 text-left last:border-0 hover:bg-brand-soft/60"
                        >
                            <ToneDot tone={SEVERITY[alert.data?.severity]?.tone ?? 'info'} className="mt-1.5" />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-xs font-semibold">
                                    {SEVERITY[alert.data?.severity]?.label ?? 'Safety'} incident raised
                                </span>
                                <span className="mt-0.5 block truncate text-[11px] text-text-secondary">
                                    {formatRelative(alert.created_at)}
                                </span>
                            </span>
                            {!alert.read_at && <ToneDot tone="info" className="mt-1.5" />}
                        </button>
                    </li>
                ))}

                {alerts.length === 0 && (
                    <li className="px-4 py-8 text-center text-xs text-text-secondary">
                        {query.isPending ? 'Loading alerts…' : 'No alerts for your office'}
                    </li>
                )}
            </ul>

            <button
                onClick={() => {
                    onNavigate();
                    navigate('/alerts');
                }}
                className="flex w-full items-center justify-center gap-1.5 bg-surface-muted px-4 py-2.5 text-xs font-semibold text-brand hover:bg-brand-soft"
            >
                View all alerts <ArrowRightIcon size={13} />
            </button>
        </div>
    );
}
