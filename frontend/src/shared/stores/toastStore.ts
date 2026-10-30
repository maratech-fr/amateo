import { create } from "zustand";

export type ToastVariant = "error" | "success" | "info";

/** Geste proposé dans le toast (« Annuler »), rendu en bouton par `Toaster`. */
export interface ToastAction {
  label: string;
  onClick: () => void;
}

export interface ToastOptions {
  action?: ToastAction;
}

export interface Toast {
  id: number;
  message: string;
  variant: ToastVariant;
  action?: ToastAction;
}

interface ToastState {
  toasts: Toast[];
  push: (message: string, variant?: ToastVariant, options?: ToastOptions) => number;
  dismiss: (id: number) => void;
}

let seq = 0;
const AUTO_DISMISS_MS: Record<ToastVariant, number> = { error: 7000, success: 4000, info: 5000 };
/** Un toast qui propose un geste reste plus longtemps : il faut le temps de lire, puis d'agir. */
const ACTION_DISMISS_MS = 10000;
const timers = new Map<number, number>();

export const useToastStore = create<ToastState>((set, get) => ({
  toasts: [],
  push: (message, variant = "error", options) => {
    const id = ++seq;
    const action = options?.action;
    set((s) => ({ toasts: [...s.toasts, { id, message, variant, ...(undefined !== action ? { action } : {}) }] }));
    if (typeof window !== "undefined") {
      timers.set(id, window.setTimeout(() => get().dismiss(id), undefined !== action ? ACTION_DISMISS_MS : AUTO_DISMISS_MS[variant]));
    }
    return id;
  },
  dismiss: (id) => {
    // Cancel the auto-dismiss timer so a manual close doesn't leave it running.
    const timer = timers.get(id);
    if (timer !== undefined) {
      clearTimeout(timer);
      timers.delete(id);
    }
    set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) }));
  },
}));

/** Imperative helper for non-component code (query/mutation cache handlers). */
export const toast = {
  error: (message: string) => useToastStore.getState().push(message, "error"),
  success: (message: string, options?: ToastOptions) => useToastStore.getState().push(message, "success", options),
  info: (message: string) => useToastStore.getState().push(message, "info"),
};
