import { Input } from "@/shared/components/ui/input";
import { SegmentedControl } from "@/shared/components/ui/segmented-control";

import { WIZARD_TEXTS } from "../texts";

/** Ce que pose un clic sur la grille des gymnases : un créneau d'entraînement, ou une occupation. */
export type PlacementKind = "slot" | "occupancy";

/** Longueur maximale d'un motif d'occupation (même borne que le serveur). */
const LABEL_MAX_LENGTH = 40;

/**
 * Le choix [Créneau | Occupation] et, en mode Occupation, le champ « Motif ». S'insère DANS la barre
 * « À poser » de chaque écran (saison, période), entre « À poser : » et la durée : il ne porte ni la
 * durée ni l'indice, qui restent à l'écran.
 */
export function PlacementKindBar({
  kind,
  onKind,
  label,
  onLabel,
}: {
  kind: PlacementKind;
  onKind: (kind: PlacementKind) => void;
  label: string;
  onLabel: (label: string) => void;
}) {
  const showLabel = "occupancy" === kind;

  return (
    <>
      <SegmentedControl<PlacementKind>
        label={WIZARD_TEXTS.placementKindGroup}
        value={kind}
        onValueChange={onKind}
        options={[
          { key: "slot", label: WIZARD_TEXTS.placementKindSlot },
          { key: "occupancy", label: WIZARD_TEXTS.placementKindOccupancy },
        ]}
      />
      {showLabel ? (
        <Input
          aria-label={WIZARD_TEXTS.occupancyLabelField}
          placeholder={WIZARD_TEXTS.occupancyLabelPlaceholder}
          maxLength={LABEL_MAX_LENGTH}
          className="w-40"
          value={label}
          onChange={(e) => onLabel(e.target.value)}
        />
      ) : null}
    </>
  );
}
