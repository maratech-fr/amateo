import { MapPinOff, MoreHorizontal, Plus, RefreshCw, Search } from "lucide-react";
import { useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Menu, MenuItem } from "@/shared/components/ui/menu";
import { OpponentLogo } from "@/shared/components/ui/opponent-logo";
import { SegmentedControl } from "@/shared/components/ui/segmented-control";
import { Spinner } from "@/shared/components/ui/spinner";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/shared/components/ui/table";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
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
import { MATCHES_TEXTS } from "./texts";

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
  /** Mode « modifier le gymnase » — le lien à re-pointer (EXCLUSIF de fbiLabel). null sinon. */
  repointLink: { id: string; label: string } | null;
  /** Les libellés orphelins du club — la file que la modale enchaîne (mode appariement). */
  unmatchedLabels: string[];
  /** L'adversaire n'a aucun code fédéral (apparié par clé sentinelle, pas de suggestions partagées). */
  sansCode: boolean;
  city: string | null;
  postalCode: string | null;
}

const SEGMENTS: { key: OpponentFilter | null; label: string; unit: string }[] = [
  { key: "sans-gymnase", label: MATCHES_TEXTS.opponentFilterNoVenue, unit: MATCHES_TEXTS.unitClubs },
  { key: "a-apparier", label: MATCHES_TEXTS.opponentFilterToPair, unit: MATCHES_TEXTS.unitVenues },
  { key: "trajet-indisponible", label: MATCHES_TEXTS.opponentFilterTravelUnavailable, unit: MATCHES_TEXTS.unitVenues },
  { key: null, label: MATCHES_TEXTS.opponentFilterAll, unit: "" },
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
  // M14 — le pourquoi, servi PAR LIGNE : « en attente de relance » (budget worker épuisé) vs
  // « introuvable » (IGN muet / gymnase mal géolocalisé). Le front AFFICHE la cause serveur.
  const awaitingRetryCount = allVenues.filter((v) => "awaiting_retry" === v.travelFailureReason).length;
  const unroutableCount = allVenues.filter((v) => "unroutable" === v.travelFailureReason).length;
  const failedVenueNames = allVenues.filter((v) => null !== v.travelFailureReason).map((v) => v.label);
  const totalTravel = allVenues.length;
  const clubsWithoutGym = opponents.filter((o) => 0 === o.venues.length).length;
  useTravelStream(computing);

  // ── Compteurs de segments — DEUX unités (clubs / salles) : l'aria-label la nomme. ──
  const sansGymnaseCount = clubsWithoutGym;
  const aApparierCount = opponents.reduce((sum, o) => sum + o.unmatchedLabels.length, 0);
  const segmentCount = (key: OpponentFilter | null): number | null =>
    "sans-gymnase" === key ? sansGymnaseCount : "a-apparier" === key ? aApparierCount : "trajet-indisponible" === key ? failedCount : null;

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
      if ("trajet-indisponible" === activeFilter && !o.venues.some((v) => "unavailable" === v.travelStatus)) {
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
  // On fige le rang à la première liste non vue : un setState GARDÉ pendant le rendu (patron React
  // « stocker une info des rendus précédents ») — React re-rend aussitôt, sans effet ni lecture de ref.
  const [frozenRank, setFrozenRank] = useState<Map<string, number> | null>(null);
  if (null === frozenRank && opponents.length > 0) {
    const ranks = new Map<string, number>();
    opponents.forEach((o, index) => ranks.set(o.code ?? o.name, index));
    setFrozenRank(ranks);
  }
  const rankOf = (o: OpponentClub): number => frozenRank?.get(o.code ?? o.name) ?? Number.MAX_SAFE_INTEGER;
  const sortedOpponents = [...filteredOpponents].sort((a, b) => rankOf(a) - rankOf(b));

  const updateLabel = "running" === update.step ? MATCHES_TEXTS.opponentsUpdating : MATCHES_TEXTS.opponentsUpdate;

  return (
    <section className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="text-base font-semibold text-foreground">{MATCHES_TEXTS.opponentsTitle}</h2>
          <p className="text-sm text-muted-foreground">{MATCHES_TEXTS.opponentsIntro}</p>
        </div>
        <div className="flex flex-col items-end gap-1">
          <Button variant="outline" size="sm" className="shrink-0" disabled={update.isPending || computing} onClick={update.run}>
            <RefreshCw className={cn("size-4", update.isPending ? "animate-spin motion-reduce:animate-none" : "")} aria-hidden="true" />
            {updateLabel}
          </Button>
          {"ready" === state && clubGeolocated && totalTravel > 0 ? (
            <div className="flex flex-col items-end">
              <p role="status" aria-live="polite" className="text-xs text-muted-foreground">
                {computing ? (
                  MATCHES_TEXTS.travelComputing
                ) : failedCount > 0 ? (
                  <>
                    {MATCHES_TEXTS.travelComputedWithFailuresPrefix}
                    {/* « N indisponibles » pose le filtre « Trajet indisponible » (P4-293) — le gestionnaire
                        passe du constat au recadrage de la liste d'un clic. */}
                    <button type="button" className="font-medium underline underline-offset-2 hover:no-underline" onClick={() => setActiveFilter("trajet-indisponible")}>
                      {MATCHES_TEXTS.travelUnavailableCount(failedCount)}
                    </button>
                    .
                  </>
                ) : (
                  MATCHES_TEXTS.travelComputed
                )}
              </p>
              <span aria-hidden="true" className="text-xs tabular-nums text-muted-foreground">
                {MATCHES_TEXTS.travelProgress(doneCount, totalTravel)}
              </span>
            </div>
          ) : null}
        </div>
      </div>

      {"ready" === state && !clubGeolocated ? (
        <NoticeBanner icon={<MapPinOff className="size-4 text-warning" aria-hidden="true" />} message={MATCHES_TEXTS.seatNotLocated}>
          <Button variant="outline" size="sm" asChild>
            <Link to="/club?section=informations">{MATCHES_TEXTS.fillSeat}</Link>
          </Button>
        </NoticeBanner>
      ) : null}

      {"ready" === state && clubGeolocated && !computing && failedCount > 0 ? (
        <NoticeBanner
          icon={<MapPinOff className="size-4 text-warning" aria-hidden="true" />}
          message={
            <span className="flex flex-col gap-0.5">
              <span>
                {awaitingRetryCount + unroutableCount > 0 ? MATCHES_TEXTS.travelFailuresByReason(awaitingRetryCount, unroutableCount) : MATCHES_TEXTS.travelFailuresBanner(failedCount)}
              </span>
              {/* D7 — invite à relancer pour reprendre le pool suivant, UNIQUEMENT s'il reste des
                  paires « en attente de relance » (un échec purement « introuvable » ne la montre pas). */}
              {awaitingRetryCount > 0 ? <span className="text-xs text-muted-foreground">{MATCHES_TEXTS.travelRelaunchToContinue}</span> : null}
              {failedVenueNames.length > 0 ? <span className="text-xs text-muted-foreground">{MATCHES_TEXTS.travelFailureVenues(failedVenueNames)}</span> : null}
            </span>
          }
        >
          <Button variant="outline" size="sm" disabled={retry.isPending} onClick={() => retry.mutate()}>
            {MATCHES_TEXTS.retryMissing}
          </Button>
        </NoticeBanner>
      ) : null}

      {"failed" === state ? <LoadErrorHint onRetry={() => void travelQuery.refetch()} /> : null}
      {"loading" === state ? <Spinner /> : null}

      {"ready" === state ? (
        <>
          {0 === opponents.length ? <EmptyHint>{MATCHES_TEXTS.noAwayThisSeason}</EmptyHint> : null}

          {opponents.length > 0 ? (
            <div className="flex flex-wrap items-center gap-2">
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <Input
                  type="search"
                  aria-label={MATCHES_TEXTS.searchOpponentOrVenue}
                  placeholder={MATCHES_TEXTS.searchOpponentOrVenue}
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

              {/* Contrôle segmenté partagé — « null » (Tous) encodé par la clé sentinelle "tous".
                  Le compteur nu reste aria-hidden ; le nom accessible du segment le reprend. */}
              <SegmentedControl
                mode="single"
                label={MATCHES_TEXTS.filterOpponents}
                options={SEGMENTS.map((segment) => {
                  const count = segmentCount(segment.key);
                  return {
                    key: segment.key ?? "tous",
                    label: segment.label,
                    count: null !== count ? count : undefined,
                    ariaLabel: null !== count ? MATCHES_TEXTS.segmentAriaLabel(segment.label, count, segment.unit) : undefined,
                  };
                })}
                value={activeFilter ?? "tous"}
                onValueChange={(key) => setActiveFilter("tous" === key ? null : (key as OpponentFilter))}
              />
            </div>
          ) : null}

          {opponents.length > 0 && 0 === sortedOpponents.length ? <EmptyHint role="status">{MATCHES_TEXTS.noSearchResult(query)}</EmptyHint> : null}

          {sortedOpponents.length > 0 ? (
            <div className="@container">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-8">
                      <span className="sr-only">{MATCHES_TEXTS.columnLogo}</span>
                    </TableHead>
                    <TableHead>{MATCHES_TEXTS.venueHeader}</TableHead>
                    <TableHead className="hidden @md:table-cell @md:w-28">{MATCHES_TEXTS.columnTravel}</TableHead>
                    <TableHead className="hidden text-right @md:table-cell @md:w-24">{MATCHES_TEXTS.columnFixtures}</TableHead>
                    <TableHead className="w-14 text-right @md:w-56">
                      <span className="sr-only">{MATCHES_TEXTS.columnActions}</span>
                    </TableHead>
                  </TableRow>
                </TableHeader>
                {sortedOpponents.map((club) => (
                  <ClubTbody
                    key={`club-${club.code ?? club.name}`}
                    club={club}
                    filter={activeFilter}
                    onAddVenue={() =>
                      setLocating({ code: club.pairingKey, clubName: club.name, fbiLabel: null, repointLink: null, unmatchedLabels: [], sansCode: null === club.code, city: club.city, postalCode: club.postalCode })
                    }
                    onPairLabel={(label) =>
                      setLocating({
                        code: club.pairingKey,
                        clubName: club.name,
                        fbiLabel: label,
                        repointLink: null,
                        unmatchedLabels: club.unmatchedLabels.map((u) => u.label),
                        sansCode: null === club.code,
                        city: club.city,
                        postalCode: club.postalCode,
                      })
                    }
                    onRepoint={(venue) =>
                      setLocating({
                        code: club.pairingKey,
                        clubName: club.name,
                        fbiLabel: null,
                        repointLink: { id: venue.id, label: venue.label },
                        unmatchedLabels: [],
                        sansCode: null === club.code,
                        city: club.city,
                        postalCode: club.postalCode,
                      })
                    }
                    onRemove={(venue) => setToRemove({ club, venue })}
                    onMerge={(source, target) => setToMerge({ club, source, target })}
                  />
                ))}
              </Table>
            </div>
          ) : null}

          {clubsWithoutGym > 0 && null === activeFilter ? <p className="text-xs text-muted-foreground">{MATCHES_TEXTS.clubsWithoutGymHint(clubsWithoutGym)}</p> : null}
        </>
      ) : null}

      {null !== locating ? (
        <LocateOpponentModal
          code={locating.code}
          clubName={locating.clubName}
          fbiLabel={locating.fbiLabel}
          repointLink={locating.repointLink}
          unmatchedLabels={locating.unmatchedLabels}
          sansCode={locating.sansCode}
          city={locating.city}
          postalCode={locating.postalCode}
          onClose={() => setLocating(null)}
        />
      ) : null}

      {/* Retirer un gymnase — destructif, la conséquence NOMMÉE (texte du serveur, jamais dérivé). */}
      <ConfirmDialog
        open={null !== toRemove}
        title={null === toRemove ? "" : MATCHES_TEXTS.removeVenueTitle(toRemove.venue.label, toRemove.club.name)}
        description={null === toRemove ? undefined : removeDescription(toRemove.club, toRemove.venue)}
        confirmLabel={MATCHES_TEXTS.removeConfirm}
        destructive
        onConfirm={() => {
          if (null !== toRemove) {
            deleteLink.mutate(toRemove.venue.id, { onSuccess: () => toast.success(MATCHES_TEXTS.venueRemoved) });
          }
          setToRemove(null);
        }}
        onCancel={() => setToRemove(null)}
      />

      {/* Fusionner un gymnase dans un autre — non destructif ; le compte cible = source + cible. */}
      <ConfirmDialog
        open={null !== toMerge}
        title={null === toMerge ? "" : MATCHES_TEXTS.mergeVenueTitle(toMerge.source.label, toMerge.target.label)}
        description={null === toMerge ? undefined : mergeDescription(toMerge.source, toMerge.target)}
        confirmLabel={MATCHES_TEXTS.mergeConfirm}
        onConfirm={() => {
          if (null !== toMerge) {
            const { target, source } = toMerge;
            repoint.mutate(
              { id: source.id, venueLabel: target.label, venueExternalRef: target.externalRef, latitude: target.latitude, longitude: target.longitude },
              { onSuccess: (result) => toast.success(MATCHES_TEXTS.mergeSuccess(target.label, result.targetFixtureCount)) },
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
    return MATCHES_TEXTS.removeDescNoFallback(club.name, club.fixtureCount);
  }
  if (0 === venue.fixtureCount) {
    return MATCHES_TEXTS.removeDescNoFixtures;
  }
  return MATCHES_TEXTS.removeDescFallback(venue.fixtureCount, venue.fallbackVenueName);
}

/** Le compte résultant de la cible = ses rencontres + celles de la source (arithmétique servie). */
function mergeDescription(source: OpponentVenue, target: OpponentVenue): string {
  const resulting = source.fixtureCount + target.fixtureCount;
  return MATCHES_TEXTS.mergeDesc(source.fixtureCount, source.label, target.label, resulting);
}

function ClubTbody({
  club,
  filter,
  onAddVenue,
  onPairLabel,
  onRepoint,
  onRemove,
  onMerge,
}: {
  club: OpponentClub;
  filter: OpponentFilter | null;
  onAddVenue: () => void;
  onPairLabel: (label: string) => void;
  onRepoint: (venue: OpponentVenue) => void;
  onRemove: (venue: OpponentVenue) => void;
  onMerge: (source: OpponentVenue, target: OpponentVenue) => void;
}) {
  // Les gymnases visibles selon le filtre : tous (aucun filtre), SEULEMENT ceux dont le trajet a
  // échoué (« Trajet indisponible », P4-293 — correctif du piège « club entier »), aucun sinon
  // (« Sans gymnase »/« À apparier » n'affichent pas de gymnase apparié).
  const venuesToShow =
    null === filter ? club.venues : "trajet-indisponible" === filter ? club.venues.filter((v) => "unavailable" === v.travelStatus) : [];
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
                {MATCHES_TEXTS.noVenueKnown}
              </StatusPill>
            </span>
          ) : null}
        </th>
        <TableCell className="hidden @md:table-cell">
          <span className="text-muted-foreground">—</span>
        </TableCell>
        <TableCell className="hidden text-right tabular-nums @md:table-cell">{noGym ? club.fixtureCount : ""}</TableCell>
        <TableCell className="text-right">
          {/* P4-285 — bouton d'ajout libellé ; style DISCRET (`ghost`, sans bordure) sur une ligne de
              tableau. Libellé VISIBLE dès le palier conteneur `@md` (là où la table déplie ses colonnes) ;
              en dessous, seule l'icône « + » est peinte et le libellé devient le nom accessible via le
              `<span sr-only>`, pour que la ligne CLUB tienne à 360 px sans défilement horizontal (décision :
              ordinateur d'abord, garde 360 px conservé). Le bouton n'est PAS `size="icon"` (garde
              `addButtonLabelGuard`) ; carré 36 px de cible sous `@md` (`w-9 px-0`, hauteur `h-9` de `sm`). */}
          <Button variant="ghost" size="sm" className="w-9 px-0 @md:w-auto @md:px-3" onClick={onAddVenue}>
            <Plus className="size-3.5" aria-hidden="true" />
            <span className="sr-only @md:not-sr-only">{MATCHES_TEXTS.addVenue}</span>
          </Button>
        </TableCell>
      </TableRow>

      {/* Une ligne par GYMNASE affiché (filtre-dépendant, cf. `venuesToShow`). */}
      {venuesToShow.map((venue) => (
        <TableRow key={`venue-${venue.id}`} className="border-0">
          <TableCell className="w-8" />
          <TableCell>
            <span className="flex flex-wrap items-center gap-1">
              <span className="text-sm">{venue.label}</span>
              <SourceBadge source={venue.source} />
            </span>
            {/* Repli mobile : rencontres + trajet en sous-ligne (les colonnes dédiées sont @md). */}
            <span className="block text-xs text-muted-foreground @md:hidden">{MATCHES_TEXTS.venueSubline(venue.fixtureCount, venue.travelMinutes, venue.approximated)}</span>
          </TableCell>
          <TableCell className="hidden tabular-nums @md:table-cell">
            <VenueTravel venue={venue} />
          </TableCell>
          <TableCell className="hidden text-right tabular-nums @md:table-cell">{venue.fixtureCount}</TableCell>
          <TableCell className="text-right">
            <Menu label={MATCHES_TEXTS.venueActionsMenu(venue.label, club.name)} trigger={<MoreHorizontal className="size-4" aria-hidden="true" />} triggerClassName="size-11 rounded-md @md:size-8">
              <MenuItem onSelect={() => onRepoint(venue)}>{MATCHES_TEXTS.editVenue}</MenuItem>
              {club.venues
                .filter((other) => other.id !== venue.id)
                .map((other) => (
                  <MenuItem key={other.id} onSelect={() => onMerge(venue, other)}>
                    {MATCHES_TEXTS.mergeInto(other.label)}
                  </MenuItem>
                ))}
              <MenuItem onSelect={() => onRemove(venue)}>{MATCHES_TEXTS.removeVenue}</MenuItem>
            </Menu>
          </TableCell>
        </TableRow>
      ))}

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
    return <span className="text-muted-foreground">{MATCHES_TEXTS.travelInProgress}</span>;
  }
  if (null !== venue.travelMinutes) {
    return <TravelMinutes minutes={venue.travelMinutes} approximated={venue.approximated} place={venue.label} />;
  }
  return <span className="text-muted-foreground">{MATCHES_TEXTS.travelUnavailable}</span>;
}

function UnmatchedRow({ unmatched, onPair }: { unmatched: OpponentUnmatchedLabel; onPair: () => void }) {
  return (
    <TableRow className="border-0">
      <TableCell className="w-8" />
      <TableCell>
        <span className="flex flex-wrap items-center gap-1">
          <span className="text-sm">{MATCHES_TEXTS.venueLabelQuoted(unmatched.label)}</span>
          <StatusPill variant="warning" icon={<MapPinOff className="size-3.5" aria-hidden="true" />}>
            {MATCHES_TEXTS.toPair}
          </StatusPill>
        </span>
        <span className="block text-xs text-muted-foreground @md:hidden">{MATCHES_TEXTS.fixtureCount(unmatched.fixtureCount)}</span>
      </TableCell>
      <TableCell className="hidden @md:table-cell">
        <span className="text-muted-foreground">—</span>
      </TableCell>
      <TableCell className="hidden text-right tabular-nums @md:table-cell">{unmatched.fixtureCount}</TableCell>
      <TableCell className="text-right">
        <Button variant="ghost" size="sm" onClick={onPair}>
          {MATCHES_TEXTS.pair}
        </Button>
      </TableCell>
    </TableRow>
  );
}
