import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowRightIcon, MonitorSmartphoneIcon } from 'lucide-react';
import { Meter } from '../Primitives';
import { formatNumber, formatPercent } from '../../lib/format';

export function AttributionPanel({ metrics }) {
    const devices = metrics?.devices ?? 0;
    const attributed = metrics?.attributed_devices ?? 0;
    const unattributed = metrics?.unattributed_devices ?? 0;
    const share = devices === 0 ? 0 : (attributed / devices) * 100;
    const compliant = share >= 98;

    return (
        <section className="rounded-xl border border-border bg-white p-5 shadow-card">
            <div className="grid h-9 w-9 place-items-center rounded-lg bg-brand-soft text-brand">
                <MonitorSmartphoneIcon size={19} />
            </div>

            <h2 className="mt-4 text-base font-bold">Learner Device Attribution</h2>
            <p className="mt-0.5 text-sm text-text-secondary tabular-nums">{formatNumber(devices)} managed devices</p>

            <div className="mt-4 flex items-end justify-between">
                <span className="text-2xl font-bold tracking-tight tabular-nums">{devices === 0 ? '—' : formatPercent(share)}</span>
                <span className={clsx('text-xs font-semibold', compliant ? 'text-success' : 'text-warning-strong')}>
                    {devices === 0 ? 'No devices enrolled' : compliant ? 'Compliant' : 'Below county target'}
                </span>
            </div>
            <Meter
                value={share}
                tone={compliant ? 'good' : 'warning'}
                label="Share of managed devices attributed to a learner"
                className="mt-2 h-2"
            />

            <dl className="mt-4 space-y-2 text-xs">
                <div className="flex justify-between">
                    <dt className="text-text-secondary">Attributed to a learner</dt>
                    <dd className="font-bold tabular-nums">{formatNumber(attributed)}</dd>
                </div>
                <div className="flex justify-between">
                    <dt className={unattributed > 0 ? 'text-warning-strong' : 'text-text-secondary'}>Require attribution</dt>
                    <dd className={clsx('font-bold tabular-nums', unattributed > 0 && 'text-warning-strong')}>{formatNumber(unattributed)}</dd>
                </div>
                <div className="flex justify-between">
                    <dt className="text-text-secondary">Offline or needing attention</dt>
                    <dd className="font-bold tabular-nums">
                        {formatNumber((metrics?.devices_by_status?.offline ?? 0) + (metrics?.devices_by_status?.attention_required ?? 0))}
                    </dd>
                </div>
            </dl>

            <p className="mt-4 text-[11px] leading-5 text-text-secondary">
                A learner-use device may be assigned to at most two learners, and one authenticated learner identity is
                required for each internet session.
            </p>

            <Link
                to="/devices?attribution=required"
                className="mt-4 flex w-full items-center justify-between rounded-lg border border-border px-3 py-2 text-xs font-semibold text-brand transition-colors duration-150 ease-gov hover:bg-brand-soft"
            >
                Manage attribution <ArrowRightIcon size={15} />
            </Link>
        </section>
    );
}
