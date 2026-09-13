import { useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { EyeIcon, EyeOffIcon, KeyRoundIcon, Loader2Icon } from 'lucide-react';
import { firstError } from '../../components/Primitives';
import { ApiError } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { AuthLayout, AuthLoading } from './AuthLayout';

export function PasswordSetupPage() {
    const { authState, isRestoring, changeTemporaryPassword } = useAuth();
    const navigate = useNavigate();
    const [password, setPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [visible, setVisible] = useState(false);
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    if (isRestoring) return <AuthLoading />;
    if (authState === 'signed_out') return <Navigate to="/signin" replace />;
    if (authState === 'signed_in') return <Navigate to="/" replace />;
    if (authState !== 'password_change') return <Navigate to="/onboarding/mfa" replace />;

    const errors = error instanceof ApiError ? error.errors : {};

    async function submit(event) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);

        try {
            await changeTemporaryPassword(password, confirmation);
            navigate('/onboarding/mfa', { replace: true });
        } catch (caught) {
            setError(caught);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <AuthLayout activeStep={0} eyebrow="First sign-in · Step 1 of 2">
            <div className="mt-4 grid h-12 w-12 place-items-center rounded-2xl bg-teal-100 text-teal-700">
                <KeyRoundIcon size={23} />
            </div>
            <h1 className="mt-5 text-[27px] font-black tracking-[-.035em] text-[#103d45]">Make the account yours</h1>
            <p className="mt-2 text-sm leading-6 text-[#587477]">Replace the temporary password before any school or county records become available.</p>

            <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
                <PasswordField id="new-password" label="New password" value={password} onChange={setPassword} visible={visible} toggle={() => setVisible((value) => !value)} autoComplete="new-password" />
                <PasswordField id="confirm-password" label="Confirm new password" value={confirmation} onChange={setConfirmation} visible={visible} autoComplete="new-password" />
                <p className="rounded-xl bg-teal-50 px-3 py-2.5 text-[11px] leading-4 text-teal-900">Use at least 12 characters with upper and lowercase letters, a number and a symbol.</p>
                {firstError(errors, 'password') && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">{firstError(errors, 'password')}</p>}
                <button type="submit" disabled={submitting} className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-700 to-cyan-600 text-sm font-bold text-white shadow-lg shadow-teal-800/15 transition hover:-translate-y-0.5 disabled:translate-y-0 disabled:opacity-60">
                    {submitting && <Loader2Icon size={17} className="animate-spin" />}
                    Save password and continue
                </button>
            </form>
        </AuthLayout>
    );
}

function PasswordField({ id, label, value, onChange, visible, toggle, autoComplete }) {
    return (
        <div>
            <label htmlFor={id} className="mb-1.5 block text-xs font-bold text-[#264e53]">{label}</label>
            <div className="relative">
                <input id={id} type={visible ? 'text' : 'password'} autoComplete={autoComplete} value={value} onChange={(event) => onChange(event.target.value)} className="h-12 w-full rounded-xl border border-teal-900/15 bg-white px-3 pr-11 text-sm shadow-sm outline-none transition focus:border-teal-500 focus:ring-4 focus:ring-teal-500/10" />
                {toggle && <button type="button" onClick={toggle} aria-label={visible ? 'Hide password' : 'Show password'} className="absolute top-1.5 right-1.5 rounded-lg p-2.5 text-[#698283] hover:bg-teal-50">{visible ? <EyeOffIcon size={17} /> : <EyeIcon size={17} />}</button>}
            </div>
        </div>
    );
}
