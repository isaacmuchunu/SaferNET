import { Link, useLocation } from 'react-router-dom';
import clsx from 'clsx';
import { PanelLeftCloseIcon, PanelLeftOpenIcon, XIcon } from 'lucide-react';
import { navigationFor } from './navigation';
import { useAuth } from '../lib/auth';
import { roleShort } from '../lib/domain';

export function Sidebar({ collapsed, mobileOpen, onCollapse, onMobileClose }) {
    const { pathname } = useLocation();
    const { user } = useAuth();
    const groups = navigationFor(user);

    const isActive = (path) => {
        if (path === '/') {
            return pathname === '/';
        }

        // "My School" points at one record, so match it exactly.
        return path.startsWith('/schools/') ? pathname === path : pathname.startsWith(path);
    };

    return (
        <>
            {mobileOpen && (
                <button aria-label="Close navigation" onClick={onMobileClose} className="fixed inset-0 z-40 bg-brand-deeper/40 lg:hidden" />
            )}

            <aside
                className={clsx(
                    'fixed inset-y-0 left-0 z-50 flex border-r border-border bg-white text-text shadow-panel transition-[width,transform] duration-200 ease-gov',
                    collapsed ? 'w-[76px]' : 'w-[244px]',
                    mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
                )}
            >
                {!collapsed && (
                    <>
                        <img
                            src="/images/safernet-sidebar-school-portrait.png"
                            alt=""
                            loading="eager"
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-0 h-full w-full object-cover object-bottom"
                        />
                        <div className="pointer-events-none absolute inset-0 bg-gradient-to-b from-white/98 via-white/92 to-white/58" />
                    </>
                )}

                <div className="relative z-10 flex min-w-0 flex-1 flex-col">
                    <div className={clsx('flex h-[72px] shrink-0 items-center gap-2 border-b border-border bg-white/95', collapsed ? 'justify-center px-3' : 'pr-2 pl-5')}>
                        <Link
                            to="/"
                            onClick={onMobileClose}
                            aria-label="SaferNET dashboard"
                            className={clsx('flex min-w-0 flex-1 items-center', collapsed && 'justify-center')}
                        >
                            <img
                                src={collapsed ? '/images/safernet-mark.png' : '/images/safernet-logo.png'}
                                alt="SaferNET"
                                className={collapsed ? 'h-11 w-11 object-contain' : 'h-[52px] w-full max-w-[170px] object-contain object-left'}
                            />
                        </Link>

                        {!collapsed && (
                            <button
                                onClick={onCollapse}
                                aria-label="Collapse navigation"
                                title="Collapse navigation"
                                className="hidden rounded-lg p-2 text-text-muted transition-colors hover:bg-brand-soft hover:text-brand lg:block"
                            >
                                <PanelLeftCloseIcon size={18} />
                            </button>
                        )}

                        <button
                            onClick={onMobileClose}
                            aria-label="Close navigation"
                            className="rounded-lg p-2 text-text-secondary hover:bg-brand-soft lg:hidden"
                        >
                            <XIcon size={19} />
                        </button>
                    </div>

                    {collapsed && (
                        <button
                            onClick={onCollapse}
                            aria-label="Expand navigation"
                            title="Expand navigation"
                            className="mx-auto mt-3 hidden rounded-lg p-2 text-text-muted transition-colors hover:bg-brand-soft hover:text-brand lg:block"
                        >
                            <PanelLeftOpenIcon size={18} />
                        </button>
                    )}

                    <nav aria-label="Main navigation" className="sidebar-scroll min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-3 py-2">
                        {groups.map((group) => (
                            <div key={group.label} className="mb-2 last:mb-0">
                                {!collapsed && (
                                    <p className="mb-0.5 px-2 text-[9px] font-bold tracking-[.14em] text-brand-dark/75 uppercase">{group.label}</p>
                                )}
                                <div className="space-y-px">
                                    {group.items.map(({ label, path, icon: Icon }) => (
                                        <Link
                                            key={path}
                                            to={path}
                                            title={collapsed ? label : undefined}
                                            onClick={onMobileClose}
                                            className={clsx(
                                                'flex h-[30px] items-center rounded-lg text-[12.5px] font-semibold transition-colors duration-150 ease-gov',
                                                collapsed ? 'justify-center px-2' : 'gap-3 px-2.5',
                                                isActive(path)
                                                    ? 'bg-brand/95 text-white shadow-sm'
                                                    : 'bg-white/38 text-text hover:bg-white/88 hover:text-brand-dark hover:shadow-sm',
                                            )}
                                        >
                                            <Icon size={16} className="shrink-0" />
                                            {!collapsed && <span className="truncate">{label}</span>}
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </nav>

                    <div className="shrink-0 border-t border-white/70 bg-white/85 px-3 py-2 backdrop-blur-sm">
                        <p className={clsx('truncate text-[10px] font-semibold text-text-secondary', collapsed ? 'text-center' : '')}>
                            {collapsed ? roleShort(user?.role) : `${roleShort(user?.role)} · ${user?.institution?.name ?? user?.subcounty?.name ?? 'Kiambu County'}`}
                        </p>
                    </div>
                </div>
            </aside>
        </>
    );
}
