import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api, setUnauthenticatedHandler, tokenStore } from './api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const queryClient = useQueryClient();
    const [user, setUser] = useState(null);
    const [status, setStatus] = useState(tokenStore.read() ? 'restoring' : 'signed_out');

    const signOutLocally = useCallback(() => {
        void queryClient.cancelQueries();
        queryClient.clear();
        tokenStore.clear();
        setUser(null);
        setStatus('signed_out');
    }, [queryClient]);

    const acceptSession = useCallback(async (payload) => {
        await queryClient.cancelQueries();
        queryClient.clear();
        tokenStore.write(payload.token);
        setUser(payload.data);
        setStatus(payload.requires);

        return payload;
    }, [queryClient]);

    useEffect(() => {
        setUnauthenticatedHandler(signOutLocally);
    }, [signOutLocally]);

    useEffect(() => {
        if (status !== 'restoring') {
            return undefined;
        }

        const controller = new AbortController();

        api.authContext(controller.signal)
            .then((payload) => {
                setUser(payload.data);
                setStatus(payload.requires);
            })
            .catch((error) => {
                if (error.name !== 'AbortError') {
                    signOutLocally();
                }
            });

        return () => controller.abort();
    }, [status, signOutLocally]);

    const signIn = useCallback(async (email, password) => {
        const payload = await api.login({ email, password, device_name: tokenStore.deviceName() });

        return acceptSession(payload);
    }, [acceptSession]);

    const changeTemporaryPassword = useCallback(async (password, passwordConfirmation) => {
        const payload = await api.changeTemporaryPassword({
            password,
            password_confirmation: passwordConfirmation,
            device_name: tokenStore.deviceName(),
        });

        return acceptSession(payload);
    }, [acceptSession]);

    const beginMfaSetup = useCallback(() => api.beginMfaSetup(), []);

    const confirmMfaSetup = useCallback(async (code) => {
        const payload = await api.confirmMfaSetup({ code, device_name: tokenStore.deviceName() });

        return acceptSession(payload);
    }, [acceptSession]);

    const verifyMfa = useCallback(async (code) => {
        const payload = await api.verifyMfa({ code, device_name: tokenStore.deviceName() });

        return acceptSession(payload);
    }, [acceptSession]);

    /** Re-reads the officer profile after it has been edited. */
    const refresh = useCallback(async () => {
        const payload = await api.me();
        setUser(payload.data);

        return payload.data;
    }, []);

    const signOut = useCallback(async () => {
        try {
            await api.logout();
        } catch {
            /* the token is discarded locally regardless of the server outcome */
        }

        signOutLocally();
    }, [signOutLocally]);

    const value = useMemo(
        () => ({
            user,
            role: user?.role,
            authState: status,
            isRestoring: status === 'restoring',
            isAuthenticated: status === 'signed_in',
            signIn,
            changeTemporaryPassword,
            beginMfaSetup,
            confirmMfaSetup,
            verifyMfa,
            signOut,
            refresh,
        }),
        [user, status, signIn, changeTemporaryPassword, beginMfaSetup, confirmMfaSetup, verifyMfa, signOut, refresh],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const context = useContext(AuthContext);

    if (context === null) {
        throw new Error('useAuth must be used within an AuthProvider.');
    }

    return context;
}
