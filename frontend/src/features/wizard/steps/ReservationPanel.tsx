import { useState } from "react";

import { useEntryConflicts } from "@/features/cockpit/queries";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { readFailed } from "@/shared/lib/readState";

import type { PriorityTier, Team, Venue, VenueTrainingSlot } from "../api";
import { reservedTeamsBySlot, effectiveSlotCapacity, slotKey } from "../lib/reservationSlots";
import { closuresByVenue, closurePeriodLabel, isSlotClosed } from "../lib/venueClosures";
import { useGridSlots, useReservations, useSharedTrainingBlocks, useSharedTrainingGroups, useWizardTeamCoaches } from "../queries";
import { ReservationGrid } from "./ReservationGrid";
import { SlotReservationModal } from "./SlotReservationModal";

/**
 * "Réserver" tab: a per-venue weekly grid of the EDITED LAYER's availability slots — click
 * a slot to pin one or two teams on it (a HARD lock honored at generation). The
 * rank-sorted summary of all reservations lives in the Récap step, not here (kept
 * épuré). Server-backed via the Reservation entity; base plan vs period overlay by
 * `schedulePlanId` (ADR-0002 inv. 5 : la réservation est une RÉPONSE, elle pend au plan).
 */
export function ReservationPanel({
  teams,
  tiers,
  venues,
  schedulePlanId,
  entryId,
  pausedTeamIds,
  disabledVenueIds,
}: {
  teams: Team[];
  tiers: PriorityTier[];
  venues: Venue[];
  schedulePlanId: string | null;
  /** L'entrée de la période (P2-22 D2) — porte les fermetures de gymnase servies par
   *  /calendar-entries/{id}/conflicts. `null` hors période (socle) : le hook est désactivé,
   *  aucune fermeture. On interroge l'id de l'entrée COURANTE (semaine enfant comprise) : le
   *  serveur résout la mère (`parentEntryId ?? id`) pour les fermetures. */
  entryId?: string | null;
  /** Équipes en pause pour la période : NOMMÉES (un épinglage existant reste lisible) mais jamais proposées. */
  pausedTeamIds?: ReadonlySet<string>;
  /** Gymnases désactivés pour la période : ATTEIGNABLES (pour retirer une réservation — le geste correctif) mais marqués, et fermés à l'ajout. */
  disabledVenueIds?: ReadonlySet<string>;
}) {
  // La grille de la couche éditée — jamais celle de la saison depuis une période :
  // une réservation hors de la grille servie bloquerait la génération (#8).
  const { data: slots = [] } = useGridSlots(schedulePlanId);
  const { data: reservations = [] } = useReservations(schedulePlanId);
  // P2-46 PR-3 — les groupes de mutualisation de la portée courante, posables sur un créneau libre.
  // En portée socle le provider renvoie socle ET périodes : on ne garde que le socle (même tri que
  // MutualisationPanel).
  const { data: allSharedGroups = [] } = useSharedTrainingGroups(schedulePlanId);
  const sharedGroups = null === schedulePlanId ? allSharedGroups.filter((g) => null === g.schedulePlanId) : allSharedGroups;
  // P2-51 PR-6 — les BLOCS de mutualisation de la portée courante, posables sur un créneau libre
  // comme une équipe à part entière. Même tri socle que les groupes (provider socle+périodes).
  const { data: allSharedBlocks = [] } = useSharedTrainingBlocks(schedulePlanId);
  const sharedBlocks = null === schedulePlanId ? allSharedBlocks.filter((b) => null === b.schedulePlanId) : allSharedBlocks;
  // P2-22 D2 — les fermetures de gymnase de la période. Hors période (entryId null) le hook
  // est désactivé → aucune fermeture, socle inchangé.
  const conflictsQuery = useEntryConflicts(entryId ?? null);
  // P2-9 PR C : le lien équipe→coach permet à la modale de refuser au clic une affectation
  // qui dédoublerait un coach — même règle que le blocage du récap (PR B).
  // ⚠ On passe l'état de la requête, PAS un `= []` de confort : sans les liens, la règle ne
  // trouve JAMAIS de conflit (Map vide ⇒ aucun coach MAIN ⇒ rien à dédoubler). Un repli
  // silencieux éteindrait donc le contrôle pendant le chargement ou après un échec — un
  // fail-OPEN sur la garde même que ce lot ajoute. La modale doit savoir qu'elle ne sait pas.
  const { data: teamCoaches, isPending: coachesPending, isError: coachesFailed, refetch: refetchCoaches } = useWizardTeamCoaches();
  const [venueId, setVenueId] = useState("");
  const [activeSlot, setActiveSlot] = useState<VenueTrainingSlot | null>(null);

  if (0 === venues.length) {
    return <EmptyHint>Ajoutez d'abord un gymnase et ses créneaux pour pouvoir réserver.</EmptyHint>;
  }

  // FAIL-CLOSED (P2-22 D2) : sans les fermetures, la grille paraîtrait pleinement réservable —
  // le gestionnaire poserait un épinglage sur un jour fermé, orphelin qui bloque la génération.
  // On refuse donc de rendre la grille tant que la lecture a échoué (hors période : hook
  // désactivé → jamais `failed`). Même patron que `PeriodStructure`.
  if (readFailed(conflictsQuery)) {
    return (
      <LoadErrorHint onRetry={() => void conflictsQuery.refetch()}>
        Impossible de vérifier les fermetures de gymnase sur cette période.
      </LoadErrorHint>
    );
  }

  const selected = venues.find((v) => v.id === venueId) ?? venues[0];
  const venueCanSplit = new Map(venues.map((v) => [v.id, v.canSplit]));
  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const venueSlots = slots.filter((s) => s.venueId === selected.id);
  const closuresOfVenue = closuresByVenue(conflictsQuery.data?.closures ?? []).get(selected.id) ?? [];
  // P2-37 D6 — les gymnases ENTIÈREMENT fermés, tels que le serveur les calcule. Un tel gymnase
  // est bien dans `disabledVenueIds` (via `useActiveVenues`), mais le MOTIF diffère d'un
  // désactivé « override » : on le distingue pour dire à l'écran la même chose que le refus
  // serveur (fermeture, pas désactivation manuelle).
  const fullyClosed = new Set(conflictsQuery.data?.fullyClosedVenueIds ?? []);
  const selectedFullyClosed = fullyClosed.has(selected.id);

  // slotKey → reserved team NAMES (for the grid badges).
  const reservedNames = new Map<string, string[]>();
  reservedTeamsBySlot(reservations).forEach((ids, key) => reservedNames.set(key, ids.map((id) => teamName.get(id) ?? "?")));

  return (
    <div>
      <p className="mb-3 text-xs text-muted-foreground">Cliquez un créneau pour y fixer une équipe : la place est réservée pour elle et conservée à chaque régénération. Le récapitulatif des réservations est dans l'étape « Récap ».</p>

      <div className="mb-3 flex items-center gap-2">
        <span className="text-xs font-medium text-muted-foreground">Gymnase</span>
        <VenueSelect
          aria-label="Gymnase"
          className="h-8"
          wrapperClassName="w-52"
          venues={venues.map((v) => ({ id: v.id, name: v.name + (fullyClosed.has(v.id) ? " (fermé cette période)" : disabledVenueIds?.has(v.id) ? " (désactivé pour cette période)" : ""), color: v.color }))}
          value={selected.id}
          onChange={(e) => {
            setActiveSlot(null); // never leave a modal open on a slot from the previous venue
            setVenueId(e.target.value);
          }}
        />
      </div>

      {/* P3-20 (2026-08-06) — ce gymnase ne sert plus la période. Ses réservations ne
          bloquent PLUS la génération (`OrphanPinGuard` les ignore : le solveur ne les
          verra jamais) et elles sont CONSERVÉES — réactiver rend la saisie telle quelle.
          L'écran reste atteignable pour le geste CORRECTIF (retirer), fermé au geste
          fautif (ajouter) : §7.2 pt 3. L'ancien texte annonçait un blocage qui n'existe
          plus — le laisser aurait fait courir le gestionnaire après un faux problème. */}
      {/* P2-37 D6 — deux motifs, deux textes : entièrement fermé (fermeture datée, on ajuste la
          fermeture) vs désactivé « override » (on réactive le gymnase). Les deux conservent les
          réservations et ferment l'ajout ; seul le geste de sortie diffère. */}
      {selectedFullyClosed ? (
        <p className="mb-3 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
          {selected.name} est indisponible sur toute la période{closuresOfVenue.length > 0 ? ` — ${closuresOfVenue.map(closurePeriodLabel).join(" · ")}` : ""} : ses créneaux et ses réservations ne partiront pas au système. Elles sont conservées — ajustez ou levez la fermeture pour le rouvrir. On ne peut plus en ajouter ici.
        </p>
      ) : disabledVenueIds?.has(selected.id) ? (
        <p className="mb-3 rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground">
          {selected.name} est désactivé pour cette période : ses créneaux et ses réservations ne partiront pas au système. Elles sont conservées — réactiver le gymnase les rend telles quelles. On ne peut plus en ajouter ici.
        </p>
      ) : null}

      <ReservationGrid
        venue={selected}
        slots={venueSlots}
        reservedTeams={reservedNames}
        slotKeyOf={(s) => slotKey(s.venueId, s.dayOfWeek, s.startTime)}
        capacityOf={(s) => effectiveSlotCapacity(s, venueCanSplit)}
        onSelectSlot={setActiveSlot}
        closures={closuresOfVenue}
      />

      {null !== activeSlot ? (
        <SlotReservationModal
          slot={activeSlot}
          venue={selected}
          teams={teams}
          tiers={tiers}
          reservations={reservations}
          teamCoaches={teamCoaches ?? null}
          coachesPending={coachesPending}
          coachesFailed={coachesFailed}
          onRetryCoaches={() => void refetchCoaches()}
          venues={venues}
          disabledVenueIds={disabledVenueIds}
          pausedTeamIds={pausedTeamIds}
          slotClosed={isSlotClosed(activeSlot, closuresOfVenue)}
          venueFullyClosed={selectedFullyClosed}
          venueClosures={closuresOfVenue}
          venueCanSplit={venueCanSplit}
          sharedTrainingGroups={sharedGroups}
          sharedTrainingBlocks={sharedBlocks}
          schedulePlanId={schedulePlanId}
          onClose={() => setActiveSlot(null)}
        />
      ) : null}
    </div>
  );
}
