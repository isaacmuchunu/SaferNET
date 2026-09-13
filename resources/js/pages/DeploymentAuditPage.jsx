import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { FileClockIcon, NetworkIcon } from 'lucide-react';
import { useAuth } from '../lib/auth';
import { capabilitiesFor } from '../lib/permissions';
import { AuditLogPage } from './AuditLogPage';
import { DeploymentPage } from './DeploymentPage';

export function DeploymentAuditPage() {
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const [searchParams, setSearchParams] = useSearchParams();
    const requested = searchParams.get('view');
    const view = requested === 'audit' && can.viewAudit ? 'audit' : 'deployment';

    return (
        <div>
            {can.viewAudit && (
                <nav aria-label="Deployment and audit views" className="mb-5 inline-flex rounded-xl border border-border bg-white p-1 shadow-card">
                    <WorkspaceTab
                        active={view === 'deployment'}
                        icon={NetworkIcon}
                        label="Deployment"
                        onClick={() => setSearchParams({}, { replace: true })}
                    />
                    <WorkspaceTab
                        active={view === 'audit'}
                        icon={FileClockIcon}
                        label="Audit log"
                        onClick={() => setSearchParams({ view: 'audit' }, { replace: true })}
                    />
                </nav>
            )}

            {view === 'audit' ? <AuditLogPage /> : <DeploymentPage />}
        </div>
    );
}

function WorkspaceTab({ active, icon: Icon, label, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-current={active ? 'page' : undefined}
            className={clsx(
                'inline-flex h-9 items-center gap-2 rounded-lg px-3.5 text-xs font-semibold transition-colors',
                active ? 'bg-brand text-white shadow-sm' : 'text-text-secondary hover:bg-brand-soft hover:text-brand-dark',
            )}
        >
            <Icon size={15} />
            {label}
        </button>
    );
}
