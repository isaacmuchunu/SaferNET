export const TOAST_EVENT = 'safernet-toast';

/**
 * Fires a toast from anywhere — including outside React — by dispatching a
 * window event the single ToastViewport listens for.
 */
export function showToast(title, options = {}) {
    window.dispatchEvent(new CustomEvent(TOAST_EVENT, { detail: { title, ...options } }));
}

export const toastError = (error, fallback = 'The action could not be completed') =>
    showToast(fallback, { description: error?.message, variant: 'error' });
