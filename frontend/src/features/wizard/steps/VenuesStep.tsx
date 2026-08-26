import { Plus, Route, Trash2 } from "lucide-react";
import { type FormEvent, useEffect, useRef, useState } from "react";
import { useSearchParams } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { DeleteConfirm } from "@/shared/components/ui/delete-confirm";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { AccordionSection } from "@/shared/components/ui/accordion";
import { Menu, MenuItem } from "@/shared/components/ui/menu";
import { Select } from "@/shared/components/ui/select";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { nextVenueColor } from "@/shared/lib/color";
import { formatDuration } from "@/shared/lib/duration";
import { toast } from "@/shared/stores/toastStore";

import { MatchWindowsEditor } from "@/features/matches/MatchWindowsEditor";
import { useVenueMatchWindows } from "@/features/matches/queries";

import { useMe } from "@/shared/session/queries";

import type { FfbbSalle, Venue, VenueTrainingSlot } from "../api";
import { DAYS, durationOptions, DURATIONS, hhmm } from "../lib/days";
import { filterSalles } from "../lib/salleSuggestions";
import { slotPlacementError } from "../lib/slotOverlap";
import { useCreateSlot, useCreateVenue, useDeleteSlot, useDeletionImpact, useDeleteVenue, useFfbbSalles, useFfbbSallesProches, useReservations, useUpdateSlot, useUpdateVenue, useVenueSlots, useWizardVenues } from "../queries";
import { useWizardStore } from "../store";
import { useWizardFooter } from "../lib/footerSlot";
import { PeriodVenues } from "./PeriodStructure";
import { TravelMatrixModal } from "./TravelMatrixModal";
import { VenueAvailabilityGrid } from "./VenueAvailabilityGrid";
import { VenueGeocodeField } from "./VenueGeocodeField";
import { CapacitySelect, GroupLabelField, SharedSlotHint } from "./slotFields";
import { WEEK } from "../lib/weekGrid";
import { venuesWithoutSlot } from "../lib/useStepValidation";
import { splitCascadePreview, type SplitCascadePreview } from "../lib/reservationSlots";

const HEX_RE = /^#[0-9a-fA-F]{6}$/;

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
        aria-label="Couleur"
        type="color"
        className="size-9 shrink-0 rounded border border-input bg-background"
        value={HEX_RE.test(hex) ? hex : current}
        onChange={(e) => commit(e.target.value)}
      />
      <Input aria-label="Couleur (hexadécimal)" className="h-9 w-24 font-mono text-xs" value={hex} placeholder="#3498DB" onChange={(e) => commit(e.target.value)} />
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
      label="Modifier le créneau"
      title="Modifier le créneau"
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" className="text-destructive" onClick={() => setConfirmDelete(true)}>
            <Trash2 className="size-4" />
            Supprimer
          </Button>
          <Button onClick={save} disabled={update.isPending}>
            Enregistrer
          </Button>
        </>
      }
    >
      <div className="mt-3 flex flex-wrap items-end gap-3">
        <label className="text-xs text-muted-foreground">
          Jour
          <Select aria-label="Jour" className="mt-0.5 h-9 w-28" value={day} onChange={(e) => (setDay(Number(e.target.value)), setError(null))}>
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
          Début
          <Input aria-label="Début" type="time" className="mt-0.5 h-9 w-28" value={time} onChange={(e) => (setTime(e.target.value), setError(null))} />
        </label>
        <label className="text-xs text-muted-foreground">
          Durée
          <Select aria-label="Durée" className="mt-0.5 h-9 w-28" value={duration} onChange={(e) => (setDuration(Number(e.target.value)), setError(null))}>
            {durationOptions(duration, slot.durationMinutes).map((d) => (
              <option key={d} value={d}>
                {formatDuration(d)}
              </option>
            ))}
          </Select>
        </label>
        {canSplit ? (
          <div className="text-xs text-muted-foreground">
            {/* CapacitySelect's inner <select> carries aria-label="Capacité". */}
            <span>Capacité</span>
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
        entityName={`créneau ${DAYS.find((d) => d.n === slot.dayOfWeek)?.label ?? ""} ${hhmm(slot.startTime)}`.trim()}
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
    return calendarEntryId ? <PeriodVenues calendarEntryId={calendarEntryId} /> : <EmptyHint>Période introuvable — revenez au cockpit pour l'ouvrir.</EmptyHint>;
  }
  return <VenuesEditor />;
}

function VenuesEditor() {
  const { data: venues = [], isSuccess: venuesLoaded } = useWizardVenues();
  // Accordéon « Ajouter un gymnase » (demande fondateur 2026-08-05 : l'encart
  // prenait trop de place). Ouvert par défaut UNIQUEMENT pour un club sans
  // gymnase — capturé UNE fois au premier rendu chargé, sinon l'ajout du 1er
  // gymnase replierait la section sous la souris.
  // defaultOpen n'est lu qu'au MONTAGE de l'accordéon : en ne le rendant
  // qu'une fois les gymnases chargés, la valeur capturée est la bonne — et
  // l'ajout du 1er gymnase ne replie rien (pas de remontage).
  const { data: slots = [] } = useVenueSlots();
  const matchWindowsQuery = useVenueMatchWindows();
  const { data: reservations = [] } = useReservations();
  const create = useCreateVenue();
  const update = useUpdateVenue();
  const delVenue = useDeleteVenue();
  const addSlot = useCreateSlot();

  const [name, setName] = useState("");
  const [addError, setAddError] = useState<string | null>(null);
  const nameRef = useRef<HTMLInputElement>(null);
  // --- Autocomplétion salles FFBB (P2-20) ---
  const { data: me } = useMe();
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
  // Colours assigned to gyms created faster than the venues query refetches, so
  // rapid successive adds don't all pick VENUE_PALETTE[0] off the stale list.
  // Pruned once a colour actually lands in the venue list.
  const pendingColorsRef = useRef<string[]>([]);

  // Fall back to the first venue when the selected id isn't in the list yet
  // (just-created, list refetching) so the panel never flashes "no venue".
  const selected = (selectedId ? venues.find((v) => v.id === selectedId) : null) ?? venues[0] ?? null;

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
          Trajets entre gymnases
        </Button>
      ) : null,
    );
    return () => setFooterExtra(null);
  }, [venues.length, setFooterExtra]);
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
    setEditingSlot(slot);
  }, [slotTarget, slots]);
  // P3-16 — l'impact vient du serveur, et seulement quand une suppression attend confirmation.
  const venueImpact = useDeletionImpact("venue", pendingDeleteVenue?.id ?? null);

  const addVenue = (event: FormEvent) => {
    event.preventDefault();
    if ("" === name.trim()) {
      // Silent no-op was frustrating: surface why + jump to the empty field.
      setAddError("Donnez un nom au gymnase avant de l'ajouter.");
      nameRef.current?.focus();
      return;
    }
    // Anti-doublon (retour fondateur 2026-08-05 : « je peux quand même ajouter à
    // nouveau, c'est pas logique ») — une salle FFBB déjà LIÉE à un gymnase ne se
    // ré-ajoute pas, et un nom déjà pris non plus (l'existant se renomme/associe).
    if (null !== pendingSalle && null !== pendingSalle.externalRef && importedRefs.has(pendingSalle.externalRef)) {
      setAddError("Cette salle FFBB est déjà liée à un de vos gymnases.");
      return;
    }
    if (venues.some((v) => norm(v.name) === norm(name.trim()))) {
      setAddError("Un gymnase porte déjà ce nom — utilisez l'existant, ou choisissez un autre nom.");
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
      { onSuccess: (created) => (setSelectedId(created.id), toast.success(`Gymnase « ${created.name} » ajouté.`)) },
    );
    setName("");
    setPendingSalle(null);
    // Keep the cursor in the name field to add the next gym without re-clicking.
    nameRef.current?.focus();
  };

  // P1-4 PR B — un gymnase à fenêtre MATCH est légitime sans créneau
  // d'entraînement (loué pour les matchs seulement) : la règle l'épargne, ici
  // comme au gate (useStepValidation — les deux sites bougent ensemble, §7.2).
  const matchVenueIds = new Set((matchWindowsQuery.data ?? []).map((w) => w.venueId));
  // D-24 : le prédicat vient de la porte — les trois sites partagent la RÈGLE, pas le libellé.
  const emptyVenues = venuesWithoutSlot(venues, slots, matchVenueIds);

  return (
    <div>
      <p className="mb-4 text-sm text-muted-foreground">
        Ajoutez vos gymnases, puis cliquez dans la grille pour poser les créneaux de disponibilité (jour + heure). Un gymnase sans créneau ne peut pas être utilisé.
      </p>

      {venuesLoaded ? (
      <AccordionSection
        title="Ajouter des gymnases"
        defaultOpen={0 === venues.length}
        className="mb-3"
      >
      <form onSubmit={addVenue} className="mb-2 rounded-lg border border-border bg-card p-3">
        <div className="flex items-end gap-2">
          <Input
            ref={nameRef}
            aria-label="Nom du gymnase"
            aria-invalid={null !== addError}
            aria-describedby={null !== addError ? "venue-add-error" : undefined}
            placeholder="Nom du gymnase"
            className={`h-8 flex-1 ${null !== addError ? "border-destructive focus-visible:ring-destructive" : ""}`}
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
            aria-label="Commune (code postal)"
            title="Les salles proposées sont celles de cette commune — changez le code postal pour une salle voisine."
            placeholder="CP"
            inputMode="numeric"
            maxLength={5}
            className="h-8 w-20"
            value={cp}
            onChange={(e) => setSalleCp(e.target.value.replace(/\D/g, ""))}
          />
          <Button type="submit" size="icon" className="size-8" disabled={create.isPending} title="Ajouter un gymnase" aria-label="Ajouter un gymnase">
            <Plus className="size-4" />
          </Button>
        </div>
        {/* Suggestions FFBB (P2-20) — la liste PROPOSE, n'impose jamais : la
            saisie libre au-dessus marche comme avant. onMouseDown (pas onClick) :
            le blur du champ retirerait la liste avant que le clic n'aboutisse. */}
        {suggestions.length > 0 ? (
          <ul aria-label={`Salles FFBB à ${cp}`} className="mt-2 max-h-48 overflow-y-auto rounded-md border border-border bg-background py-1 text-sm">
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
                    {linked ? <span className="ml-auto shrink-0 text-xs text-muted-foreground">déjà lié ✓</span> : null}
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

      {/* P2-21 lot D — les gymnases d'à côté, un clic pour les ajouter. La liste
          PROPOSE, n'impose jamais ; le nom officiel se renomme ensuite à sa main. */}
      {(nearbyQuery.data?.salles.length ?? 0) > 0 ? (
        <div className="rounded-lg border border-border bg-card p-3">
          <div className="mb-2 flex flex-wrap items-center gap-2">
            <span className="text-xs font-medium text-muted-foreground">
              Gymnases à proximité (FFBB{null != nearbyQuery.data?.radiusKm ? `, ${nearbyQuery.data.radiusKm} km` : ""}) — cliquez pour ajouter, renommez ensuite à votre main :
            </span>
            <Select aria-label="Rayon de recherche" className="ml-auto h-7 w-28 text-xs" value={nearbyRadius ?? "auto"} onChange={(e) => setNearbyRadius("auto" === e.target.value ? null : Number(e.target.value))}>
              <option value="auto">Auto</option>
              {[3, 5, 10, 20].map((km) => (
                <option key={km} value={km}>
                  {km} km
                </option>
              ))}
            </Select>
          </div>
          <ul className="flex max-h-44 flex-col gap-1 overflow-y-auto">
            {0 === filteredNearby.length ? (
              <li className="text-xs text-muted-foreground">Aucune salle FFBB ne correspond à « {name.trim()} » — ajoutez-le comme gymnase avec le bouton +.</li>
            ) : null}
            {filteredNearby.map((salle) => {
              const added = null !== salle.externalRef && importedRefs.has(salle.externalRef);
              return (
                <li key={`${salle.externalRef ?? salle.name}-${salle.address ?? ""}`} className="flex items-center gap-2 text-sm">
                  <Button
                    size="sm"
                    variant={added ? "ghost" : "outline"}
                    className="h-7 shrink-0"
                    disabled={added || create.isPending}
                    onClick={() => {
                      const color = nextVenueColor([...venues.map((v) => v.color), ...pendingColorsRef.current]);
                      pendingColorsRef.current.push(color);
                      create.mutate(
                        { name: salle.name, color, canSplit: false, externalRef: salle.externalRef, latitude: salle.latitude, longitude: salle.longitude },
                        { onSuccess: (created) => (setSelectedId(created.id), toast.success(`Gymnase « ${created.name} » ajouté.`)) },
                      );
                    }}
                  >
                    {added ? "✓ Ajouté" : "+ Ajouter"}
                  </Button>
                  {/* « Associer à… » (demande fondateur 2026-08-05) : cette salle EST un
                      gymnase déjà saisi à la main — on pose numéro fédéral + GPS sur LE
                      SIEN, sans jamais le renommer (le nom d'usage reste roi). Offert
                      seulement s'il reste des gymnases non liés ; pastilles DANS la
                      liste (menu custom — le natif ne sait pas les rendre). */}
                  {!added && null !== salle.externalRef && venues.some((v) => null == v.externalRef) ? (
                    <Menu
                      label={`Associer ${salle.name} à un gymnase existant`}
                      trigger={<span className="text-xs">Associer à…</span>}
                      triggerClassName="h-7 w-auto shrink-0 rounded-md border border-input px-2 text-xs"
                    >
                      {venues.filter((v) => null == v.externalRef).map((v) => (
                        <MenuItem
                          key={v.id}
                          icon={<VenueSwatch color={v.color ?? DEFAULT_VENUE_COLOR} className="size-2.5" />}
                          onSelect={() =>
                            update.mutate(
                              {
                                id: v.id,
                                body: { name: v.name, color: v.color, canSplit: v.canSplit, isActive: v.isActive, externalRef: salle.externalRef, latitude: salle.latitude, longitude: salle.longitude },
                              },
                              { onSuccess: () => toast.success(`« ${salle.name} » associé à « ${v.name} ».`) },
                            )
                          }
                        >
                          {v.name}
                        </MenuItem>
                      ))}
                    </Menu>
                  ) : null}
                  <span className={added ? "text-muted-foreground" : "font-medium"}>{salle.name}</span>
                  {salle.address ? <span className="truncate text-xs text-muted-foreground">{salle.address}{salle.city ? `, ${salle.city}` : ""}</span> : null}
                </li>
              );
            })}
          </ul>
        </div>
      ) : null}
      </AccordionSection>
      ) : null}

      {emptyVenues.length > 0 ? (
        <p role="alert" className="mb-3 text-sm text-destructive">
          {emptyVenues.length > 1
            ? "Ces gymnases doivent avoir au moins un créneau d'entraînement ou une fenêtre match"
            : "Ce gymnase doit avoir au moins un créneau d'entraînement ou une fenêtre match"}{" "}
          : {emptyVenues.map((v) => v.name).join(", ")}.
        </p>
      ) : null}

      {null === selected ? (
        <EmptyHint>Aucun gymnase pour le moment.</EmptyHint>
      ) : (
        <>
          {/* Selection row — pick WHICH gym to work on (kept visually separate
              from the edit card below so the two intents don't blur together). */}
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <label className="text-sm font-medium" htmlFor="venue-picker">
              Gymnase :
            </label>
            {/* Pastille AVANT le nom (demande fondateur 2026-08-05) — VenueSelect partagé. */}
            <VenueSelect
              id="venue-picker"
              aria-label="Gymnase"
              className="h-9"
              wrapperClassName="w-60"
              venues={venues.map((v) => ({ id: v.id, name: v.name, color: v.color ?? DEFAULT_VENUE_COLOR }))}
              value={selected.id}
              onChange={(e) => {
                setSelectedId(e.target.value);
                setEditingSlot(null);
                // Drop the rename buffer so switching gyms can't write the name
                // typed for the previous one onto the newly selected gym.
                setVenueName("");
              }}
            />
          </div>

          {/* Edit card — properties of the SELECTED gym (distinct block). */}
          <div className="mb-3 rounded-lg border border-border bg-card p-3">
            <div className="mb-2 text-xs font-medium text-muted-foreground">Édition du gymnase « {selected.name} »</div>
            <div className="flex flex-wrap items-center gap-3">
              <ColorField key={`color-${selected.id}`} venue={selected} onApply={(color) => update.mutate({ id: selected.id, body: { name: selected.name, color, canSplit: selected.canSplit } })} />
              <Input
                aria-label="Renommer le gymnase"
                className="h-9 w-56"
                defaultValue={selected.name}
                key={`name-${selected.id}`}
                onChange={(e) => setVenueName(e.target.value)}
                onBlur={() => venueName.trim() && venueName !== selected.name && update.mutate({ id: selected.id, body: { name: venueName.trim(), color: selected.color, canSplit: selected.canSplit } })}
              />
              <label className="flex items-center gap-1 text-xs text-muted-foreground" title="Un gymnase divisible peut accueillir 2 équipes en même temps (terrain coupé en deux).">
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
                Terrain divisible
              </label>
              <Button size="icon" variant="ghost" className="ml-auto size-8 text-destructive" onClick={() => setPendingDeleteVenue(selected)} title="Supprimer ce gymnase" aria-label="Supprimer ce gymnase">
                <Trash2 className="size-4" />
              </Button>
            </div>
            {/* P2-53 RMM-8 — adresse + géolocalisation. La géo alimente la matrice de trajet
                (bouton « Trajets entre gymnases » du pied de page). PUT partiel : n'écrit que
                address/lat/long, le reste du gymnase est préservé. */}
            <div className="mt-3 border-t border-border pt-3">
              <VenueGeocodeField
                key={`geo-${selected.id}`}
                venue={selected}
                onLocate={({ address, latitude, longitude }) => update.mutate({ id: selected.id, body: { name: selected.name, address, latitude, longitude } })}
              />
            </div>
          </div>

          {/* Slot-placement toolbar — only the duration of the next dropped slot.
              Capacity is set per-slot in the edit panel (a new slot is always 1). */}
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <span className="text-xs text-muted-foreground">À poser :</span>
            <Select aria-label="Durée à poser" className="h-9 w-24" value={duration} onChange={(e) => setDuration(Number(e.target.value))}>
              {DURATIONS.map((d) => (
                <option key={d} value={d}>
                  {formatDuration(d)}
                </option>
              ))}
            </Select>
            {/* L'indice manquait : la barre annonçait une durée sans dire ce qu'on en fait
                (retour terrain). Il vit ICI, là où le regard est déjà au moment où la
                question se pose. */}
            {/* « un créneau » tout court devenait ambigu dès qu'une plage match s'affiche
                dans la même grille : on dit ce que le clic pose. */}
            <span className="text-xs text-muted-foreground">— cliquez la grille pour ajouter un créneau d'entraînement</span>
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
                accès match modifiable en bas de l'écran uniquement
              </span>
            ) : null}
          </div>

          <VenueAvailabilityGrid
            venue={selected}
            slots={venueSlots}
            matchWindows={venueMatchWindows}
            selectedSlotId={editingSlot?.id ?? null}
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

          {null !== editingSlot ? (
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
            <div className="mb-2 text-xs font-medium text-muted-foreground">
              Accès match de « {selected.name} » (créneaux accordés les jours de match)
            </div>
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
        title={pendingSplitOff ? `Rendre « ${pendingSplitOff.venue.name} » indivisible ?` : ""}
        confirmLabel="Rendre indivisible"
        description={
          pendingSplitOff ? (
            <>
              Ces créneaux repasseront à 1 équipe et leurs réservations seront vidées — vous devrez re-réserver&nbsp;:
              <ul className="mt-2 list-disc space-y-0.5 pl-5">
                {pendingSplitOff.preview.slots.map((s) => (
                  <li key={s.id}>
                    {DAYS.find((d) => d.n === s.dayOfWeek)?.label ?? ""} {hhmm(s.startTime)}
                  </li>
                ))}
              </ul>
              {pendingSplitOff.preview.reservationCount > 0 ? (
                <p className="mt-3 font-medium text-foreground">
                  {pendingSplitOff.preview.reservationCount}{" "}
                  {pendingSplitOff.preview.reservationCount > 1 ? "réservations seront vidées" : "réservation sera vidée"}.
                </p>
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
    </div>
  );
}
