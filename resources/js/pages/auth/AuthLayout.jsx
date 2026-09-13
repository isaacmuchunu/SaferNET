import { CheckIcon, ShieldCheckIcon } from 'lucide-react';
import clsx from 'clsx';

const STEPS = ['Secure password', 'Authenticator MFA', 'Protected access'];

export function AuthLayout({ children, activeStep = 0, eyebrow = 'Protected officer access' }) {
    return (
        <div className="relative min-h-screen overflow-hidden bg-[#eef9f7] text-[#123b3a]">
            <div className="pointer-events-none absolute -top-40 -right-36 h-[32rem] w-[32rem] rounded-full bg-cyan-300/30 blur-3xl" />
            <div className="pointer-events-none absolute -bottom-52 -left-36 h-[34rem] w-[34rem] rounded-full bg-emerald-300/30 blur-3xl" />

            <div className="relative mx-auto grid min-h-screen max-w-[1580px] lg:grid-cols-[minmax(0,1.15fr)_minmax(430px,.85fr)]">
                <aside className="relative hidden min-h-screen overflow-hidden border-r border-white/70 lg:block">
                    <img
                        src="/images/safernet-login-image.png"
                        alt="Learners collaborating in a protected school computer laboratory"
                        className="absolute inset-0 h-full w-full object-cover object-center"
                    />
                    <div className="absolute inset-0 bg-gradient-to-b from-[#dff8f3]/35 via-[#063946]/10 to-[#032f35]/95" />
                    <div className="absolute inset-0 bg-gradient-to-r from-[#e6faf6]/30 via-transparent to-[#043c46]/15" />

                    <div className="relative z-10 flex min-h-screen flex-col px-10 py-9 xl:px-16 xl:py-12">
                        <img
                            src="/images/safernet-logo.png"
                            alt="SaferNET — Kiambu County Schools Web Filtering"
                            className="h-12 w-auto max-w-[360px] object-contain drop-shadow-[0_2px_8px_rgba(255,255,255,.8)] xl:h-14"
                        />

                        <div className="mt-auto max-w-[610px] text-white drop-shadow-[0_2px_12px_rgba(0,25,35,.35)]">
                            <span className="inline-flex items-center gap-2 rounded-full border border-white/25 bg-[#073c43]/45 px-3 py-1.5 text-[11px] font-bold tracking-[.12em] text-white uppercase shadow-sm backdrop-blur-md">
                                <ShieldCheckIcon size={14} />
                                County digital safety network
                            </span>
                            <h2 className="mt-5 max-w-[560px] text-[38px] leading-[1.04] font-black tracking-[-.045em] text-white xl:text-[48px]">
                                Safer learning starts with trusted access.
                            </h2>
                            <p className="mt-4 max-w-lg text-[15px] leading-7 text-white/85">
                                One secure workspace for county oversight, school protection and accountable learner internet safety.
                            </p>
                        </div>

                        <p className="mt-7 border-t border-white/20 pt-5 text-[11px] font-medium tracking-wide text-white/75">
                            Kiambu County Directorate of Education · Authorised officers only
                        </p>
                    </div>
                </aside>

                <main className="flex min-h-screen items-center justify-center px-5 py-8 sm:px-9 lg:px-12 xl:px-16">
                    <div className="w-full max-w-[460px] animate-fade-up">
                        <div className="mb-8 flex items-center justify-between gap-4 lg:hidden">
                            <img src="/images/safernet-logo.png" alt="SaferNET" className="h-12 w-auto max-w-[230px]" />
                            <span className="grid h-10 w-10 place-items-center rounded-xl bg-teal-700 shadow-lg shadow-teal-800/15">
                                <ShieldCheckIcon size={21} className="text-white" />
                            </span>
                        </div>

                        <div className="mb-6 overflow-hidden rounded-2xl border border-white/80 shadow-[0_18px_45px_rgba(20,72,84,.14)] lg:hidden">
                            <img
                                src="/images/safernet-login-image.png"
                                alt="Learners collaborating in a protected school computer laboratory"
                                className="h-32 w-full object-cover object-center sm:h-40"
                            />
                        </div>

                        <section className="rounded-[26px] border border-white/90 bg-white/88 p-6 shadow-[0_30px_80px_rgba(23,79,86,.14)] backdrop-blur-xl sm:p-8">
                            <p className="text-[10px] font-extrabold tracking-[.18em] text-teal-700 uppercase">{eyebrow}</p>
                            {children}
                        </section>

                        <div className="mt-6 grid grid-cols-3 gap-2" aria-label="Account security stages">
                            {STEPS.map((step, index) => {
                                const complete = index < activeStep;
                                const current = index === activeStep;

                                return (
                                    <div key={step} className="min-w-0">
                                        <div className={clsx('h-1 rounded-full', complete || current ? 'bg-teal-600' : 'bg-teal-900/10')} />
                                        <p className={clsx('mt-2 flex items-center gap-1 text-[9px] font-bold tracking-wide uppercase', current ? 'text-teal-800' : 'text-[#78908f]')}>
                                            {complete && <CheckIcon size={10} />}
                                            <span className="truncate">{step}</span>
                                        </p>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </main>
            </div>
        </div>
    );
}

export function AuthLoading() {
    return (
        <div className="grid min-h-screen place-items-center bg-[#eef9f7]">
            <div className="flex animate-fade-in flex-col items-center gap-3">
                <img src="/images/safernet-mark.png" alt="SaferNET" className="h-14 w-14 object-contain" />
                <p className="text-[10px] font-bold tracking-[.18em] text-teal-800 uppercase">Securing your session</p>
            </div>
        </div>
    );
}
