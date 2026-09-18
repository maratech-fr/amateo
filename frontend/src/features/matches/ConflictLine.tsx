import { Bus, ChevronDown, ChevronRight, Clock, Dumbbell, Home, Sparkles } from "lucide-react";
import { Fragment, type ReactNode, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { coachFullName } from "@/shared/lib/coachName";
import { frDateShortNoYear } from "@/shared/lib/date";
import { dayLabelLong } from "@/shared/lib/days";
import { formatDurationMinutes } from "@/shared/lib/time";
import { cn } from "@/shared/lib/utils";

import type { Coach, Conflict, ConflictSideRole, LeagueKickoffWindow, Team, Venue, VenueAccessWindow } from "./api";
import { SIDE_ROLE_WORD } from "./lib/conflictLabels";
import { buildConflictSideLines, type ConflictSideKind, type ConflictSideLine, type ConflictSideModel } from "./lib/conflictSideLines";
import { sortConflictsByDate } from "./lib/conflictOrder";
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

/** « SF2 (coach) » / « SM2 (joueur) » — le rôle par côté annote l'équipe. */
function annotateSide(name: string, role: ConflictSideRole | undefined): string {
  return undefined !== role ? `${name} (${SIDE_ROLE_WORD[role]})` : name;
}

function conflictTitle(conflict: Conflict, coaches: Map<string, Coach>): string {
  // Personne en double : le nom seul. Le rôle vit désormais PAR CÔTÉ dans le résumé
  // (« SF2 (coach) et SM2 (joueur) ») — la ligne ne relit plus `coachRole`.
  if (undefined !== conflict.coachId) {
    return coachName(coaches, conflict.coachId);
  }
  switch (conflict.type) {
    case "VENUE_OVERLAP":
      return "Deux matchs sur le même créneau";
    case "LEAGUE_WINDOW_VIOLATION":
      return "Hors fenêtre autorisée par la ligue";
    case "ACCESS_WINDOW_LOST":
      return "Hors accès match";
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

/** « samedi 16:00–18:00, mercredi 18:00–20:00 » (jour du match d'abord, servi par le serveur) ;
 *  sans aucun accès match sur ce gymnase → « aucun accès match ce jour-là ». */
function accessWindowsPhrase(conflict: Conflict): string {
  const windows = (conflict.windows ?? []) as VenueAccessWindow[];
  if (0 === windows.length) {
    return "aucun accès match ce jour-là";
  }
  return windows.map((w) => `${dayLabelLong(w.dayOfWeek)} ${w.startTime}–${w.endTime}`).join(", ");
}

function conflictSummary(conflict: Conflict, teams: Map<string, Team>, venues: Map<string, Venue>): string {
  if ("VENUE_OVERLAP" === conflict.type && conflict.left && conflict.right) {
    return `${teamName(teams, conflict.left.teamId)} et ${teamName(teams, conflict.right.teamId)} — ${frDateShortNoYear(conflict.left.matchDate)}`;
  }
  if ("MATCH_MATCH" === conflict.type && conflict.left && conflict.right) {
    // Tous MAIN → nu (« SF2 et SM2 ») ; sinon CHAQUE côté est annoté de son rôle
    // (« SF2 (coach) et SM2 (joueur) ») — jamais un seul côté. Un seul prédicat.
    const leftName = teamName(teams, conflict.left.teamId);
    const rightName = teamName(teams, conflict.right.teamId);
    const allMain = [conflict.left.role, conflict.right.role].every((role) => "MAIN" === role);
    const body = allMain ? `${leftName} et ${rightName}` : `${annotateSide(leftName, conflict.left.role)} et ${annotateSide(rightName, conflict.right.role)}`;
    return `${body} — ${frDateShortNoYear(conflict.left.matchDate)}`;
  }
  if ("MATCH_TRAINING" === conflict.type && conflict.fixture && conflict.training) {
    // Le match d'abord, même règle d'annotation (nu si tout MAIN).
    const matchName = teamName(teams, conflict.fixture.teamId);
    const trainingName = teamName(teams, conflict.training.teamId);
    const matchRole = "role" in conflict.fixture ? conflict.fixture.role : undefined;
    const allMain = [matchRole, conflict.training.role].every((role) => "MAIN" === role);
    return allMain
      ? `Match ${matchName} × entraînement ${trainingName}`
      : `Match ${annotateSide(matchName, matchRole)} × entraînement ${annotateSide(trainingName, conflict.training.role)}`;
  }
  if ("VENUE_UNAVAILABLE" === conflict.type && conflict.fixture) {
    return `Match ${teamName(teams, conflict.fixture.teamId)} du ${frDateShortNoYear(conflict.fixture.matchDate)} — gymnase indisponible, à repositionner`;
  }
  if ("ACCESS_WINDOW_LOST" === conflict.type && conflict.fixture) {
    const venueName = venues.get(conflict.venueId ?? "")?.name ?? "ce gymnase";
    return `Placé hors des accès match de ${venueName} (${accessWindowsPhrase(conflict)}) — déplacez le match ou ajustez l'accès dans Configuration.`;
  }
  if ("LEAGUE_WINDOW_VIOLATION" === conflict.type && conflict.fixture) {
    const windows = ((conflict.windows ?? []) as LeagueKickoffWindow[]).map((w) => `${w.kickoffMin}–${w.kickoffMax}`).join(", ");
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

/** L'icône de lieu par côté (domicile/extérieur/entraînement) — TABLE, pas un décideur. */
const KIND_ICON: Record<ConflictSideKind, typeof Home> = {
  home: Home,
  away: Bus,
  training: Dumbbell,
};

/** La pastille « estimé » collée à l'heure du coup d'envoi emprunté à une habitude. */
function EstimatedPill(): ReactNode {
  return (
    <StatusPill variant="neutral" className="px-1.5 py-0" icon={<Clock className="size-3" aria-hidden="true" />}>
      estimé
    </StatusPill>
  );
}

/** Une ligne de côté (équipe + rôle | lieu · adversaire + horaires) — MATCH_MATCH / MATCH_TRAINING. */
function ConflictSideRow({ side }: { side: ConflictSideLine }) {
  const Icon = KIND_ICON[side.kind];
  return (
    <li className="grid grid-cols-[5.5rem_1fr] gap-x-2">
      <span>
        <span className="font-medium text-foreground">{side.teamName}</span>
        {undefined !== side.roleWord ? <span className="text-muted-foreground"> {side.roleWord}</span> : null}
      </span>
      {/* min-w-0 : sans lui, une piste 1fr de grille refuse de rétrécir → l'enfant déborde. */}
      <div className="flex min-w-0 flex-wrap gap-x-3 gap-y-0.5">
        {/* Groupe LIEU : le bloc icône + domicile/extérieur reste insécable ; l'adversaire
            (potentiellement long) est un bloc SÉPARÉ qui s'enroule et se casse — jamais tronqué,
            jamais nowrap (seules les HEURES le sont). */}
        <span className="flex min-w-0 flex-wrap items-center gap-x-1">
          <span className="flex items-center gap-1 whitespace-nowrap">
            <Icon className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span className="text-foreground">{side.place}</span>
          </span>
          {undefined !== side.opponent ? (
            <span className="min-w-0 text-foreground [overflow-wrap:anywhere]">
              <span aria-hidden="true">· </span>
              {side.opponent}
            </span>
          ) : null}
        </span>
        {/* Groupe HORAIRES : chaque segment (libellé + heure) insécable. */}
        <span className="flex flex-wrap items-center gap-x-1">
          {side.segments.map((segment, index) => (
            <span key={index} className="whitespace-nowrap">
              {undefined !== segment.separator ? <span aria-hidden="true">{"arrow" === segment.separator ? " → " : " · "}</span> : null}
              {undefined !== segment.label ? <span className={true === segment.emphasis ? "font-semibold text-foreground" : "text-muted-foreground"}>{segment.label} </span> : null}
              <span className={cn("tabular-nums text-foreground", true === segment.emphasis ? "font-semibold" : undefined)}>{segment.value}</span>
              {true === segment.estimated ? (
                <>
                  {" "}
                  <EstimatedPill />
                </>
              ) : null}
            </span>
          ))}
          {true === side.travelUnknown ? (
            <span className="whitespace-nowrap text-muted-foreground">
              <span aria-hidden="true"> · </span>trajet inconnu
            </span>
          ) : null}
        </span>
      </div>
    </li>
  );
}

/**
 * Le DÉTAIL par côté d'un conflit de personne : une ligne par équipe + la ligne de
 * chevauchement. Remplace, pour MATCH_MATCH / MATCH_TRAINING, la ligne grise et la
 * pastille globale « heure estimée » (le coup d'envoi porte sa propre pastille).
 */
function ConflictSideDetail({ model }: { model: ConflictSideModel }) {
  const { overlap } = model;
  return (
    <div className="mt-1 text-xs">
      <ul className="flex flex-col gap-0.5" aria-label="Détail par équipe">
        {model.sides.map((side, index) => (
          <ConflictSideRow key={index} side={side} />
        ))}
      </ul>
      {/* Chevauchement : PAS de text-warning (sous AA sur fond teinté) ; date répétée
          seulement quand début et fin tombent deux jours différents. */}
      <p className="mt-1 font-medium text-foreground">
        Chevauchement <span className="sr-only">de </span>
        <span className="tabular-nums">
          {overlap.crossDay ? `${overlap.startDay} ` : null}
          {overlap.start}
        </span>
        <span aria-hidden="true"> → </span>
        <span className="sr-only"> à </span>
        <span className="tabular-nums">
          {overlap.crossDay ? `${overlap.endDay} ` : null}
          {overlap.end}
        </span>{" "}
        · <span className="font-semibold">{formatDurationMinutes(overlap.minutes)}</span>
      </p>
    </div>
  );
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
  /** Les gymnases — pour nommer le lieu d'un entraînement dans le détail par côté. */
  venues: Map<string, Venue>;
  tone: DiagnosticGroup["tone"];
  isNew: boolean;
  /** Slot d'action à droite (pastille/éditeur de traitement, « Voir la semaine ») — absent dans un rendu nu. */
  trailing?: ReactNode;
  /** Slot pleine largeur SOUS la ligne (P4-207 : la note de traitement). */
  below?: ReactNode;
  /** Une écriture est en cours sur ce conflit → `aria-busy` sur le `<li>` (P4-207). */
  ariaBusy?: boolean;
}

/**
 * Une ligne de conflit — le `<li>` du radar. Le slot `trailing` se pose à droite (et
 * passe SOUS la phrase à 400 px, `flex-col` → `sm:flex-row`) ; le slot `below` s'étend
 * en pleine largeur sous la ligne (la note). Sans trailing ni below, la structure et
 * le rendu sont ceux du radar d'origine.
 *
 * Familles PERSONNE (MATCH_MATCH / MATCH_TRAINING) : une LIGNE PAR CÔTÉ + une ligne de
 * chevauchement (`buildConflictSideLines`) remplacent la ligne grise et la pastille
 * globale « heure estimée » (le coup d'envoi porte sa propre pastille). Les familles
 * gymnase/passerelle gardent leur rendu ACTUEL (ligne grise + pastille globale).
 */
export function ConflictLine({ conflict, teams, coaches, venues, tone, isNew, trailing, below, ariaBusy }: ConflictLineProps) {
  const sideModel = buildConflictSideLines(conflict, teams, venues);
  const content = (
    // min-w-0 flex-1 : la colonne texte rétrécit et rend la place au trailing shrink-0
    // (décision fondateur 2026-09-17 — les actions empilées gardent leur largeur propre).
    <div className="min-w-0 flex-1">
      <p className="flex flex-wrap items-center gap-1.5 font-medium">
        {conflictTitle(conflict, coaches)}
        {isNew ? (
          <StatusPill variant="accent" className="border-accent/30 px-1.5 text-[0.65rem] uppercase tracking-wide" icon={<Sparkles className="size-3 text-accent" aria-hidden="true" />}>
            Nouveau
          </StatusPill>
        ) : null}
      </p>
      <p className="text-muted-foreground">
        {conflictSummary(conflict, teams, venues)}
        {/* La pastille GLOBALE « heure estimée » disparaît pour les familles à détail par côté. */}
        {null === sideModel && estimatedTag(conflict) ? <span className="ml-1 rounded bg-muted px-1 text-xs uppercase tracking-wide">heure estimée</span> : null}
      </p>
      {null !== sideModel ? (
        <ConflictSideDetail model={sideModel} />
      ) : undefined !== conflict.start && undefined !== conflict.end ? (
        <p className="text-xs text-muted-foreground">
          {whenLabel(conflict.start)} → {whenLabel(conflict.end)}
        </p>
      ) : null}
    </div>
  );

  const topRow =
    undefined !== trailing ? (
      <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        {content}
        <div className="shrink-0 self-start">{trailing}</div>
      </div>
    ) : (
      content
    );

  return (
    <li aria-busy={true === ariaBusy || undefined} className={cn("rounded-md border px-3 py-2 text-sm", TONE_CLASSES[tone], undefined !== trailing || undefined !== below ? "flex flex-col gap-2" : undefined)}>
      {topRow}
      {undefined !== below ? <div>{below}</div> : null}
    </li>
  );
}

interface ConflictSeverityGroupsProps {
  conflicts: Conflict[];
  teams: Map<string, Team>;
  coaches: Map<string, Coach>;
  /** Les gymnases — pour nommer le lieu d'un entraînement dans le détail par côté. */
  venues: Map<string, Venue>;
  /** RMM-3 — empreintes des conflits NOUVEAUX (chip « Nouveau », ornement pur). */
  newFingerprints?: ReadonlySet<string>;
  /**
   * P4-207 — rendu d'un conflit délégué à l'appelant (radar ET onglet Conflits passent
   * un `ConflictResolutionControl`, qui compose lui-même la `ConflictLine` avec la
   * pastille/éditeur et la note). Absent = rendu nu d'une `ConflictLine` (usage pur).
   */
  renderConflict?: (conflict: Conflict, meta: { tone: DiagnosticGroup["tone"]; isNew: boolean }) => ReactNode;
}

/**
 * Le regroupement par gravité (1 = pire d'abord), gravités 6-7 repliées derrière un
 * compte — N angles morts se lisent en UNE ligne, pas N alertes. Maison unique
 * consommée par le `ConflictRadar` et par chaque entrée de l'onglet Conflits ; sans
 * `renderConflict`, elle rend des `ConflictLine` nues.
 */
export function ConflictSeverityGroups({ conflicts, teams, coaches, venues, newFingerprints, renderConflict }: ConflictSeverityGroupsProps) {
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
                {/* DANS un groupe de gravité : date croissante (l'ordre des groupes et des
                    entrées de pivot ne bouge pas). Décision fondateur 2026-09-17. */}
                {sortConflictsByDate(group.conflicts).map((conflict, index) => {
                  const key = conflict.fingerprint ?? `${conflict.type}-${conflict.coachId ?? conflict.unavailabilityId ?? ""}-${index}`;
                  const meta = { tone: group.tone, isNew: isNew(conflict) };
                  return undefined !== renderConflict ? (
                    <Fragment key={key}>{renderConflict(conflict, meta)}</Fragment>
                  ) : (
                    <ConflictLine key={key} conflict={conflict} teams={teams} coaches={coaches} venues={venues} tone={meta.tone} isNew={meta.isNew} />
                  );
                })}
              </ul>
            )}
          </section>
        );
      })}
    </div>
  );
}
