/** Cockpit calendar date helpers — pure, no date library. All dates are ISO Y-m-d. */

// « Aujourd'hui » vit désormais dans `shared/lib/clock` (une seule source pour tout le
// front, pilotable en dev). Ré-exporté ici pour que les 9 fichiers qui l'importent depuis
// ce module restent inchangés — P4-16 migrera les appelants quand elle traitera le serveur.
export { toISODate, todayISO } from "@/shared/lib/clock";
import { toISODate } from "@/shared/lib/clock";

const MONTH_LABELS = ["Janvier", "Février", "Mars", "Avril", "Mai", "Juin", "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Décembre"];

export const monthLabel = (month: number): string => MONTH_LABELS[month] ?? "";

/** First and last ISO date covering the calendar grid for a given month (Monday-first, 6 weeks). */
export function monthWindow(year: number, month: number): { from: string; to: string } {
  const grid = buildMonthGrid(year, month);
  return { from: grid[0].iso, to: grid[grid.length - 1].iso };
}

export interface GridDay {
  iso: string;
  day: number;
  inMonth: boolean;
}

/**
 * A 6-row Monday-first grid of the month. Leading/trailing days spill from the
 * adjacent months so every row has 7 cells.
 */
/** getDay(): 0=Sun..6=Sat → décalage Monday-first (Mon=0 … Sun=6). Source unique
 *  partagée par la grille du mois et le découpage en semaines (mondayOf). */
const mondayOffset = (date: Date): number => (date.getDay() + 6) % 7;

export function buildMonthGrid(year: number, month: number): GridDay[] {
  const first = new Date(year, month, 1);
  const offset = mondayOffset(first);
  const start = new Date(year, month, 1 - offset);

  const days: GridDay[] = [];
  for (let i = 0; i < 42; i += 1) {
    const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
    days.push({ iso: toISODate(d), day: d.getDate(), inMonth: d.getMonth() === month });
  }
  return days;
}

/** Whether ISO date `d` falls within the inclusive [start, end] range (string compare is safe for Y-m-d). */
export function isWithin(d: string, start: string, end: string): boolean {
  return d >= start && d <= end;
}

/** ISO date `n` days after `iso`. */
export function addDays(iso: string, n: number): string {
  const [y, m, d] = iso.split("-").map(Number);
  return toISODate(new Date(y, m - 1, d + n));
}

/** Numeric French date dd-mm-yyyy (FR reading order, dashes), e.g. "2026-10-17" → "17-10-2026". */
export function frDateNumeric(iso: string): string {
  const [y, m, d] = iso.split("-");
  return `${d}-${m}-${y}`;
}

/** Short French date for compact UI copy, e.g. "2026-12-19" → "19 déc. 2026". */
export function frDateShort(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  return new Date(y, m - 1, d).toLocaleDateString("fr-FR", { day: "numeric", month: "short", year: "numeric" });
}

/** Compact French date WITHOUT the year, e.g. "2026-12-19" → "19 déc." — for labels whose
 *  window is known to sit inside the current season (A2 : the year is then just noise). */
export function frDateShortNoYear(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  return new Date(y, m - 1, d).toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
}

/** Whole days from `from` to `to` (ISO), floored, negative if `to` is before `from`. */
export function daysUntil(from: string, to: string): number {
  const a = Date.parse(`${from}T00:00:00`);
  const b = Date.parse(`${to}T00:00:00`);
  return Math.round((b - a) / 86_400_000);
}

/**
 * Intersect an ISO [start, end] range with the season window; null when disjoint.
 * Une période de calendrier vit DANS sa saison (un planning couvre une saison) :
 * les vacances d'été chevauchent la frontière — on n'écrit jamais leurs jours
 * hors-saison dans le calendrier de la saison courante (revue #260 round 1).
 */
export function clampRangeToSeason(
  start: string,
  end: string,
  season: { startDate: string; endDate: string },
): { startDate: string; endDate: string } | null {
  const s = start > season.startDate ? start : season.startDate;
  const e = end < season.endDate ? end : season.endDate;
  return s <= e ? { startDate: s, endDate: e } : null;
}

/** Le lundi de la semaine ISO contenant `iso` (même décalage que la grille du mois). */
export function mondayOf(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  return toISODate(new Date(y, m - 1, d - mondayOffset(new Date(y, m - 1, d))));
}

export interface WeekWindow {
  /** Fenêtre du plan de semaine : lun→dim, clampée à la saison. */
  startDate: string;
  endDate: string;
  /** Le lundi théorique (clé d'affichage stable, même si la saison rogne la fenêtre). */
  monday: string;
}

/**
 * Les semaines pleines (lun→dim) couvrant [start, end], chacune clampée à la
 * saison (P2-5 E1 : « la semaine est l'unité hors socle »). Une semaine
 * entièrement hors saison est omise.
 */
export function weeksCovering(start: string, end: string, season: { startDate: string; endDate: string }): WeekWindow[] {
  const weeks: WeekWindow[] = [];
  for (let monday = mondayOf(start); monday <= end; monday = addDays(monday, 7)) {
    const clamped = clampRangeToSeason(monday, addDays(monday, 6), season);
    if (null !== clamped) {
      weeks.push({ startDate: clamped.startDate, endDate: clamped.endDate, monday });
    }
  }
  return weeks;
}

/** La date `iso` tombe-t-elle Ven/Sam/Dim ? (mondayOffset : Lun=0 … Ven=4, Sam=5, Dim=6). */
function startsLateInWeek(iso: string): boolean {
  const [y, m, d] = iso.split("-").map(Number);
  return mondayOffset(new Date(y, m - 1, d)) >= 4;
}

/**
 * Les semaines à AJUSTER d'une période. Cas particulier VACANCES (holiday) démarrant
 * Ven/Sam/Dim (retour fondateur 2026-07-19) : la semaine partielle de début n'a pas
 * d'impact réel (les vacances tombent le soir venu → l'impact est sur les semaines
 * SUIVANTES) — on l'écarte, l'ajustement commence au lundi suivant. Ex. Toussaint
 * ven 16 oct → 1er nov : on propose les semaines du 19–25 et 26–01, pas le 12–18.
 * Règle réservée aux vacances : fermetures/coupures gardent weeksCovering. La garde
 * `length > 1` évite de renvoyer vide (un week-end de vacances isolé garde sa semaine).
 */
/**
 * P3-13 — UNE SEMAINE EST ACTIONNABLE TANT QU'IL LUI RESTE DES JOURS DEVANT.
 *
 * Besoin fondateur 2026-08-01 : le radar comptait « 0/7 semaines couvertes » alors que 3
 * étaient DERRIÈRE, et la campagne coachs sollicitait pour du passé. « On gère l'avenir,
 * pas le présent. »
 *
 * ⚠ Le premier jet lisait ça comme « la semaine n'a pas COMMENCÉ » (`monday > today`), et
 * la revue #344 a montré que c'est faux et dangereux — « commencé » n'est pas « fini » :
 *  - une fermeture du MERCREDI 11 devenait implanifiable dès le lundi 9, parce que la
 *    puce « + créer » de sa semaine disparaissait alors que la fermeture était encore
 *    entièrement devant (et le DayDialog ne reproduit ces puces que pour les vacances) ;
 *  - une vacance démarrant un samedi ne pouvait plus faire l'objet d'une collecte le lundi
 *    suivant, pour des séances pourtant toutes à venir ;
 *  - une semaine rognée par le début de saison (saison démarrant un mardi) était déclarée
 *    « commencée » le lundi d'avant, alors que la saison n'existait pas encore.
 *
 * D'où le critère : `endDate >= today` — la semaine reste tant qu'un de ses jours n'est pas
 * passé. C'est EXACTEMENT le test que le radar applique déjà au niveau période
 * (`e.endDate >= today`) : une seule notion de « c'est derrière », à deux échelles.
 *
 * On lit donc `endDate` et non `monday` : le lundi dit QUELLE semaine c'est (clé stable),
 * la fin dit s'il reste quelque chose à y faire. Ce sont deux questions différentes.
 *
 * Fonctions PURES, hors React : les tests d'écran mockent les hooks et ne garderaient que
 * le câblage (leçon P2-15 / CLAUDE.md §7.2).
 */
export function isActionableWeek(week: WeekWindow, today: string): boolean {
  return week.endDate >= today;
}

/** Les semaines de `weeks` qu'il reste quelque chose à traiter. @see isActionableWeek */
export function actionableWeeks(weeks: WeekWindow[], today: string): WeekWindow[] {
  return weeks.filter((w) => isActionableWeek(w, today));
}

/**
 * LES SEMAINES QU'UNE PÉRIODE OFFRE ENCORE — le seul point d'entrée pour OFFRIR une
 * semaine, partout (radar, modale du jour, picker).
 *
 * `periodAdjustWeeks` répond à la GÉOMÉTRIE (« quelles semaines cette période couvre-t-elle,
 * la partielle du vendredi écartée »), pas au TEMPS. Les avoir gardées séparées a coûté
 * (revue #344 round 2) : le picker proposait — et cochait — des semaines révolues, dont la
 * création produisait un plan de semaine que le radar filtrait ensuite partout. Un
 * artefact sans carte, sans puce et sans retour possible.
 *
 * Une règle vaut à TOUS ses sites, sinon les écrans se contredisent (CLAUDE.md §7.2 pt 1).
 */
export function periodWeeksToAdjust(
  start: string,
  end: string,
  season: { startDate: string; endDate: string },
  periodType: string | null,
  today: string,
): WeekWindow[] {
  return actionableWeeks(periodAdjustWeeks(start, end, season, periodType), today);
}

export function periodAdjustWeeks(start: string, end: string, season: { startDate: string; endDate: string }, periodType: string | null): WeekWindow[] {
  const weeks = weeksCovering(start, end, season);
  // Garde `weeks[0].startDate === monday` (revue C F3) : on n'écarte QUE si la 1ʳᵉ
  // semaine est PLEINE (lun→dim). Si la saison a rogné son début (vacance à cheval
  // clampée à un début de saison qui tombe Ven/Sam/Dim), ce n'est pas le cas
  // « la vacance commence en fin de semaine » du fondateur : on garde cette semaine
  // en-saison réelle.
  const dropFirst = "holiday" === periodType && weeks.length > 1 && startsLateInWeek(start) && weeks[0].startDate === weeks[0].monday;
  return dropFirst ? weeks.slice(1) : weeks;
}

/**
 * P2-41 — UN SEGMENT hors socle : un bloc de semaines calendaires pleines et contiguës
 * (lun→dim, clamp saison admis aux bords). La semaine simple est le segment de taille 1.
 * `weeks` porte les semaines OFFERTES du segment (jamais les semaines d'un trou franchi par une
 * fusion) ; `monday` est la clé d'affichage stable (le lundi de la 1ʳᵉ semaine).
 */
export interface WeekSegment {
  weeks: WeekWindow[];
  startDate: string;
  endDate: string;
  monday: string;
  /** Semaine d'entame/de fin PARTIELLE de l'événement (taille 1) : libellé « (entamée) ». */
  partial: boolean;
}

const makeSegment = (weeks: WeekWindow[], partial: boolean): WeekSegment => ({
  weeks,
  startDate: weeks[0].startDate,
  endDate: weeks[weeks.length - 1].endDate,
  monday: weeks[0].monday,
  partial,
});

/** L'événement [start, end] couvre-t-il TOUTE la semaine calendaire (lun→dim) ? */
const eventCoversFullWeek = (week: WeekWindow, eventStart: string, eventEnd: string): boolean =>
  eventStart <= week.monday && eventEnd >= addDays(week.monday, 6);

/**
 * P2-41 — LE DÉCOUPAGE en segments, aux ruptures GÉOMÉTRIQUES seulement (calculables des semaines
 * OFFERTES + de la fenêtre de l'événement, AUCUNE règle solveur redérivée) :
 *  (a) une semaine d'entame/de fin que l'événement ne couvre pas ENTIÈREMENT → segment de taille 1
 *      (le run de semaines pleines adjacent forme son propre segment) ;
 *  (b) une discontinuité de l'offre (trou d'exclusion vacances P2-40 ou du filtre temporel) → chaque
 *      run contigu = un segment.
 * Un run de semaines pleines contiguës sans rupture = UN segment multi-semaines.
 */
export function segmentsFromOffer(offered: WeekWindow[], eventStart: string, eventEnd: string): WeekSegment[] {
  const segments: WeekSegment[] = [];
  let run: WeekWindow[] = [];
  const flush = (): void => {
    if (run.length > 0) {
      segments.push(makeSegment(run, false));
      run = [];
    }
  };
  for (let i = 0; i < offered.length; i += 1) {
    const week = offered[i];
    const partial = !eventCoversFullWeek(week, eventStart, eventEnd);
    if (partial) {
      flush();
      segments.push(makeSegment([week], true));
      continue;
    }
    const gapBefore = i > 0 && week.monday !== addDays(offered[i - 1].monday, 7);
    if (gapBefore) {
      flush();
    }
    run.push(week);
  }
  flush();
  return segments;
}

/** Le nombre de semaines calendaires que le segment COUVRE (span lundi→lundi, trou fusionné compris). */
export function segmentWeekCount(segment: WeekSegment): number {
  const lastMonday = segment.weeks[segment.weeks.length - 1].monday;
  return Math.round(daysUntil(segment.monday, lastMonday) / 7) + 1;
}

/**
 * Libellé du segment dans le picker — présentation, pas décision.
 *
 * A2 — quand la saison est connue ET que la fenêtre du segment tient DEDANS, l'année est du bruit
 * (le radar déborde) : on l'omet. Dès que la fenêtre sort de la saison affichée — ou que la saison
 * est inconnue — on garde l'année, qui lève l'ambiguïté. Pure géométrie, aucune règle redérivée.
 */
export function segmentLabel(segment: WeekSegment, season?: { startDate: string; endDate: string }): string {
  const inSeason = undefined !== season && segment.startDate >= season.startDate && segment.endDate <= season.endDate;
  const fmt = inSeason ? frDateShortNoYear : frDateShort;
  const count = segmentWeekCount(segment);
  if (count > 1) {
    return `Semaines du ${fmt(segment.startDate)} au ${fmt(segment.endDate)} — d'un bloc (${count} semaines)`;
  }
  return segment.partial ? `Semaine du ${fmt(segment.startDate)} (entamée)` : `Semaine du ${fmt(segment.startDate)}`;
}

/** Scinde un segment multi-semaines en ses semaines OFFERTES, chacune un segment de taille 1 (pleine). */
export function splitSegment(segment: WeekSegment): WeekSegment[] {
  return segment.weeks.map((w) => makeSegment([w], false));
}

/** Fusionne deux segments ADJACENTS (a avant b) en un bloc unique — le serveur ne borne que
 *  contiguïté + enveloppe, donc la fusion par-dessus une rupture est permise. */
export function mergeSegments(a: WeekSegment, b: WeekSegment): WeekSegment {
  return makeSegment([...a.weeks, ...b.weeks], false);
}

/** Une entrée de couverture groupée PAR ENFANT (P2-41) : un enfant-segment sur N semaines
 *  consécutives devient une seule entrée ; une semaine manquante (child null) reste seule. */
export interface CoverageGroup<C> {
  child: C | null;
  weeks: WeekWindow[];
  startDate: string;
  endDate: string;
  /** Clé de rendu stable : l'id de l'enfant, ou `new-{lundi}` pour une semaine à créer. */
  key: string;
}

/**
 * P2-41 — regroupe des créneaux { semaine, enfant } CONSÉCUTIFS portant le MÊME enfant en une entrée
 * (le libellé « du X au Y » d'un segment) ; une semaine manquante (child null) reste individuelle —
 * le geste « + créer » est ponctuel, à la semaine. Le comptage « N/M semaines couvertes » reste au
 * niveau semaine, en amont : ce regroupement ne touche QUE le rendu.
 */
export function groupCoverageSlots<C extends { id: string }>(slots: { week: WeekWindow; child: C | null }[]): CoverageGroup<C>[] {
  const groups: CoverageGroup<C>[] = [];
  for (const slot of slots) {
    const last = groups[groups.length - 1];
    if (null !== slot.child && undefined !== last && last.child?.id === slot.child.id) {
      last.weeks.push(slot.week);
      last.endDate = slot.week.endDate;
      continue;
    }
    groups.push({
      child: slot.child,
      weeks: [slot.week],
      startDate: slot.week.startDate,
      endDate: slot.week.endDate,
      key: slot.child?.id ?? `new-${slot.week.monday}`,
    });
  }
  return groups;
}

/**
 * P2-38 (prévention) — retire de l'offre les semaines qu'une PLAGE DÉJÀ PLANIFIÉE (servie par le
 * backend) recoupe. Chevauchement inclusif de dates ISO. Les plages viennent du serveur : le front
 * ne calcule PAS quelle fenêtre est planifiée (règle d'or), il soustrait ce qu'on lui sert. Aucune
 * plage ⇒ l'offre est rendue intacte (fail-open : le pire cas retombe sur l'existant, gardé par le 409).
 */
export function subtractPlannedWeeks(offered: WeekWindow[], plannedRanges: { startDate: string; endDate: string }[]): WeekWindow[] {
  if (0 === plannedRanges.length) {
    return offered;
  }
  return offered.filter((w) => !plannedRanges.some((r) => r.startDate <= w.endDate && r.endDate >= w.startDate));
}

/** Une fenêtre de vacances telle que le front la LIT dans les données servies (P2-40). */
export interface HolidayWindow {
  label: string;
  startDate: string;
  endDate: string;
}

/** Un bloc de semaines qu'une (ou des) vacance(s) gouvernent, écarté de l'offre d'une fermeture. */
export interface ExcludedWeekRange {
  startDate: string;
  endDate: string;
  /** Noms des vacances qui couvrent ce bloc (union dédupliquée, ordre rencontré). */
  labels: string[];
}

export interface ClosureWeeksOffer {
  /** Les semaines que la fermeture offre ENCORE — hors de celles gouvernées par les vacances. */
  offered: WeekWindow[];
  /** Les blocs de semaines écartés parce qu'une vacance les gouverne (ligne d'info du picker). */
  excludedRanges: ExcludedWeekRange[];
}

/**
 * P2-40 — L'UNION des fenêtres de vacances SERVIES. Règle d'OFFRE de présentation, ÉCART ASSUMÉ à
 * la règle d'or (frontend.md) : le front dérive ici depuis les données servies (useCalendarEntries
 * + useSchoolHolidays), il ne miroite AUCUN calcul backend et n'est donc pas au registre des
 * miroirs. Par API directe une semaine peut encore naître sous vacances sans plan ; le 409 P2-38
 * reste le filet dès qu'un plan existe (son refus reste affiché par noteWindowConflict).
 *
 * Deux sources, comme le radar :
 *  - les entrées calendrier de type vacances NON ignorées (mère matérialisée ou vacance custom) ;
 *  - le feed des vacances scolaires de la zone, clampé à la saison.
 * Une vacance IGNORÉE (matérialisée puis écartée) ne compte pas.
 */
export function holidayWindows(
  entries: { periodType: string | null; status: string; schoolHolidayId: string | null; title: string; startDate: string; endDate: string }[],
  schoolHolidays: { id: string; label: string; startDate: string; endDate: string }[],
  season: { startDate: string; endDate: string },
): HolidayWindow[] {
  const ignoredHolidayIds = new Set(entries.filter((e) => "ignored" === e.status && null !== e.schoolHolidayId).map((e) => e.schoolHolidayId));
  const windows: HolidayWindow[] = [];
  for (const e of entries) {
    if ("holiday" === e.periodType && "ignored" !== e.status) {
      windows.push({ label: e.title, startDate: e.startDate, endDate: e.endDate });
    }
  }
  for (const h of schoolHolidays) {
    if (ignoredHolidayIds.has(h.id)) {
      continue;
    }
    const clamped = clampRangeToSeason(h.startDate, h.endDate, season);
    if (null !== clamped) {
      windows.push({ label: h.label, startDate: clamped.startDate, endDate: clamped.endDate });
    }
  }
  return windows;
}

/**
 * P2-40 — L'OFFRE de semaines d'une FERMETURE (indispo de gymnase) qui chevauche des vacances :
 * les semaines gouvernées par les vacances sont EXCLUES de l'offre (pas grisées) — le rappel vit
 * déjà dans le planning des vacances. Une semaine est exclue ssi son lundi est OFFERT par des
 * vacances : `periodAdjustWeeks(fenêtre vacances, "holiday")` (donc la règle dropFirst Ven/Sam/Dim
 * joue — une vacance démarrant vendredi n'offre pas sa semaine d'entame, qui reste offerte par la
 * fermeture). Foyer UNIQUE : les sites closure passent par ici ; les autres périodes gardent
 * `periodWeeksToAdjust`.
 */
export function closureWeeksOffer(
  start: string,
  end: string,
  season: { startDate: string; endDate: string },
  today: string,
  holidayWins: HolidayWindow[],
): ClosureWeeksOffer {
  const weeks = periodWeeksToAdjust(start, end, season, "closure", today);
  // lundi → noms des vacances qui l'offrent (dédupliqués, ordre rencontré).
  const labelsByMonday = new Map<string, string[]>();
  for (const hw of holidayWins) {
    for (const w of periodAdjustWeeks(hw.startDate, hw.endDate, season, "holiday")) {
      const existing = labelsByMonday.get(w.monday);
      if (undefined === existing) {
        labelsByMonday.set(w.monday, [hw.label]);
      } else if (!existing.includes(hw.label)) {
        existing.push(hw.label);
      }
    }
  }
  const offered = weeks.filter((w) => !labelsByMonday.has(w.monday));
  // Regrouper les semaines exclues CONTIGUËS (dans l'ordre de la fermeture) en blocs d'info.
  const excludedRanges: ExcludedWeekRange[] = [];
  let run: WeekWindow[] = [];
  let runLabels: string[] = [];
  const flush = (): void => {
    if (0 === run.length) {
      return;
    }
    excludedRanges.push({ startDate: run[0].startDate, endDate: run[run.length - 1].endDate, labels: runLabels });
    run = [];
    runLabels = [];
  };
  for (const w of weeks) {
    const labels = labelsByMonday.get(w.monday);
    if (undefined === labels) {
      flush();
      continue;
    }
    run.push(w);
    for (const l of labels) {
      if (!runLabels.includes(l)) {
        runLabels.push(l);
      }
    }
  }
  flush();
  return { offered, excludedRanges };
}
