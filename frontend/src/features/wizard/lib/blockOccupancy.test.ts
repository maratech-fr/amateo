import { describe, expect, it } from "vitest";

import { type BlockLike, completeBlockOnCase } from "./blockOccupancy";

/**
 * #12 (retours Rillieux, décision fondateur 2026-10-13 « groupe mutualisé = une équipe ») — le FOYER
 * de la dérivation « cette case est-elle BLOC-COMPLÈTE ? » côté wizard : les équipes réservées sur la
 * case sont EXACTEMENT les membres d'un bloc ⇒ le groupe occupe la case en entier (UN occupant), elle
 * s'affiche « Complet · Groupe … » et jamais « 2/1 ». MIROIR DÉCLARÉ de la règle (b) réciproque de
 * `App\Service\ReservationGroupOccupancy` (`reservedSetMatchesABlock` / `matchingBlock`), gardé par
 * `blockOccupancy.parity.test.ts` + `ReservationBlockOccupancyMirrorParityTest.php`.
 *
 * Le plafond PAR ÉQUIPE (règle (d)) n'est PAS ici : il reste au serveur.
 */
describe("completeBlockOnCase — une case bloc-complète = le groupe (1 occupant, pas 2)", () => {
  const block = (id: string, teamIds: string[]): BlockLike => ({ id, teamIds });

  it("rend le bloc quand les équipes réservées sont EXACTEMENT ses membres (2 membres)", () => {
    const g = block("g", ["u15f", "u18f"]);
    expect(completeBlockOnCase(["u15f", "u18f"], [g])).toBe(g);
  });

  it("est insensible à l'ORDRE des équipes réservées (c'est un ensemble)", () => {
    const g = block("g", ["u15f", "u18f"]);
    expect(completeBlockOnCase(["u18f", "u15f"], [g])).toBe(g);
  });

  it("rend null sur un bloc PARTIEL (un seul membre réservé) — règle actuelle, équipe individuelle", () => {
    expect(completeBlockOnCase(["u15f"], [block("g", ["u15f", "u18f"])])).toBeNull();
  });

  it("rend null en cas MIXTE — les membres du bloc PLUS une équipe en plus (la case porte autre chose que le groupe)", () => {
    expect(completeBlockOnCase(["u15f", "u18f", "sm1"], [block("g", ["u15f", "u18f"])])).toBeNull();
  });

  it("rend null quand aucun bloc n'est en portée", () => {
    expect(completeBlockOnCase(["u15f", "u18f"], [])).toBeNull();
  });

  it("rend null sur une case vide", () => {
    expect(completeBlockOnCase([], [block("g", ["u15f", "u18f"])])).toBeNull();
  });

  it("discrimine le BON bloc quand plusieurs existent", () => {
    const g1 = block("g1", ["u15f", "u18f"]);
    const g2 = block("g2", ["sm3", "sm4"]);
    expect(completeBlockOnCase(["sm3", "sm4"], [g1, g2])).toBe(g2);
  });

  it("gère un bloc à trois membres (complet si les trois réservés, sinon null)", () => {
    const trio = block("trio", ["u11a", "u11b", "u11c"]);
    expect(completeBlockOnCase(["u11a", "u11b", "u11c"], [trio])).toBe(trio);
    expect(completeBlockOnCase(["u11a", "u11b"], [trio])).toBeNull();
  });
});
