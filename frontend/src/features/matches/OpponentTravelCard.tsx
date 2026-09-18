import { Building2, ChevronDown, MapPinOff, RefreshCw, RotateCcw, Search } from "lucide-react";
import { useId, useMemo, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { WarningPanel } from "@/shared/components/ui/warning-panel";
import { readState } from "@/shared/lib/readState";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { OpponentTravel } from "./api";
import { AwayTravelChip } from "./AwayTravelChip";
import { LocateOpponentModal } from "./LocateOpponentModal";
import { clubMatchesQuery, queryTokens, textMatchesQuery } from "./lib/opponentSearch";
import { useFixtures, useOpponentTravel, useSetOpponentTravelAuto, useUpdateOpponents } from "./queries";
import { SourceBadge } from "./SourceBadge";

/**
 * P2-54 « adversaire multi-gymnases » PR-3 — l'écran SET-UP du trajet adverse, GROUPÉ PAR
 * CLUB. Le backend sert une entrée PAR ÉQUIPE adverse (grain `(code, opponentTeamKey)`) ;
 * l'écran les regroupe par organisme, montre le défaut du club puis chaque équipe, avec sa
 * source AUTO/MANUEL et le grain (`scope`) qui la gouverne. Tout (précision, minutes,
 * `scope`, `source`) vient du BACKEND — le front n'en re-dérive rien, il DÉRIVE seulement un
 * libellé de club d'AFFICHAGE (présentation pure, jamais une clé).
 *
 * PR 2a/2b — une RECHERCHE instantanée (club ou équipe), les adversaires SANS code fédéral sortis
 * dans une liste repliée à part, et « Mettre à jour les adversaires » : depuis la PR 2b, UN seul
 * appel serveur (`/api/opponents/refresh`) enchaîne les trois passes (codes FFBB, gymnases depuis
 * le fichier, trajets) — un unique libellé d'étape « Mise à jour… ».
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
  const fixtures = useFixtures();
  const update = useUpdateOpponents();
  const revert = useSetOpponentTravelAuto();
  const [locating, setLocating] = useState<Locating | null>(null);
  const [query, setQuery] = useState("");
  const [orphansOpen, setOrphansOpen] = useState(false);
  const orphansListId = useId();

  const state = readState(travelQuery);
  const opponents = travelQuery.data ?? [];
  const unlocatedCount = opponents.filter((o) => !o.located).length;

  // ── Groupement par CLUB (code fédéral) + lignes « non résolues » à part ──────────
  const { groups, unresolved } = useMemo(() => {
    const coded = new Map<string, OpponentTravel[]>();
    const orphans: OpponentTravel[] = [];
    for (const o of travelQuery.data ?? []) {
      if (null === o.opponentOrganismeCode) {
        orphans.push(o);
      } else {
        const bucket = coded.get(o.opponentOrganismeCode) ?? [];
        bucket.push(o);
        coded.set(o.opponentOrganismeCode, bucket);
      }
    }
    const built: ClubGroup[] = [...coded.entries()].map(([code, entries]) => ({ code, clubLabel: deriveClubLabel(entries[0].opponentLabel), entries }));
    return { groups: built, unresolved: orphans };
  }, [travelQuery.data]);

  // ── Recherche instantanée : un club conservé ENTIER si son libellé OU une équipe matche ──
  const tokens = queryTokens(query);
  const filteredGroups = useMemo(
    () => (0 === tokens.length ? groups : groups.filter((g) => clubMatchesQuery(g.clubLabel, g.entries.map((e) => e.opponentLabel), tokens))),
    [groups, tokens],
  );
  // Clubs non-localisés d'abord, puis alphabétique (fr).
  const sortedGroups = [...filteredGroups].sort(
    (a, b) => Number(b.entries.some((e) => !e.located)) - Number(a.entries.some((e) => !e.located)) || a.clubLabel.localeCompare(b.clubLabel, "fr"),
  );
  const matchingOrphans = useMemo(
    () => (0 === tokens.length ? unresolved : unresolved.filter((o) => textMatchesQuery(o.opponentLabel, tokens))).slice().sort((a, b) => a.opponentLabel.localeCompare(b.opponentLabel, "fr")),
    [unresolved, tokens],
  );
  const orphansExpanded = orphansOpen || ("" !== query && matchingOrphans.length > 0);

  // ── Indice « Dans le fichier » pour Localiser : les salles FBI vues sur les rencontres de CET
  //    adversaire (grain équipe), distinctes, 3 au plus, dans l'ordre d'apparition. ──
  const fileVenueLabels = useMemo(() => {
    if (null === locating) {
      return [];
    }
    const { opponentOrganismeCode: code, opponentTeamKey: key } = locating.opponent;
    const labels: string[] = [];
    for (const fx of fixtures.data ?? []) {
      if (fx.opponentOrganismeCode === code && fx.opponentTeamKey === key && null !== fx.fbiVenueLabel && !labels.includes(fx.fbiVenueLabel)) {
        labels.push(fx.fbiVenueLabel);
        if (labels.length >= 3) {
          break;
        }
      }
    }
    return labels;
  }, [locating, fixtures.data]);

  const revertToClubDefault = (code: string, teamKey: string | null): void => {
    revert.mutate({ opponentOrganismeCode: code, ...(null === teamKey ? {} : { opponentTeamKey: teamKey }) }, { onSuccess: () => toast.success("Défaut du club rétabli.") });
  };

  const updateLabel = "running" === update.step ? "Mise à jour…" : "Mettre à jour les adversaires";

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-start justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          Précisez le gymnase de ces adversaires pour estimer le trajet de vos coachs. Le défaut vaut pour tout le club ; une
          équipe peut recevoir son propre gymnase.
        </p>
        <Button variant="outline" size="sm" className="shrink-0" disabled={update.isPending} onClick={update.run}>
          <RefreshCw className={cn("size-4", update.isPending ? "animate-spin" : "")} aria-hidden="true" />
          {updateLabel}
        </Button>
      </div>
      {/* Annonce a11y de la progression — MONTÉE avant le clic (le lecteur d'écran suit l'état). */}
      <p role="status" className="sr-only">
        {"running" === update.step ? "Mise à jour des adversaires en cours…" : ""}
      </p>

      {"failed" === state ? <LoadErrorHint onRetry={() => void travelQuery.refetch()} /> : null}
      {/* UXC-22 — un chargement se dit par un SPINNER inline (activité), pas un état vide. */}
      {"loading" === state ? <Spinner /> : null}

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

          {/* Recherche — masquée sans adversaire (le filtre n'aurait rien à faire). */}
          {opponents.length > 0 ? (
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
              <Input
                type="search"
                aria-label="Rechercher un club ou une équipe"
                placeholder="Rechercher un club ou une équipe"
                className="h-9 w-full pl-9 sm:max-w-xs"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={(e) => {
                  if ("Escape" === e.key) {
                    setQuery("");
                  }
                }}
              />
            </div>
          ) : null}

          {/* Élément role=status UNIQUE : requête vide → rien ; résultats → compte ; zéro → l'invite. */}
          {"" !== query && 0 === filteredGroups.length && 0 === matchingOrphans.length ? (
            <EmptyHint role="status">Aucun adversaire pour « {query} ».</EmptyHint>
          ) : "" !== query && filteredGroups.length > 0 ? (
            <p role="status" className="text-xs text-muted-foreground">
              {filteredGroups.length} club{filteredGroups.length > 1 ? "s" : ""} sur {groups.length}
            </p>
          ) : null}

          {sortedGroups.length > 0 ? (
            <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
              {sortedGroups.map((group) => (
                <ClubRow key={`club-${group.code}`} group={group} revertPending={revert.isPending} onLocate={setLocating} onRevert={revertToClubDefault} />
              ))}
            </ul>
          ) : null}

          {/* Adversaires SANS code fédéral — liste repliée à part, compte suivant le filtre. */}
          {matchingOrphans.length > 0 ? (
            <div className="flex flex-col gap-2">
              <Button
                variant="ghost"
                size="sm"
                className="self-start"
                aria-expanded={orphansExpanded}
                aria-controls={orphansExpanded ? orphansListId : undefined}
                onClick={() => setOrphansOpen((open) => !open)}
              >
                <ChevronDown className={cn("size-4", orphansExpanded ? "rotate-180" : "")} aria-hidden="true" />
                {matchingOrphans.length} adversaire{matchingOrphans.length > 1 ? "s" : ""} sans code fédéral
              </Button>
              <p className="text-xs text-muted-foreground">« Mettre à jour les adversaires » tente de retrouver leur code FFBB.</p>
              {orphansExpanded ? (
                <ul id={orphansListId} className="flex flex-col divide-y divide-border rounded-md border border-border">
                  {matchingOrphans.map((orphan) => (
                    <li key={`orphan-${orphan.opponentLabel}`} className="px-3 py-2">
                      <h4 className="text-sm font-medium">{deriveClubLabel(orphan.opponentLabel)}</h4>
                      <p className="text-xs text-muted-foreground">code fédéral non résolu</p>
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          ) : null}
        </>
      ) : null}

      {null !== locating ? (
        <LocateOpponentModal opponent={locating.opponent} clubLabel={locating.clubLabel} lockedToClub={locating.lockedToClub} fileVenueLabels={fileVenueLabels} onClose={() => setLocating(null)} />
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
            <Button variant="ghost" size="sm" aria-label={`Localiser ${clubLabel}, toutes les équipes`} onClick={() => onLocate({ opponent: entries[0], clubLabel, lockedToClub: true })}>
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
