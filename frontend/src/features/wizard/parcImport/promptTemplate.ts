import { PRODUCT_NAME } from "@/shared/lib/product";

/**
 * Lot correctif import IA (« G2 ») PR1 — le GABARIT du prompt que le gestionnaire colle dans un
 * chatbot externe (GPT / Gemini / Claude / Copilot) pour qu'il rende tout son parc au format
 * `parc-v2` : UNE ENTRÉE PAR CASE. L'appli déduit ensuite équipes / séances / genre / catégorie /
 * mutualisations / réservations des cases (cadrage G2 D1). Les coachs ne sont PLUS extraits (D11).
 *
 * `PARC_V2_SPEC` est la source machine-lisible du format côté front : version, champs de case,
 * plafond d'équipes par case. `buildParcPrompt` en DÉRIVE le texte — le prompt et la spec ne peuvent
 * donc pas diverger côté front. La parité spec ⇄ VALIDATEUR backend
 * (`App\Service\ParcImport\ParcPayloadValidator`, la vérité de ce qui est recevable) est tenue par
 * `ParcPromptValidatorParityTest` (CrossStack, groupe `contract`).
 */
export const PARC_V2_SPEC = {
  version: "parc-v2",
  caseFields: ["gymnase", "jour", "debut", "duree", "equipes", "groupe"],
  maxTeamsPerCase: 6,
} as const;

/** Les noms déjà saisis d'une catégorie, ou une mention « aucun » si la liste est vide. */
function listOrNone(names: readonly string[]): string {
  return names.length > 0 ? names.map((n) => `  • ${n}`).join("\n") : "  (aucun pour l'instant)";
}

/** Le contexte du club (nom / ville / sport), utile surtout quand aucun nom n'est encore saisi. */
function clubContext(club?: { name?: string; city?: string; sport?: string }): string {
  const parts: string[] = [];
  if (club?.name) {
    parts.push(`le club « ${club.name} »`);
  }
  if (club?.city) {
    parts.push(`à ${club.city}`);
  }
  parts.push(`sport : ${club?.sport ?? "basket"}`);
  return parts.join(", ");
}

/**
 * Fabrique le texte du prompt à coller dans le chatbot. Fonction PURE : aucune lecture réseau, aucun
 * effet. Tolère des listes vides (club neuf — le contexte club prend alors le relais). Les noms déjà
 * saisis servent de CORRECTEUR d'orthographe, jamais de filtre : le chatbot garde AUSSI ce qu'il
 * détecte au-delà de cette liste.
 */
export function buildParcPrompt(input: {
  teams: readonly string[];
  venues: readonly string[];
  club?: { name?: string; city?: string; sport?: string };
}): string {
  const fields = PARC_V2_SPEC.caseFields.join(", ");

  return [
    `Vous aidez un gestionnaire de club de basket à transcrire tout son planning d'entraînement pour l'outil ${PRODUCT_NAME}, à partir de son document.`,
    `Contexte : ${clubContext(input.club)}.`,
    "",
    `Le meilleur support est un fichier Excel du planning ; une image (photo, capture) ou un PDF conviennent aussi.`,
    "",
    `Rendez UNIQUEMENT un objet JSON au format « ${PARC_V2_SPEC.version} », et RIEN D'AUTRE : aucune phrase avant ou après, aucun commentaire, aucun bloc Markdown — seulement le JSON.`,
    "",
    `L'objet porte le champ « format » valant exactement « ${PARC_V2_SPEC.version} », une liste « gymnases » (chacun { "nom": … }) et une liste « cases ». UNE ENTRÉE PAR CASE réellement écrite sur le document, avec ces champs : ${fields}.`,
    "",
    "Détail des champs d'une case :",
    `- "gymnase" : le nom du gymnase de la case (en France, souvent le nom d'une personnalité : « Jean Vilar », « Matéo »).`,
    `- "jour" : le numéro du jour — lundi = 1, mardi = 2, mercredi = 3, jeudi = 4, vendredi = 5, samedi = 6, dimanche = 7.`,
    `- "debut" : l'heure de début au format 24 h « HH:MM ». "duree" : la durée en minutes.`,
    `- "equipes" : TOUTES les équipes écrites dans la case, chacune par son nom (une entrée par équipe, ex. ["U9F1", "U9F2"]) ; au plus ${PARC_V2_SPEC.maxTeamsPerCase} équipes par case. Une case sans équipe (créneau vide du gymnase) porte une liste vide.`,
    `- "groupe" : si les équipes de la case s'entraînent ENSEMBLE (mutualisation, ex. « CEC »), mettez ce libellé de groupe ici ; sinon omettez ce champ.`,
    "",
    "Noms déjà saisis dans l'outil — réutilisez EXACTEMENT ces noms (même orthographe, mêmes accents) pour CORRIGER le nommage dès que le planning les mentionne :",
    "Équipes :",
    listOrNone(input.teams),
    "Gymnases :",
    listOrNone(input.venues),
    "",
    "Important : cette liste CORRIGE le nommage, elle ne FILTRE rien. Gardez AUSSI toutes les équipes et tous les gymnases que vous détectez au-delà de cette liste, même absents de l'outil — ne jetez jamais une case lisible.",
    "",
    "Le jour d'une case vient UNIQUEMENT de la colonne où elle se trouve, jamais du nom de l'équipe ni de la couleur. Une case mal placée se corrige ; une case oubliée est perdue. Rendez uniquement le JSON.",
  ].join("\n");
}
