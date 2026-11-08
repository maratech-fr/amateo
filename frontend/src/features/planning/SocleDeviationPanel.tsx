import { HTTPError } from "ky";
import { useEffect, useId, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { CtcMark } from "@/shared/components/ui/ctc-mark";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { frDateNumericNoYear } from "@/shared/lib/date";
import { errorMessage } from "@/shared/lib/errorMessage";
import { cn } from "@/shared/lib/utils";

import { placementLabel } from "./lib/socleDeviationCells";
import { toReplaceReasonLabel } from "./lib/toReplaceReason";
import { PLANNING_TEXTS } from "./texts";
import type { SocleDeviation, SocleDeviationMoved, SocleDeviationRank, SocleDeviationTeam, SocleDeviationUnplaced } from "./api";

/** Ce que l'écran sait d'une équipe : son nom et son entente (CTC), lus des lookups de la page. */
export interface SocleDeviationTeamInfo {
  name: string;
  isCtc: boolean;
  ctcPartner: string | null;
}

interface SocleDeviationPanelProps {
  deviation: SocleDeviation;
  teamOf: (teamId: string) => SocleDeviationTeamInfo;
  venueName: (venueId: string) => string;
  /**
   * D6-d — cliquer une ligne « déplacée » sélectionne (et fait défiler jusqu'à) SA carte dans la
   * grille : `onSelectSlot(entry.to.slotId)`, même recette que `LocksPanel`/les diagnostics. Les
   * lignes « à replacer » restent du texte (aucune carte à viser). Absent = lignes non cliquables.
   */
  onSelectSlot?: (slotId: string) => void;
}

const DAY_MS = 86_400_000;

/**
 * Fermetures de gymnase (PR A) — le BILAN PAR RANG d'une version de FERMETURE vs le planning de
 * saison (ADR-0004) : rang par rang (ordre SERVI), puis sous chaque rang les équipes TOUCHÉES ou EN
 * PAUSE (jamais les inchangées, comptées au pied), puis sous chaque équipe ses lignes de détail —
 * déplacées (cliquables vers leur carte) et non replacées (pastille de RAISON servie ; raison nulle =
 * aucune étiquette, jamais inventée). Les nouvelles sont comptées, jamais listées.
 *
 * Tout vient du backend (`SocleDeviation` : comptes `teams[]`/`ranks[]`, lignes `moved`/`unplaced`,
 * partenaires `sharedWith` en ids) : le panneau PRÉSENTE, il ne recompte ni ne retrie rien — seul le
 * nom des partenaires se trie ici, par nom (`teamOf(id).name`). Le repère d'entente (`CtcMark`) se pose
 * devant le nom de l'équipe de la ligne, jamais devant un partenaire embarqué dans une phrase.
 *
 * Distinct de {@see ToReplaceList} (les deux panneaux coexistent sur l'écran de génération). Ne rend
 * rien quand aucun rang n'est servi (aucune équipe n'a de séance).
 */
export function SocleDeviationPanel({ deviation, teamOf, venueName, onSelectSlot }: SocleDeviationPanelProps) {
  const headingId = useId();
  if (0 === deviation.ranks.length) {
    return null;
  }

  const { startDate, endDate } = deviation.window;
  const oneWeek = (Date.parse(endDate) - Date.parse(startDate)) / DAY_MS < 7;
  const title = oneWeek ? PLANNING_TEXTS.rankReportTitleWeek(frDateNumericNoYear(startDate)) : PLANNING_TEXTS.rankReportTitleWeeks(frDateNumericNoYear(startDate), frDateNumericNoYear(endDate));

  const partnerNames = (ids: string[]): string[] => ids.map((id) => teamOf(id).name).sort((a, b) => a.localeCompare(b, "fr"));
  const unchanged = deviation.ranks.reduce((sum, r) => sum + r.unchanged, 0);
  const paused = deviation.ranks.reduce((sum, r) => sum + r.paused, 0);
  const touched = deviation.ranks.reduce((sum, r) => sum + r.teams, 0) - unchanged - paused;

  return (
    <section className="mb-4 rounded-md border border-border bg-card px-3 py-2 text-sm" aria-labelledby={headingId}>
      <h2 id={headingId} className="font-medium text-foreground">
        {title}
      </h2>
      <p className="mt-0.5 text-xs text-muted-foreground">{PLANNING_TEXTS.rankReportSubtitle}</p>
      <ul className="mt-2 flex flex-col gap-2">
        {deviation.ranks.map((rank) => (
          <RankItem key={`${rank.tierLabel}-${rank.tierWeight}`} rank={rank} deviation={deviation} teamOf={teamOf} venueName={venueName} partnerNames={partnerNames} onSelectSlot={onSelectSlot} />
        ))}
      </ul>
      <p className="mt-2 text-xs text-muted-foreground">{PLANNING_TEXTS.rankReportFooter(touched, unchanged, paused)}</p>
    </section>
  );
}

function RankItem({
  rank,
  deviation,
  teamOf,
  venueName,
  partnerNames,
  onSelectSlot,
}: {
  rank: SocleDeviationRank;
  deviation: SocleDeviation;
  teamOf: (teamId: string) => SocleDeviationTeamInfo;
  venueName: (venueId: string) => string;
  partnerNames: (ids: string[]) => string[];
  onSelectSlot?: (slotId: string) => void;
}) {
  const shown = deviation.teams.filter((t) => t.tierLabel === rank.tierLabel && t.tierWeight === rank.tierWeight && (t.changed || t.paused));
  return (
    <li>
      <p className="font-medium text-foreground">{PLANNING_TEXTS.rankReportLine(rank)}</p>
      {shown.length > 0 ? (
        <ul className="mt-1 flex flex-col gap-1 ps-3">
          {shown.map((team) => (
            <TeamItem
              key={team.teamId}
              team={team}
              moved={deviation.moved.filter((m) => m.teamId === team.teamId)}
              unplaced={deviation.unplaced.filter((u) => u.teamId === team.teamId)}
              teamOf={teamOf}
              venueName={venueName}
              partnerNames={partnerNames}
              onSelectSlot={onSelectSlot}
            />
          ))}
        </ul>
      ) : null}
    </li>
  );
}

function TeamItem({
  team,
  moved,
  unplaced,
  teamOf,
  venueName,
  partnerNames,
  onSelectSlot,
}: {
  team: SocleDeviationTeam;
  moved: SocleDeviationMoved[];
  unplaced: SocleDeviationUnplaced[];
  teamOf: (teamId: string) => SocleDeviationTeamInfo;
  venueName: (venueId: string) => string;
  partnerNames: (ids: string[]) => string[];
  onSelectSlot?: (slotId: string) => void;
}) {
  const info = teamOf(team.teamId);
  const hasDetail = !team.paused && (moved.length > 0 || unplaced.length > 0);
  return (
    <li>
      <p className="flex items-center text-foreground">
        {info.isCtc ? <CtcMark partner={info.ctcPartner} /> : null}
        <span>{PLANNING_TEXTS.teamReportLine(info.name, team, partnerNames(team.sharedWith))}</span>
      </p>
      {hasDetail ? (
        <ul className="mt-0.5 flex flex-col gap-0.5 ps-3 text-muted-foreground">
          {moved.map((entry, i) => {
            const shared = entry.to.sharedWith.length > 0 ? ` ${PLANNING_TEXTS.sharedWithDetail(partnerNames(entry.to.sharedWith))}` : "";
            const content = (
              <span>
                {placementLabel(entry.from.dayOfWeek, entry.from.startTime, venueName(entry.from.venueId))}
                {" → "}
                <span className="text-foreground">{placementLabel(entry.to.dayOfWeek, entry.to.startTime, venueName(entry.to.venueId))}</span>
                {shared}
              </span>
            );
            return (
              <li key={`m-${entry.to.slotId}-${i}`}>
                {undefined !== onSelectSlot ? (
                  // D6-d — bouton nu qui vise la carte dans la grille (mêmes classes que les lignes
                  // cliquables de LocksPanel).
                  <button
                    type="button"
                    onClick={() => onSelectSlot(entry.to.slotId)}
                    className={cn("flex w-full flex-wrap items-center gap-y-0.5 rounded-md px-1 py-0.5 text-left transition hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-accent")}
                  >
                    {content}
                  </button>
                ) : (
                  <span className="flex flex-wrap items-center gap-y-0.5 px-1 py-0.5">{content}</span>
                )}
              </li>
            );
          })}
          {unplaced.map((entry, i) => (
            <li key={`u-${entry.dayOfWeek}-${entry.startTime}-${entry.venueId}-${i}`} className="flex flex-wrap items-center gap-x-2 gap-y-0.5 px-1 py-0.5">
              <span>
                {placementLabel(entry.dayOfWeek, entry.startTime, venueName(entry.venueId))}
                {entry.sharedWith.length > 0 ? ` ${PLANNING_TEXTS.sharedWithDetail(partnerNames(entry.sharedWith))}` : ""}
              </span>
              {null !== entry.reason ? <StatusPill>{toReplaceReasonLabel(entry.reason)}</StatusPill> : null}
            </li>
          ))}
        </ul>
      ) : null}
    </li>
  );
}

/**
 * D8 — l'échec de lecture du bilan, dit DANS l'écran (jamais en toast : la query porte
 * `meta.ownsError`). Un 409 dit le message SERVEUR (version non terminée, socle non validé) sans
 * « Réessayer » — le geste qui le lève est ailleurs ; toute autre panne dit « Le bilan n'a pas pu
 * être lu. » avec « Réessayer ».
 */
export function SocleDeviationLoadError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  const isConflict = error instanceof HTTPError && 409 === error.response.status;
  const [conflictMessage, setConflictMessage] = useState<string | null>(null);
  useEffect(() => {
    if (!isConflict) {
      return;
    }
    let live = true;
    void errorMessage(error).then((message) => {
      if (live) {
        setConflictMessage(message);
      }
    });
    return () => {
      live = false;
    };
  }, [error, isConflict]);

  if (isConflict) {
    return null === conflictMessage ? null : <LoadErrorHint className="mb-4">{conflictMessage}</LoadErrorHint>;
  }
  return (
    <LoadErrorHint className="mb-4" onRetry={onRetry}>
      {PLANNING_TEXTS.rankReportFailed}
    </LoadErrorHint>
  );
}
