import type { CalendarEntry, SchoolHoliday } from "../api";

/**
 * Single home of the calendar's visual markers, shared by the month grid AND the
 * day dialog so an entry looks the same in both (user request: same emoji in the
 * modal as on the calendar). Emojis are decorative — always pair them with the
 * entry title / an aria-label at the call site.
 */

/**
 * La « mère » d'une période vacances : l'entrée qui matérialise une vacance
 * scolaire (schoolHolidayId) SANS parent. C'est un ANCRAGE technique (plans et
 * semaines-enfants s'y rattachent), pas un événement à part entière — la vacance
 * scolaire EST déjà l'événement (surlignage amber). On la masque donc des
 * marqueurs du calendrier et de la liste supprimable de la modale jour (retour
 * fondateur : « pas d'événement fantôme, les vacances sont déjà un événement »).
 * Les semaines-enfants n'ont pas de schoolHolidayId, les fermetures non plus.
 */
export const isHolidayAnchor = (e: CalendarEntry): boolean => null === e.parentEntryId && null !== e.schoolHolidayId;

/**
 * Semaine-enfant d'une VACANCE (P2-5 E1) : un rail technique (1 entrée = 1 plan),
 * pas un événement — le surlignage amber de la vacance marque déjà la période.
 * Masquée des marqueurs de la grille comme sa mère (retour fondateur 2026-07-24 :
 * « pas de ⛔ en plus juste parce que je crée un planning »). Les semaines de
 * FERMETURE gardent leur ⛔ : sans surlignage amber, ce serait la seule trace
 * visible de la fermeture au calendrier.
 */
export const isHolidayWeekChild = (e: CalendarEntry): boolean => null !== e.parentEntryId && "holiday" === e.periodType;

/**
 * Enfant d'une FERMETURE (`parentEntryId` + `periodType:"closure"`) dont la MÈRE figure parmi les
 * entrées voisines (`siblings`) : une fermeture prolongée à travers les vacances devient une mère
 * (CLOSURE racine, couvrant toute la fenêtre) + des enfants (ses sous-segments). La mère REPRÉSENTE
 * la fermeture — au calendrier comme dans la modale du jour — donc l'enfant n'ajoute pas sa propre
 * pastille ni sa propre ligne (sinon un double ⛔ / une double ligne le même jour).
 *
 * Contrairement à `isHolidayWeekChild` (qui n'a pas besoin du contexte des frères), le masquage est
 * CONDITIONNEL à la présence de la mère : un enfant ORPHELIN d'affichage (mère absente des entrées
 * du jour) reste la seule trace de la fermeture et GARDE sa pastille. Par construction un enfant ⊆
 * sa mère, donc la mère couvre toujours les jours de l'enfant — le cas orphelin ne survient pas pour
 * cette feature, mais le garder conditionnel évite un jour muet si jamais il survenait.
 */
export const isClosureChildWithMotherPresent = (e: CalendarEntry, siblings: CalendarEntry[]): boolean =>
  null !== e.parentEntryId && "closure" === e.periodType && siblings.some((m) => m.id === e.parentEntryId);

/** Emoji marker for a calendar entry (event / period). */
export const entryIcon = (e: CalendarEntry): string => {
  if (e.kind === "period") {
    if (e.periodType === "cutoff") {
      return "🛑";
    }
    // Une VACANCE (mère comme enfant) ne porte JAMAIS ⛔ : le surlignage amber de la vacance
    // scolaire (et son emoji de saison) la marque déjà — un ⛔ en plus la ferait passer pour une
    // interdiction. Retour fondateur 2026-07-24, étendu à la MÈRE le 2026-08-19 : la doctrine ne
    // masquait que les enfants (`isHolidayWeekChild`) et `isHolidayAnchor` ne couvre que la mère
    // AVEC schoolHolidayId ; une mère holiday sans ancrage recevait encore ⛔ ici. Pas de marqueur
    // — l'amber suffit ; les FERMETURES gardent leur ⛔ (seule trace au calendrier).
    if (e.periodType === "holiday") {
      return "";
    }
    return "⛔";
  }
  return e.isDisruptive ? "🚫" : "🎉";
};

/** Emoji per school-holiday period, so the marker fits the season (not a beach for winter). */
const HOLIDAY_ICON: Record<string, string> = {
  ete: "🏖️", // été → plage
  toussaint: "🎃", // automne → citrouille
  noel: "🎄", // Noël → sapin
  hiver: "⛷️", // hiver → ski
  printemps: "🐰", // printemps / Pâques → lapin
};
export const holidayIcon = (h: SchoolHoliday): string => HOLIDAY_ICON[h.holidayType] ?? "🏖️";

/**
 * Accessible/fallback name for an entry marker. `title` may be empty (imported /
 * auto-named entries), so fall back to the marker's meaning — never an empty
 * label (a silent marker for a screen reader).
 */
export const entryLabel = (e: CalendarEntry): string => {
  if (e.title.trim() !== "") {
    return e.title;
  }
  if (e.kind === "period") {
    return e.periodType === "cutoff" ? "Coupure" : "Période fermée";
  }
  return e.isDisruptive ? "Événement perturbant" : "Événement";
};
