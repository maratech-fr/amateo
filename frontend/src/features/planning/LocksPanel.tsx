import { Eye, EyeOff, Lock, PanelRightClose } from "lucide-react";

import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { cn } from "@/shared/lib/utils";

import type { Slot } from "./api";
import { DAYS, type Lookups, parseTimeToMinutes, toHourMinute } from "./lib/grid";
import { LOCK_LENS_META, LOCK_LENS_ORDER } from "./lib/lockLens";

interface LocksPanelProps {
  /** Les créneaux du planning affiché verrouillés À LA MAIN (`lockOrigin === "MANUAL"`),
   *  déjà filtrés côté page. Le panneau les ordonne (jour puis heure) et les résout. */
  locks: Slot[];
  lookups: Lookups;
  selectedSlotId: string | null;
  /** Sélectionne (et fait défiler) le créneau — même mécanisme qu'un clic de diagnostic. */
  onSelectSlot: (slotId: string) => void;
  /** État de la lentille verrous (surbrillance de la grille). */
  lensActive: boolean;
  onToggleLens: () => void;
  /** Replie le panneau (et éteint la lentille — géré côté page, pas d'état fantôme).
   *  Même affordance que le repli des diagnostics : le bouton de la barre le rouvre. */
  onCollapse: () => void;
}

const dayLabelOf = (day: number): string => DAYS.find((d) => d.n === day)?.label ?? "?";

/**
 * PR 3 — « Verrous lisibles ». Panneau latéral (aside) listant les verrous posés À LA MAIN
 * sur le planning affiché : sa raison d'être est de rendre visible « le travail de verrouillage »
 * (l'en-tête le compte), et d'y naviguer d'un clic. Il porte aussi la lentille (surbrillance de
 * la grille par origine de verrou) et sa légende. Décision fondateur : panneau latéral, PAS de
 * menu déroulant — cohérent avec SlotDetail/DiagnosticsPanel qui vivent dans le même aside.
 */
export function LocksPanel({ locks, lookups, selectedSlotId, onSelectSlot, lensActive, onToggleLens, onCollapse }: LocksPanelProps) {
  const entries = [...locks]
    .map((slot) => ({
      slotId: slot.id,
      day: slot.dayOfWeek,
      startMin: parseTimeToMinutes(slot.startTime),
      teamLabel: lookups.teams.get(slot.teamId)?.name ?? "Équipe ?",
      venueLabel: lookups.venues.get(slot.venueId)?.name ?? "Gymnase ?",
      dayLabel: dayLabelOf(slot.dayOfWeek),
      timeLabel: toHourMinute(slot.startTime),
    }))
    .sort((a, b) => a.day - b.day || a.startMin - b.startMin);

  const count = entries.length;

  return (
    <Card role="region" aria-label="Verrous manuels" className="flex h-full min-h-0 flex-col overflow-hidden">
      <CardHeader className="shrink-0 flex-row items-start justify-between gap-2 pb-3">
        <div className="flex flex-col gap-0.5">
          <CardTitle className="flex items-center gap-2 text-base">
            <Lock aria-hidden="true" className="size-4 text-accent" />
            Verrous manuels
          </CardTitle>
          {/* « Preuve du travail » : le nombre de verrous posés à la main. */}
          <p className="text-xs text-muted-foreground">
            {count} verrou{count > 1 ? "s" : ""} posé{count > 1 ? "s" : ""} à la main
          </p>
        </div>
        {/* Repli — MÊME affordance que DiagnosticsPanel (retour fondateur : une seule manière). */}
        <button type="button" onClick={onCollapse} aria-label="Réduire les verrous manuels" title="Réduire (plus de place pour la grille)" className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground">
          <PanelRightClose className="size-4" />
        </button>
      </CardHeader>
      <CardContent className="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto pt-0">
        <button
          type="button"
          onClick={onToggleLens}
          aria-pressed={lensActive}
          className={cn(
            "flex shrink-0 items-center gap-2 self-start rounded-md border px-2 py-1 text-sm transition",
            lensActive ? "border-accent bg-surface-accent text-foreground" : "border-border text-muted-foreground hover:bg-muted hover:text-foreground",
          )}
        >
          {lensActive ? <EyeOff aria-hidden="true" className="size-4" /> : <Eye aria-hidden="true" className="size-4" />}
          {lensActive ? "Masquer sur la grille" : "Voir sur la grille"}
        </button>

        {/* Légende de la lentille — pastille + icône + libellé par catégorie : le sens n'est
            JAMAIS porté par la couleur seule (WCAG 1.4.1). Affichée quand la lentille est active. */}
        {lensActive ? (
          <ul aria-label="Légende de la lentille verrous" className="flex shrink-0 flex-col gap-1 rounded-md border border-border p-2">
            {LOCK_LENS_ORDER.map((origin) => {
              const meta = LOCK_LENS_META[origin];
              const Icon = meta.Icon;
              return (
                <li key={origin} className="flex items-center gap-2 text-xs text-muted-foreground">
                  <span aria-hidden="true" className={cn("size-2.5 shrink-0 rounded-full", meta.dotClass)} />
                  <Icon aria-hidden="true" className={cn("size-3.5 shrink-0", meta.textClass)} />
                  <span>{meta.label}</span>
                </li>
              );
            })}
          </ul>
        ) : null}

        {0 === count ? (
          <EmptyHint>Aucun verrou manuel sur ce planning.</EmptyHint>
        ) : (
          <ul className="flex flex-col gap-1">
            {entries.map((entry) => (
              <li key={entry.slotId}>
                <button
                  type="button"
                  onClick={() => onSelectSlot(entry.slotId)}
                  aria-label={`${entry.teamLabel} — ${entry.dayLabel} ${entry.timeLabel}, ${entry.venueLabel}`}
                  className={cn(
                    "flex w-full flex-col gap-0.5 rounded-md border border-border px-2 py-1.5 text-left text-xs transition hover:bg-muted",
                    entry.slotId === selectedSlotId ? "bg-muted ring-1 ring-accent" : "",
                  )}
                >
                  <span className="font-medium text-foreground">{entry.teamLabel}</span>
                  <span className="flex flex-wrap items-center gap-x-1 text-muted-foreground">
                    <span>
                      {entry.dayLabel} {entry.timeLabel}
                    </span>
                    <span aria-hidden="true">·</span>
                    <span>{entry.venueLabel}</span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
