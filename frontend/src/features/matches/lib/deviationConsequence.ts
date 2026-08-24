import type { DeviationField, FixtureStatus } from "../api";

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
  /** Prendre le fichier LIBÈRE-t-il le créneau placé (dé-placement) ? date/salle oui, heure non. */
  releasesSlot: boolean;
}

const KEEP_APP = "Rien n'est écrit — un pense-bête gardera l'écart jusqu'au prochain dépôt, pour vérifier que la correction a bien été faite dans FBI.";

const CONSEQUENCE: Record<DeviationField, FieldConsequence> = {
  date: {
    takeFile: "Prendre la date du fichier dé-place le match — ça libère le créneau placé, à replacer.",
    keepApp: KEEP_APP,
    releasesSlot: true,
  },
  venue: {
    takeFile: "Prendre la salle du fichier dé-place le match — ça libère le créneau placé, à replacer.",
    keepApp: KEEP_APP,
    releasesSlot: true,
  },
  kickoff: {
    takeFile: "Prendre l'heure du fichier garde le placement (le créneau reste).",
    keepApp: KEEP_APP,
    releasesSlot: false,
  },
};

export function fieldConsequence(field: DeviationField): FieldConsequence {
  return CONSEQUENCE[field];
}

/**
 * Un match SAISI/VALIDÉ dans FBI est « déposé » à la fédération : prendre le
 * fichier le fera repasser à « Placé », à re-saisir. Membership de présentation
 * (le signalement renforcé), pas un verdict — même stance que `fixtureStatusLabel`.
 */
export function isDeposited(status: FixtureStatus): boolean {
  return "SUBMITTED" === status || "VALIDATED" === status;
}

/** Le message du signalement renforcé, quand l'écart porte sur un match déposé. */
export const DEPOSITED_WARNING = "Ce match était marqué saisi dans FBI — prendre le fichier le fera repasser à « Placé », à re-saisir dans FBI.";
