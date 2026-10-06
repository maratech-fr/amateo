/**
 * Foyer UNIQUE des libellés accordés au genre d'un coach (P4-311). Un seul endroit
 * tranche « joueur / joueuse / joueur·euse » — exactement le germe du bug D-22 des
 * tables de jours recopiées. Toute vue qui désigne LA personne (pastille de liaison,
 * pill de rôle planning, StatusPill matchs, résumé de conflit, tag de récap, groupe
 * « Coach retiré·e ») passe par ici.
 *
 * Le genre non précisé rend la DOUBLE FORME au point médian (décision fondateur) : un
 * coach dont le gestionnaire n'a rien saisi reste « joueur·euse ». Les PLURIELS de
 * catégorie (« Salariés / Coachs-joueurs / Bénévoles ») et l'option de rôle « Joueur »
 * d'un Select ne sont PAS une personne — ils ne passent pas par ce foyer (hors v1).
 *
 * N'accorde QUE les cinq mots décidés ; « coach », « adjoint », « principal »,
 * « assistant » restent tels quels (hors liste).
 */

export type CoachGender = "FEMALE" | "MALE" | "UNSPECIFIED";

type Forms = Readonly<Record<CoachGender, string>>;

const PLAYER: Forms = { FEMALE: "joueuse", MALE: "joueur", UNSPECIFIED: "joueur·euse" };
const SALARIED: Forms = { FEMALE: "salariée", MALE: "salarié", UNSPECIFIED: "salarié·e" };
const COACH_PLAYER: Forms = { FEMALE: "coach-joueuse", MALE: "coach-joueur", UNSPECIFIED: "coach-joueur·euse" };
const RETIRED: Forms = { FEMALE: "retirée", MALE: "retiré", UNSPECIFIED: "retiré·e" };
const VEHICLED: Forms = { FEMALE: "véhiculée", MALE: "véhiculé", UNSPECIFIED: "véhiculé·e" };

/** Première lettre en capitale (même recette que `days.ts::dayLabelLongCap`). */
export const capitalizeWord = (word: string): string => ("" === word ? "" : word.charAt(0).toUpperCase() + word.slice(1));

/** « joueur / joueuse / joueur·euse » — un coach qui JOUE dans une équipe. */
export const playerWord = (gender: CoachGender): string => PLAYER[gender];

/** « salarié / salariée / salarié·e » — un coach employé par le club. */
export const salariedWord = (gender: CoachGender): string => SALARIED[gender];

/** « coach-joueur / coach-joueuse / coach-joueur·euse » — tag de récap. */
export const coachPlayerWord = (gender: CoachGender): string => COACH_PLAYER[gender];

/** « retiré / retirée / retiré·e » — un coach supprimé (genre inconnu ⇒ double forme). */
export const retiredWord = (gender: CoachGender): string => RETIRED[gender];

/** « véhiculé / véhiculée / véhiculé·e » — case « Véhiculé » de la fiche. */
export const vehicledWord = (gender: CoachGender): string => VEHICLED[gender];

/** Les options du Select « Genre » de la fiche coach, dans l'ordre d'affichage. */
export const COACH_GENDER_OPTIONS: ReadonlyArray<{ value: CoachGender; label: string }> = [
  { value: "UNSPECIFIED", label: "Non précisé" },
  { value: "FEMALE", label: "Femme" },
  { value: "MALE", label: "Homme" },
];
