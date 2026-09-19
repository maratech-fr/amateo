import { ChevronDown, MapPin, MapPinned, MapPinOff, RefreshCw, RotateCcw, Search } from "lucide-react";
import { useId, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { OpponentLogo } from "@/shared/components/ui/opponent-logo";
import { Spinner } from "@/shared/components/ui/spinner";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/shared/components/ui/table";
import { WarningPanel } from "@/shared/components/ui/warning-panel";
import { readState } from "@/shared/lib/readState";
import { useTravelStream } from "@/shared/lib/travelStream";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { OpponentTravel } from "./api";
import { TravelMinutes } from "./AwayTravelChip";
import { LocateOpponentModal } from "./LocateOpponentModal";
import { opponentInitials } from "./lib/opponentInitials";
import { clubMatchesQuery, queryTokens, textMatchesQuery } from "./lib/opponentSearch";
import { applyOpponentFilterToParams, decodeOpponentFilter, type OpponentFilter } from "./lib/urlState";
import { useClubGeolocated, useFixtures, useOpponentTravel, useResolveOpponentTravel, useSetOpponentTravelAuto, useUpdateOpponents } from "./queries";
import { SourceBadge } from "./SourceBadge";

/**
 * C8 — l'onglet « Adversaires » (`/matchs/adversaires`), refondu en LISTE PAR CLUB : un `<tbody>`
 * par organisme (1ʳᵉ ligne « Toutes les équipes (défaut) », puis une ligne par équipe). Sorti de
 * la Configuration en page sœur dédiée. Reprend la logique de l'ancienne carte : recherche,
 * groupes par club, `LocateOpponentModal`, « Rétablir l'automatique », orphelins repliés, bandeau
 * siège + « Renseigner le siège », « Mettre à jour les adversaires ».
 *
 * Le backend sert une entrée PAR ÉQUIPE (`(code, opponentTeamKey)`) avec `travelStatus`
 * (done/pending/unavailable), `precision`, `source`, `scope`, `hasLogo` — le front n'en re-dérive
 * RIEN (🔴 `.claude/rules/frontend.md`), il DÉRIVE seulement un libellé de club d'AFFICHAGE. Le
 * calcul des trajets est asynchrone (C6) : la progression vient de `travelStatus` (rafraîchi par
 * Mercure via `useTravelStream`), jamais d'un spinner par ligne.
 */

/** Libellé de club pour l'AFFICHAGE : le libellé brut moins un suffixe d'équipe final « - n ».
 *  Présentation pure (jamais une clé de résolution — le serveur, lui, sert `opponentTeamKey`). */
function deriveClubLabel(rawLabel: string): string {
  const stripped = rawLabel.replace(/\s*-\s*\d+\s*$/, "").trim();
  return "" === stripped ? rawLabel : stripped;
}

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

const SEGMENTS: { key: OpponentFilter | null; label: string }[] = [
  { key: "a-localiser", label: "À localiser" },
  { key: "ville", label: "Gymnase à préciser" },
  { key: null, label: "Tous" },
];

export function OpponentsPage() {
  const travelQuery = useOpponentTravel();
  const clubGeolocated = useClubGeolocated();
  const fixtures = useFixtures();
  const update = useUpdateOpponents();
  const revert = useSetOpponentTravelAuto();
  const retry = useResolveOpponentTravel();
  const [locating, setLocating] = useState<Locating | null>(null);
  const [query, setQuery] = useState("");
  const [orphansOpen, setOrphansOpen] = useState(false);
  const orphansListId = useId();

  const [searchParams, setSearchParams] = useSearchParams();
  const activeFilter = decodeOpponentFilter(searchParams);
  const setActiveFilter = (filter: OpponentFilter | null): void => {
    setSearchParams(applyOpponentFilterToParams(searchParams, filter), { replace: true });
  };

  const state = readState(travelQuery);
  const opponents = useMemo(() => travelQuery.data ?? [], [travelQuery.data]);

  // ── Groupement par CLUB (code fédéral) + lignes « non résolues » (sans code) à part ──
  const { groups, unresolved } = useMemo(() => {
    const coded = new Map<string, OpponentTravel[]>();
    const orphans: OpponentTravel[] = [];
    for (const o of opponents) {
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
  }, [opponents]);

  // ── Rencontres par équipe : jointure front (code, teamKey) sur les fixtures AWAY servies. ──
  const fixtureCounts = useMemo(() => {
    const counts = new Map<string, number>();
    for (const fx of fixtures.data ?? []) {
      if (null === fx.opponentOrganismeCode) {
        continue;
      }
      const key = `${fx.opponentOrganismeCode}|${fx.opponentTeamKey ?? ""}`;
      counts.set(key, (counts.get(key) ?? 0) + 1);
    }
    return counts;
  }, [fixtures.data]);

  // ── Compteurs de segments (sur les équipes CODÉES — celles que le tableau porte). ──
  const codedEntries = useMemo(() => groups.flatMap((g) => g.entries), [groups]);
  const toLocateCount = codedEntries.filter((e) => !e.located).length;
  const cityCount = codedEntries.filter((e) => e.located && "CITY" === e.precision).length;
  const segmentCount = (key: OpponentFilter | null): number | null => ("a-localiser" === key ? toLocateCount : "ville" === key ? cityCount : null);

  // ── Recherche + filtre segmenté (ET) : un club conservé ENTIER s'il matche. ──
  const tokens = queryTokens(query);
  const filteredGroups = useMemo(() => {
    const entryMatchesFilter = (entry: OpponentTravel): boolean =>
      "a-localiser" === activeFilter ? !entry.located : "ville" === activeFilter ? entry.located && "CITY" === entry.precision : true;
    return groups.filter(
      (g) =>
        (0 === tokens.length || clubMatchesQuery(g.clubLabel, g.entries.map((e) => e.opponentLabel), tokens)) &&
        (null === activeFilter || g.entries.some(entryMatchesFilter)),
    );
  }, [groups, tokens, activeFilter]);
  // Tri : les clubs SANS trajet (au moins une équipe sans minutes) d'abord, puis alphabétique (fr).
  const sortedGroups = [...filteredGroups].sort(
    (a, b) => Number(b.entries.some((e) => null === e.travelMinutes)) - Number(a.entries.some((e) => null === e.travelMinutes)) || a.clubLabel.localeCompare(b.clubLabel, "fr"),
  );

  const matchingOrphans = useMemo(
    () => (0 === tokens.length ? unresolved : unresolved.filter((o) => textMatchesQuery(o.opponentLabel, tokens))).slice().sort((a, b) => a.opponentLabel.localeCompare(b.opponentLabel, "fr")),
    [unresolved, tokens],
  );
  const orphansExpanded = orphansOpen || ("" !== query && matchingOrphans.length > 0);

  // ── Progression du calcul asynchrone (C6), dérivée du GET (`travelStatus`). ──
  const locatedOpps = opponents.filter((o) => o.located);
  const computing = opponents.some((o) => "pending" === o.travelStatus);
  const doneCount = locatedOpps.filter((o) => "done" === o.travelStatus).length;
  const failedCount = locatedOpps.filter((o) => "unavailable" === o.travelStatus).length;
  const totalTravel = locatedOpps.length;
  const unlocatedCount = opponents.filter((o) => !o.located).length;
  // Tient le flux Mercure ouvert tant qu'un calcul tourne (le refetch rafraîchit `travelStatus`).
  useTravelStream(computing);

  // ── Indice « Dans le fichier » pour Localiser (salles FBI vues sur les rencontres). ──
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
    <section className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="text-base font-semibold text-foreground">Adversaires</h2>
          <p className="text-sm text-muted-foreground">Où jouent vos adversaires et le trajet depuis le siège du club — localisez, épinglez un gymnase, recalculez.</p>
        </div>
        <div className="flex flex-col items-end gap-1">
          <Button variant="outline" size="sm" className="shrink-0" disabled={update.isPending || computing} onClick={update.run}>
            <RefreshCw className={cn("size-4", update.isPending ? "animate-spin" : "")} aria-hidden="true" />
            {updateLabel}
          </Button>
          {/* Progression : région live à DEUX phrases stables + compteur chiffré frère (aria-hidden). */}
          {"ready" === state && clubGeolocated && totalTravel > 0 ? (
            <div className="flex flex-col items-end">
              <p role="status" aria-live="polite" className="text-xs text-muted-foreground">
                {computing ? "Calcul des trajets en cours…" : failedCount > 0 ? `Trajets calculés — ${failedCount} indisponibles.` : "Trajets calculés."}
              </p>
              <span aria-hidden="true" className="text-xs tabular-nums text-muted-foreground">
                {doneCount} / {totalTravel} trajets calculés
              </span>
            </div>
          ) : null}
        </div>
      </div>

      {/* Sans siège localisé, AUCUN trajet ne s'estime : on le dit et on renvoie vers la fiche club. */}
      {"ready" === state && !clubGeolocated ? (
        <WarningPanel icon={<MapPinOff className="size-4 text-warning" aria-hidden="true" />} message="Trajets indisponibles : l'adresse du siège du club n'est pas localisée.">
          <Button variant="outline" size="sm" asChild>
            <Link to="/club?section=informations">Renseigner le siège</Link>
          </Button>
        </WarningPanel>
      ) : null}

      {/* Échec PARTIEL du calcul : des trajets localisés n'ont pas pu être obtenus → réessayer. */}
      {"ready" === state && clubGeolocated && !computing && failedCount > 0 ? (
        <WarningPanel
          icon={<MapPinOff className="size-4 text-warning" aria-hidden="true" />}
          message={`${failedCount} trajet${failedCount > 1 ? "s" : ""} n'${failedCount > 1 ? "ont" : "a"} pas pu être calculé${failedCount > 1 ? "s" : ""} — ces matchs n'entrent pas dans le radar.`}
        >
          <Button variant="outline" size="sm" disabled={retry.isPending} onClick={() => retry.mutate()}>
            Réessayer les manquants
          </Button>
        </WarningPanel>
      ) : null}

      {"failed" === state ? <LoadErrorHint onRetry={() => void travelQuery.refetch()} /> : null}
      {"loading" === state ? <Spinner /> : null}

      {"ready" === state ? (
        <>
          {0 === opponents.length ? <EmptyHint>Aucun match à l'extérieur cette saison.</EmptyHint> : null}

          {unlocatedCount > 0 ? (
            <p className="text-xs text-muted-foreground">
              {unlocatedCount} équipe{unlocatedCount > 1 ? "s" : ""} adverse{unlocatedCount > 1 ? "s" : ""} sans gymnase — leurs matchs n'entrent pas dans le radar.
            </p>
          ) : null}

          {opponents.length > 0 ? (
            <div className="flex flex-wrap items-center gap-2">
              {/* Recherche instantanée. */}
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

              {/* Filtre segmenté (patron `CalendarControls`) : un seul `aria-pressed`. */}
              <div role="group" aria-label="Filtrer les adversaires" className="flex flex-wrap items-center gap-1 rounded-md border border-border p-0.5">
                {SEGMENTS.map((segment) => {
                  const count = segmentCount(segment.key);
                  const pressed = segment.key === activeFilter;
                  return (
                    <Button key={segment.label} type="button" size="sm" aria-pressed={pressed} variant={pressed ? "default" : "ghost"} onClick={() => setActiveFilter(segment.key)}>
                      {segment.label}
                      {null !== count ? <span className="ml-1 tabular-nums">({count})</span> : null}
                    </Button>
                  );
                })}
              </div>
            </div>
          ) : null}

          {/* Retour de recherche/filtre : requête sans résultat → l'invite ; sinon le compte. */}
          {opponents.length > 0 && 0 === filteredGroups.length && 0 === matchingOrphans.length ? (
            <EmptyHint role="status">Aucun adversaire pour « {query} ».</EmptyHint>
          ) : null}

          {sortedGroups.length > 0 ? (
            <div className="@container">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-8">
                      <span className="sr-only">Logo</span>
                    </TableHead>
                    <TableHead>Adversaire</TableHead>
                    <TableHead>Gymnase</TableHead>
                    <TableHead className="hidden @md:table-cell">Trajet</TableHead>
                    <TableHead className="hidden text-right @md:table-cell">Rencontres</TableHead>
                    <TableHead className="text-right">
                      <span className="sr-only">Actions</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                {sortedGroups.map((group) => (
                  <ClubTbody key={`club-${group.code}`} group={group} fixtureCounts={fixtureCounts} revertPending={revert.isPending} onLocate={setLocating} onRevert={revertToClubDefault} />
                ))}
              </Table>
            </div>
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
    </section>
  );
}

function TravelCell({ entry }: { entry: OpponentTravel }) {
  if ("pending" === entry.travelStatus) {
    return <span className="text-muted-foreground">en cours…</span>;
  }
  if (null !== entry.travelMinutes) {
    return <TravelMinutes minutes={entry.travelMinutes} approximated={entry.approximated} place={entry.locationName ?? ""} />;
  }
  return <span className="text-muted-foreground">indisponible</span>;
}

function GymnaseCell({ entry, fallback }: { entry: OpponentTravel | null; fallback?: string }) {
  if (null === entry || !entry.located || null === entry.precision) {
    return <span className="text-xs text-muted-foreground">{fallback ?? "lieu inconnu"}</span>;
  }
  return (
    <span className="flex flex-wrap items-center gap-1">
      <span className="text-sm">{entry.locationName}</span>
      {null !== entry.source ? <SourceBadge source={entry.source} /> : null}
      {"CITY" === entry.precision ? <StatusPill icon={<MapPin className="size-3.5" aria-hidden="true" />}>ville seule</StatusPill> : null}
    </span>
  );
}

function LocateButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <Button variant="ghost" size="sm" aria-label={`Localiser ${label}`} onClick={onClick}>
      <MapPinned className="size-4" aria-hidden="true" />
      <span className="hidden @md:inline">Localiser</span>
    </Button>
  );
}

function ClubTbody({
  group,
  fixtureCounts,
  revertPending,
  onLocate,
  onRevert,
}: {
  group: ClubGroup;
  fixtureCounts: Map<string, number>;
  revertPending: boolean;
  onLocate: (locating: Locating) => void;
  onRevert: (code: string, teamKey: string | null) => void;
}) {
  const { code, clubLabel, entries } = group;
  // Le défaut du club se LIT sur une entrée gouvernée par le club (scope CLUB ou aucun).
  const clubDefault = entries.find((e) => "CLUB" === e.scope || null === e.scope) ?? null;
  const clubIsManual = null !== clubDefault && "CLUB" === clubDefault.scope && "MANUAL" === clubDefault.source;
  const clubHasLogo = entries.some((e) => e.hasLogo);

  return (
    <TableBody className="border-b border-border last:border-0">
      {/* Ligne CLUB (défaut) — en tête, `<th scope="row">` porte le nom du club. */}
      <TableRow className="border-0">
        <TableCell className="w-8">
          <OpponentLogo code={code} hasLogo={clubHasLogo} initials={opponentInitials(clubLabel)} size="md" />
        </TableCell>
        <th scope="row" className="px-3 py-2 text-left align-middle font-normal">
          <span className="block text-sm font-semibold">{clubLabel}</span>
          <span className="block text-xs text-muted-foreground">Toutes les équipes (défaut)</span>
        </th>
        <TableCell>
          <GymnaseCell entry={clubDefault} fallback="—" />
        </TableCell>
        <TableCell className="hidden tabular-nums @md:table-cell">{null !== clubDefault ? <TravelCell entry={clubDefault} /> : <span className="text-muted-foreground">—</span>}</TableCell>
        <TableCell className="hidden text-right @md:table-cell" />
        <TableCell className="text-right">
          <span className="inline-flex items-center gap-1">
            <LocateButton label={`${clubLabel}, toutes les équipes`} onClick={() => onLocate({ opponent: entries[0], clubLabel, lockedToClub: true })} />
            {clubIsManual ? (
              <Button
                variant="ghost"
                size="sm"
                className="hidden @md:inline-flex"
                aria-label={`Rétablir l'automatique pour ${clubLabel}, toutes les équipes`}
                disabled={revertPending}
                onClick={() => onRevert(code, null)}
              >
                <RotateCcw className="size-3.5" aria-hidden="true" />
                Rétablir l'automatique
              </Button>
            ) : null}
          </span>
        </TableCell>
      </TableRow>

      {/* Une ligne PAR ÉQUIPE — libellé brut entier, jamais dérivé. */}
      {entries.map((entry) => {
        const count = fixtureCounts.get(`${code}|${entry.opponentTeamKey ?? ""}`) ?? 0;
        return (
          <TableRow key={`${code}-${entry.opponentTeamKey ?? entry.opponentLabel}`} className="border-0">
            <TableCell className="w-8">
              <OpponentLogo code={code} hasLogo={entry.hasLogo} initials={opponentInitials(entry.opponentLabel)} size="md" />
            </TableCell>
            <TableCell>
              <span className="block text-sm font-medium">{entry.opponentLabel}</span>
              <span className="block text-xs text-muted-foreground">
                {clubLabel}
                {null !== entry.city ? ` · ${entry.city}` : ""}
              </span>
              {/* Repli mobile : le compte de rencontres, colonne dédiée masquée < @md. */}
              <span className="block text-xs text-muted-foreground @md:hidden">
                {count} rencontre{count > 1 ? "s" : ""}
              </span>
            </TableCell>
            <TableCell>
              <GymnaseCell entry={entry} />
            </TableCell>
            <TableCell className="hidden tabular-nums @md:table-cell">
              <TravelCell entry={entry} />
            </TableCell>
            <TableCell className="hidden text-right tabular-nums @md:table-cell">{count}</TableCell>
            <TableCell className="text-right">
              <span className="inline-flex items-center gap-1">
                <LocateButton label={entry.opponentLabel} onClick={() => onLocate({ opponent: entry, clubLabel, lockedToClub: false })} />
                {"TEAM" === entry.scope ? (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="hidden @md:inline-flex"
                    aria-label={`Revenir au défaut du club pour ${entry.opponentLabel}`}
                    disabled={revertPending}
                    onClick={() => onRevert(code, entry.opponentTeamKey)}
                  >
                    <RotateCcw className="size-3.5" aria-hidden="true" />
                    Revenir au défaut du club
                  </Button>
                ) : null}
              </span>
            </TableCell>
          </TableRow>
        );
      })}
    </TableBody>
  );
}
