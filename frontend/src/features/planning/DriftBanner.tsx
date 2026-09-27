import { CalendarClock } from "lucide-react";

import type { DriftEntry } from "./lib/drift";

/**
 * P2-30 (geste 3) — le bandeau « séances à la dérive » : les équipes qui ont MOINS de séances
 * placées que ce qu'on attend d'elles. PRÉSENTATION pure — le compte vient de `computeDrift`
 * (backend : seuils + placement) ; ici on ne fait que l'AFFICHER. Chaque équipe est un bouton
 * qui ARME le mode cible en PLACEMENT (clic sur une case → placeSlot pour cette équipe).
 */
interface DriftBannerProps {
  entries: DriftEntry[];
  teamName: (teamId: string) => string;
  /** Armer le mode cible en placement pour cette équipe. */
  onPlace: (teamId: string) => void;
  /** L'équipe dont le placement est en cours d'armement (mode cible actif) — bouton mis en avant. */
  activeTeamId?: string | null;
}

export function DriftBanner({ entries, teamName, onPlace, activeTeamId = null }: DriftBannerProps) {
  if (0 === entries.length) {
    return null;
  }
  return (
    <div className="mb-4 rounded-md border border-warning/40 bg-surface-warning px-3 py-2 text-sm" role="region" aria-label="Séances à replacer">
      <p className="flex items-center gap-1.5 font-medium text-foreground">
        <CalendarClock aria-hidden="true" className="size-4 text-warning" />
        Séances à replacer
      </p>
      <ul className="mt-1.5 flex flex-wrap gap-2">
        {entries.map((entry) => (
          <li key={entry.teamId}>
            <button
              type="button"
              onClick={() => onPlace(entry.teamId)}
              aria-pressed={entry.teamId === activeTeamId}
              className={`rounded-full border px-2.5 py-1 text-xs font-medium transition hover:bg-warning/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent ${
                entry.teamId === activeTeamId ? "border-accent bg-accent/15 text-foreground" : "border-warning/50 text-foreground"
              }`}
            >
              {teamName(entry.teamId)} · {entry.missing} séance{entry.missing > 1 ? "s" : ""} à replacer
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}
