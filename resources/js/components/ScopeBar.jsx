import { Link, useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import { Building2Icon, ChevronRightIcon, LandmarkIcon, NetworkIcon, XIcon } from 'lucide-react';
import { useScope } from '../lib/scope';
import { useAuth } from '../lib/auth';
import { capabilitiesFor } from '../lib/permissions';

/**
 * The drill-down trail. It sits under the top bar for county and sub-county
 * officers and states, in one line, whose records the page below is showing.
 */
export function ScopeBar() {
    const navigate = useNavigate();
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const scope = useScope();

    if (!scope.adjustable) {
        return null;
    }

    const steps = [
        {
            key: 'county',
            label: 'Kiambu County',
            icon: LandmarkIcon,
            active: !scope.subcounty && !scope.institution,
            onSelect: () => {
                scope.clear();
                navigate(can.viewSubcounties ? '/subcounties' : '/schools');
            },
        },
        scope.subcounty && {
            key: 'subcounty',
            label: scope.subcounty.name,
            icon: NetworkIcon,
            active: !scope.institution,
            onSelect: () => {
                scope.selectSubcounty(scope.subcounty);
                navigate(`/subcounties/${scope.subcounty.id}`);
            },
        },
        scope.institution && {
            key: 'institution',
            label: scope.institution.name,
            icon: Building2Icon,
            active: true,
            onSelect: () => navigate(`/schools/${scope.institution.id}`),
        },
    ].filter(Boolean);

    return (
        <div className="border-b border-border bg-white/80 backdrop-blur-sm">
            <div className="mx-auto flex w-full max-w-[1720px] flex-wrap items-center gap-x-1 gap-y-2 px-4 py-2 sm:px-6 lg:px-7">
                <span className="mr-1 text-[10px] font-semibold tracking-[.12em] text-text-muted uppercase">Viewing</span>

                {steps.map((step, index) => (
                    <span key={step.key} className="flex items-center gap-1">
                        {index > 0 && <ChevronRightIcon size={13} className="text-border" />}
                        <button
                            onClick={step.onSelect}
                            className={clsx(
                                'flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-semibold transition-colors duration-150 ease-gov',
                                step.active ? 'bg-brand-soft text-brand' : 'text-text-secondary hover:bg-surface-muted hover:text-text',
                            )}
                        >
                            <step.icon size={13} />
                            {step.label}
                        </button>
                    </span>
                ))}

                {(scope.subcounty || scope.institution) && (
                    <>
                        <button
                            onClick={scope.clear}
                            className="ml-1 flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-semibold text-text-muted transition-colors hover:bg-surface-muted hover:text-danger"
                        >
                            <XIcon size={12} /> Clear
                        </button>

                        <span className="ml-auto hidden items-center gap-3 text-[11px] text-text-secondary md:flex">
                            Registers below are limited to this selection
                            {scope.institution && (
                                <Link to={`/schools/${scope.institution.id}`} className="font-semibold text-brand hover:underline">
                                    Open school
                                </Link>
                            )}
                        </span>
                    </>
                )}
            </div>
        </div>
    );
}
