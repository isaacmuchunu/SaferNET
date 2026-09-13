import { useState } from 'react';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { EyeIcon, EyeOffIcon, Loader2Icon, LockKeyholeIcon } from 'lucide-react';
import { firstError } from '../../components/Primitives';
import { ApiError } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { showToast } from '../../lib/toast';
import { AuthLayout, AuthLoading } from './AuthLayout';

const NEXT_ROUTE = {
    password_change: '/onboarding/password',
    mfa_setup: '/onboarding/mfa',
    mfa_challenge: '/mfa',
};

export function SignInPage() {
    const { authState, isAuthenticated, isRestoring, signIn } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);

    if (isRestoring) return <AuthLoading />;
    if (isAuthenticated) return <Navigate to={location.state?.from ?? '/'} replace />;
    if (NEXT_ROUTE[authState]) return <Navigate to={NEXT_ROUTE[authState]} replace />;

    const fieldErrors = error instanceof ApiError ? error.errors : {};
    const banner = error && !(error instanceof ApiError && error.isValidation) ? error.message : firstError(fieldErrors, 'email');

    async function submit(event) {
        event.preventDefault();
        setSubmitting(true);
        setError(null);

        try {
            const payload = await signIn(email, password);

            if (payload.requires === 'signed_in') {
                showToast(`Welcome back, ${payload.data.name}`, { description: 'Your access scope is active.' });
                navigate(location.state?.from ?? '/', { replace: true });
                return;
            }

            navigate(NEXT_ROUTE[payload.requires], { replace: true });
        } catch (caught) {
            setError(caught);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <AuthLayout activeStep={0}>
            <div className="mt-4 flex items-center justify-between gap-4">
                <div>
                    <h1 className="text-[30px] font-black tracking-[-.04em] text-[#103d45]">Welcome back</h1>
                    <p className="mt-1.5 text-sm leading-6 text-[#5a7478]">Sign in with your official SAFERNET account.</p>
                </div>
                <div className="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-teal-100 to-cyan-100 text-teal-700">
                    <LockKeyholeIcon size={22} />
                </div>
            </div>

            <form onSubmit={submit} className="mt-7 space-y-4" noValidate>
                <div>
                    <label htmlFor="email" className="mb-1.5 block text-xs font-bold text-[#264e53]">Official email</label>
                    <input id="email" type="email" autoComplete="username" autoFocus value={email} onChange={(event) => setEmail(event.target.value)} placeholder="name@safernet.go.ke" className="h-12 w-full rounded-xl border border-teal-900/15 bg-white px-3 text-sm shadow-sm outline-none transition placeholder:text-[#9cb0b0] focus:border-teal-500 focus:ring-4 focus:ring-teal-500/10" />
                </div>

                <div>
                    <label htmlFor="password" className="mb-1.5 block text-xs font-bold text-[#264e53]">Password</label>
                    <div className="relative">
                        <input id="password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Enter your password" className="h-12 w-full rounded-xl border border-teal-900/15 bg-white pr-12 pl-3 text-sm shadow-sm outline-none transition placeholder:text-[#9cb0b0] focus:border-teal-500 focus:ring-4 focus:ring-teal-500/10" />
                        <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? 'Hide password' : 'Show password'} className="absolute top-1.5 right-1.5 rounded-lg p-2.5 text-[#698283] transition hover:bg-teal-50 hover:text-teal-700">
                            {showPassword ? <EyeOffIcon size={17} /> : <EyeIcon size={17} />}
                        </button>
                    </div>
                    {firstError(fieldErrors, 'password') && <p className="mt-1.5 text-[11px] font-semibold text-red-700">{firstError(fieldErrors, 'password')}</p>}
                </div>

                {banner && <p role="alert" className="rounded-xl border border-red-100 bg-red-50 px-3 py-2.5 text-xs font-semibold text-red-700">{banner}</p>}

                <button type="submit" disabled={submitting} className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-700 via-teal-600 to-cyan-600 text-sm font-bold text-white shadow-lg shadow-teal-800/20 transition hover:-translate-y-0.5 hover:shadow-xl disabled:translate-y-0 disabled:opacity-60">
                    {submitting && <Loader2Icon size={17} className="animate-spin" />}
                    {submitting ? 'Verifying secure access…' : 'Continue securely'}
                </button>
            </form>

            <div className="mt-6 flex items-start gap-2.5 rounded-xl border border-teal-100 bg-teal-50/80 px-3 py-3 text-[11px] leading-4 text-[#557273]">
                <LockKeyholeIcon size={14} className="mt-0.5 shrink-0 text-teal-700" />
                New officers use the temporary password sent separately by email and SMS, then create a private password and enable MFA.
            </div>
        </AuthLayout>
    );
}
