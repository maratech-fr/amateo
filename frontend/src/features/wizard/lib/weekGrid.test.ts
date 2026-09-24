import { describe, expect, it } from "vitest";

import { END_MIN, gridTemplateColumns, rows, START_MIN, STEP, WEEK } from "./weekGrid";

/**
 * P4-37 — LA GRILLE MONTRE TOUT CE QUI EXISTE EN BASE.
 *
 * Deux bornes cachaient des données que l'API acceptait et que le solveur recevait déjà :
 * la grille s'arrêtait à 22h, et au samedi. Un créneau 22h-23h ou un dimanche était donc
 * servi au planning sans jamais pouvoir être posé ni relu — au point que `PeriodVenues`
 * avait dû se doter d'un filet pour au moins les rendre supprimables.
 *
 * Géométrie PARTAGÉE par trois grilles (disponibilités, réservations, structure de
 * période) : ce fichier est le seul endroit où l'épingler une fois pour les trois.
 */
describe("weekGrid — les bornes de la grille hebdomadaire", () => {
  it("couvre 8h → 23h", () => {
    expect(START_MIN).toBe(8 * 60);
    expect(END_MIN).toBe(23 * 60);
    // Une ligne par quart d'heure, dernière ligne commençant à 22h45.
    expect(rows).toHaveLength((END_MIN - START_MIN) / STEP);
    expect(rows[rows.length - 1]).toBe(22 * 60 + 45);
  });

  it("couvre les SEPT jours, dimanche compris", () => {
    expect(WEEK.map((d) => d.n)).toEqual([1, 2, 3, 4, 5, 6, 7]);
    expect(WEEK.at(-1)?.label).toBe("Dim");
  });

  it("déclare autant de colonnes que de jours (la gouttière en plus)", () => {
    // Le gabarit dérive de WEEK : une colonne oubliée écraserait les créneaux du dernier
    // jour sur celui d'avant, sans erreur visible.
    expect(gridTemplateColumns).toContain(`repeat(${WEEK.length},`);
  });
});
