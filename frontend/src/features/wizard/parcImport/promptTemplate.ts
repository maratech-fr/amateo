import { PRODUCT_NAME } from "@/shared/lib/product";

/**
 * Chantier G (import du parc par IA), PR3 — le GABARIT du prompt que le gestionnaire colle dans un
 * chatbot externe pour qu'il rende tout son parc au format `parc-v1`.
 *
 * `PARC_V1_SPEC` est la source machine-lisible du format côté front : version, sections (requises
 * et optionnelle), énumération de genre, champs par section. `buildParcPrompt` en DÉRIVE le texte —
 * le prompt et la spec ne peuvent donc pas diverger côté front. La parité spec ⇄ VALIDATEUR backend
 * (`App\Service\ParcImport\ParcPayloadValidator`, la vérité de ce qui est recevable) est tenue par
 * `ParcPromptValidatorParityTest` (CrossStack, groupe `contract`) : toute dérive de version, de
 * section, de genre ou de champ, d'un côté ou de l'autre, rougit.
 */
export const PARC_V1_SPEC = {
  version: "parc-v1",
  requiredSections: ["equipes", "gymnases", "creneaux", "mutualisations", "reservations"],
  optionalSections: ["coachs"],
  genders: ["M", "F", "MIXTE"],
  fields: {
    equipes: ["nom", "genre", "seances"],
    gymnases: ["nom"],
    creneaux: ["gymnase", "jour", "debut", "duree", "equipes_en_parallele"],
    mutualisations: ["equipes", "seances_communes"],
    coachs: ["nom"],
    reservations: ["equipe", "gymnase", "jour", "debut"],
  },
} as const;

type ParcSection = keyof typeof PARC_V1_SPEC.fields;

/** Les hints HUMAINS par section — purs libellés explicatifs, les NOMS de champ viennent de la spec. */
const SECTION_HINTS: Record<ParcSection, string> = {
  equipes: "les équipes du club",
  gymnases: "les gymnases où le club s'entraîne",
  creneaux: "les créneaux d'entraînement récurrents (jour 1 = lundi … 7 = dimanche, debut au format HH:MM, duree en minutes, equipes_en_parallele de 1 à 3)",
  mutualisations: "les groupes d'équipes qui s'entraînent ensemble (equipes = la liste de leurs noms, seances_communes = combien de séances par semaine en commun)",
  coachs: "les entraîneurs",
  reservations: "les cases déjà posées pour une équipe donnée (equipe, gymnase, jour, heure de début)",
};

/** Une ligne « - section : champ1, champ2 … (explication) », dérivée de la spec. */
function describeSection(section: ParcSection, optional: boolean): string {
  const fields = PARC_V1_SPEC.fields[section].join(", ");
  const tag = optional ? " — section FACULTATIVE (absente si le club n'en a aucun)" : "";
  return `- "${section}" : ${SECTION_HINTS[section]}. Champs de chaque élément : ${fields}.${tag}`;
}

/** Les noms déjà saisis d'une catégorie, ou une mention « aucun » si la liste est vide. */
function listOrNone(names: readonly string[]): string {
  return names.length > 0 ? names.map((n) => `  • ${n}`).join("\n") : "  (aucun pour l'instant)";
}

/**
 * Fabrique le texte du prompt à coller dans le chatbot. Les noms EXACTS déjà saisis sont embarqués
 * pour que l'assistant les réutilise au lieu d'en inventer des variantes. Tolère des listes vides
 * (club neuf). Fonction PURE : aucune lecture réseau, aucun effet.
 */
export function buildParcPrompt(input: { teams: readonly string[]; venues: readonly string[]; coaches: readonly string[] }): string {
  const sections = [
    ...PARC_V1_SPEC.requiredSections.map((s) => describeSection(s as ParcSection, false)),
    ...PARC_V1_SPEC.optionalSections.map((s) => describeSection(s as ParcSection, true)),
  ].join("\n");

  return [
    `Vous aidez un gestionnaire de club de basket à décrire tout son planning d'entraînement pour l'outil ${PRODUCT_NAME}.`,
    "",
    `Rendez UNIQUEMENT un objet JSON au format « ${PARC_V1_SPEC.version} », et RIEN D'AUTRE : aucune phrase avant ou après, aucun commentaire, seulement le JSON.`,
    "",
    "L'objet porte le champ « format » valant exactement « " + PARC_V1_SPEC.version + " », puis ces sections (chacune une liste, éventuellement vide) :",
    sections,
    "",
    `Le genre d'une équipe (champ « genre ») vaut exactement l'une de ces valeurs : ${PARC_V1_SPEC.genders.join(", ")}.`,
    "",
    "Noms déjà saisis dans l'outil — réutilisez EXACTEMENT ces noms (même orthographe, mêmes accents) dès que le planning les mentionne, n'en inventez aucune variante :",
    "Équipes :",
    listOrNone(input.teams),
    "Gymnases :",
    listOrNone(input.venues),
    "Coachs :",
    listOrNone(input.coaches),
    "",
    "Règle importante : si deux équipes occupent la même case (même gymnase, même jour, même heure), c'est forcément soit une mutualisation (déclarez-les dans « mutualisations »), soit un terrain divisé en deux (posez « equipes_en_parallele » ≥ 2 sur le créneau). Ne mettez jamais deux équipes sur la même case sans l'une de ces deux déclarations.",
    "",
    "Tout champ que vous ne connaissez pas peut être omis. N'ajoutez pas de section hors de cette liste.",
  ].join("\n");
}
