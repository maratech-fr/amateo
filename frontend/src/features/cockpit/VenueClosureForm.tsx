import { useState } from "react";

import { useVenues } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { useWorkingSeason } from "@/shared/session/queries";
import { toast } from "@/shared/stores/toastStore";

import type { ClosureImpactParams } from "./api";
import { ClosureImpactPanel, type ClosureImpactState } from "./ClosureImpactPanel";
import { todayISO } from "./lib/date";
import { useClosureImpactPreview, useCreateVenueClosure } from "./queries";
import { COCKPIT_TEXTS } from "./texts";

/** « 15/7 » — jour/mois sans zéro de tête. */
const dayMonth = (iso: string): string => `${Number(iso.slice(8, 10))}/${Number(iso.slice(5, 7))}`;

/** Une date complète AAAA-MM-JJ (une année partielle en cours de saisie est ignorée). */
const isFullDate = (value: string): boolean => /^\d{4}-\d{2}-\d{2}$/.test(value);

/**
 * LA saisie unique d'une indisponibilité de gymnase (chantier « Fermetures de gymnase », C1/C3) —
 * ouverte depuis la carte du cockpit (dates vides) ET depuis le jour du calendrier (`initialStartDate`).
 *
 * « Enregistrer vaut geste » : l'enregistrement retire, côté serveur, les verrous et réservations que
 * la fermeture met hors de portée. On ne retire donc rien sans l'avoir ANNONCÉ — « Enregistrer » reste
 * inactif tant que l'aperçu n'a pas été LU pour ces dates (en vol ou en échec : inactif). Hors saison
 * (fenêtre qui ne touche aucun jour de la saison) : bouton inactif, miroir du 422 serveur ; à cheval
 * sur la fin de saison : seules les dates en saison comptent, le formulaire le dit. Le toast confirme
 * ce qui a été retiré (`removed` de la réponse). Une génération en vol : le hook nomme le motif, le
 * formulaire reste ouvert.
 */
export function VenueClosureForm({ initialStartDate, onDone }: { initialStartDate: string | null; onDone: () => void }) {
  const { data: venues } = useVenues();
  const season = useWorkingSeason();
  // Plancher du début gelé à l'ouverture (même règle que les autres saisies du jour du calendrier).
  const [today] = useState(() => todayISO());
  const createClosure = useCreateVenueClosure();
  const [venueId, setVenueId] = useState("");
  const [title, setTitle] = useState("");
  const [startDate, setStartDate] = useState(initialStartDate ?? "");
  const [endDate, setEndDate] = useState(initialStartDate ?? "");

  const datesValid = isFullDate(startDate) && isFullDate(endDate) && startDate <= endDate && startDate >= today;
  const outOfSeason = datesValid && null !== season && (endDate < season.startDate || startDate > season.endDate);
  const clipped = datesValid && !outOfSeason && null !== season && endDate > season.endDate;
  const params: ClosureImpactParams | null = "" !== venueId && datesValid && !outOfSeason ? { venueId, startDate, endDate } : null;
  // L'attente avant lecture (pas une relecture à chaque frappe) vit dans le hook : tant qu'il
  // attend, l'aperçu est « en vol ».
  const preview = useClosureImpactPreview(params);

  let state: ClosureImpactState = "idle";
  if (outOfSeason) {
    state = "out_of_season";
  } else if (null !== params) {
    if (preview.isError) {
      state = "failed";
    } else if (preview.isSuccess && !preview.isFetching && undefined !== preview.data) {
      state = "ready";
    } else {
      state = "loading";
    }
  }

  const venueName = venues?.find((v) => v.id === venueId)?.name ?? "";
  const canSave = "ready" === state && !createClosure.isPending;

  const setStart = (value: string) => {
    setStartDate(value);
    setEndDate((prev) => ("" === prev || prev < value ? value : prev));
  };

  const submit = () => {
    if (!canSave || null === params) return;
    // Intitulé structuré « gymnase — raison » (le calendrier nomme le gymnase ET la raison) ; pas de
    // préfixe si la raison nomme déjà le gymnase ; « fermé » par défaut ; borné à la colonne (180).
    const reason = title.trim();
    const base = "" === reason ? `${venueName} — fermé` : reason.includes(venueName) ? reason : `${venueName} — ${reason}`;
    createClosure.mutate(
      { title: base.slice(0, 180), startDate, endDate, venueId },
      {
        onSuccess: (data) => {
          toast.success(COCKPIT_TEXTS.closureSavedToast(data.removed.locks, data.removed.reservations, venueName));
          onDone();
        },
      },
    );
  };

  return (
    <div className="space-y-3">
      <VenueSelect
        aria-label={COCKPIT_TEXTS.unavailableVenueLabel}
        placeholder={COCKPIT_TEXTS.unavailableVenuePlaceholder}
        venues={(venues ?? []).map((v) => ({ id: v.id, name: v.name, color: v.color }))}
        value={venueId}
        onValueChange={setVenueId}
      />
      <Input aria-label={COCKPIT_TEXTS.unavailabilityTitleLabel} placeholder={COCKPIT_TEXTS.optionalTitlePlaceholder} maxLength={140} value={title} onChange={(e) => setTitle(e.target.value)} />
      <div className="grid grid-cols-2 gap-2">
        <label className="block text-xs text-muted-foreground">
          {COCKPIT_TEXTS.dateFrom}
          <Input type="date" className="mt-1" value={startDate} min={today} onChange={(e) => setStart(e.target.value)} />
        </label>
        <label className="block text-xs text-muted-foreground">
          {COCKPIT_TEXTS.dateUntil}
          <Input type="date" className="mt-1" value={endDate} min={startDate} onChange={(e) => setEndDate(e.target.value)} />
        </label>
      </div>
      {outOfSeason && null !== season ? <p className="text-xs text-destructive">{COCKPIT_TEXTS.closureOutOfSeason(dayMonth(season.startDate), dayMonth(season.endDate))}</p> : null}
      {clipped && null !== season ? <p className="text-xs text-muted-foreground">{COCKPIT_TEXTS.closureSeasonClipped(dayMonth(startDate), dayMonth(season.endDate))}</p> : null}
      <ClosureImpactPanel impact={"ready" === state ? (preview.data ?? null) : null} state={state} venueName={venueName} onRetry={() => void preview.refetch()} />
      <Button
        className="w-full"
        onClick={submit}
        disabled={!canSave}
        disabledReason={canSave ? undefined : outOfSeason ? COCKPIT_TEXTS.closureSaveOutOfSeason : null === params ? COCKPIT_TEXTS.closureSaveNeedsInput : COCKPIT_TEXTS.closureSaveNeedsPreview}
      >
        {COCKPIT_TEXTS.save}
      </Button>
    </div>
  );
}
