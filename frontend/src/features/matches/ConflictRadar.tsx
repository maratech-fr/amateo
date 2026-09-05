import { AlertTriangle, ChevronDown, ChevronRight, ShieldCheck, Sparkles } from "lucide-react";
import { useState } from "react";

import { frDateShortNoYear } from "@/shared/lib/date";
import { StatusPill } from "@/shared/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { cn } from "@/shared/lib/utils";

import type { Coach, Conflict, Team } from "./api";
import { groupBySeverity } from "./lib/diagnostic";
import { coachFullName } from "@/shared/lib/coachName";

interface ConflictRadarProps {
  conflicts: Conflict[];
  teams: Map<string, Team>;
  coaches: Map<string, Coach>;
  /**
   * RMM-3 — les empreintes des conflits NOUVEAUX depuis la dernière visite (le
   * « gardien »). Un conflit dont l'empreinte est dedans porte une chip « Nouveau ».
   * ORNEMENT PUR : rien de la sévérité, du tri, des libellés ni des étapes de la
   * boucle n'en dépend ; absent (query non résolue) = aucune chip, radar intact.
   */
  newFingerprints?: ReadonlySet<string>;
}

function coachName(coaches: Map<string, Coach>, id: string): string {
  const coach = coaches.get(id);
  // D-33 : formatage partagé — cette version omettait le `.trim()`, laissant un espace
  // final visible quand le coach n'a pas de nom de famille.
  return coachFullName(coach);
}

function teamName(teams: Map<string, Team>, id: string): string {
  return teams.get(id)?.name ?? "Équipe ?";
}

/** "sam. 4 oct. 15:30" from an ISO datetime. */
function whenLabel(iso: string): string {
  const date = new Date(iso);
  return date.toLocaleString("fr-FR", { weekday: "short", day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
}

function conflictTitle(conflict: Conflict, coaches: Map<string, Coach>): string {
  if (undefined !== conflict.coachId) {
    const role = "ASSISTANT" === conflict.coachRole ? " (assistant)" : "";
    return `${coachName(coaches, conflict.coachId)}${role}`;
  }
  switch (conflict.type) {
    case "VENUE_OVERLAP":
      return "Deux matchs sur le même créneau";
    case "LEAGUE_WINDOW_VIOLATION":
      return "Hors fenêtre autorisée par la ligue";
    case "ACCESS_WINDOW_LOST":
      return "L'accès match ne couvre plus ce match";
    case "TEAM_LINK_OVERLAP":
      return "Passerelle violée";
    case "COMPETITION_INCOMPLETE":
      return "Calendrier incomplet";
    case "AWAY_NO_FOOTPRINT":
      return "Extérieur sans heure ni habitude";
    default:
      return `Gymnase indisponible${null != conflict.label && "" !== conflict.label ? ` (${conflict.label})` : ""}`;
  }
}

function conflictSummary(conflict: Conflict, teams: Map<string, Team>): string {
  if (("MATCH_MATCH" === conflict.type || "VENUE_OVERLAP" === conflict.type) && conflict.left && conflict.right) {
    return `${teamName(teams, conflict.left.teamId)} et ${teamName(teams, conflict.right.teamId)} — ${frDateShortNoYear(conflict.left.matchDate)}`;
  }
  if ("MATCH_TRAINING" === conflict.type && conflict.fixture && conflict.training) {
    return `Match ${teamName(teams, conflict.fixture.teamId)} × entraînement ${teamName(teams, conflict.training.teamId)}`;
  }
  if ("VENUE_UNAVAILABLE" === conflict.type && conflict.fixture) {
    return `Match ${teamName(teams, conflict.fixture.teamId)} du ${frDateShortNoYear(conflict.fixture.matchDate)} — gymnase indisponible, à repositionner`;
  }
  if ("ACCESS_WINDOW_LOST" === conflict.type && conflict.fixture) {
    return `Match ${teamName(teams, conflict.fixture.teamId)} du ${frDateShortNoYear(conflict.fixture.matchDate)} à ${conflict.fixture.kickoffTime ?? "?"} — la fenêtre d'accès a changé après le placement`;
  }
  if ("LEAGUE_WINDOW_VIOLATION" === conflict.type && conflict.fixture) {
    const windows = (conflict.windows ?? []).map((w) => `${w.kickoffMin}–${w.kickoffMax}`).join(", ");
    return `Match ${teamName(teams, conflict.fixture.teamId)} du ${frDateShortNoYear(conflict.fixture.matchDate)} à ${conflict.fixture.kickoffTime ?? "?"} (fenêtres : ${windows}) — dérogation à demander tôt`;
  }
  if ("TEAM_LINK_OVERLAP" === conflict.type && conflict.left && conflict.right) {
    return `Équipes liées en même temps — ${teamName(teams, conflict.left.teamId)} et ${teamName(teams, conflict.right.teamId)} (joueurs partagés)`;
  }
  if ("COMPETITION_INCOMPLETE" === conflict.type && undefined !== conflict.teamId) {
    return `${conflict.competitionName ?? "?"} (${teamName(teams, conflict.teamId)}) — ${conflict.imported ?? 0}/${conflict.expected ?? "?"} journées : fichier partiel ou phase pas encore sortie`;
  }
  if ("AWAY_NO_FOOTPRINT" === conflict.type && conflict.fixture) {
    return `${teamName(teams, conflict.fixture.teamId)} · ${frDateShortNoYear(conflict.fixture.matchDate)} — invisible du radar, déclarez une habitude`;
  }
  return "Conflit";
}

/** « heure estimée » when a side's window borrows the team's habit (P1-4 PR C). */
function estimatedTag(conflict: Conflict): boolean {
  return true === conflict.fixture?.estimatedKickoff || true === conflict.left?.estimatedKickoff || true === conflict.right?.estimatedKickoff;
}

const TONE_CLASSES = {
  destructive: "border-destructive/40 bg-destructive/5",
  warning: "border-warning/30 bg-warning/5",
  muted: "border-border bg-muted/30",
} as const;

/**
 * The GRADED diagnostic (P1-4 PR E2): server-computed findings grouped by
 * severity (1 = worst first), severity 7 folded behind a count — 40 blind away
 * matches must read as one line, not 40 alerts. Empty = green "no clash".
 */
export function ConflictRadar({ conflicts, teams, coaches, newFingerprints }: ConflictRadarProps) {
  const groups = groupBySeverity(conflicts);
  const [unfolded, setUnfolded] = useState<Set<number>>(new Set());

  const isNew = (conflict: Conflict): boolean => undefined !== conflict.fingerprint && true === newFingerprints?.has(conflict.fingerprint);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <AlertTriangle className="size-4 text-warning" />
          Conflits
          {conflicts.length > 0 ? <span className="rounded-full bg-warning/15 px-2 text-xs text-warning">{conflicts.length}</span> : null}
        </CardTitle>
      </CardHeader>
      <CardContent>
        {0 === conflicts.length ? (
          <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <ShieldCheck className="size-4 text-success" />
            Aucun conflit détecté.
          </p>
        ) : (
          <div className="flex flex-col gap-3">
            {groups.map((group) => {
              const folded = group.folded && !unfolded.has(group.severity);
              return (
                <section key={group.severity}>
                  <h3 className="mb-1 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    {group.folded ? (
                      <button
                        type="button"
                        className="flex items-center gap-1.5"
                        aria-expanded={!folded}
                        onClick={() => {
                          const next = new Set(unfolded);
                          if (next.has(group.severity)) {
                            next.delete(group.severity);
                          } else {
                            next.add(group.severity);
                          }
                          setUnfolded(next);
                        }}
                      >
                        {folded ? <ChevronRight className="size-3" /> : <ChevronDown className="size-3" />}
                        {group.title}
                        <span className="rounded-full bg-muted px-1.5">{group.conflicts.length}</span>
                      </button>
                    ) : (
                      <>
                        {group.title}
                        <span className="rounded-full bg-muted px-1.5">{group.conflicts.length}</span>
                      </>
                    )}
                  </h3>
                  {folded ? null : (
                    <ul className="flex flex-col gap-2">
                      {group.conflicts.map((conflict, index) => (
                        <li
                          key={`${conflict.type}-${conflict.coachId ?? conflict.unavailabilityId ?? ""}-${index}`}
                          className={cn("rounded-md border px-3 py-2 text-sm", TONE_CLASSES[group.tone])}
                        >
                          <p className="flex flex-wrap items-center gap-1.5 font-medium">
                            {conflictTitle(conflict, coaches)}
                            {isNew(conflict) ? (
                              <StatusPill variant="accent" className="border-accent/30 px-1.5 text-[0.65rem] uppercase tracking-wide" icon={<Sparkles className="size-3 text-accent" aria-hidden="true" />}>
                                Nouveau
                              </StatusPill>
                            ) : null}
                          </p>
                          <p className="text-muted-foreground">
                            {conflictSummary(conflict, teams)}
                            {estimatedTag(conflict) ? <span className="ml-1 rounded bg-muted px-1 text-xs uppercase tracking-wide">heure estimée</span> : null}
                          </p>
                          {undefined !== conflict.start && undefined !== conflict.end ? (
                            <p className="text-xs text-muted-foreground">
                              {whenLabel(conflict.start)} → {whenLabel(conflict.end)}
                            </p>
                          ) : null}
                        </li>
                      ))}
                    </ul>
                  )}
                </section>
              );
            })}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
