import { DAYS } from "./days";

/**
 * Shared weekly-grid geometry for the wizard slot grids (Gymnases availability +
 * Réserver). Single source of truth so the two grids can never drift apart.
 *
 * Qui consomme quoi (vérifié le 2026-08-21) : **la géométrie VERTICALE** (`START_MIN`,
 * `END_MIN`, `STEP`, `ROW_H`) n'est lue que par ces deux grilles ; `WEEK` (les jours) l'est
 * aussi par `steps/PeriodVenues.tsx` et `steps/VenuesStep.tsx`. Les grilles du module
 * matchs et celle du PLANNING n'en dépendent pas — `features/planning/WeekGrid.tsx` a sa
 * propre géométrie et dérive ses bornes des créneaux visibles (`planning/lib/grid.ts`).
 * Les deux modèles coexistent volontairement : le planning MASQUE les jours vides, ce qu'un
 * éditeur de créneaux ne peut pas se permettre (P4-37).
 */
export const START_MIN = 8 * 60; // 08:00
export const END_MIN = 23 * 60; // 23:00 — un créneau 22h-23h existe en base et restait INVISIBLE (P4-37)
export const STEP = 15; // 15-minute graduation (slots start/last on quarter-hours)
export const ROW_H = 11; // px per 15 min (~44px/hour)
// Les SEPT jours (P4-37). La grille s'arrêtait au samedi : un créneau du dimanche était
// alors servi au solveur sans jamais pouvoir être posé ni relu ici — le même défaut que la
// borne de 22h, sur l'autre axe. `PeriodVenues` avait dû se doter d'un filet
// (`offGridSlots`) pour au moins le rendre supprimable ; on traite désormais la cause.
// ⚠ `ReservationGrid` faisait `WEEK.findIndex(d => d.n === slot.dayOfWeek)` : un dimanche y
// donnait -1, donc une colonne fausse. Corrigé du même coup.
export const WEEK = DAYS;

export const rows = Array.from({ length: (END_MIN - START_MIN) / STEP }, (_, i) => START_MIN + i * STEP);

export const gridTemplateColumns = `3rem repeat(${WEEK.length}, minmax(3rem, 1fr))`;
export const gridTemplateRows = `1.5rem repeat(${rows.length}, ${ROW_H}px)`;
