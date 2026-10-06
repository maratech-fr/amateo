import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";
import { useToastStore, type ToastVariant } from "@/shared/stores/toastStore";

const ACCENT: Record<ToastVariant, string> = {
  error: "border-l-destructive",
  success: "border-l-success",
  info: "border-l-accent",
};

/**
 * Global toast host (FRT-01/02). Theme-aware (uses card tokens), accessible
 * (aria-live region; errors use role="alert"). Rendered once at the app root.
 */
export function Toaster() {
  const toasts = useToastStore((s) => s.toasts);
  const dismiss = useToastStore((s) => s.dismiss);

  return (
    <div
      className="pointer-events-none fixed inset-x-0 bottom-0 z-[100] flex flex-col items-center gap-2 p-4 sm:items-end"
      aria-live="polite"
      aria-atomic="false"
    >
      {toasts.map((t) => (
        <div
          key={t.id}
          role={t.variant === "error" ? "alert" : "status"}
          className={cn(
            "pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border border-l-4 border-border bg-card px-4 py-3 text-sm text-card-foreground shadow-lg",
            ACCENT[t.variant],
          )}
        >
          <span className="flex-1 break-words">{t.message}</span>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            onClick={() => dismiss(t.id)}
            aria-label="Fermer la notification"
            className="-mr-2 -mt-1 shrink-0 text-muted-foreground"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <path d="M18 6 6 18M6 6l12 12" />
            </svg>
          </Button>
        </div>
      ))}
    </div>
  );
}
