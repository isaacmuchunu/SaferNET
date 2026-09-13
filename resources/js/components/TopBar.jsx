import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { BellIcon, ChevronDownIcon, LogOutIcon, MenuIcon, UserRoundIcon } from 'lucide-react';
import clsx from 'clsx';
import { GlobalSearch } from './GlobalSearch';
import { NotificationMenu } from './NotificationMenu';
import { UserAvatar } from './UserAvatar';
import { useAuth } from '../lib/auth';
import { roleLabel } from '../lib/domain';
import { titleCase } from '../lib/format';
import { useDashboard, useNotifications } from '../lib/queries';

export function TopBar({ onMenu }) {
    const { pathname } = useLocation();
    const navigate = useNavigate();
    const { user, signOut } = useAuth();
    const [profileOpen, setProfileOpen] = useState(false);
    const [alertsOpen, setAlertsOpen] = useState(false);
    const profileRef = useRef(null);
    const alertsRef = useRef(null);

    const notifications = useNotifications({}, { refetchInterval: 120_000 });
    const dashboard = useDashboard();

    const unread = (notifications.data?.data ?? []).filter((item) => !item.read_at).length;
    // Detail routes end in a record id; name the section rather than the id.
    const segment = pathname.split('/').filter(Boolean)[0]?.replaceAll('-', ' ') ?? 'Dashboard';
    const degraded = (dashboard.data?.components_by_health?.degraded ?? 0) + (dashboard.data?.components_by_health?.offline ?? 0);
    // Component attention is the operational headline; fall back to unread
    // portal alerts when every protection component is healthy.
    const attentionCount = degraded > 0 ? degraded : unread;

    useEffect(() => {
        const close = (event) => {
            if (!profileRef.current?.contains(event.target)) {
                setProfileOpen(false);
            }

            if (!alertsRef.current?.contains(event.target)) {
                setAlertsOpen(false);
            }
        };

        const onKey = (event) => {
            if (event.key === 'Escape') {
                setProfileOpen(false);
                setAlertsOpen(false);
            }
        };

        document.addEventListener('pointerdown', close);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('pointerdown', close);
            document.removeEventListener('keydown', onKey);
        };
    }, []);

    return (
        <header className="sticky top-0 z-30 h-[72px] border-b border-border bg-white/95 backdrop-blur-sm">
            <div className="flex h-full items-center gap-3 px-4 sm:px-6 lg:px-7">
                <button onClick={onMenu} aria-label="Open navigation" className="rounded-lg p-2 text-text-secondary hover:bg-surface-muted lg:hidden">
                    <MenuIcon size={21} />
                </button>

                <p className="hidden min-w-fit text-xs text-text-secondary md:block">
                    <Link to="/" className="font-medium text-text hover:underline">
                        {user?.institution?.name ?? user?.subcounty?.name ?? 'Kiambu County'}
                    </Link>
                    <span aria-hidden className="mx-2 text-border">
                        /
                    </span>
                    <span>{titleCase(segment)}</span>
                </p>

                <GlobalSearch />

                <div className="ml-auto flex items-center gap-1 sm:gap-2">
                    <div ref={alertsRef} className="relative">
                        <button
                            onClick={() => setAlertsOpen((value) => !value)}
                            aria-expanded={alertsOpen}
                            aria-label={`Attention center, ${unread} unread alerts and ${degraded} components needing attention`}
                            title={`${unread} unread alerts · ${degraded} components need attention`}
                            className={clsx(
                                'relative rounded-xl p-2 text-text-secondary transition',
                                alertsOpen ? 'bg-brand-soft text-brand ring-1 ring-brand/15' : 'hover:bg-surface-muted hover:text-brand',
                            )}
                        >
                            <BellIcon size={19} />
                            {attentionCount > 0 && (
                                <span className="absolute -top-1 -right-1 grid h-[18px] min-w-[18px] place-items-center rounded-full border-2 border-white bg-danger px-1 text-[9px] leading-none font-bold text-white shadow-sm">
                                    {attentionCount > 99 ? '99+' : attentionCount}
                                </span>
                            )}
                        </button>
                        {alertsOpen && (
                            <NotificationMenu
                                query={notifications}
                                componentAttention={degraded}
                                onNavigate={() => setAlertsOpen(false)}
                            />
                        )}
                    </div>

                    <div ref={profileRef} className="relative">
                        <button
                            onClick={() => setProfileOpen((value) => !value)}
                            aria-expanded={profileOpen}
                            className={clsx(
                                'ml-1 flex items-center gap-2 rounded-xl p-1.5 transition',
                                profileOpen ? 'bg-brand-soft ring-1 ring-brand/15' : 'hover:bg-surface-muted',
                            )}
                        >
                            <UserAvatar user={user} size="sm" />
                            <span className="hidden max-w-[150px] text-left xl:block">
                                <span className="block truncate text-xs font-semibold">{user?.name}</span>
                                <span className="block truncate text-[10px] text-text-secondary">{roleLabel(user?.role)}</span>
                            </span>
                            <ChevronDownIcon
                                size={14}
                                className={clsx('hidden text-text-muted transition-transform duration-200 sm:block', profileOpen && 'rotate-180 text-brand')}
                            />
                        </button>

                        {profileOpen && (
                            <div className="absolute top-12 right-0 w-72 animate-scale-in rounded-2xl border border-brand/15 bg-white/98 p-2 shadow-[0_22px_55px_rgba(12,65,72,.18)] backdrop-blur-xl">
                                <div className="flex items-center gap-3 rounded-xl bg-gradient-to-br from-brand-soft to-cyan-50 px-3 py-3">
                                    <UserAvatar user={user} size="md" />
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-bold">{user?.name}</p>
                                        <p className="truncate text-[11px] text-text-secondary">{user?.email}</p>
                                        <p className="mt-1 text-[9px] font-bold tracking-wider text-brand uppercase">{roleLabel(user?.role)}</p>
                                    </div>
                                </div>
                                <button
                                    onClick={() => {
                                        setProfileOpen(false);
                                        navigate('/settings');
                                    }}
                                    className="mt-2 flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left text-sm font-medium transition hover:bg-brand-soft hover:text-brand"
                                >
                                    <UserRoundIcon size={15} className="text-text-muted" /> Account settings
                                </button>
                                <button
                                    onClick={async () => {
                                        setProfileOpen(false);
                                        await signOut();
                                        navigate('/signin', { replace: true });
                                    }}
                                    className="flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-danger transition hover:bg-danger-soft"
                                >
                                    <LogOutIcon size={15} /> Sign out
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </header>
    );
}
