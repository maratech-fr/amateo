import { useId, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Label } from "@/shared/components/ui/label";
import { Listbox } from "@/shared/components/ui/listbox";
import { Modal } from "@/shared/components/ui/modal";
import { frDateShortNoYear } from "@/shared/lib/date";

import type { Conflict, ConflictFixtureView, DeviationField, Team, Venue } from "./api";
import { FIELD_LABEL } from "./lib/fbiCorrectionLabel";

/**
 * Lot N — le dialogue « erreur FBI ». « Erreur FBI » est le PREMIER statut qui exige un
 * complément : au lieu d'écrire tout de suite (comme les autres statuts), le geste ouvre
 * ce petit dialogue qui demande LAQUELLE des deux rencontres de la collision est fautive
 * (libellée avec le détail du commit B : équipe · vs adversaire · gymnase · date) et QUEL
 * champ (date/heure/salle). Il rend `{ fixtureId, field }` — le contrôle appelle alors le
 * PUT avec ce complément.
 *
 * Primitives PARTAGÉES (jamais une copie locale) : `Modal` (piège de focus, Échap), deux
 * `Listbox` (APG, clavier, libellés explicites). L'information n'est JAMAIS portée par la
 * seule couleur. Le dialogue DIT que la déclaration laisse une trace ailleurs (une entrée
 * dans « FBI — à faire »), dont la fermeture est indépendante.
 */

/** Les 3 champs du registre — TABLE de présentation (`FIELD_LABEL`), jamais un décideur. */
const FIELD_OPTIONS: { value: DeviationField; label: string }[] = (["venue", "date", "kickoff"] as DeviationField[]).map((field) => ({
  value: field,
  label: FIELD_LABEL[field] ?? field,
}));

interface FbiErrorDialogProps {
  conflict: Conflict;
  teams: Map<string, Team>;
  venues: Map<string, Venue>;
  busy: boolean;
  onConfirm: (complement: { fixtureId: string; field: DeviationField }) => void;
  onCancel: () => void;
}

/** Le libellé d'un côté de la collision : « SF1 vs BC Villeurbanne · Gymnase Croix-Luizet · 14/03 ». */
function sideLabel(side: ConflictFixtureView, teams: Map<string, Team>, venueName: string): string {
  const team = teams.get(side.teamId)?.name ?? "Équipe ?";
  const opponent = undefined !== side.opponentLabel && "" !== side.opponentLabel ? ` vs ${side.opponentLabel}` : "";
  return `${team}${opponent} · ${venueName} · ${frDateShortNoYear(side.matchDate)}`;
}

export function FbiErrorDialog({ conflict, teams, venues, busy, onConfirm, onCancel }: FbiErrorDialogProps) {
  const matchFieldId = useId();
  const columnFieldId = useId();
  const noticeId = useId();

  const venueName = null != conflict.venueId ? (venues.get(conflict.venueId)?.name ?? "Gymnase ?") : "Gymnase ?";
  const sides = [conflict.left, conflict.right].filter((side): side is ConflictFixtureView => undefined !== side);
  const matchOptions = sides.map((side) => ({ value: side.fixtureId, label: sideLabel(side, teams, venueName) }));

  const [fixtureId, setFixtureId] = useState(matchOptions[0]?.value ?? "");
  const [field, setField] = useState<DeviationField>("venue");

  const canConfirm = "" !== fixtureId && !busy;

  return (
    <Modal
      label="Déclarer une erreur FBI"
      title="Erreur FBI"
      onClose={onCancel}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" size="sm" onClick={onCancel}>
            Annuler
          </Button>
          <Button variant="default" size="sm" aria-describedby={noticeId} disabled={!canConfirm} onClick={() => onConfirm({ fixtureId, field })}>
            Déclarer l'erreur FBI
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-1.5">
          <Label id={matchFieldId}>Quelle rencontre est fautive ?</Label>
          <Listbox aria-labelledby={matchFieldId} options={matchOptions} value={fixtureId} onValueChange={setFixtureId} />
        </div>
        <div className="flex flex-col gap-1.5">
          <Label id={columnFieldId}>Quel champ est faux dans FBI ?</Label>
          <Listbox aria-labelledby={columnFieldId} options={FIELD_OPTIONS} value={field} onValueChange={(v) => setField(v as DeviationField)} />
        </div>
        <p id={noticeId} className="text-sm text-muted-foreground">
          Cette déclaration ouvre une entrée dans « FBI — à faire » (à corriger dans FBI) : la valeur reste à vérifier — l'appli a
          importé l'erreur, elle ne connaît pas la bonne valeur. La fermeture de cette entrée se fait dans la liste, indépendamment
          du conflit.
        </p>
      </div>
    </Modal>
  );
}
