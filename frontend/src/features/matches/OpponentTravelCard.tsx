import { Building2, MapPinOff, RefreshCw, RotateCcw } from "lucide-react";
import { useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { WarningPanel } from "@/shared/components/ui/warning-panel";
import { readState } from "@/shared/lib/readState";
import { toast } from "@/shared/stores/toastStore";

import type { OpponentTravel } from "./api";
import { AwayTravelChip } from "./AwayTravelChip";
import { LocateOpponentModal } from "./LocateOpponentModal";
import { useOpponentTravel, useResolveOpponentTravel, useSetOpponentTravelAuto } from "./queries";
import { SourceBadge } from "./SourceBadge";

/**
 * P2-54 « adversaire multi-gymnases » PR-3 — l'écran SET-UP du trajet adverse, GROUPÉ PAR
 * CLUB. Le backend sert une entrée PAR ÉQUIPE adverse (grain `(code, opponentTeamKey)`) ;
 * l'écran les regroupe par organisme, montre le défaut du club puis chaque équipe, avec sa
 * source AUTO/MANUEL et le grain (`scope`) qui la gouverne. Tout (précision, minutes,
 * `scope`, `source`) vient du BACKEND — le front n'en re-dérive rien, il DÉRIVE seulement un
 * libellé de club d'AFFICHAGE (présentation pure, jamais une clé).
 */

/** Libellé de club pour l'AFFICHAGE : le libellé brut moins un suffixe d'équipe final « - n ».
 *  Présentation pure (jamais une clé de résolution — le serveur, lui, sert `opponentTeamKey`). */
function deriveClubLabel(rawLabel: string): string {
  const stripped = rawLabel.replace(/\s*-\s*\d+\s*$/, "").trim();
  return "" === stripped ? rawLabel : stripped;
}

/** Table de LIBELLÉS (présentation) pour la pastille de grain — jamais un `switch` décideur. */
const SCOPE_PILL_LABEL: Record<"TEAM" | "CLUB" | "none", string | null> = {
  TEAM: null,
  CLUB: "défaut du club",
  none: "défaut du club",
};

interface ClubGroup {
  code: string;
  clubLabel: string;
  entries: OpponentTravel[];
}

interface Locating {
  opponent: OpponentTravel;
  clubLabel: string;
  lockedToClub: boolean;
}

export function OpponentTravelCard() {
  const travelQuery = useOpponentTravel();
  const resolve = useResolveOpponentTravel();
  const revert = useSetOpponentTravelAuto();
  const [locating, setLocating] = useState<Locating | null>(null);

  const state = readState(travelQuery);
  const opponents = travelQuery.data ?? [];
  const unlocatedCount = opponents.filter((o) => !o.located).length;

  // ── Groupement par CLUB (code fédéral) + lignes « non résolues » à part ──────────
  const coded = new Map<string, OpponentTravel[]>();
  const unresolved: OpponentTravel[] = [];
  for (const o of opponents) {
    if (null === o.opponentOrganismeCode) {
      unresolved.push(o);
    } else {
      const bucket = coded.get(o.opponentOrganismeCode) ?? [];
      bucket.push(o);
      coded.set(o.opponentOrganismeCode, bucket);
    }
  }
  const groups: ClubGroup[] = [...coded.entries()].map(([code, entries]) => ({
    code,
    clubLabel: deriveClubLabel(entries[0].opponentLabel),
    entries,
  }));

  // Chaque ligne (club groupé ou entrée non résolue) porte son témoin de tri : les clubs
  // ayant AU MOINS une équipe non localisée d'abord, puis alphabétique (fr).
  type Row = { hasUnlocated: boolean; sortLabel: string; group: ClubGroup | null; orphan: OpponentTravel | null };
  const rows: Row[] = [
    ...groups.map((g) => ({ hasUnlocated: g.entries.some((e) => !e.located), sortLabel: g.clubLabel, group: g, orphan: null })),
    ...unresolved.map((o) => ({ hasUnlocated: true, sortLabel: deriveClubLabel(o.opponentLabel), group: null, orphan: o })),
  ].sort((a, b) => Number(b.hasUnlocated) - Number(a.hasUnlocated) || a.sortLabel.localeCompare(b.sortLabel, "fr"));

  const revertToClubDefault = (code: string, teamKey: string | null): void => {
    revert.mutate(
      { opponentOrganismeCode: code, ...(null === teamKey ? {} : { opponentTeamKey: teamKey }) },
      { onSuccess: () => toast.success("Défaut du club rétabli.") },
    );
  };

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-start justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          Précisez le gymnase de ces adversaires pour estimer le trajet de vos coachs. Le défaut vaut pour
          tout le club ; une équipe peut recevoir son propre gymnase.
        </p>
        <Button variant="ghost" size="sm" className="shrink-0" disabled={resolve.isPending} onClick={() => resolve.mutate()}>
          <RefreshCw className="size-4" aria-hidden="true" />
          {resolve.isPending ? "Recalcul…" : "Recalculer les trajets"}
        </Button>
      </div>

      {"failed" === state ? <LoadErrorHint onRetry={() => void travelQuery.refetch()} /> : null}
      {"loading" === state ? <EmptyHint>Chargement…</EmptyHint> : null}

      {"ready" === state ? (
        <>
          {0 === opponents.length ? <EmptyHint>Aucun match à l'extérieur cette saison.</EmptyHint> : null}

          {unlocatedCount > 0 ? (
            <WarningPanel
              icon={<MapPinOff className="size-4 text-warning" aria-hidden="true" />}
              message={`${unlocatedCount} équipe${unlocatedCount > 1 ? "s" : ""} adverse${unlocatedCount > 1 ? "s" : ""} sans gymnase — leurs matchs n'entrent pas dans le radar.`}
            />
          ) : opponents.length > 0 ? (
            <EmptyHint>Tous vos adversaires sont localisés — les temps de trajet sont estimés automatiquement.</EmptyHint>
          ) : null}

          {rows.length > 0 ? (
            <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
              {rows.map((row) =>
                null !== row.orphan ? (
                  // ── Entrée SANS code fédéral : une ligne club à part, sans lignes équipe ni bouton.
                  <li key={`orphan-${row.orphan.opponentLabel}`} className="px-3 py-2">
                    <h4 className="text-sm font-medium">{deriveClubLabel(row.orphan.opponentLabel)}</h4>
                    <p className="text-xs text-muted-foreground">code fédéral non résolu — relancez la localisation</p>
                  </li>
                ) : (
                  <ClubRow
                    key={`club-${row.group!.code}`}
                    group={row.group!}
                    revertPending={revert.isPending}
                    onLocate={setLocating}
                    onRevert={revertToClubDefault}
                  />
                ),
              )}
            </ul>
          ) : null}
        </>
      ) : null}

      {null !== locating ? (
        <LocateOpponentModal
          opponent={locating.opponent}
          clubLabel={locating.clubLabel}
          lockedToClub={locating.lockedToClub}
          onClose={() => setLocating(null)}
        />
      ) : null}
    </div>
  );
}

function ClubRow({
  group,
  revertPending,
  onLocate,
  onRevert,
}: {
  group: ClubGroup;
  revertPending: boolean;
  onLocate: (locating: Locating) => void;
  onRevert: (code: string, teamKey: string | null) => void;
}) {
  const { code, clubLabel, entries } = group;
  const unlocated = entries.filter((e) => !e.located).length;
  // Le défaut du club se LIT sur une entrée gouvernée par le club (scope CLUB ou aucun) — les
  // entrées TEAM ne le révèlent pas. Aucune → défaut non affiché (« — »), l'action seule.
  const clubDefault = entries.find((e) => "CLUB" === e.scope || null === e.scope) ?? null;
  const clubIsManual = null !== clubDefault && "CLUB" === clubDefault.scope && "MANUAL" === clubDefault.source;

  return (
    <li className="px-3 py-2">
      <div className="flex items-baseline justify-between gap-2">
        <h4 className="text-sm font-medium">{clubLabel}</h4>
        <span className="shrink-0 text-xs text-muted-foreground">
          {entries.length} équipe{entries.length > 1 ? "s" : ""}
          {unlocated > 0 ? ` · ${unlocated} à localiser` : ""}
        </span>
      </div>

      <ul className="mt-1.5 flex flex-col gap-1.5">
        {/* Ligne CLUB (défaut) — toujours en tête. */}
        <li className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-sm">
          <span className="flex min-w-0 flex-wrap items-center gap-x-1">
            <span className="text-muted-foreground">Toutes les équipes (défaut)</span>
            {null !== clubDefault ? <AwayTravelChip travel={clubDefault} /> : <span className="ml-1 text-xs text-muted-foreground">—</span>}
          </span>
          <span className="flex shrink-0 items-center gap-1">
            <Button
              variant="ghost"
              size="sm"
              aria-label={`Localiser ${clubLabel}, toutes les équipes`}
              onClick={() => onLocate({ opponent: entries[0], clubLabel, lockedToClub: true })}
            >
              Localiser
            </Button>
            {clubIsManual ? (
              <Button
                variant="ghost"
                size="sm"
                aria-label={`Rétablir l'automatique pour ${clubLabel}, toutes les équipes`}
                disabled={revertPending}
                onClick={() => onRevert(code, null)}
              >
                <RotateCcw className="size-3.5" aria-hidden="true" />
                Rétablir l'automatique
              </Button>
            ) : null}
          </span>
        </li>

        {/* Une ligne PAR ÉQUIPE — libellé brut entier, jamais dérivé. */}
        {entries.map((entry) => {
          const pillLabel = entry.located ? SCOPE_PILL_LABEL[entry.scope ?? "none"] : null;
          return (
            <li key={`${code}-${entry.opponentTeamKey ?? entry.opponentLabel}`} className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-sm">
              <span className="flex min-w-0 flex-wrap items-center gap-x-1">
                <span className="font-medium">{entry.opponentLabel}</span>
                {null !== entry.source ? <SourceBadge source={entry.source} /> : null}
                {null !== pillLabel ? <StatusPill icon={<Building2 className="size-3.5" aria-hidden="true" />}>{pillLabel}</StatusPill> : null}
                <AwayTravelChip travel={entry} />
              </span>
              <span className="flex shrink-0 items-center gap-1">
                <Button variant="ghost" size="sm" aria-label={`Localiser ${entry.opponentLabel}`} onClick={() => onLocate({ opponent: entry, clubLabel, lockedToClub: false })}>
                  Localiser
                </Button>
                {"TEAM" === entry.scope ? (
                  <Button
                    variant="ghost"
                    size="sm"
                    aria-label={`Revenir au défaut du club pour ${entry.opponentLabel}`}
                    disabled={revertPending}
                    onClick={() => onRevert(code, entry.opponentTeamKey)}
                  >
                    <RotateCcw className="size-3.5" aria-hidden="true" />
                    Revenir au défaut du club
                  </Button>
                ) : null}
              </span>
            </li>
          );
        })}
      </ul>
    </li>
  );
}
