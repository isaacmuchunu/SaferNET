import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { useAuth } from './auth';
import { capabilitiesFor } from './permissions';

const ScopeContext = createContext(null);
const LEGACY_STORAGE_KEY = 'safernet.scope';
const EMPTY_SCOPE = { subcounty: null, institution: null };

function storageKey(userId) {
    return `safernet.scope.${userId}`;
}

function readStoredScope(userId) {
    try {
        const stored = JSON.parse(window.localStorage.getItem(storageKey(userId)) ?? 'null');

        return stored && typeof stored === 'object' ? stored : null;
    } catch {
        return null;
    }
}

/**
 * The drill-down a county or sub-county officer is working inside.
 *
 * A County Director starts with every sub-county in view, narrows to one, then
 * to one school; from that point every register — learners, devices, incidents,
 * laboratories, officers — answers for that school alone until the scope is
 * cleared. A school officer has exactly one scope and cannot leave it.
 */
export function ScopeProvider({ children }) {
    const { user } = useAuth();
    const can = capabilitiesFor(user?.role);
    const [ownedScope, setOwnedScope] = useState({ ownerId: null, scope: EMPTY_SCOPE });

    const officeScope = user?.role === 'scde' && user?.subcounty
        ? { subcounty: { id: user.subcounty.id, name: user.subcounty.name }, institution: null }
        : EMPTY_SCOPE;

    // A school officer's scope is their own institution, always.
    const fixed = can.isSchool && user?.institution
        ? {
              subcounty: user.subcounty ? { id: user.subcounty.id, name: user.subcounty.name } : null,
              institution: { id: user.institution.id, name: user.institution.name },
          }
        : null;

    useEffect(() => {
        try {
            window.localStorage.removeItem(LEGACY_STORAGE_KEY);
        } catch {
            /* no-op */
        }

        if (!user?.id) {
            setOwnedScope({ ownerId: null, scope: EMPTY_SCOPE });
            return;
        }

        const stored = readStoredScope(user.id);
        const safeScope = user.role === 'scde'
            ? {
                  subcounty: officeScope.subcounty,
                  institution: stored?.institution?.subcounty_id === user.subcounty?.id ? stored.institution : null,
              }
            : (stored ?? officeScope);

        setOwnedScope({ ownerId: user.id, scope: safeScope });
    }, [user?.id, user?.role, user?.subcounty?.id, user?.subcounty?.name]);

    const scope = ownedScope.ownerId === user?.id ? ownedScope.scope : officeScope;
    const setScope = useCallback((next) => {
        if (!user?.id) {
            return;
        }

        setOwnedScope((current) => ({
            ownerId: user.id,
            scope: typeof next === 'function' ? next(current.ownerId === user.id ? current.scope : officeScope) : next,
        }));
    }, [user?.id, officeScope.subcounty?.id, officeScope.subcounty?.name]);

    useEffect(() => {
        if (fixed || !user?.id || ownedScope.ownerId !== user.id) {
            return;
        }

        try {
            window.localStorage.setItem(storageKey(user.id), JSON.stringify(scope));
        } catch {
            /* the scope is a convenience; losing it is harmless */
        }
    }, [scope, fixed, ownedScope.ownerId, user?.id]);

    const selectSubcounty = useCallback((subcounty) => {
        if (user?.role === 'scde') {
            setScope(officeScope);
            return;
        }

        setScope({ subcounty: subcounty ? { id: subcounty.id, name: subcounty.name } : null, institution: null });
    }, [setScope, user?.role, officeScope.subcounty?.id, officeScope.subcounty?.name]);

    const selectInstitution = useCallback((institution) => {
        setScope((current) => ({
            subcounty: user?.role === 'scde'
                ? officeScope.subcounty
                : institution?.subcounty
                ? { id: institution.subcounty.id, name: institution.subcounty.name }
                : current.subcounty,
            institution: institution
                ? {
                      id: institution.id,
                      name: institution.name,
                      subcounty_id: institution.subcounty?.id ?? current.subcounty?.id ?? null,
                  }
                : null,
        }));
    }, [setScope, user?.role, officeScope.subcounty?.id, officeScope.subcounty?.name]);

    const clear = useCallback(() => setScope(officeScope), [setScope, officeScope.subcounty?.id, officeScope.subcounty?.name]);

    const value = useMemo(() => {
        const active = fixed ?? scope;

        return {
            ...active,
            /** True when the officer may change scope at all. */
            adjustable: !fixed,
            /** Query parameters every scoped register should send. */
            params: {
                institution_id: active.institution?.id ?? undefined,
                subcounty_id: active.institution ? undefined : (active.subcounty?.id ?? undefined),
            },
            label: active.institution?.name ?? active.subcounty?.name ?? null,
            selectSubcounty,
            selectInstitution,
            clear,
        };
    }, [fixed, scope, selectSubcounty, selectInstitution, clear]);

    return <ScopeContext.Provider value={value}>{children}</ScopeContext.Provider>;
}

export function useScope() {
    const context = useContext(ScopeContext);

    if (context === null) {
        throw new Error('useScope must be used within a ScopeProvider.');
    }

    return context;
}
