import { ChevronDown, ChevronRight, Sparkles } from "lucide-react";
import { type ReactNode, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { coachFullName } from "@/shared/lib/coachName";
import { frDateShortNoYear } from "@/shared/lib/date";
import { cn } from "@/shared/lib/utils";

import type { Coach, Conflict, Team } from "./api";
import { groupBySeverity, type DiagnosticGroup } from "./lib/diagnostic";

/**
 * PR A (onglet Conflits) — la LIGNE de conflit et son regroupement par gravité,
 * EXTRAITS du `ConflictRadar` (une seule maison : le radar les CONSOMME, rendu
 * identique). `ConflictLine` porte le titre, la phrase, la chip « Nouveau », le tag
 * « heure estimée », la plage horaire et la tonalité — plus un slot `trailing`
 * optionnel (le bouton « Voir la semaine » de l'onglet Conflits ; le radar n'en passe
 * pas → ligne identique). `ConflictSeverityGroups` groupe par gravité (gravités 6-7
 * repliées derrière un compte, patron radar), consommé par le radar ET l'onglet.
 */

function coachName(coaches: Map<string, Coach>, id: string): string {
  return coachFullName(coaches.get(id));
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
    const role = "ASSISTANT" === conflict.coachRole ? " (assistant d'un côté)" : "";
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
    case "FRIENDLY_ON_MATCH_SLOT":
      return "Amical sur un créneau de match";
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
  if ("FRIENDLY_ON_MATCH_SLOT" === conflict.type && conflict.fixture) {
    const reasons = conflict.reasons ?? [];
    const bits: string[] = [];
    if (reasons.includes("MATCH_SLOT_WINDOW")) {
      bits.push("créneau d'accès match");
    }
    if (reasons.includes("MATCH_WEEKEND")) {
      bits.push("week-end de match");
    }
    const why = bits.length > 0 ? ` (${bits.join(", ")})` : "";
    return `Amical ${teamName(teams, conflict.fixture.teamId)} du ${frDateShortNoYear(conflict.fixture.matchDate)}${why} — placement libre, à surveiller`;
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

interface ConflictLineProps {
  conflict: Conflict;
  teams: Map<string, Team>;
  coaches: Map<string, Coach>;
  tone: DiagnosticGroup["tone"];
  isNew: boolean;
  /** Slot d'action à droite (onglet Conflits : « Voir la semaine ») — absent dans le radar. */
  trailing?: ReactNode;
}

/**
 * Une ligne de conflit — le `<li>` du radar, à l'identique. Le slot `trailing`, quand
 * il est fourni, se pose à droite (et passe SOUS la phrase à 400 px, `flex-col` →
 * `sm:flex-row`) ; sans trailing, la structure et le rendu sont ceux du radar.
 */
export function ConflictLine({ conflict, teams, coaches, tone, isNew, trailing }: ConflictLineProps) {
  return (
    <li className={cn("rounded-md border px-3 py-2 text-sm", TONE_CLASSES[tone], undefined !== trailing ? "flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between" : undefined)}>
      <div>
        <p className="flex flex-wrap items-center gap-1.5 font-medium">
          {conflictTitle(conflict, coaches)}
          {isNew ? (
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
      </div>
      {undefined !== trailing ? <div className="self-start">{trailing}</div> : null}
    </li>
  );
}

interface ConflictSeverityGroupsProps {
  conflicts: Conflict[];
  teams: Map<string, Team>;
  coaches: Map<string, Coach>;
  /** RMM-3 — empreintes des conflits NOUVEAUX (chip « Nouveau », ornement pur). */
  newFingerprints?: ReadonlySet<string>;
  /** Onglet Conflits : le slot d'action par conflit (« Voir la semaine »). Absent = radar. */
  renderTrailing?: (conflict: Conflict) => ReactNode;
}

/**
 * Le regroupement par gravité (1 = pire d'abord), gravités 6-7 repliées derrière un
 * compte — N angles morts se lisent en UNE ligne, pas N alertes. Maison unique
 * consommée par le `ConflictRadar` (sans `renderTrailing` → rendu identique) et par
 * chaque entrée de l'onglet Conflits.
 */
export function ConflictSeverityGroups({ conflicts, teams, coaches, newFingerprints, renderTrailing }: ConflictSeverityGroupsProps) {
  const groups = groupBySeverity(conflicts);
  const [unfolded, setUnfolded] = useState<Set<number>>(new Set());

  const isNew = (conflict: Conflict): boolean => undefined !== conflict.fingerprint && true === newFingerprints?.has(conflict.fingerprint);

  return (
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
                  <ConflictLine
                    key={`${conflict.type}-${conflict.coachId ?? conflict.unavailabilityId ?? ""}-${index}`}
                    conflict={conflict}
                    teams={teams}
                    coaches={coaches}
                    tone={group.tone}
                    isNew={isNew(conflict)}
                    trailing={renderTrailing?.(conflict)}
                  />
                ))}
              </ul>
            )}
          </section>
        );
      })}
    </div>
  );
}
