import { useEffect, useRef, useState } from 'react';
import { CheckCircle2Icon, CopyIcon, Loader2Icon, SmartphoneIcon } from 'lucide-react';
import { Navigate, useNavigate } from 'react-router-dom';
import { firstError } from '../../components/Primitives';
import { ApiError } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { AuthLayout, AuthLoading } from './AuthLayout';

export function MfaSetupPage() {
    const { authState, isRestoring, beginMfaSetup, confirmMfaSetup } = useAuth();
    const navigate = useNavigate();
    const started = useRef(false);
    const [setup, setSetup] = useState(null);
    const [code, setCode] = useState('');
    const [recoveryCodes, setRecoveryCodes] = useState(null);
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (authState !== 'mfa_setup' || started.current) return;

        started.current = true;
        beginMfaSetup()
            .then((payload) => setSetup(payload.data))
            .catch(setError);
    }, [authState, beginMfaSetup]);

    if (isRestoring) return <AuthLoading />;
    if (authState === 'signed_out') return <Navigate to="/signin" replace />;
    if (authState === 'password_change') return <Navigate to="/onboarding/password" replace />;
    if (authState === 'signed_in' && !recoveryCodes) return <Navigate to="/" replace />;
    if (authState === 'mfa_challenge') return <Navigate to="/mfa" replace />;

    const errors = error instanceof ApiError ? error.errors : {};

    async function submit(event) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);

        try {
            const payload = await confirmMfaSetup(code);
            setRecoveryCodes(payload.recovery_codes);
        } catch (caught) {
            setError(caught);
        } finally {
            setSubmitting(false);
        }
    }

    if (recoveryCodes) {
        return (
            <AuthLayout activeStep={2} eyebrow="MFA recovery kit">
                <div className="mt-4 grid h-12 w-12 place-items-center rounded-2xl bg-emerald-100 text-emerald-700"><CheckCircle2Icon size={24} /></div>
                <h1 className="mt-5 text-[27px] font-black tracking-[-.035em] text-[#103d45]">MFA is now active</h1>
                <p className="mt-2 text-sm leading-6 text-[#587477]">Store these one-use recovery codes somewhere secure. They will not be shown again.</p>
                <div className="mt-5 grid grid-cols-2 gap-2 rounded-2xl border border-teal-900/10 bg-[#f4fbfa] p-4 font-mono text-sm font-bold text-[#174c50]">
                    {recoveryCodes.map((recoveryCode) => <span key={recoveryCode}>{recoveryCode}</span>)}
                </div>
                <button type="button" onClick={() => navigator.clipboard?.writeText(recoveryCodes.join('\n'))} className="mt-3 inline-flex items-center gap-2 text-xs font-bold text-teal-700"><CopyIcon size={14} /> Copy all codes</button>
                <button type="button" onClick={() => navigate('/', { replace: true })} className="mt-6 h-12 w-full rounded-xl bg-gradient-to-r from-teal-700 to-cyan-600 text-sm font-bold text-white shadow-lg shadow-teal-800/15">I have saved the codes</button>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout activeStep={1} eyebrow="First sign-in · Step 2 of 2">
            <div className="mt-4 grid h-12 w-12 place-items-center rounded-2xl bg-cyan-100 text-cyan-700"><SmartphoneIcon size={24} /></div>
            <h1 className="mt-5 text-[27px] font-black tracking-[-.035em] text-[#103d45]">Protect sign-in with MFA</h1>
            <p className="mt-2 text-sm leading-6 text-[#587477]">Add SAFERNET to Microsoft Authenticator, Google Authenticator or another TOTP app.</p>

            {setup ? (
                <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
                    <div className="rounded-2xl border border-cyan-200 bg-gradient-to-br from-cyan-50 to-teal-50 p-4">
                        <p className="text-[10px] font-extrabold tracking-[.14em] text-teal-700 uppercase">Manual setup key</p>
                        <p className="mt-2 break-all font-mono text-base font-black tracking-[.12em] text-[#174c50]">{setup.secret}</p>
                        <a href={setup.otpauth_uri} className="mt-3 inline-flex text-xs font-bold text-cyan-700 underline decoration-cyan-300 underline-offset-4">Open in authenticator app</a>
                    </div>
                    <div>
                        <label htmlFor="mfa-code" className="mb-1.5 block text-xs font-bold text-[#264e53]">Enter the current 6-digit code</label>
                        <input id="mfa-code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))} className="h-14 w-full rounded-xl border border-teal-900/15 bg-white text-center font-mono text-2xl font-black tracking-[.35em] shadow-sm outline-none transition focus:border-teal-500 focus:ring-4 focus:ring-teal-500/10" />
                    </div>
                    {firstError(errors, 'code') && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">{firstError(errors, 'code')}</p>}
                    <button type="submit" disabled={submitting || code.length !== 6} className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-700 to-cyan-600 text-sm font-bold text-white shadow-lg shadow-teal-800/15 disabled:opacity-60">
                        {submitting && <Loader2Icon size={17} className="animate-spin" />} Verify and enter SAFERNET
                    </button>
                </form>
            ) : (
                <div className="mt-8 flex items-center gap-3 rounded-xl bg-teal-50 px-4 py-3 text-sm font-semibold text-teal-800"><Loader2Icon size={18} className="animate-spin" /> Preparing your secure key…</div>
            )}
            {error && !(error instanceof ApiError && error.isValidation) && <p role="alert" className="mt-4 rounded-xl bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">{error.message}</p>}
        </AuthLayout>
    );
}
