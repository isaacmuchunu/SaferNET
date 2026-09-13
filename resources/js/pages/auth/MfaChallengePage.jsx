import { useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { Loader2Icon, ShieldCheckIcon } from 'lucide-react';
import { firstError } from '../../components/Primitives';
import { ApiError } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { AuthLayout, AuthLoading } from './AuthLayout';

export function MfaChallengePage() {
    const { authState, isRestoring, verifyMfa } = useAuth();
    const navigate = useNavigate();
    const [code, setCode] = useState('');
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    if (isRestoring) return <AuthLoading />;
    if (authState === 'signed_out') return <Navigate to="/signin" replace />;
    if (authState === 'password_change') return <Navigate to="/onboarding/password" replace />;
    if (authState === 'mfa_setup') return <Navigate to="/onboarding/mfa" replace />;
    if (authState === 'signed_in') return <Navigate to="/" replace />;

    const errors = error instanceof ApiError ? error.errors : {};

    async function submit(event) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);

        try {
            await verifyMfa(code);
            navigate('/', { replace: true });
        } catch (caught) {
            setError(caught);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <AuthLayout activeStep={1} eyebrow="Identity verification">
            <div className="mt-4 grid h-12 w-12 place-items-center rounded-2xl bg-teal-100 text-teal-700"><ShieldCheckIcon size={24} /></div>
            <h1 className="mt-5 text-[27px] font-black tracking-[-.035em] text-[#103d45]">One more security check</h1>
            <p className="mt-2 text-sm leading-6 text-[#587477]">Enter the current code from your authenticator app. A saved recovery code also works.</p>
            <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
                <div>
                    <label htmlFor="mfa-code" className="mb-1.5 block text-xs font-bold text-[#264e53]">Authenticator or recovery code</label>
                    <input id="mfa-code" autoFocus autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value)} className="h-14 w-full rounded-xl border border-teal-900/15 bg-white text-center font-mono text-xl font-black tracking-[.22em] shadow-sm outline-none transition focus:border-teal-500 focus:ring-4 focus:ring-teal-500/10" />
                </div>
                {firstError(errors, 'code') && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">{firstError(errors, 'code')}</p>}
                <button type="submit" disabled={submitting || code.trim().length < 6} className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-700 to-cyan-600 text-sm font-bold text-white shadow-lg shadow-teal-800/15 disabled:opacity-60">
                    {submitting && <Loader2Icon size={17} className="animate-spin" />} Verify secure sign-in
                </button>
            </form>
        </AuthLayout>
    );
}
