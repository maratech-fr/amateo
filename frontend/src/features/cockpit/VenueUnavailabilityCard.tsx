import { CalendarOff, Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { useCreateVenueUnavailability, useDeleteVenueUnavailability, useUnavailabilityImpact, useVenues, useVenueUnavailabilities } from "@/features/matches/queries";
import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";

/** « 4 févr. » from Y-m-d. */
const frDate = (ymd: string): string => new Date(`${ymd}T12:00:00Z`).toLocaleDateString("fr-FR", { day: "numeric", month: "short" });

/**
 * Indisponibilités gymnase — posées ICI, au calendrier du club (décision
 * fondateur 2026-08-03 : « ça affecte les matchs, et le planning n'est qu'une
 * conséquence »). Toutes circonstances, ALERTE seulement : la carte annonce ce
 * que chaque fermeture touche (matchs placés + séances d'entraînement des
 * plannings effectifs) et laisse le gestionnaire agir — rien n'est bloqué,
 * rien n'est régénéré.
 */
export function VenueUnavailabilityCard() {
  const venuesQuery = useVenues();
  const unavailabilitiesQuery = useVenueUnavailabilities();
  const impactQuery = useUnavailabilityImpact();
  const create = useCreateVenueUnavailability();
  const remove = useDeleteVenueUnavailability();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [venueId, setVenueId] = useState("");
  const [startDate, setStartDate] = useState("");
  const [endDate, setEndDate] = useState("");
  const [label, setLabel] = useState("");

  const venues = venuesQuery.data ?? [];
  const venueName = (id: string): string => venues.find((v) => v.id === id)?.name ?? "Gymnase ?";
  const unavailabilities = unavailabilitiesQuery.data ?? [];
  const impactByUnavailability = new Map((impactQuery.data?.items ?? []).map((item) => [item.unavailabilityId, item]));

  const rangeInvalid = "" === startDate || "" === endDate || startDate > endDate;
  const canCreate = "" !== venueId && !rangeInvalid && !create.isPending;

  const submit = (): void => {
    if (!canCreate) {
      return;
    }
    create.mutate(
      { venueId, startDate, endDate, ...("" !== label.trim() ? { label: label.trim() } : {}) },
      { onSuccess: () => setDialogOpen(false) },
    );
  };

  return (
    <div className="rounded-lg border border-border bg-card p-3">
      <div className="mb-2 flex items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <CalendarOff className="size-4 text-warning" />
          Indisponibilités gymnase
        </h2>
        <Button
          variant="outline"
          size="sm"
          onClick={() => {
            setVenueId(venues[0]?.id ?? "");
            setStartDate("");
            setEndDate("");
            setLabel("");
            setDialogOpen(true);
          }}
        >
          <Plus className="size-4" />
          Déclarer
        </Button>
      </div>

      {unavailabilitiesQuery.isError ? (
        <p className="text-sm text-destructive">Les indisponibilités n’ont pas pu être chargées.</p>
      ) : 0 === unavailabilities.length && !unavailabilitiesQuery.isLoading ? (
        <p className="text-xs text-muted-foreground">
          Aucune — déclarez ici une fermeture (travaux, reprise mairie…) : elle vaut pour TOUT, matchs comme
          entraînements, et alerte sur ce qu'elle touche.
        </p>
      ) : (
        <ul className="flex flex-col gap-2">
          {unavailabilities.map((unavailability) => {
            const impact = impactByUnavailability.get(unavailability.id);
            return (
              <li key={unavailability.id} className="rounded-md border border-warning/30 bg-warning/5 px-3 py-2 text-sm">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-medium">
                      {venueName(unavailability.venueId)} — du {frDate(unavailability.startDate)} au {frDate(unavailability.endDate)}
                      {null !== unavailability.label ? ` (${unavailability.label})` : ""}
                    </p>
                    {undefined !== impact ? (
                      <p className="text-xs text-muted-foreground">
                        {impact.trainingSlotCount > 0
                          ? `${impact.trainingSlotCount} créneau(x) d'entraînement/sem. (${impact.trainingOccurrences} séance(s))`
                          : "Aucun entraînement concerné"}
                        {" · "}
                        {impact.affectedFixtures.length > 0
                          ? `${impact.affectedFixtures.length} match(s) placé(s) à repositionner`
                          : "aucun match placé"}
                      </p>
                    ) : impactQuery.isError ? (
                      <p className="text-xs text-destructive">Impact non évalué — rechargez la page.</p>
                    ) : null}
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="size-7 shrink-0"
                    aria-label={`Supprimer l'indisponibilité de ${venueName(unavailability.venueId)}`}
                    disabled={remove.isPending}
                    onClick={() => remove.mutate(unavailability.id)}
                  >
                    <Trash2 className="size-4" />
                  </Button>
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {dialogOpen ? (
        <Modal
          label="Indisponibilité gymnase"
          title="Déclarer une indisponibilité"
          onClose={() => setDialogOpen(false)}
          footer={
            <>
              <Button variant="outline" size="sm" onClick={() => setDialogOpen(false)}>
                Annuler
              </Button>
              <Button size="sm" disabled={!canCreate} onClick={submit}>
                Déclarer
              </Button>
            </>
          }
        >
          <div className="flex flex-col gap-3">
            <p className="text-xs text-muted-foreground">
              Toutes circonstances : le gymnase est fermé pour les matchs ET les entraînements sur la plage. Rien
              n'est supprimé ni régénéré — vous êtes alerté de ce qui est touché.
            </p>
            <label className="flex flex-col gap-1 text-sm">
              <span className="text-muted-foreground">Gymnase</span>
              <Select aria-label="Gymnase indisponible" value={venueId} onChange={(e) => setVenueId(e.target.value)}>
                {/* ⚠ L'option VIDE n'est pas un ornement : sans elle, `venueId` naît à "" sans
                    correspondre à aucune option, et le navigateur affiche la PREMIÈRE comme
                    sélectionnée. L'écran montrait donc un gymnase choisi pendant que l'état était
                    vide — « Déclarer » restait mort, sans un mot. Pire : cliquer l'option déjà
                    affichée ne déclenche pas `change`, donc le premier gymnase de la liste était
                    INATTEIGNABLE. Mesuré par le parcours e2e « incident » (P4-122), qui a passé
                    sept minutes sur ce bouton. Même forme que la liste jumelle de `DayDialog`. */}
                <option value="">Gymnase indisponible…</option>
                {venues.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.name}
                  </option>
                ))}
              </Select>
            </label>
            <div className="grid grid-cols-2 gap-2">
              <label className="flex flex-col gap-1 text-sm">
                <span className="text-muted-foreground">Du</span>
                <input aria-label="Début de l'indisponibilité" type="date" className="h-9 rounded-md border border-border bg-background px-2 text-sm" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
              </label>
              <label className="flex flex-col gap-1 text-sm">
                <span className="text-muted-foreground">Au (inclus)</span>
                <input aria-label="Fin de l'indisponibilité" type="date" className="h-9 rounded-md border border-border bg-background px-2 text-sm" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
              </label>
            </div>
            {"" !== startDate && "" !== endDate && startDate > endDate ? (
              <p className="text-xs text-destructive">La fin doit être après le début.</p>
            ) : null}
            <label className="flex flex-col gap-1 text-sm">
              <span className="text-muted-foreground">Motif (optionnel)</span>
              <input aria-label="Motif de l'indisponibilité" className="h-9 rounded-md border border-border bg-background px-2 text-sm" placeholder="travaux, reprise mairie…" value={label} onChange={(e) => setLabel(e.target.value)} />
            </label>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}
