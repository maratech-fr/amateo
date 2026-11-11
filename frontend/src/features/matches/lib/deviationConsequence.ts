import type { DeviationField } from "../api";

/**
 * RMM-4 — la CONSÉQUENCE d'un choix de réconciliation, en français, montrée AVANT
 * que le gestionnaire ne tranche. PRÉSENTATION pure : le backend décide vraiment
 * (`FbiFixtureImporter::applyDeviationMode`) ; ici on ÉPELLE ce qu'il fera, jamais
 * on ne le redérive. Le garde anti-redérivation ne police que les enums de
 * CONTRAINTE (scope/ruleType/family) — brancher sur `field`/`status` pour choisir
 * un LIBELLÉ est du même régime que `diagnostic.ts` / `fixtureStatusLabel.ts`.
 *
 * Une TABLE, jamais un ternaire dispersé : TypeScript exige les trois champs, un
 * `field` ajouté au contrat sans conséquence ici rendrait le type incomplet.
 */
export const FIELD_LABEL: Record<DeviationField, string> = {
  date: "Date",
  kickoff: "Heure",
  venue: "Salle",
};

export interface FieldConsequence {
  /** Ce que « prendre le fichier » fait — la branche qui change quelque chose. */
  takeFile: string;
  /** Ce que « garder l'app » fait — rien d'écrit, une trace. */
  keepApp: string;
}

const KEEP_APP = "Rien n'est écrit — la rencontre est traitée avec la valeur d'Amateo ; si le prochain dépôt diverge encore, l'écart reviendra ici.";

const CONSEQUENCE: Record<DeviationField, FieldConsequence> = {
  date: {
    takeFile: "Prendre la date du fichier change la date du match ; il garde son gymnase, son heure et son statut.",
    keepApp: KEEP_APP,
  },
  venue: {
    takeFile: "Prendre la salle du fichier met le match dans le gymnase choisi ; il garde sa date, son heure et son statut. Avec « terrain neutre », il quitte son créneau.",
    keepApp: KEEP_APP,
  },
  kickoff: {
    takeFile: "Prendre l'heure du fichier garde le placement (le créneau reste).",
    keepApp: KEEP_APP,
  },
};

export function fieldConsequence(field: DeviationField): FieldConsequence {
  return CONSEQUENCE[field];
}
