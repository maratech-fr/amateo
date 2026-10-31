import { AlertTriangle, Lock, Plus, Route, Sparkles, Trash2 } from "lucide-react";
import { type FormEvent, useEffect, useRef, useState } from "react";
import { useSearchParams } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { DeleteConfirm } from "@/shared/components/ui/delete-confirm";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Select } from "@/shared/components/ui/select";
import { StatusPill } from "@/shared/components/ui/badge";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { nextVenueColor } from "@/shared/lib/color";
import { readState } from "@/shared/lib/readState";
import { useSocleValidated } from "@/shared/lib/socle";
import { useTravelStream } from "@/shared/lib/travelStream";
import { toast } from "@/shared/stores/toastStore";

import { MatchWindowsEditor } from "@/features/matches/MatchWindowsEditor";
import { useVenueMatchWindows } from "@/features/matches/queries";

import { useMe, useWorkingSeason } from "@/shared/session/queries";

import type { FfbbSalle, Venue, VenueTrainingSlot } from "../api";
import { DAYS, hhmm } from "../lib/days";
import { filterSalles } from "../lib/salleSuggestions";
import { slotPlacementError } from "../lib/slotOverlap";
import { useCreateSlot, useCreateVenue, useDeleteSlot, useDeletionImpact, useDeleteVenue, useFfbbSalles, useFfbbSallesByName, useFfbbSallesProches, useLocateVenue, useReservations, useUpdateSlot, useUpdateVenue, useVenueGeoCheck, useVenueSlots, useWizardTeams, useWizardVenues } from "../queries";
import { venueGeoAlertMessage } from "../lib/venueGeoAlert";
import { useWizardStore } from "../store";
import { useWizardFooter } from "../lib/footerSlot";
import { WIZARD_TEXTS } from "../texts";
import { AssociateSalleModal } from "./AssociateSalleModal";
import { PeriodVenues } from "./PeriodVenues";
import { SalleResultRow } from "./SalleResultRow";
import { ParcImportModal } from "../parcImport/ParcImportModal";
import { TravelMatrixModal } from "./TravelMatrixModal";
import { VenueAvailabilityGrid } from "./VenueAvailabilityGrid";
import { VenueGeocodeField } from "./VenueGeocodeField";
import { CapacitySelect, DurationSelect, GroupLabelField, SharedSlotHint, SlotEndField } from "./slotFields";
import { WEEK } from "../lib/weekGrid";
import { venuesWithoutSlot } from "../lib/useStepValidation";
import { splitCascadePreview, type SplitCascadePreview } from "../lib/reservationSlots";

const HEX_RE = /^#[0-9a-fA-F]{6}$/;

/** Seuil de la recherche par nom dans la FFBB — même valeur que le serveur (#20). */
const MIN_NAME_SEARCH_LENGTH = 3;

/** Neutral grey fallback when a venue has no colour set yet. */
const DEFAULT_VENUE_COLOR = "#666666";

/** Colour swatch + free hex field; both apply immediately, no Enter needed. */
export function ColorField({ venue, onApply }: { venue: Venue; onApply: (color: string) => void }) {
  const current = venue.color ?? DEFAULT_VENUE_COLOR;
  const [hex, setHex] = useState(current);
  // The native colour picker fires onChange CONTINUOUSLY while dragging — persisting
  // a PUT per step races the Doctrine @Version lock ("optimistic lock failed"). Keep
  // the live preview immediate (setHex) but debounce the write to the settled colour.
  // On unmount (leaving the step, switching gyms) we FLUSH the pending colour so a
  // last-second edit is never dropped. onApply is read through a ref to avoid a
  // stale closure.
  const applyTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const pending = useRef<string | null>(null);
  const onApplyRef = useRef(onApply);
  useEffect(() => {
    onApplyRef.current = onApply;
  }, [onApply]);
  // Reflect an EXTERNAL colour change (refetch, concurrent update) in the field —
  // but only when the user is not mid-edit (no pending write), so it never
  // clobbers what is being typed.
  useEffect(() => {
    if (null === pending.current) {
      setHex(venue.color ?? DEFAULT_VENUE_COLOR);
    }
  }, [venue.color]);

  const flush = () => {
    if (null !== applyTimer.current) {
      clearTimeout(applyTimer.current);
      applyTimer.current = null;
    }
    if (null !== pending.current) {
      onApplyRef.current(pending.current);
      pending.current = null;
    }
  };
  useEffect(() => flush, []);

  const commit = (value: string) => {
    setHex(value);
    if (HEX_RE.test(value)) {
      pending.current = value;
      if (null !== applyTimer.current) {
        clearTimeout(applyTimer.current);
      }
      applyTimer.current = setTimeout(flush, 300);
    }
  };
  return (
    <div className="flex items-center gap-1">
      <input
        aria-label={WIZARD_TEXTS.venueColorLabel}
        type="color"
        className="size-9 shrink-0 rounded border border-input bg-background"
        value={HEX_RE.test(hex) ? hex : current}
        onChange={(e) => commit(e.target.value)}
      />
      <Input aria-label={WIZARD_TEXTS.venueColorHexLabel} className="h-9 w-24 font-mono text-xs" value={hex} placeholder="#3498DB" onChange={(e) => commit(e.target.value)} />
    </div>
  );
}

function SlotEditor({ slot, canSplit, otherSlots, onClose }: { slot: VenueTrainingSlot; canSplit: boolean; otherSlots: VenueTrainingSlot[]; onClose: () => void }) {
  const update = useUpdateSlot();
  const del = useDeleteSlot();
  // P4-108 — cette collection n'était chargée dans CETTE modale que pour compter l'impact
  // d'une suppression de créneau depuis le cache. Le serveur le fait, et lui voit aussi les
  // verrous HARD que la réservation avait matérialisés.
  const [day, setDay] = useState(slot.dayOfWeek);
  const [time, setTime] = useState(hhmm(slot.startTime));
  const [duration, setDuration] = useState(slot.durationMinutes);
  const [capacity, setCapacity] = useState(slot.capacity);
  const [groupLabel, setGroupLabel] = useState(slot.groupLabel ?? "");
  const [error, setError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const slotImpact = useDeletionImpact("slot", confirmDelete ? slot.id : null);

  const save = () => {
    // Minuit puis chevauchement — même foyer que la pose au clic (`slotPlacementError`),
    // `otherSlots` excluant déjà le créneau édité.
    const invalid = slotPlacementError(otherSlots, day, time, duration);
    if (null !== invalid) {
      setError(invalid);
      return;
    }
    const effCapacity = canSplit ? capacity : 1;
    // Close ONLY once the write succeeds. If the backend rejects it (e.g. a stale
    // cache let a now-overlapping edit slip past the client check, caught by the
    // server backstop), keep the modal open so the edit is not silently lost and
    // the user can adjust — the global mutation net surfaces the error toast.
    // Otherwise a rejected edit would look saved (modal already gone).
    // Le libellé ne part QUE sur un créneau partageable (capacité effective ≥ 2) — sinon null,
    // pour ne pas provoquer le 422 du backend (P2-17).
    update.mutate(
      { id: slot.id, body: { venueId: slot.venueId, dayOfWeek: day, startTime: time, durationMinutes: duration, capacity: effCapacity, groupLabel: effCapacity >= 2 ? groupLabel : null } },
      { onSuccess: onClose },
    );
  };

  return (
    <Modal
      label={WIZARD_TEXTS.editSlotTitle}
      title={WIZARD_TEXTS.editSlotTitle}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" className="text-destructive" onClick={() => setConfirmDelete(true)}>
            <Trash2 className="size-4" />
            {WIZARD_TEXTS.deleteLabel}
          </Button>
          <Button onClick={save} disabled={update.isPending}>
            {WIZARD_TEXTS.save}
          </Button>
        </>
      }
    >
      <div className="mt-3 flex flex-wrap items-end gap-3">
        <label className="text-xs text-muted-foreground">
          {WIZARD_TEXTS.slotDayLabel}
          <Select aria-label={WIZARD_TEXTS.slotDayLabel} className="mt-0.5 h-9" wrapperClassName="w-28" value={day} onChange={(e) => (setDay(Number(e.target.value)), setError(null))}>
            {/* Les SEPT jours, lus de la géométrie partagée. Ce select portait sa PROPRE
                copie amputée du dimanche : un créneau du dimanche — désormais posable —
                s'y ouvrait sur un champ VIDE, et le jour n'était pas rattrapable (P4-37). */}
            {WEEK.map((d) => (
              <option key={d.n} value={d.n}>
                {d.label}
              </option>
            ))}
          </Select>
        </label>
        <label className="text-xs text-muted-foreground">
          {WIZARD_TEXTS.slotStartLabel}
          <Input aria-label={WIZARD_TEXTS.slotStartLabel} type="time" className="mt-0.5 h-9 w-28" value={time} onChange={(e) => (setTime(e.target.value), setError(null))} />
        </label>
        <label className="text-xs text-muted-foreground">
          {WIZARD_TEXTS.slotDurationLabel}
          {/* D8 — « Autre… » ouvre une saisie libre (pas de 15, min 15, fin ≤ minuit vu le début). */}
          <DurationSelect aria-label={WIZARD_TEXTS.slotDurationLabel} className="mt-0.5 h-9" wrapperClassName="w-28" value={duration} present={[slot.durationMinutes]} startTime={time} onChange={(d) => (setDuration(d), setError(null))} />
        </label>
        <SlotEndField startTime={time} duration={duration} />
        {canSplit ? (
          <div className="text-xs text-muted-foreground">
            {/* CapacitySelect's inner select carries the Capacité accessible name. */}
            <span>{WIZARD_TEXTS.capacityLabel}</span>
            <CapacitySelect value={capacity} onChange={setCapacity} canSplit={canSplit} className="mt-0.5 block h-9 w-52" />
          </div>
        ) : null}
      </div>

      {canSplit ? <SharedSlotHint capacity={capacity} /> : null}
      {canSplit ? <GroupLabelField capacity={capacity} value={groupLabel} onChange={setGroupLabel} /> : null}

      {null !== error ? (
        <p role="alert" className="mt-3 text-sm text-destructive">
          {error}
        </p>
      ) : null}

      <DeleteConfirm
        open={confirmDelete}
        entityName={WIZARD_TEXTS.slotEntityName(`${DAYS.find((d) => d.n === slot.dayOfWeek)?.label ?? ""} ${hhmm(slot.startTime)}`).trim()}
        affectsPeriodPlans
        // P4-108 : le serveur compte — l'écran ignorait les verrous HARD que la réservation
        // avait matérialisés, or ils partent avec le créneau.
        impact={slotImpact.data ?? undefined}
        impactLoading={slotImpact.isPending && confirmDelete}
        impactFailed={slotImpact.isError}
        onConfirm={() => {
          // Close only once the delete succeeds (same as save()): a rejected
          // write keeps the editor open instead of silently vanishing.
          del.mutate(slot.id, { onSuccess: onClose });
          setConfirmDelete(false);
        }}
        onCancel={() => setConfirmDelete(false)}
      />
    </Modal>
  );
}

export function VenuesStep() {
  const { mode, calendarEntryId } = useWizardStore();
  // Period mode never renders the seasonal editor (base-plan writes) — see TeamsStep.
  if ("period" === mode) {
    return calendarEntryId ? <PeriodVenues calendarEntryId={calendarEntryId} /> : <EmptyHint>{WIZARD_TEXTS.periodNotFound}</EmptyHint>;
  }
  return <VenuesEditor />;
}

function VenuesEditor() {
  // Décision fondateur 2026-09-28 — « Modifier les données du club » : quand le socle (plan de
  // saison) est VALIDÉ, la fiche du gymnase reste éditable mais la GRILLE des créneaux
  // d'entraînement passe en lecture seule (les créneaux sont une contrainte : on rouvre le planning
  // pour les changer). `VenuesEditor` n'est rendu qu'en mode SAISON (`VenuesStep` route la période
  // vers `PeriodVenues`), donc le socle validé suffit à décider. Faux en onboarding ou après
  // « Rouvrir » → l'édition redevient normale.
  const slotsReadOnly = useSocleValidated();
  const { data: venues = [], isSuccess: venuesLoaded } = useWizardVenues();
  // Accordéon « Ajouter un gymnase » (demande fondateur 2026-08-05 : l'encart prenait trop de
  // place). Tant que le gestionnaire n'y a pas touché, il est ouvert pour un club sans gymnase, et
  // lui seul. Un ajout RÉUSSI le referme, quel que soit le chemin ; un échec le laisse ouvert.
  const [addOpenChoice, setAddOpenChoice] = useState<boolean | null>(null);
  const addOpen = addOpenChoice ?? 0 === venues.length;
  const { data: slots = [] } = useVenueSlots();
  const matchWindowsQuery = useVenueMatchWindows();
  const { data: reservations = [] } = useReservations();
  // Contrôle de cohérence de position (verdict SERVEUR) : les gymnases FFBB dont le point
  // enregistré paraît incohérent avec l'adresse fédérale. Le front ne fait qu'AFFICHER (bandeau
  // sur la fiche + pastille « À vérifier » dans la liste) — aucune règle recalculée ici.
  const { data: geoAlerts = [] } = useVenueGeoCheck();
  const geoAlertById = new Map(geoAlerts.map((a) => [a.venueId, a] as const));
  const create = useCreateVenue();
  const update = useUpdateVenue();
  // P4-279 — le PUT géo passe par son hook dédié : il confirme « Position enregistrée » au niveau
  // hook (toast de succès), là où `useUpdateVenue` (renommage, couleur, divisible) reste muet.
  const locate = useLocateVenue();
  const delVenue = useDeleteVenue();
  const addSlot = useCreateSlot();

  // Chantier G — l'import du parc par IA (onboarding), même porte d'entrée que côté Équipes. Le
  // prompt embarque le périmètre DÉJÀ saisi (équipes/gymnases/coachs), d'où les deux lectures voisines.
  const [parcOpen, setParcOpen] = useState(false);
  const { data: parcTeams = [] } = useWizardTeams();
  const parcSeasonId = useWorkingSeason()?.id;

  const [name, setName] = useState("");
  const [addError, setAddError] = useState<string | null>(null);
  const nameRef = useRef<HTMLInputElement>(null);
  // --- Autocomplétion salles FFBB (P2-20) ---
  const { data: me } = useMe();
  const parcClubId = me?.club?.id;
  // CP de recherche : celui du club par défaut, modifiable (une salle peut être
  // dans la commune voisine — l'index FFBB ne lie les salles qu'aux communes).
  const [salleCp, setSalleCp] = useState<string | null>(null);
  const cp = salleCp ?? me?.club?.postalCode ?? "";
  const sallesQuery = useFfbbSalles(cp);
  const [nameFocused, setNameFocused] = useState(false);
  // La salle choisie dans la liste : son ancrage FFBB (numéro fédéral + GPS)
  // part avec la création. TOUT changement manuel du nom l'efface — un gymnase
  // renommé à la main n'est plus, de façon sûre, cette salle-là.
  const [pendingSalle, setPendingSalle] = useState<FfbbSalle | null>(null);
  const suggestions = nameFocused && null === pendingSalle ? filterSalles(sallesQuery.data?.salles ?? [], name) : [];
  // P2-21 lot D — « cochez vos gymnases parmi ceux d'à côté » (§6.9, validé 9/9
  // sur BCCL). radius null = AUTO (3→20 km jusqu'à ~5 salles, côté serveur).
  const [nearbyRadius, setNearbyRadius] = useState<number | null>(null);
  const nearbyQuery = useFfbbSallesProches(nearbyRadius);
  // « Déjà ajouté » se reconnaît au NUMÉRO fédéral, pas au nom : le gestionnaire
  // renomme ses salles (« ADN », « JDR ») — un rapprochement par libellé échouerait.
  const importedRefs = new Set(venues.map((v) => v.externalRef).filter((r): r is string => null != r));
  // Le champ « Nom du gymnase » FILTRE les salles à l'écran en direct (demande
  // fondateur : un seul geste — je tape, ça filtre ; inconnu → j'ajoute avec +).
  const norm = (v: string) => v.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
  const nearbyFilter = norm(name.trim());
  const filteredNearby = (nearbyQuery.data?.salles ?? []).filter(
    (salle) => "" === nearbyFilter || norm(`${salle.name} ${salle.address ?? ""} ${salle.city ?? ""}`).includes(nearbyFilter),
  );
  // Retour Rillieux #20 — recherche par NOM dans TOUTE la FFBB, en dernier recours quand le nom
  // saisi n'est NI dans la commune NI à proximité (le gymnase « Mont Blanc » de Rillieux, absent
  // des deux, avait dû être créé à la main SANS adresse → 0 trajet). Le hook est best-effort
  // (502 muet), actif ≥ 3 caractères, débouncé (`queries.ts`) ; on ne l'alimente QUE quand il a
  // un rôle (nom assez long, pas déjà résolu par une suggestion, et introuvable localement) —
  // sinon il reste désactivé et la saisie libre au-dessus demeure le chemin normal.
  const nameTrimmed = name.trim();
  const nameNorm = norm(nameTrimmed);
  const matchesLocally =
    "" !== nameNorm &&
    [...(sallesQuery.data?.salles ?? []), ...(nearbyQuery.data?.salles ?? [])].some((salle) => norm(salle.name).includes(nameNorm));
  const showNameSearch = nameTrimmed.length >= MIN_NAME_SEARCH_LENGTH && null === pendingSalle && !matchesLocally;
  const nameSearch = useFfbbSallesByName(showNameSearch ? nameTrimmed : "");
  const nameSearchRead = readState(nameSearch);
  const nameSearchResults = showNameSearch ? (nameSearch.data?.salles ?? []) : [];
  const [selectedId, setSelectedId] = useState("");
  const [duration, setDuration] = useState(90);
  const [venueName, setVenueName] = useState("");
  const [editingSlot, setEditingSlot] = useState<VenueTrainingSlot | null>(null);
  // Capture the venue at click time — the dropdown selection may change before
  // the user confirms, and we must delete the venue the dialog is about.
  const [pendingDeleteVenue, setPendingDeleteVenue] = useState<Venue | null>(null);
  // v2 cohérence canSplit — décocher « terrain divisible » sur un gymnase dont des créneaux
  // accueillent 2 équipes ou plus est une cascade destructive (créneaux ramenés à 1, réservations
  // vidées). On CONFIRME avant. Le venue est capturé au clic (la sélection peut changer avant l'OK).
  const [pendingSplitOff, setPendingSplitOff] = useState<{ venue: Venue; preview: SplitCascadePreview } | null>(null);
  // Associer / Dissocier une salle FFBB, sur la carte du gymnase : confirmés, puis défaits par le toast.
  const [associatingVenue, setAssociatingVenue] = useState<Venue | null>(null);
  const [pendingAssociate, setPendingAssociate] = useState<{ salle: FfbbSalle; venue: Venue } | null>(null);
  const [pendingDissociate, setPendingDissociate] = useState<Venue | null>(null);
  // Colours assigned to gyms created faster than the venues query refetches, so
  // rapid successive adds don't all pick VENUE_PALETTE[0] off the stale list.
  // Pruned once a colour actually lands in the venue list.
  const pendingColorsRef = useRef<string[]>([]);

  // Résolution du gymnase affiché dans la carte d'édition. Priorité : l'id sélectionné trouvé
  // dans la liste (éventuellement périmée) → le gymnase TOUT JUSTE CRÉÉ porté par la mutation
  // tant que la liste n'a pas re-fetché (son id correspond à la sélection qu'on vient de poser) →
  // le premier gymnase. Le palier du milieu est ce qui empêche la carte d'afficher le gymnase
  // PRÉCÉDENT juste après un ajout : `selectedId` pointe déjà sur le gymnase neuf, mais `venues`
  // ne l'a pas encore re-fetché, donc `venues.find` rend `undefined` et l'ancien `?? venues[0]`
  // retombait sur le premier gymnase de la liste (P4-339).
  const selected =
    (selectedId ? venues.find((v) => v.id === selectedId) : null) ??
    (selectedId && create.data?.id === selectedId ? create.data : null) ??
    venues[0] ??
    null;
  const selectedGeoAlert = null === selected ? undefined : geoAlertById.get(selected.id);

  // P2-53 RMM-8 — « Trajets entre gymnases » : un bouton du pied de page (à côté de « Suivant »),
  // patron du « Trier » des Équipes. Il n'a de sens qu'avec au moins deux gymnases (il faut une
  // paire). Ouvre la matrice de trajet.
  const { setFooterExtra } = useWizardFooter();
  const [matrixOpen, setMatrixOpen] = useState(false);
  useEffect(() => {
    setFooterExtra(
      venues.length >= 2 ? (
        <Button size="sm" variant="outline" onClick={() => setMatrixOpen(true)}>
          <Route className="size-4" />
          {WIZARD_TEXTS.venueTravelButton}
        </Button>
      ) : null,
    );
    return () => setFooterExtra(null);
  }, [venues.length, setFooterExtra]);

  // P4-279 — tant que l'écran « Gymnases » est ouvert, il écoute le flux des trajets (décision
  // fondateur : toast LOCAL, aucune écoute globale dans AppLayout). Le recalcul tourne dans le
  // worker après une écriture géo : à la TRANSITION vers un terminal `VENUE_MATRIX`, on confirme
  // « Trajets recalculés. ». On COMPARE au dernier événement vu (ref initialisée au montage) pour
  // ne PAS toaster sur un terminal DÉJÀ passé (rouvrir l'écran ne rejoue pas un vieux recalcul), et
  // on se tait si `TravelMatrixModal` est ouverte — elle montre déjà sa propre jauge/verdict.
  // Double abonnement Mercure sans souci : `useTravelStream` est un singleton ref-compté
  // (`shared/lib/travelStream.ts`) — modale + écran partagent une seule connexion.
  const travelStream = useTravelStream(true);
  const lastTravelRef = useRef(travelStream.latest);
  useEffect(() => {
    const previous = lastTravelRef.current;
    const current = travelStream.latest;
    lastTravelRef.current = current;
    if (current === previous || matrixOpen) {
      return;
    }
    if (null !== current && "VENUE_MATRIX" === current.scope && current.terminal) {
      toast.success(WIZARD_TEXTS.travelRecomputed);
    }
  }, [travelStream.latest, matrixOpen]);

  const venueSlots = null === selected ? [] : slots.filter((s) => s.venueId === selected.id);
  // Les fenêtres match du gymnase affiché : la grille les rend en fantôme, la légende
  // n'apparaît que s'il y en a. Une seule dérivation pour les deux — sinon la légende
  // et la grille peuvent finir par ne pas parler du même gymnase.
  const venueMatchWindows = null === selected ? [] : (matchWindowsQuery.data ?? []).filter((w) => w.venueId === selected.id);

  // Drop pending colours that the refetched venue list now carries.
  useEffect(() => {
    const persisted = new Set(venues.map((v) => v.color?.toLowerCase()).filter((c): c is string => undefined !== c && null !== c));
    pendingColorsRef.current = pendingColorsRef.current.filter((c) => !persisted.has(c.toLowerCase()));
  }, [venues]);

  // P2-25 — deep-link `?step=venues&slot=X` : on POSITIONNE sur CE créneau (on sélectionne son
  // gymnase ET on ouvre son éditeur), pas seulement sur l'écran — arriver sur l'étape sans être
  // sur l'objet ne corrige pas la douleur du fondateur. Un slot introuvable (encore en
  // chargement, ou supprimé) → no-op : atterrissage propre sur l'étape, jamais un écran cassé.
  const [searchParams] = useSearchParams();
  const clearDeepLinkOrigin = useWizardStore((s) => s.clearDeepLinkOrigin);
  const slotTarget = searchParams.get("slot");
  const consumedSlotRef = useRef<string | null>(null);
  useEffect(() => {
    if (null === slotTarget || consumedSlotRef.current === slotTarget) {
      return;
    }
    const slot = slots.find((s) => s.id === slotTarget);
    if (undefined === slot) {
      return; // chargement ou id inconnu → on retentera au prochain render (dep sur slots)
    }
    consumedSlotRef.current = slotTarget;
    // eslint-disable-next-line react-hooks/set-state-in-effect -- one-shot : positionne sur le créneau ciblé (sélection du gymnase + ouverture de son éditeur)
    setSelectedId(slot.venueId);
    // En lecture seule on positionne sur le gymnase du créneau mais on N'OUVRE PAS l'éditeur — un
    // deep-link ne doit pas rouvrir un chemin d'édition que le socle validé ferme.
    if (!slotsReadOnly) {
      setEditingSlot(slot);
    }
  }, [slotTarget, slots, slotsReadOnly]);
  // P3-16 — l'impact vient du serveur, et seulement quand une suppression attend confirmation.
  const venueImpact = useDeletionImpact("venue", pendingDeleteVenue?.id ?? null);

  const onVenueCreated = (created: Venue) => {
    setSelectedId(created.id);
    toast.success(WIZARD_TEXTS.venueAdded(created.name));
    setAddOpenChoice(false);
  };

  // Le champ ne se vide qu'une fois l'ajout réussi : après un échec, le nom reste à corriger.
  const onVenueCreatedFromField = (created: Venue) => {
    setName("");
    setPendingSalle(null);
    onVenueCreated(created);
  };

  // L'« Annuler » du toast rend le numéro fédéral et la position d'avant le geste : le serveur efface
  // ce qui n'est pas fourni quand `externalRef` est vide, et une valeur fournie l'emporte.
  const restoreAnchor = (venue: Venue) =>
    update.mutate({ id: venue.id, body: { name: venue.name, externalRef: venue.externalRef ?? "", address: venue.address, latitude: venue.latitude, longitude: venue.longitude } });

  const confirmAssociate = ({ salle, venue }: { salle: FfbbSalle; venue: Venue }) =>
    update.mutate(
      { id: venue.id, body: { name: venue.name, color: venue.color, canSplit: venue.canSplit, isActive: venue.isActive, externalRef: salle.externalRef, address: venue.address || salle.address, latitude: salle.latitude, longitude: salle.longitude } },
      { onSuccess: () => toast.success(WIZARD_TEXTS.salleLinkedToVenue(salle.name, venue.name), { replaces: `venue-anchor-${venue.id}`, action: { label: WIZARD_TEXTS.undoAction, onClick: () => restoreAnchor(venue) } }) },
    );

  const confirmDissociate = (venue: Venue) =>
    update.mutate(
      { id: venue.id, body: { name: venue.name, externalRef: "" } },
      { onSuccess: () => toast.success(WIZARD_TEXTS.venueDissociated(venue.name), { replaces: `venue-anchor-${venue.id}`, action: { label: WIZARD_TEXTS.undoAction, onClick: () => restoreAnchor(venue) } }) },
    );

  const addVenue = (event: FormEvent) => {
    event.preventDefault();
    if ("" === name.trim()) {
      // Silent no-op was frustrating: surface why + jump to the empty field.
      setAddError(WIZARD_TEXTS.venueNameRequired);
      nameRef.current?.focus();
      return;
    }
    // Anti-doublon (retour fondateur 2026-08-05 : « je peux quand même ajouter à
    // nouveau, c'est pas logique ») — une salle FFBB déjà LIÉE à un gymnase ne se
    // ré-ajoute pas, et un nom déjà pris non plus (l'existant se renomme/associe).
    if (null !== pendingSalle && null !== pendingSalle.externalRef && importedRefs.has(pendingSalle.externalRef)) {
      setAddError(WIZARD_TEXTS.salleAlreadyLinked);
      return;
    }
    if (venues.some((v) => norm(v.name) === norm(name.trim()))) {
      setAddError(WIZARD_TEXTS.venueNameTaken);
      return;
    }
    setAddError(null);
    // Select the freshly created venue so the slot grid targets it — otherwise
    // the selection stays on the previous gym and slots land on the wrong one.
    // A new gym gets a distinct rainbow colour by default (not flat grey); count
    // in-flight assignments so rapid adds don't collide on the same palette hue.
    const color = nextVenueColor([...venues.map((v) => v.color), ...pendingColorsRef.current]);
    pendingColorsRef.current.push(color);
    // Ancrage FFBB si le nom vient d'une suggestion (P2-20) — sinon création
    // libre, strictement comme avant.
    const anchor = null !== pendingSalle ? { externalRef: pendingSalle.externalRef, latitude: pendingSalle.latitude, longitude: pendingSalle.longitude } : {};
    create.mutate(
      { name: name.trim(), color, canSplit: false, ...anchor },
      { onSuccess: onVenueCreatedFromField },
    );
    // Le curseur reste dans le champ tant que l'accordéon est ouvert (un ajout réussi le referme).
    nameRef.current?.focus();
  };

  // Retour Rillieux #20 — un clic sur un résultat de recherche par nom crée le gymnase en UN geste
  // AVEC son ancrage fédéral ET son adresse/position (patron de « à proximité », mais l'adresse part
  // aussi — sans quoi 0 trajet). L'anti-doublon est porté par le bouton lui-même (désactivé + « déjà
  // lié ✓ ») : une salle déjà liée n'arrive pas ici.
  const addSalleFromName = (salle: FfbbSalle) => {
    const color = nextVenueColor([...venues.map((v) => v.color), ...pendingColorsRef.current]);
    pendingColorsRef.current.push(color);
    create.mutate(
      { name: salle.name, color, canSplit: false, externalRef: salle.externalRef, address: salle.address, latitude: salle.latitude, longitude: salle.longitude },
      // Vider le champ referme le bloc de recherche (nom < 3) — le gymnase est créé.
      { onSuccess: onVenueCreatedFromField },
    );
  };

  // P1-4 PR B — un gymnase à fenêtre MATCH est légitime sans créneau
  // d'entraînement (loué pour les matchs seulement) : la règle l'épargne, ici
  // comme au gate (useStepValidation — les deux sites bougent ensemble, §7.2).
  const matchVenueIds = new Set((matchWindowsQuery.data ?? []).map((w) => w.venueId));
  // D-24 : le prédicat vient de la porte — les trois sites partagent la RÈGLE, pas le libellé.
  const emptyVenues = venuesWithoutSlot(venues, slots, matchVenueIds);

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">{WIZARD_TEXTS.venuesIntro}</p>

      {/* Chantier G — import du parc par IA (onboarding), même porte d'entrée que l'étape Équipes. */}
      <div className="mb-4">
        <Button variant="outline" size="sm" onClick={() => setParcOpen(true)}>
          <Sparkles className="size-4" />
          {WIZARD_TEXTS.importParcWithAi}
        </Button>
      </div>

      {venuesLoaded ? (
      <AccordionSection
        title={WIZARD_TEXTS.addVenuesSectionTitle}
        open={addOpen}
        onToggle={setAddOpenChoice}
        className="mb-3"
      >
      <form onSubmit={addVenue} className="mb-2 rounded-lg border border-border bg-card p-3">
        <div className="flex items-end gap-2">
          <Input
            ref={nameRef}
            aria-label={WIZARD_TEXTS.venueNameLabel}
            aria-invalid={null !== addError}
            aria-describedby={null !== addError ? "venue-add-error" : undefined}
            placeholder={WIZARD_TEXTS.venueNameLabel}
            className={`flex-1 ${null !== addError ? "border-destructive focus-visible:ring-destructive" : ""}`}
            value={name}
            onFocus={() => setNameFocused(true)}
            onBlur={() => setNameFocused(false)}
            onChange={(e) => {
              setName(e.target.value);
              // Nom retouché à la main → l'ancrage FFBB de la suggestion tombe
              // (un gymnase renommé n'est plus, de façon sûre, cette salle-là).
              setPendingSalle(null);
              if (null !== addError) {
                setAddError(null);
              }
            }}
          />
          <Input
            aria-label={WIZARD_TEXTS.communeCodeLabel}
            title={WIZARD_TEXTS.communeCodeHelp}
            placeholder={WIZARD_TEXTS.cpPlaceholder}
            inputMode="numeric"
            maxLength={5}
            className="w-20"
            value={cp}
            onChange={(e) => setSalleCp(e.target.value.replace(/\D/g, ""))}
          />
          <Button type="submit" size="sm" disabled={create.isPending} className="shrink-0">
            <Plus className="size-3.5" />
            {WIZARD_TEXTS.addVenueButtonShort}
            {/* Séparateur explicite : sans lui, le calcul du nom accessible colle « Ajouter » au
                complément (« Ajouterun gymnase ») — l'espace de tête du span sr-only est rogné. */}
            {" "}
            <span className="sr-only">{WIZARD_TEXTS.addVenueButtonSrSuffix}</span>
          </Button>
        </div>
        {/* Suggestions FFBB (P2-20) — la liste PROPOSE, n'impose jamais : la
            saisie libre au-dessus marche comme avant. onMouseDown (pas onClick) :
            le blur du champ retirerait la liste avant que le clic n'aboutisse. */}
        {suggestions.length > 0 ? (
          <ul aria-label={WIZARD_TEXTS.sallesAtLabel(cp)} className="mt-2 max-h-48 overflow-y-auto rounded-md border border-border bg-background py-1 text-sm">
            {suggestions.map((salle) => {
              // Déjà lié à un gymnase du club : la ligne reste LISIBLE (nommer)
              // mais fermée au geste fautif — re-choisir referait un doublon.
              const linked = null !== salle.externalRef && importedRefs.has(salle.externalRef);
              return (
                <li key={`${salle.externalRef ?? salle.name}-${salle.address ?? ""}`}>
                  <button
                    type="button"
                    disabled={linked}
                    className="flex w-full items-baseline gap-2 px-2.5 py-1 text-left hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent"
                    onMouseDown={(e) => {
                      e.preventDefault();
                      if (linked) return;
                      setName(salle.name);
                      setPendingSalle(salle);
                    }}
                  >
                    <span className="font-medium">{salle.name}</span>
                    {salle.address ? <span className="truncate text-xs text-muted-foreground">{salle.address}</span> : null}
                    {linked ? <span className="ml-auto shrink-0 text-xs text-muted-foreground">{WIZARD_TEXTS.alreadyLinkedTag}</span> : null}
                  </button>
                </li>
              );
            })}
          </ul>
        ) : null}
      </form>

      {/* AUD-A11Y-13 — `aria-invalid` disait « ce champ est fautif » sans jamais dire
          POURQUOI : le message existait à côté, non relié. Un lecteur d'écran l'annonce
          une fois par `role="alert"` (interruption), puis le champ redevient muet — on
          revient dessus, on sait que c'est faux, on ne sait plus ce qu'on doit corriger.
          `aria-describedby` le rattache : le motif se relit avec le champ, à volonté. */}
      {null !== addError ? (
        <p id="venue-add-error" role="alert" className="mb-3 text-sm text-destructive">
          {addError}
        </p>
      ) : null}

      {/* Retour Rillieux #20 — le nom saisi n'est ni dans la commune ni à proximité : on le cherche
          dans TOUTE la FFBB par son nom. Trouvé → un clic le crée AVEC son ancrage fédéral ET son
          adresse/position (sans quoi 0 trajet) ; absent → la saisie libre ci-dessus reste le chemin.
          Best-effort : chargement (Spinner) / vide (EmptyHint) / 502 (LoadErrorHint, discret). */}
      {showNameSearch ? (
        <div className="mb-3 rounded-lg border border-border bg-card p-3">
          <div className="mb-1 text-xs font-medium text-muted-foreground">{WIZARD_TEXTS.notFoundFfbbTitle}</div>
          <p className="mb-2 text-xs text-muted-foreground">{WIZARD_TEXTS.nameSearchHint}</p>
          {"loading" === nameSearchRead ? (
            <Spinner />
          ) : "failed" === nameSearchRead ? (
            <LoadErrorHint>{WIZARD_TEXTS.nameSearchError}</LoadErrorHint>
          ) : 0 === nameSearchResults.length ? (
            <EmptyHint>{WIZARD_TEXTS.nameSearchEmpty}</EmptyHint>
          ) : (
            <ul aria-label={WIZARD_TEXTS.nameSearchResultsLabel} className="flex max-h-44 flex-col gap-1 overflow-y-auto">
              {nameSearchResults.map((salle) => (
                <SalleResultRow
                  key={`${salle.externalRef ?? salle.name}-${salle.address ?? ""}`}
                  salle={salle}
                  venues={venues}
                  action={{ label: WIZARD_TEXTS.addTag, accessibleName: WIZARD_TEXTS.salleActionName(WIZARD_TEXTS.addTag, salle.name), disabled: create.isPending, onPick: addSalleFromName }}
                />
              ))}
            </ul>
          )}
        </div>
      ) : null}

      {/* P2-21 lot D — les gymnases d'à côté, un clic pour les ajouter. La liste
          PROPOSE, n'impose jamais ; le nom officiel se renomme ensuite à sa main. */}
      {(nearbyQuery.data?.salles.length ?? 0) > 0 ? (
        <div className="rounded-lg border border-border bg-card p-3">
          <div className="mb-2 flex flex-wrap items-center gap-2">
            <span className="text-xs font-medium text-muted-foreground">{WIZARD_TEXTS.nearbyVenuesTitle(nearbyQuery.data?.radiusKm ?? null)}</span>
            <Select aria-label={WIZARD_TEXTS.searchRadiusLabel} className="ml-auto text-xs" wrapperClassName="w-28" value={nearbyRadius ?? "auto"} onChange={(e) => setNearbyRadius("auto" === e.target.value ? null : Number(e.target.value))}>
              <option value="auto">{WIZARD_TEXTS.radiusAuto}</option>
              {[3, 5, 10, 20].map((km) => (
                <option key={km} value={km}>
                  {WIZARD_TEXTS.kmOption(km)}
                </option>
              ))}
            </Select>
          </div>
          <ul className="flex max-h-44 flex-col gap-1 overflow-y-auto">
            {0 === filteredNearby.length ? (
              <li className="text-xs text-muted-foreground">{WIZARD_TEXTS.noSalleMatches(name.trim())}</li>
            ) : null}
            {filteredNearby.map((salle) => (
              <SalleResultRow
                key={`${salle.externalRef ?? salle.name}-${salle.address ?? ""}`}
                salle={salle}
                venues={venues}
                action={{
                  label: WIZARD_TEXTS.addTag,
                  disabled: create.isPending,
                  onPick: (picked) => {
                    const color = nextVenueColor([...venues.map((v) => v.color), ...pendingColorsRef.current]);
                    pendingColorsRef.current.push(color);
                    create.mutate(
                      { name: picked.name, color, canSplit: false, externalRef: picked.externalRef, address: picked.address, latitude: picked.latitude, longitude: picked.longitude },
                      { onSuccess: onVenueCreated },
                    );
                  },
                }}
              />
            ))}
          </ul>
        </div>
      ) : null}
      </AccordionSection>
      ) : null}

      {emptyVenues.length > 0 ? (
        <p role="alert" className="mb-3 text-sm text-destructive">
          {emptyVenues.length > 1 ? WIZARD_TEXTS.emptyVenuesPlural : WIZARD_TEXTS.emptyVenuesSingular}{" "}
          : {emptyVenues.map((v) => v.name).join(", ")}.
        </p>
      ) : null}

      {null === selected ? (
        <EmptyHint>{WIZARD_TEXTS.noVenuesYet}</EmptyHint>
      ) : (
        <>
          {/* Selection row — pick WHICH gym to work on (kept visually separate
              from the edit card below so the two intents don't blur together). */}
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <label className="text-sm font-medium" htmlFor="venue-picker">
              {WIZARD_TEXTS.venuePickerLabel}
            </label>
            {/* Pastille couleur dans chaque option ET le trigger — VenueSelect partagé (Listbox). */}
            <VenueSelect
              id="venue-picker"
              aria-label={WIZARD_TEXTS.venueSelectAria}
              className="h-9"
              wrapperClassName="w-60"
              venues={venues.map((v) => ({
                id: v.id,
                name: v.name,
                color: v.color ?? DEFAULT_VENUE_COLOR,
                // Pastille « À vérifier » sur les gymnases dont la position paraît incohérente
                // (verdict serveur) — texte + icône, jamais la couleur seule.
                badge: geoAlertById.has(v.id) ? (
                  <StatusPill variant="warning" icon={<AlertTriangle className="size-3 text-warning" />}>
                    {WIZARD_TEXTS.toCheckTag}
                  </StatusPill>
                ) : undefined,
              }))}
              value={selected.id}
              onValueChange={(next) => {
                setSelectedId(next);
                setEditingSlot(null);
                // Drop the rename buffer so switching gyms can't write the name
                // typed for the previous one onto the newly selected gym.
                setVenueName("");
              }}
            />
          </div>

          {/* Edit card — properties of the SELECTED gym (distinct block). */}
          <div className="mb-3 rounded-lg border border-border bg-card p-3">
            <div className="mb-2 text-xs font-medium text-muted-foreground">{WIZARD_TEXTS.venueEditHeading(selected.name)}</div>
            <div className="flex flex-wrap items-center gap-3">
              <ColorField key={`color-${selected.id}`} venue={selected} onApply={(color) => update.mutate({ id: selected.id, body: { name: selected.name, color, canSplit: selected.canSplit } })} />
              <Input
                aria-label={WIZARD_TEXTS.renameVenueLabel}
                className="h-9 w-56"
                defaultValue={selected.name}
                key={`name-${selected.id}`}
                onChange={(e) => setVenueName(e.target.value)}
                onBlur={() => venueName.trim() && venueName !== selected.name && update.mutate({ id: selected.id, body: { name: venueName.trim(), color: selected.color, canSplit: selected.canSplit } })}
              />
              <label className="flex items-center gap-1 text-xs text-muted-foreground" title={WIZARD_TEXTS.splitVenueHelp}>
                <input
                  type="checkbox"
                  checked={selected.canSplit}
                  onChange={(e) => {
                    const next = e.target.checked;
                    // Décocher : si des créneaux du gymnase accueillent encore 2 équipes ou plus,
                    // on CONFIRME (cascade destructive) au lieu de muter directement. Cocher
                    // (false→true) n'a jamais de conséquence destructive → aucune modale.
                    if (!next) {
                      const preview = splitCascadePreview(venueSlots, reservations, selected.id);
                      if (preview.slots.length > 0) {
                        setPendingSplitOff({ venue: selected, preview });
                        return;
                      }
                    }
                    update.mutate({ id: selected.id, body: { name: selected.name, color: selected.color, canSplit: next } });
                  }}
                />
                {WIZARD_TEXTS.splitVenueLabel}
              </label>
              <Button size="icon" variant="ghost" className="ml-auto size-8 text-destructive" onClick={() => setPendingDeleteVenue(selected)} title={WIZARD_TEXTS.deleteVenueLabel} aria-label={WIZARD_TEXTS.deleteVenueLabel}>
                <Trash2 className="size-4" />
              </Button>
            </div>
            {/* P2-53 RMM-8 — adresse + géolocalisation. La géo alimente la matrice de trajet
                (bouton « Trajets entre gymnases » du pied de page). PUT partiel : n'écrit que
                address/lat/long, le reste du gymnase est préservé. */}
            <div className="mt-3 border-t border-border pt-3">
              {/* Contrôle de cohérence de position (verdict serveur) : quand le point enregistré
                  paraît incohérent avec l'adresse FFBB, on le dit ICI, au-dessus du champ géo — le
                  gestionnaire vérifie sur la carte ou colle les coordonnées exactes (déjà offerts
                  par le champ ci-dessous). L'alerte disparaît dès qu'il corrige. */}
              {undefined === selectedGeoAlert ? null : (
                <NoticeBanner
                  tone="warning"
                  role="status"
                  className="mb-3"
                  icon={<AlertTriangle className="size-4 text-warning" />}
                  message={venueGeoAlertMessage(selectedGeoAlert)}
                />
              )}
              {/* La salle FFBB se gère ici, sur le gymnase lui-même. Associer pose numéro fédéral + GPS + adresse
                  (si le gymnase n'en a pas) sans jamais renommer (le nom d'usage reste roi) ; Dissocier ne laisse que le nom. */}
              {null != selected.externalRef ? (
                <div className="mb-3">
                  <Button variant="outline" size="sm" onClick={() => setPendingDissociate(selected)}>
                    {WIZARD_TEXTS.dissociateVenueButton(selected.name)}
                  </Button>
                </div>
              ) : (
                <div className="mb-3">
                  <Button variant="outline" size="sm" onClick={() => setAssociatingVenue(selected)}>
                    {WIZARD_TEXTS.associateVenueMenu(selected.name)}
                  </Button>
                </div>
              )}
              {/* La clé porte aussi la salle : le champ garde son état local (repli, saisie) et doit
                  repartir de zéro après Associer, Dissocier ou leur « Annuler ». */}
              <VenueGeocodeField
                key={`geo-${selected.id}-${selected.externalRef ?? ""}`}
                venue={selected}
                onLocate={({ address, latitude, longitude }) => locate.mutate({ id: selected.id, body: { name: selected.name, address, latitude, longitude } })}
              />
            </div>
          </div>

          {/* Lecture seule (socle validé) : la barre de pose n'a pas lieu d'être — on la remplace
              par la MENTION qui dit pourquoi la grille ne répond plus au clic. */}
          {slotsReadOnly ? (
            <NoticeBanner
              tone="muted"
              role="status"
              className="mb-3"
              icon={<Lock className="size-4 text-muted-foreground" />}
              message={WIZARD_TEXTS.slotsReadOnlyNotice}
            />
          ) : (
          /* Slot-placement toolbar — only the duration of the next dropped slot.
              Capacity is set per-slot in the edit panel (a new slot is always 1). */
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <span className="text-xs text-muted-foreground">{WIZARD_TEXTS.toPlaceLabel}</span>
            {/* D8 — même sélecteur partagé ; pas d'heure connue ici (le début vient du clic
                sur la grille) : la borne de minuit est vérifiée à la pose (`slotPlacementError`). */}
            <DurationSelect aria-label={WIZARD_TEXTS.durationToPlaceLabel} className="h-9" wrapperClassName="w-24" value={duration} onChange={setDuration} />
            {/* L'indice manquait : la barre annonçait une durée sans dire ce qu'on en fait
                (retour terrain). Il vit ICI, là où le regard est déjà au moment où la
                question se pose. */}
            {/* « un créneau » tout court devenait ambigu dès qu'une plage match s'affiche
                dans la même grille : on dit ce que le clic pose. */}
            <span className="text-xs text-muted-foreground">{WIZARD_TEXTS.clickGridHint}</span>
            {/* La hachure est un motif MUET sans ce mot : elle apparaît dans la grille sans
                que rien n'en dise le sens. La légende ne s'affiche que s'il y a quelque
                chose à légender. */}
            {0 < venueMatchWindows.length ? (
              <span className="ml-auto flex items-center gap-1.5 text-xs text-muted-foreground">
                <span
                  aria-hidden="true"
                  className="inline-block size-3 rounded-sm border border-dashed border-accent/60"
                  style={{ backgroundImage: "repeating-linear-gradient(45deg, color-mix(in oklch, var(--accent) 18%, transparent) 0 4px, transparent 4px 9px)" }}
                />
                {WIZARD_TEXTS.matchAccessHint}
              </span>
            ) : null}
          </div>
          )}

          <VenueAvailabilityGrid
            venue={selected}
            slots={venueSlots}
            matchWindows={venueMatchWindows}
            selectedSlotId={editingSlot?.id ?? null}
            readOnly={slotsReadOnly}
            onAdd={(dayOfWeek, startTime) => {
              const invalid = slotPlacementError(venueSlots, dayOfWeek, startTime, duration);
              if (null !== invalid) {
                toast.error(invalid);
                return;
              }
              addSlot.mutate({ venueId: selected.id, dayOfWeek, startTime, durationMinutes: duration, capacity: 1 });
            }}
            onSelect={(slot) => setEditingSlot(slot)}
          />

          {null !== editingSlot && !slotsReadOnly ? (
            <SlotEditor
              key={editingSlot.id}
              slot={editingSlot}
              canSplit={selected.canSplit}
              otherSlots={venueSlots.filter((s) => s.id !== editingSlot.id)}
              onClose={() => {
                // Fermer l'éditeur du créneau ciblé = « on a agi » → le retour nommé s'efface.
                const closingId = editingSlot.id;
                setEditingSlot(null);
                if (closingId === consumedSlotRef.current) {
                  clearDeepLinkOrigin();
                }
              }}
            />
          ) : null}

          {/* P1-4 PR B — accès MATCH du gymnase : le document qui donne les
              créneaux d'entraînement donne aussi les accès des jours de match —
              un document, un écran. Même éditeur que la page matchs.
              ⚠ Le libellé ne nomme PAS le propriétaire des lieux (« la mairie ») :
              c'est le cas du BCCL, pas de tous les clubs — certains dépendent
              d'un conseil départemental, d'un lycée ou d'une salle privée. */}
          <div className="mt-3 rounded-lg border border-border bg-card p-3">
            <div className="mb-2 text-xs font-medium text-muted-foreground">{WIZARD_TEXTS.matchAccessHeading(selected.name)}</div>
            <MatchWindowsEditor venueId={selected.id} />
          </div>
        </>
      )}

      <DeleteConfirm
        open={pendingDeleteVenue !== null}
        entityName={pendingDeleteVenue?.name ?? ""}
        affectsPeriodPlans
        // P3-16 : plus aucun compte deviné depuis le cache — le serveur dit ce qu'il détruit.
        impact={venueImpact.data ?? undefined}
        impactLoading={venueImpact.isPending && null !== pendingDeleteVenue}
        impactFailed={venueImpact.isError}
        onCancel={() => setPendingDeleteVenue(null)}
        onConfirm={() => {
          if (pendingDeleteVenue) {
            delVenue.mutate(pendingDeleteVenue.id);
          }
          setPendingDeleteVenue(null);
        }}
      />

      {/* v2 cohérence canSplit — confirmation avant de rendre un gymnase indivisible alors que des
          créneaux y accueillent 2 équipes ou plus. Confirmer → mutation avec confirmSplitCascade.
          Le 422 backend reste le filet si cette modale est contournée. */}
      <ConfirmDialog
        open={pendingSplitOff !== null}
        title={pendingSplitOff ? WIZARD_TEXTS.makeIndivisibleTitle(pendingSplitOff.venue.name) : ""}
        confirmLabel={WIZARD_TEXTS.makeIndivisibleConfirm}
        description={
          pendingSplitOff ? (
            <>
              {WIZARD_TEXTS.splitCascadeDescription}
              <ul className="mt-2 list-disc space-y-0.5 pl-5">
                {pendingSplitOff.preview.slots.map((s) => (
                  <li key={s.id}>
                    {DAYS.find((d) => d.n === s.dayOfWeek)?.label ?? ""} {hhmm(s.startTime)}
                  </li>
                ))}
              </ul>
              {pendingSplitOff.preview.reservationCount > 0 ? (
                <p className="mt-3 font-medium text-foreground">{WIZARD_TEXTS.reservationsEmptied(pendingSplitOff.preview.reservationCount)}</p>
              ) : null}
            </>
          ) : undefined
        }
        onCancel={() => setPendingSplitOff(null)}
        onConfirm={() => {
          if (pendingSplitOff) {
            update.mutate({
              id: pendingSplitOff.venue.id,
              body: { name: pendingSplitOff.venue.name, color: pendingSplitOff.venue.color, canSplit: false, confirmSplitCascade: true },
            });
          }
          setPendingSplitOff(null);
        }}
      />

      <AssociateSalleModal
        venue={associatingVenue}
        venues={venues}
        onClose={() => setAssociatingVenue(null)}
        onPick={(salle) => {
          if (null !== associatingVenue) {
            setPendingAssociate({ salle, venue: associatingVenue });
          }
          setAssociatingVenue(null);
        }}
      />

      <ConfirmDialog
        open={null !== pendingAssociate}
        destructive={false}
        title={null !== pendingAssociate ? WIZARD_TEXTS.associateVenueTitle(pendingAssociate.salle.name, pendingAssociate.venue.name) : ""}
        description={
          null !== pendingAssociate ? (
            <>
              {"" === WIZARD_TEXTS.associateVenueAddress(pendingAssociate.salle) ? null : <p className="mb-2 font-medium text-foreground">{WIZARD_TEXTS.associateVenueAddress(pendingAssociate.salle)}</p>}
              <p>{null === pendingAssociate.salle.latitude || null === pendingAssociate.salle.longitude ? WIZARD_TEXTS.associateVenueDescriptionNoGps : WIZARD_TEXTS.associateVenueDescription}</p>
            </>
          ) : undefined
        }
        confirmLabel={WIZARD_TEXTS.associateVenueConfirm}
        onCancel={() => setPendingAssociate(null)}
        onConfirm={() => {
          if (null !== pendingAssociate) {
            confirmAssociate(pendingAssociate);
          }
          setPendingAssociate(null);
        }}
      />

      <ConfirmDialog
        open={null !== pendingDissociate}
        title={null !== pendingDissociate ? WIZARD_TEXTS.dissociateVenueTitle(pendingDissociate.name) : ""}
        description={WIZARD_TEXTS.dissociateVenueDescription}
        confirmLabel={WIZARD_TEXTS.dissociateVenueConfirm}
        onCancel={() => setPendingDissociate(null)}
        onConfirm={() => {
          if (null !== pendingDissociate) {
            confirmDissociate(pendingDissociate);
          }
          setPendingDissociate(null);
        }}
      />

      {matrixOpen ? (
        <TravelMatrixModal
          onClose={() => setMatrixOpen(false)}
          onLocateVenue={(venueId) => {
            // « Renseigner l'adresse » d'un gymnase sans géo → on le sélectionne et on ferme la
            // matrice : la fiche du gymnase (avec le champ adresse) est juste en dessous.
            setSelectedId(venueId);
            setVenueName("");
            setMatrixOpen(false);
          }}
        />
      ) : null}

      {/* Chantier G — la modale d'import du parc par IA, montée club + saison connus (onboarding). */}
      {parcOpen && undefined !== parcClubId && undefined !== parcSeasonId ? (
        <ParcImportModal
          origin="venues"
          clubId={parcClubId}
          seasonId={parcSeasonId}
          teams={parcTeams.map((t) => ({ id: t.id, name: t.name }))}
          venues={venues.map((v) => ({ id: v.id, name: v.name }))}
          club={{ name: me?.club?.name, city: me?.club?.city ?? undefined }}
          onClose={() => setParcOpen(false)}
        />
      ) : null}
    </div>
  );
}
