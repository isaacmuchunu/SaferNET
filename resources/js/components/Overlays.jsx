import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import clsx from 'clsx';
import { TriangleAlertIcon, XIcon } from 'lucide-react';
import { Button } from './Primitives';

const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** Escape-to-close, scroll lock and contained focus for every overlay. */
function useOverlay(open, onClose, panelRef) {
    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const previouslyFocused = document.activeElement;
        const { overflow } = document.body.style;
        document.body.style.overflow = 'hidden';

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose();

                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focusable = Array.from(panelRef.current?.querySelectorAll(FOCUSABLE) ?? []);

            if (focusable.length === 0) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', onKeyDown);
        const timer = window.setTimeout(() => {
            (panelRef.current?.querySelector('[data-autofocus]') ?? panelRef.current)?.focus();
        }, 0);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            window.clearTimeout(timer);
            document.body.style.overflow = overflow;
            previouslyFocused?.focus?.();
        };
    }, [open, onClose, panelRef]);
}

/** Right-hand record panel — the portal's primary detail surface. */
export function Drawer({ open, onClose, title, subtitle, children, footer, width = 'max-w-lg' }) {
    const panelRef = useRef(null);
    useOverlay(open, onClose, panelRef);

    if (!open) {
        return null;
    }

    return createPortal(
        <div className="fixed inset-0 z-80">
            <button aria-label="Close panel" onClick={onClose} className="absolute inset-0 animate-fade-in bg-brand-deeper/35" />
            <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                tabIndex={-1}
                className={clsx('absolute top-0 right-0 flex h-full w-full flex-col bg-white shadow-panel outline-none animate-slide-in', width)}
            >
                <div className="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
                    <div className="min-w-0">
                        <h2 className="truncate text-base font-bold">{title}</h2>
                        {subtitle && <p className="mt-0.5 truncate text-xs text-text-secondary">{subtitle}</p>}
                    </div>
                    <button onClick={onClose} aria-label="Close" className="rounded-lg p-1.5 text-text-muted hover:bg-surface-muted">
                        <XIcon size={18} />
                    </button>
                </div>
                <div className="flex-1 overflow-y-auto px-5 py-4">{children}</div>
                {footer && <div className="flex flex-wrap items-center gap-2 border-t border-border px-5 py-3.5">{footer}</div>}
            </div>
        </div>,
        document.body,
    );
}

/** Confirmation for decisions that are recorded or hard to reverse. */
export function ConfirmDialog({ open, title, description, confirmLabel, tone = 'brand', loading = false, onCancel, onConfirm }) {
    const panelRef = useRef(null);
    useOverlay(open, onCancel, panelRef);

    if (!open) {
        return null;
    }

    return createPortal(
        <div className="fixed inset-0 z-90 grid place-items-center p-4">
            <button aria-label="Cancel" onClick={onCancel} className="absolute inset-0 animate-fade-in bg-brand-deeper/35" />
            <div
                ref={panelRef}
                role="alertdialog"
                aria-modal="true"
                aria-label={title}
                tabIndex={-1}
                className="relative w-full max-w-sm animate-scale-in rounded-xl bg-white p-5 shadow-panel outline-none"
            >
                <div className={clsx('grid h-10 w-10 place-items-center rounded-full', tone === 'danger' ? 'bg-danger-soft text-danger' : 'bg-brand-soft text-brand')}>
                    <TriangleAlertIcon size={19} />
                </div>
                <h2 className="mt-3 text-base font-bold">{title}</h2>
                <p className="mt-1.5 text-sm leading-5 text-text-secondary">{description}</p>
                <div className="mt-5 flex justify-end gap-2">
                    <Button onClick={onCancel} disabled={loading}>
                        Cancel
                    </Button>
                    <Button data-autofocus variant={tone === 'danger' ? 'danger' : 'primary'} loading={loading} onClick={onConfirm}>
                        {confirmLabel}
                    </Button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
