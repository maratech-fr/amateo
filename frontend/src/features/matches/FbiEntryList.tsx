import { Check } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";

import type { Fixture, Team } from "./api";

interface FbiEntryListProps {
  /** Fixtures of the active week (already bucketed by the page). */
  fixtures: Fixture[];
  teams: Map<string, Team>;
  busy: boolean;
  onSubmit: (fixture: Fixture) => void;
}

/**
 * RMM-1 PR3 (L9, forme MINIMALE) — l'étape « Saisi dans FBI ». La vue de saisie
 * complète (par équipe, filtrable, échéance) est PR4 ; ici la liste des domiciles
 * PLACÉS de la semaine, chacun avec le geste « Marquer saisi » (submitFixture,
 * livré PR1). Cocher ferme la boucle : le match passe SUBMITTED et quitte la
 * liste. Un domicile déjà saisi n'y figure plus (il n'a plus rien à faire).
 */
export function FbiEntryList({ fixtures, teams, busy, onSubmit }: FbiEntryListProps) {
  const toEnter = fixtures.filter((f) => "HOME" === f.homeAway && "PLACED" === f.status).sort((a, b) => a.matchDate.localeCompare(b.matchDate));

  if (0 === toEnter.length) {
    return <EmptyHint>Aucun domicile à recopier dans FBI cette semaine.</EmptyHint>;
  }

  return (
    <ul className="flex flex-col gap-2">
      {toEnter.map((fixture) => (
        <li key={fixture.id} className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2 text-sm">
          <span className="min-w-0">
            <span className="block truncate font-medium">{teams.get(fixture.teamId)?.name ?? "Équipe ?"}</span>
            <span className="block truncate text-xs text-muted-foreground">
              {fixture.matchDate} · {fixture.kickoffTime ?? "—"} · vs {fixture.opponentLabel}
              {null !== fixture.externalRef ? <span className="tabular-nums"> · n° {fixture.externalRef}</span> : null}
            </span>
          </span>
          <Button variant="outline" size="sm" disabled={busy} className="shrink-0 border-success/40 text-success hover:bg-success/10" onClick={() => onSubmit(fixture)}>
            <Check className="size-3.5" />
            Marquer saisi
          </Button>
        </li>
      ))}
    </ul>
  );
}
