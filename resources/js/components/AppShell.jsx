import { useEffect, useState } from 'react';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import clsx from 'clsx';
import { Sidebar } from './Sidebar';
import { TopBar } from './TopBar';
import { ScopeBar } from './ScopeBar';
import { useAuth } from '../lib/auth';
import { capabilitiesFor } from '../lib/permissions';

const COLLAPSE_KEY = 'safernet.sidebar.collapsed';

export function AppShell() {
    const location = useLocation();
    const [collapsed, setCollapsed] = useState(() => {
        try {
            return window.localStorage.getItem(COLLAPSE_KEY) === '1';
        } catch {
            return false;
        }
    });
    const [mobileOpen, setMobileOpen] = useState(false);

    useEffect(() => {
        setMobileOpen(false);
    }, [location.pathname]);

    useEffect(() => {
        try {
            window.localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
        } catch {
            /* preference is optional */
        }
    }, [collapsed]);

    return (
        <div className="min-h-screen w-full bg-canvas text-text">
            <a
                href="#main-content"
                className="fixed top-3 left-3 z-110 -translate-y-20 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-brand shadow-panel focus:translate-y-0"
            >
                Skip to main content
            </a>

            <Sidebar
                collapsed={collapsed}
                mobileOpen={mobileOpen}
                onCollapse={() => setCollapsed((value) => !value)}
                onMobileClose={() => setMobileOpen(false)}
            />

            <div className={clsx('min-h-screen transition-[padding] duration-200 ease-gov', collapsed ? 'lg:pl-[76px]' : 'lg:pl-[244px]')}>
                <TopBar onMenu={() => setMobileOpen(true)} />
                <ScopeBar />
                <main id="main-content" tabIndex={-1} className="mx-auto w-full max-w-[1720px] px-4 py-5 outline-none sm:px-6 lg:px-7 lg:py-6">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}

/** Blocks the shell until the stored token has completed password and MFA checks. */
export function RequireAuth() {
    const { isAuthenticated, isRestoring } = useAuth();
    const location = useLocation();

    if (isRestoring) {
        return (
            <div className="grid min-h-screen place-items-center bg-canvas">
                <div className="flex animate-fade-in flex-col items-center gap-3">
                    <img src="/images/safernet-mark.png" alt="SaferNET" className="h-12 w-12 object-contain" />
                    <p className="text-[11px] tracking-[.16em] text-text-muted uppercase">Restoring your session</p>
                </div>
            </div>
        );
    }

    return isAuthenticated ? <Outlet /> : <Navigate to="/signin" replace state={{ from: location.pathname + location.search }} />;
}

/**
 * Route-level mirror of the server-side policies: a page an office may not use
 * is never reachable by typing its address either.
 */
export function RequireCapability({ capability, children }) {
    const { user } = useAuth();

    return capabilitiesFor(user?.role)[capability] ? (children ?? <Outlet />) : <Navigate to="/" replace />;
}
