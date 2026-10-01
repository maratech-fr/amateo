import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Clock } from "lucide-react";
import { useEffect, useRef, useState } from "react";

import { api } from "@/shared/api/client";
import { Input } from "@/shared/components/ui/input";

/**
 * Dev-only clock simulator, sitting next to the club name. Shows the app's
 * current "now" (which the whole app + crons honour via the backend clock) and,
 * on click, lets you pin it to any date/time to rehearse the July-15 season
 * pivot, holiday reminders, etc. Rendered only under `import.meta.env.DEV`.
 */
interface ClockState {
  now: string;
  pinned: boolean;
}

const getClock = (): Promise<ClockState> => api.get("dev/clock").json<ClockState>();
const setClock = (at: string | null): Promise<ClockState> => api.post("dev/clock", { json: { at } }).json<ClockState>();

// The whole widget works in UTC so the calendar day the dev picks is exactly
// the day the season pivot (SeasonResolver, UTC date) sees — no off-by-one near
// midnight from a browser-local → UTC conversion.

/** ISO string → value for a <input type="datetime-local">, in UTC (no seconds). */
const toInputValue = (iso: string): string => new Date(iso).toISOString().slice(0, 16);

export function DevClock() {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const { data } = useQuery({ queryKey: ["dev-clock"], queryFn: getClock, staleTime: 10_000 });

  const mutation = useMutation({
    mutationFn: setClock,
    onSuccess: (state) => {
      queryClient.setQueryData(["dev-clock"], state);
      // Season selection, gates and readonly flags derive from "now" — refresh
      // everything so the shifted clock is reflected across the app at once.
      void queryClient.invalidateQueries();
      setOpen(false);
    },
  });

  useEffect(() => {
    if (!open) {
      return;
    }
    const onDown = (e: MouseEvent): void => {
      if (null !== rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", onDown);
    return () => document.removeEventListener("mousedown", onDown);
  }, [open]);

  if (undefined === data) {
    return null;
  }

  const label = new Date(data.now).toLocaleString("fr-FR", { dateStyle: "short", timeStyle: "short", timeZone: "UTC" });
  const shift = (days: number): void => {
    const d = new Date(data.now);
    d.setUTCDate(d.getUTCDate() + days);
    mutation.mutate(d.toISOString());
  };

  return (
    <div ref={rootRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        title="Horloge simulée (dev) — cliquer pour modifier"
        className={`flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs transition-colors ${
          data.pinned ? "border-amber-500/60 bg-amber-500/10 text-amber-600 dark:text-amber-400" : "border-border text-muted-foreground hover:bg-muted"
        }`}
      >
        <Clock className="size-3" />
        {label}
        {data.pinned ? <span className="font-semibold">•</span> : null}
      </button>
      {open ? (
        <div className="absolute left-0 z-40 mt-1 w-64 rounded-lg border border-border bg-card p-3 shadow-lg">
          <div className="mb-1 text-xs font-medium text-muted-foreground">Simuler la date / l'heure (dev)</div>
          <Input
            type="datetime-local"
            aria-label="Date et heure simulées"
            defaultValue={toInputValue(data.now)}
            className="mb-2"
            onChange={(e) => {
              const v = e.target.value;
              if ("" !== v) {
                // Interpret the picked wall time as UTC so the chosen day is
                // exactly the day the season pivot sees.
                mutation.mutate(new Date(`${v}:00Z`).toISOString());
              }
            }}
          />
          <div className="mb-2 flex flex-wrap gap-1">
            <button type="button" className="rounded border border-border px-2 py-1 text-xs hover:bg-muted" onClick={() => shift(1)}>
              +1 j
            </button>
            <button type="button" className="rounded border border-border px-2 py-1 text-xs hover:bg-muted" onClick={() => shift(7)}>
              +1 sem
            </button>
            <button type="button" className="rounded border border-border px-2 py-1 text-xs hover:bg-muted" onClick={() => shift(30)}>
              +1 mois
            </button>
          </div>
          <button
            type="button"
            className="w-full rounded-md border border-border px-2 py-1 text-xs text-muted-foreground hover:bg-muted disabled:opacity-50"
            disabled={!data.pinned}
            onClick={() => mutation.mutate(null)}
          >
            Réinitialiser (heure réelle)
          </button>
        </div>
      ) : null}
    </div>
  );
}
