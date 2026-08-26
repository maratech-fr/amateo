import { AlertTriangle, MapPin, MapPinOff } from "lucide-react";
import { EmptyHint } from "@/shared/components/ui/empty-hint";

import type { Fixture, Team } from "./api";
import { unplacedReasonLabel } from "./lib/unplacedReasonLabel";

interface UnplacedListProps {
  fixtures: Fixture[];
  teams: Map<string, Team>;
  selectedFixtureId: string | null;
  /** P1-4 PR D — per-fixture reason from the last auto-placement (« demandez
   * votre dérogation tôt »). Not persisted: lives until the next data refresh. */
  unplacedReasons?: Map<string, string>;
  onSelect: (id: string) => void;
}

/** A home fixture still needing a venue + kickoff. Away fixtures are not placed here. */
function isUnplacedHome(fixture: Fixture): boolean {
  return "HOME" === fixture.homeAway && (null === fixture.venueId || null === fixture.kickoffTime);
}

/** The to-do list of home matches to place — clicking one opens the placement panel. */
export function UnplacedList({ fixtures, teams, selectedFixtureId, unplacedReasons, onSelect }: UnplacedListProps) {
  const unplaced = fixtures.filter(isUnplacedHome).sort((a, b) => a.matchDate.localeCompare(b.matchDate));

  if (0 === unplaced.length) {
    return <EmptyHint>Aucun match domicile à placer.</EmptyHint>;
  }

  return (
    <ul className="flex flex-col gap-1">
      {unplaced.map((fixture) => (
        <li key={fixture.id}>
          <button
            type="button"
            onClick={() => onSelect(fixture.id)}
            aria-pressed={fixture.id === selectedFixtureId}
            className={`flex w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm transition-colors ${
              fixture.id === selectedFixtureId ? "border-accent bg-accent/10" : "border-border hover:bg-muted"
            }`}
          >
            <span className="min-w-0">
              <span className="block truncate font-medium">{teams.get(fixture.teamId)?.name ?? "Équipe ?"}</span>
              <span className="block truncate text-xs text-muted-foreground">
                {fixture.matchDate} · vs {fixture.opponentLabel}
                {/* RMM-1 PR3 (L7) — n° de rencontre : repère discret, jamais une clé. */}
                {null !== fixture.externalRef ? <span className="tabular-nums"> · n° {fixture.externalRef}</span> : null}
              </span>
              {/* P2-52 — la raison PERSISTANTE « le gymnase n'est plus affilié » : INFO calme
                  (ton muted + MapPinOff), distincte de la raison volatile d'auto-placement
                  ci-dessous (ton warning + AlertTriangle). Le match est récupérable. */}
              {null !== unplacedReasonLabel(fixture.unplacedReason) ? (
                <span className="mt-0.5 flex items-start gap-1 text-xs text-muted-foreground">
                  <MapPinOff className="mt-0.5 size-3 shrink-0" aria-hidden="true" />
                  {unplacedReasonLabel(fixture.unplacedReason)}
                </span>
              ) : null}
              {undefined !== unplacedReasons?.get(fixture.id) ? (
                <span className="mt-0.5 flex items-start gap-1 text-xs text-warning">
                  <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                  {unplacedReasons.get(fixture.id)}
                </span>
              ) : null}
            </span>
            <MapPin className="size-4 shrink-0 text-muted-foreground" />
          </button>
        </li>
      ))}
    </ul>
  );
}
