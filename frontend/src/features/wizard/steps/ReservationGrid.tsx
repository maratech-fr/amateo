import type { Closure } from "@/features/cockpit/api";
import { cn } from "@/shared/lib/utils";

import type { Venue, VenueTrainingSlot } from "../api";
import { fmtMinutes as fmt, hhmm, toMinutes as startMinutes } from "../lib/days";
import { closureForSlot, closureLabel } from "../lib/venueClosures";
import { END_MIN, gridTemplateColumns, ROW_H, START_MIN, STEP, WEEK } from "../lib/weekGrid";

interface Props {
  venue: Venue;
  slots: VenueTrainingSlot[];
  /** slotKey → names of the teams reserved on it (empty/absent = free). */
  reservedTeams: Map<string, string[]>;
  slotKeyOf: (slot: VenueTrainingSlot) => string;
  capacityOf: (slot: VenueTrainingSlot) => number;
  onSelectSlot: (slot: VenueTrainingSlot) => void;
  /**
   * Fermetures de gymnase (P2-22, D1/D2) — venue-scoped. Un créneau d'un jour fermé est barré ;
   * SANS réservation il est désactivé (rien à y ajouter), AVEC réservation il reste cliquable
   * pour le geste correctif (retirer l'épinglage orphelin qui bloque la génération). Vide par défaut.
   */
  closures?: Closure[];
}

/**
 * Per-venue weekly grid for the "Réserver" tab. Mirrors VenueAvailabilityGrid's
 * geometry but is ASSIGN-mode: empty cells are inert (you reserve existing slots,
 * not create them), and each slot shows the team(s) reserved on it + its
 * remaining capacity, click → the assign modal.
 */
export function ReservationGrid({ venue, slots, reservedTeams, slotKeyOf, capacityOf, onSelectSlot, closures = [] }: Props) {
  const color = venue.color ?? "var(--accent)";

  // Dynamic vertical range: only the hours that actually hold slots for THIS
  // venue (Réserver-only — the Gymnases grid keeps the fixed 08–23 for creation).
  // Rounded to the hour; falls back to the fixed range when the venue has none.
  const startMins = slots.map((s) => startMinutes(s.startTime));
  const endMins = slots.map((s) => startMinutes(s.startTime) + s.durationMinutes);
  const gridStart = slots.length > 0 ? Math.floor(Math.min(...startMins) / 60) * 60 : START_MIN;
  const gridEnd = slots.length > 0 ? Math.ceil(Math.max(...endMins) / 60) * 60 : END_MIN;
  const gridRows = Array.from({ length: Math.max(1, (gridEnd - gridStart) / STEP) }, (_, i) => gridStart + i * STEP);
  const gridTemplateRows = `1.5rem repeat(${gridRows.length}, ${ROW_H}px)`;

  return (
    // Même traitement que la grille de saisie (P4-37) : la 7ᵉ colonne rend le défilement
    // horizontal courant sur petit écran, et sans en-têtes figés on perd la colonne de vue.
    // Empilement strict identique — créneaux (z-10) < gouttière (z-20) < jours (z-30) < coin.
    <div className="max-h-[min(70vh,40rem)] overflow-auto rounded-lg border border-border bg-card">
      <div className="grid text-xs" style={{ gridTemplateColumns, gridTemplateRows }}>
        <div className="sticky left-0 top-0 z-40 border-b border-r border-border bg-card" style={{ gridColumn: 1, gridRow: 1 }} />
        {WEEK.map((d, i) => (
          <div key={d.n} className="sticky top-0 z-30 border-b border-l border-border bg-card py-0.5 text-center font-medium" style={{ gridColumn: 2 + i, gridRow: 1 }}>
            {d.label}
          </div>
        ))}

        {/* Time gutter — label on the hour */}
        {gridRows.map((m, i) => (
          <div key={`t${m}`} className="sticky left-0 z-20 border-r border-border bg-card pr-1 text-right text-[10px] text-muted-foreground" style={{ gridColumn: 1, gridRow: 2 + i }}>
            {0 === m % 60 ? fmt(m) : ""}
          </div>
        ))}

        {/* Inert grid lines — no create-on-click here (unlike the Gymnases grid). */}
        {WEEK.map((d, di) =>
          gridRows.map((m, ri) => <div key={`g${d.n}-${m}`} className={cn("border-l border-t border-border/40 border-l-2 border-l-border", 0 === m % 60 ? "border-t-border/70" : "", m >= 12 * 60 && m < 14 * 60 ? "bg-destructive/5" : "")} style={{ gridColumn: 2 + di, gridRow: 2 + ri }} aria-hidden="true" />),
        )}

        {/* Slots */}
        {slots.map((slot) => {
          const di = WEEK.findIndex((d) => d.n === slot.dayOfWeek);
          if (di < 0) {
            return null;
          }
          const startRow = 2 + Math.round((startMinutes(slot.startTime) - gridStart) / STEP);
          const span = Math.max(1, Math.round(slot.durationMinutes / STEP));
          const teams = reservedTeams.get(slotKeyOf(slot)) ?? [];
          const capacity = capacityOf(slot);
          const label = 0 === teams.length ? "libre" : teams.join(", ");
          const dayLabel = WEEK[di]?.label ?? "";
          // P2-17 — libellé de groupe du créneau mutualisé, affiché discrètement (pas de fusion
          // ici, contrairement à la vue planning). Vide/trim→rien.
          const groupLabel = (slot.groupLabel ?? "").trim();
          // P2-22 D1/D2 — jour fermé : barré + libellé. Désactivé SANS réservation (rien à
          // ajouter), cliquable AVEC (geste correctif : retirer l'épinglage orphelin).
          const closedBy = closureForSlot(slot, closures);
          const closedText = closedBy ? closureLabel(closedBy) : "";
          const disabled = null !== closedBy && 0 === teams.length;
          return (
            <button
              key={slot.id}
              type="button"
              disabled={disabled}
              onClick={() => onSelectSlot(slot)}
              aria-label={`${dayLabel} ${hhmm(slot.startTime)} · ${venue.name}${"" !== groupLabel ? ` · ${groupLabel}` : ""} · ${teams.length}/${capacity} réservé${closedBy ? ` · ${closedText}` : ""} — cliquer pour gérer`}
              className={cn(
                "z-10 m-px flex flex-col items-start gap-0.5 overflow-hidden rounded border border-border border-l-4 px-1 py-0.5 text-left text-[10px] leading-tight hover:ring-1 hover:ring-accent",
                closedBy ? "line-through opacity-60" : "",
              )}
              style={{ gridColumn: 2 + di, gridRow: `${startRow} / span ${span}`, borderLeftColor: color, backgroundColor: `color-mix(in oklch, ${color} 30%, var(--card))` }}
            >
              <span className="flex w-full items-center justify-between gap-1 font-medium">
                <span>{hhmm(slot.startTime)}</span>
                {/* P4-180 — texte en `text-foreground`, jamais `text-accent`/`text-muted-foreground` : sur le
                    fond TEINTÉ de la case (color-mix ci-dessous) ces jetons tombent sous AA (gotcha #11). La
                    couleur d'accent reste réservée au GRAPHIQUE (bordure gauche, teinte de fond) ; la
                    hiérarchie plein/libre passe par la graisse et le compteur lui-même, pas par la couleur. */}
                <span className="shrink-0 tabular-nums text-foreground">
                  {teams.length}/{capacity}
                </span>
              </span>
              {"" !== groupLabel ? <span className="w-full truncate font-semibold uppercase tracking-wide text-foreground">{groupLabel}</span> : null}
              {closedBy ? <span className="w-full truncate">{closedText}</span> : null}
              <span className={cn("truncate text-foreground", 0 !== teams.length ? "font-medium" : "")}>{label}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}
