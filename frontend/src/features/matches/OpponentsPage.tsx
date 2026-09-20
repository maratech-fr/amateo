import { MapPinOff, MoreHorizontal, Plus, RefreshCw, Search } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Menu, MenuItem } from "@/shared/components/ui/menu";
import { OpponentLogo } from "@/shared/components/ui/opponent-logo";
import { Spinner } from "@/shared/components/ui/spinner";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/shared/components/ui/table";
import { WarningPanel } from "@/shared/components/ui/warning-panel";
import { readState } from "@/shared/lib/readState";
import { useTravelStream } from "@/shared/lib/travelStream";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { OpponentClub, OpponentUnmatchedLabel, OpponentVenue } from "./api";
import { TravelMinutes } from "./AwayTravelChip";
import { LocateOpponentModal } from "./LocateOpponentModal";
import { opponentInitials } from "./lib/opponentInitials";
import { queryTokens, textMatchesQuery } from "./lib/opponentSearch";
import { applyOpponentFilterToParams, decodeOpponentFilter, type OpponentFilter } from "./lib/urlState";
import { useClubGeolocated, useDeleteVenueLink, useOpponentTravel, useRepointVenueLink, useResolveOpponentTravel, useUpdateOpponents } from "./queries";
import { SourceBadge } from "./SourceBadge";

/**
 * C8 (amendement 2026-09-20) — l'onglet « Adversaires » (`/matchs/adversaires`) refondu au grain
 * GYMNASE : un `<tbody>` par CLUB adverse (ligne club « Ajouter un gymnase », puis une ligne par
 * gymnase apparié, puis les libellés « à apparier »). Le backend sert la liste PAR CLUB
 * (`OpponentClub`) avec, par gymnase, trajet/statut/source/compte et le gymnase de REPLI si retiré
 * (`fallbackVenueName`) — le front n'en re-dérive RIEN (🔴 `.claude/rules/frontend.md`). Le calcul
 * des trajets est asynchrone : la progression vient de `travelStatus` (Mercure via `useTravelStream`).
 */

interface Locating {
  code: string;
  clubName: string;
  /** Le libellé de fichier à apparier (ligne orpheline) ; null = « ajouter un gymnase » au club. */
  fbiLabel: string | null;
  city: string | null;
  postalCode: string | null;
}

const SEGMENTS: { key: OpponentFilter | null; label: string; unit: string }[] = [
  { key: "sans-gymnase", label: "Sans gymnase", unit: "clubs" },
  { key: "a-apparier", label: "À apparier", unit: "salles" },
  { key: null, label: "Tous", unit: "" },
];

export function OpponentsPage() {
  const travelQuery = useOpponentTravel();
  const clubGeolocated = useClubGeolocated();
  const update = useUpdateOpponents();
  const retry = useResolveOpponentTravel();
  const deleteLink = useDeleteVenueLink();
  const repoint = useRepointVenueLink();
  const [locating, setLocating] = useState<Locating | null>(null);
  const [toRemove, setToRemove] = useState<{ club: OpponentClub; venue: OpponentVenue } | null>(null);
  const [toMerge, setToMerge] = useState<{ club: OpponentClub; source: OpponentVenue; target: OpponentVenue } | null>(null);
  const [query, setQuery] = useState("");

  const [searchParams, setSearchParams] = useSearchParams();
  const activeFilter = decodeOpponentFilter(searchParams);
  const setActiveFilter = (filter: OpponentFilter | null): void => {
    setSearchParams(applyOpponentFilterToParams(searchParams, filter), { replace: true });
  };

  const state = readState(travelQuery);
  const opponents = useMemo(() => travelQuery.data?.opponents ?? [], [travelQuery.data]);

  // ── Progression du calcul asynchrone, dérivée du GET (`travelStatus` par gymnase). ──
  const allVenues = useMemo(() => opponents.flatMap((o) => o.venues), [opponents]);
  const computing = allVenues.some((v) => "pending" === v.travelStatus);
  const doneCount = allVenues.filter((v) => "done" === v.travelStatus).length;
  const failedCount = allVenues.filter((v) => "unavailable" === v.travelStatus).length;
  const totalTravel = allVenues.length;
  const clubsWithoutGym = opponents.filter((o) => 0 === o.venues.length).length;
  useTravelStream(computing);

  // ── Compteurs de segments — DEUX unités (clubs / salles) : l'aria-label la nomme. ──
  const sansGymnaseCount = clubsWithoutGym;
  const aApparierCount = opponents.reduce((sum, o) => sum + o.unmatchedLabels.length, 0);
  const segmentCount = (key: OpponentFilter | null): number | null => ("sans-gymnase" === key ? sansGymnaseCount : "a-apparier" === key ? aApparierCount : null);

  // ── Recherche + filtre segmenté (ET). Un club est GARDÉ s'il matche le filtre, et n'affiche
  // que ses lignes filles pertinentes (fin du « club entier »). ──
  const tokens = queryTokens(query);
  const filteredOpponents = useMemo(() => {
    return opponents.filter((o) => {
      if ("sans-gymnase" === activeFilter && 0 !== o.venues.length) {
        return false;
      }
      if ("a-apparier" === activeFilter && 0 === o.unmatchedLabels.length) {
        return false;
      }
      if (0 === tokens.length) {
        return true;
      }
      const haystacks = [o.name, ...o.venues.map((v) => v.label), ...o.unmatchedLabels.map((u) => u.label)];
      return haystacks.some((h) => textMatchesQuery(h, tokens));
    });
  }, [opponents, tokens, activeFilter]);

  // Rang GELÉ au montage (décision fondateur 2026-09-20) : le backend sert déjà les clubs
  // SANS gymnase d'abord, puis alphabétique — on FIGE cet ordre à la première liste non vide
  // pour qu'un club qu'on vient d'apparier ne SAUTE pas de place sous les mains. Portée =
  // montage du composant (quitter l'onglet et revenir recalcule, assumé). Un club apparu
  // après le gel s'ajoute en fin (rang « infini », stable dans l'ordre backend).
  const rankRef = useRef<Map<string, number>>(new Map());
  useEffect(() => {
    if (0 === rankRef.current.size && opponents.length > 0) {
      opponents.forEach((o, index) => rankRef.current.set(o.code ?? o.name, index));
    }
  }, [opponents]);
  const frozenRank = (o: OpponentClub): number => rankRef.current.get(o.code ?? o.name) ?? Number.MAX_SAFE_INTEGER;
  const sortedOpponents = [...filteredOpponents].sort((a, b) => frozenRank(a) - frozenRank(b));

  const updateLabel = "running" === update.step ? "Mise à jour…" : "Mettre à jour les adversaires";

  return (
    <section className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="text-base font-semibold text-foreground">Adversaires</h2>
          <p className="text-sm text-muted-foreground">Le gymnase de chaque adversaire et le trajet depuis le siège du club — appariez une salle, corrigez, recalculez.</p>
        </div>
        <div className="flex flex-col items-end gap-1">
          <Button variant="outline" size="sm" className="shrink-0" disabled={update.isPending || computing} onClick={update.run}>
            <RefreshCw className={cn("size-4", update.isPending ? "animate-spin" : "")} aria-hidden="true" />
            {updateLabel}
          </Button>
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

      {"ready" === state && !clubGeolocated ? (
        <WarningPanel icon={<MapPinOff className="size-4 text-warning" aria-hidden="true" />} message="Trajets indisponibles : l'adresse du siège du club n'est pas localisée.">
          <Button variant="outline" size="sm" asChild>
            <Link to="/club?section=informations">Renseigner le siège</Link>
          </Button>
        </WarningPanel>
      ) : null}

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

          {opponents.length > 0 ? (
            <div className="flex flex-wrap items-center gap-2">
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <Input
                  type="search"
                  aria-label="Rechercher un club ou un gymnase"
                  placeholder="Rechercher un club ou un gymnase"
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

              <div role="group" aria-label="Filtrer les adversaires" className="flex flex-wrap items-center gap-1 rounded-md border border-border p-0.5">
                {SEGMENTS.map((segment) => {
                  const count = segmentCount(segment.key);
                  const pressed = segment.key === activeFilter;
                  return (
                    <Button
                      key={segment.label}
                      type="button"
                      size="sm"
                      aria-pressed={pressed}
                      variant={pressed ? "default" : "ghost"}
                      // Deux unités : le texte visible est le nombre nu, l'aria-label la nomme.
                      aria-label={null !== count ? `${segment.label} — ${count} ${segment.unit}` : segment.label}
                      onClick={() => setActiveFilter(segment.key)}
                    >
                      {segment.label}
                      {null !== count ? (
                        <span className="ml-1 tabular-nums" aria-hidden="true">
                          ({count})
                        </span>
                      ) : null}
                    </Button>
                  );
                })}
              </div>
            </div>
          ) : null}

          {opponents.length > 0 && 0 === sortedOpponents.length ? <EmptyHint role="status">Aucun adversaire pour « {query} ».</EmptyHint> : null}

          {sortedOpponents.length > 0 ? (
            <div className="@container">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-8">
                      <span className="sr-only">Logo</span>
                    </TableHead>
                    <TableHead>Gymnase</TableHead>
                    <TableHead className="hidden @md:table-cell">Trajet</TableHead>
                    <TableHead className="hidden text-right @md:table-cell">Rencontres</TableHead>
                    <TableHead className="text-right">
                      <span className="sr-only">Actions</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                {sortedOpponents.map((club) => (
                  <ClubTbody
                    key={`club-${club.code ?? club.name}`}
                    club={club}
                    filter={activeFilter}
                    onAddVenue={() => club.code !== null && setLocating({ code: club.code, clubName: club.name, fbiLabel: null, city: club.city, postalCode: club.postalCode })}
                    onPairLabel={(label) => club.code !== null && setLocating({ code: club.code, clubName: club.name, fbiLabel: label, city: club.city, postalCode: club.postalCode })}
                    onRemove={(venue) => setToRemove({ club, venue })}
                    onMerge={(source, target) => setToMerge({ club, source, target })}
                  />
                ))}
              </Table>
            </div>
          ) : null}

          {clubsWithoutGym > 0 && null === activeFilter ? (
            <p className="text-xs text-muted-foreground">
              {clubsWithoutGym} club{clubsWithoutGym > 1 ? "s" : ""} adverse{clubsWithoutGym > 1 ? "s" : ""} sans gymnase — leurs rencontres n'entrent pas dans le radar.
            </p>
          ) : null}
        </>
      ) : null}

      {null !== locating ? (
        <LocateOpponentModal code={locating.code} clubName={locating.clubName} fbiLabel={locating.fbiLabel} city={locating.city} postalCode={locating.postalCode} onClose={() => setLocating(null)} />
      ) : null}

      {/* Retirer un gymnase — destructif, la conséquence NOMMÉE (texte du serveur, jamais dérivé). */}
      <ConfirmDialog
        open={null !== toRemove}
        title={null === toRemove ? "" : `Retirer « ${toRemove.venue.label} » de ${toRemove.club.name} ?`}
        description={null === toRemove ? undefined : removeDescription(toRemove.club, toRemove.venue)}
        confirmLabel="Retirer"
        destructive
        onConfirm={() => {
          if (null !== toRemove) {
            deleteLink.mutate(toRemove.venue.id, { onSuccess: () => toast.success("Gymnase retiré.") });
          }
          setToRemove(null);
        }}
        onCancel={() => setToRemove(null)}
      />

      {/* Fusionner un gymnase dans un autre — non destructif ; le compte cible = source + cible. */}
      <ConfirmDialog
        open={null !== toMerge}
        title={null === toMerge ? "" : `Fusionner « ${toMerge.source.label} » dans « ${toMerge.target.label} » ?`}
        description={null === toMerge ? undefined : mergeDescription(toMerge.source, toMerge.target)}
        confirmLabel="Fusionner"
        onConfirm={() => {
          if (null !== toMerge) {
            const { target, source } = toMerge;
            repoint.mutate(
              { id: source.id, venueLabel: target.label, venueExternalRef: target.externalRef, latitude: target.latitude, longitude: target.longitude },
              { onSuccess: (result) => toast.success(`Fusionné — « ${target.label} » porte ${result.targetFixtureCount} rencontre${result.targetFixtureCount > 1 ? "s" : ""}.`) },
            );
          }
          setToMerge(null);
        }}
        onCancel={() => setToMerge(null)}
      />
    </section>
  );
}

/** La conséquence NOMMÉE d'un retrait — le repli vient du serveur (`fallbackVenueName`). */
function removeDescription(club: OpponentClub, venue: OpponentVenue): string {
  if (null === venue.fallbackVenueName) {
    return `${club.name} n'aura plus aucun gymnase connu. Ses ${club.fixtureCount} rencontre${club.fixtureCount > 1 ? "s" : ""} sortiront du radar de conflits et n'auront plus de trajet estimé.`;
  }
  if (0 === venue.fixtureCount) {
    return "Aucune rencontre n'est rattachée à ce gymnase.";
  }
  return `${venue.fixtureCount} rencontre${venue.fixtureCount > 1 ? "s" : ""} retomberont sur « ${venue.fallbackVenueName} », le gymnase le plus fréquent de ce club ; leur trajet deviendra approché. Le catalogue fédéral n'est pas modifié.`;
}

/** Le compte résultant de la cible = ses rencontres + celles de la source (arithmétique servie). */
function mergeDescription(source: OpponentVenue, target: OpponentVenue): string {
  const resulting = source.fixtureCount + target.fixtureCount;
  return `Les ${source.fixtureCount} rencontre${source.fixtureCount > 1 ? "s" : ""} de « ${source.label} » rejoindront « ${target.label} », qui en portera ${resulting}. Le libellé « ${source.label} » restera reconnu à l'import.`;
}

function ClubTbody({
  club,
  filter,
  onAddVenue,
  onPairLabel,
  onRemove,
  onMerge,
}: {
  club: OpponentClub;
  filter: OpponentFilter | null;
  onAddVenue: () => void;
  onPairLabel: (label: string) => void;
  onRemove: (venue: OpponentVenue) => void;
  onMerge: (source: OpponentVenue, target: OpponentVenue) => void;
}) {
  const showVenues = null === filter;
  const showUnmatched = null === filter || "a-apparier" === filter;
  const noGym = 0 === club.venues.length;

  return (
    <TableBody className="border-b border-border last:border-0">
      {/* Ligne CLUB — `<th scope="row">` porte le nom ; l'action « Ajouter un gymnase ». */}
      <TableRow className="border-0">
        <TableCell className="w-8">
          <OpponentLogo code={club.code} hasLogo={club.hasLogo} initials={opponentInitials(club.name)} size="md" />
        </TableCell>
        <th scope="row" className="px-3 py-2 text-left align-middle font-normal">
          <span className="block text-sm font-semibold">{club.name}</span>
          {null !== club.city ? <span className="block text-xs text-muted-foreground">{club.city}</span> : null}
          {noGym ? (
            <span className="mt-1 flex items-center gap-1">
              <StatusPill variant="warning" icon={<MapPinOff className="size-3.5" aria-hidden="true" />}>
                Aucun gymnase connu
              </StatusPill>
            </span>
          ) : null}
        </th>
        <TableCell className="hidden @md:table-cell">
          <span className="text-muted-foreground">—</span>
        </TableCell>
        <TableCell className="hidden text-right tabular-nums @md:table-cell">{noGym ? club.fixtureCount : ""}</TableCell>
        <TableCell className="text-right">
          {club.code !== null ? (
            <Button variant="ghost" size="sm" onClick={onAddVenue}>
              <Plus className="size-3.5" aria-hidden="true" />
              Ajouter un gymnase
            </Button>
          ) : null}
        </TableCell>
      </TableRow>

      {/* Une ligne par GYMNASE apparié. */}
      {showVenues
        ? club.venues.map((venue) => (
            <TableRow key={`venue-${venue.id}`} className="border-0">
              <TableCell className="w-8" />
              <TableCell>
                <span className="flex flex-wrap items-center gap-1">
                  <span className="text-sm">{venue.label}</span>
                  <SourceBadge source={venue.source} />
                </span>
                {/* Repli mobile : rencontres + trajet en sous-ligne (les colonnes dédiées sont @md). */}
                <span className="block text-xs text-muted-foreground @md:hidden">
                  {venue.fixtureCount} rencontre{venue.fixtureCount > 1 ? "s" : ""}
                  {null !== venue.travelMinutes ? ` · ${venue.approximated ? "~" : ""}${venue.travelMinutes} min` : ""}
                </span>
              </TableCell>
              <TableCell className="hidden tabular-nums @md:table-cell">
                <VenueTravel venue={venue} />
              </TableCell>
              <TableCell className="hidden text-right tabular-nums @md:table-cell">{venue.fixtureCount}</TableCell>
              <TableCell className="text-right">
                <Menu label={`Actions pour ${venue.label} — ${club.name}`} trigger={<MoreHorizontal className="size-4" aria-hidden="true" />} triggerClassName="size-11 rounded-md">
                  {club.venues
                    .filter((other) => other.id !== venue.id)
                    .map((other) => (
                      <MenuItem key={other.id} onSelect={() => onMerge(venue, other)}>
                        Fusionner dans « {other.label} »
                      </MenuItem>
                    ))}
                  <MenuItem onSelect={() => onRemove(venue)}>Retirer ce gymnase</MenuItem>
                </Menu>
              </TableCell>
            </TableRow>
          ))
        : null}

      {/* Les libellés « à apparier » — dans le MÊME tbody, après les gymnases. */}
      {showUnmatched
        ? club.unmatchedLabels.map((unmatched) => (
            <UnmatchedRow key={`unmatched-${unmatched.label}`} unmatched={unmatched} onPair={() => onPairLabel(unmatched.label)} />
          ))
        : null}
    </TableBody>
  );
}

function VenueTravel({ venue }: { venue: OpponentVenue }) {
  if ("pending" === venue.travelStatus) {
    return <span className="text-muted-foreground">en cours…</span>;
  }
  if (null !== venue.travelMinutes) {
    return <TravelMinutes minutes={venue.travelMinutes} approximated={venue.approximated} place={venue.label} />;
  }
  return <span className="text-muted-foreground">indisponible</span>;
}

function UnmatchedRow({ unmatched, onPair }: { unmatched: OpponentUnmatchedLabel; onPair: () => void }) {
  return (
    <TableRow className="border-0">
      <TableCell className="w-8" />
      <TableCell>
        <span className="flex flex-wrap items-center gap-1">
          <span className="text-sm">« {unmatched.label} »</span>
          <StatusPill variant="warning" icon={<MapPinOff className="size-3.5" aria-hidden="true" />}>
            à apparier
          </StatusPill>
        </span>
        <span className="block text-xs text-muted-foreground @md:hidden">
          {unmatched.fixtureCount} rencontre{unmatched.fixtureCount > 1 ? "s" : ""}
        </span>
      </TableCell>
      <TableCell className="hidden @md:table-cell">
        <span className="text-muted-foreground">—</span>
      </TableCell>
      <TableCell className="hidden text-right tabular-nums @md:table-cell">{unmatched.fixtureCount}</TableCell>
      <TableCell className="text-right">
        <Button variant="ghost" size="sm" onClick={onPair}>
          Apparier
        </Button>
      </TableCell>
    </TableRow>
  );
}
