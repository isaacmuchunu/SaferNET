import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import clsx from 'clsx';
import { CheckCircle2Icon, InfoIcon, TriangleAlertIcon, XCircleIcon, XIcon } from 'lucide-react';
import { TOAST_EVENT } from '../lib/toast';

const VARIANTS = {
    success: { icon: CheckCircle2Icon, className: 'text-success' },
    info: { icon: InfoIcon, className: 'text-info' },
    warning: { icon: TriangleAlertIcon, className: 'text-warning-strong' },
    error: { icon: XCircleIcon, className: 'text-danger' },
};

export function ToastViewport() {
    const [toasts, setToasts] = useState([]);

    useEffect(() => {
        const add = (event) => {
            const id = Date.now() + Math.random();
            setToasts((items) => [...items.slice(-2), { id, variant: 'success', ...event.detail }]);
            window.setTimeout(() => setToasts((items) => items.filter((item) => item.id !== id)), 5000);
        };

        window.addEventListener(TOAST_EVENT, add);

        return () => window.removeEventListener(TOAST_EVENT, add);
    }, []);

    return createPortal(
        <div
            role="status"
            aria-live="polite"
            className="pointer-events-none fixed right-5 bottom-5 z-100 flex w-[calc(100%-2.5rem)] max-w-[360px] flex-col gap-2"
        >
            {toasts.map((toast) => {
                const { icon: Icon, className } = VARIANTS[toast.variant] ?? VARIANTS.success;

                return (
                    <div
                        key={toast.id}
                        className="pointer-events-auto flex animate-fade-up items-start gap-3 rounded-xl border border-border bg-white p-3.5 shadow-panel"
                    >
                        <Icon size={18} className={clsx('mt-0.5 shrink-0', className)} />
                        <div className="min-w-0 flex-1">
                            <p className="text-sm leading-5 font-semibold">{toast.title}</p>
                            {toast.description && <p className="mt-0.5 text-xs leading-4 text-text-secondary">{toast.description}</p>}
                        </div>
                        <button
                            onClick={() => setToasts((items) => items.filter((item) => item.id !== toast.id))}
                            aria-label="Dismiss notification"
                            className="rounded-md p-0.5 text-text-muted hover:bg-surface-muted hover:text-text"
                        >
                            <XIcon size={15} />
                        </button>
                    </div>
                );
            })}
        </div>,
        document.body,
    );
}
