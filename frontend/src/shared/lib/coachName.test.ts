import { describe, expect, it } from "vitest";

import { COACH_UNKNOWN, coachFullName, coachNameParts } from "./coachName";
import { isManagementRole, MANAGEMENT_ROLES } from "./roles";

/**
 * D-33 — trois formatages coexistaient, dont deux SANS `.trim()` : un coach sans nom de
 * famille s'affichait « Alex » dans le wizard et « Alex␣ » sur le planning et le radar
 * de conflits — espace final visible en badge et en infobulle. Et trois replis différents
 * désignaient le même vide.
 */
describe("nom affiché d'un coach (foyer unique, D-33)", () => {
  it("ne laisse pas d'espace parasite quand une moitié manque", () => {
    expect(coachFullName({ firstName: "Alex", lastName: null })).toBe("Alex");
    expect(coachFullName({ firstName: null, lastName: "Dupont" })).toBe("Dupont");
    expect(coachFullName({ firstName: "Alex", lastName: "Dupont" })).toBe("Alex Dupont");
  });

  it("un coach absent ou sans nom rend UN seul libellé", () => {
    expect(coachFullName(null)).toBe(COACH_UNKNOWN);
    expect(coachFullName({ firstName: "", lastName: "" })).toBe(COACH_UNKNOWN);
  });
});

/**
 * D5 (lot 2) — la tuile du planning coupe le nom du coach en deux lignes (prénom puis nom plus
 * petit) quand elle a la hauteur : le foyer unique fournit les deux moitiés, trimmées, sans
 * décider de la mise en page.
 */
describe("coachNameParts — les deux moitiés du nom (D5)", () => {
  it("rend prénom et nom trimmés séparément", () => {
    expect(coachNameParts({ firstName: "Marie", lastName: "Durand" })).toEqual({ first: "Marie", last: "Durand" });
    expect(coachNameParts({ firstName: " Marie ", lastName: " Durand " })).toEqual({ first: "Marie", last: "Durand" });
  });

  it("laisse `last` vide quand le coach n'a pas de nom de famille", () => {
    expect(coachNameParts({ firstName: "Alex", lastName: null })).toEqual({ first: "Alex", last: "" });
  });

  it("un coach absent ou totalement vide retombe sur le libellé d'inconnu", () => {
    expect(coachNameParts(null)).toEqual({ first: COACH_UNKNOWN, last: "" });
    expect(coachNameParts({ firstName: "", lastName: "" })).toEqual({ first: COACH_UNKNOWN, last: "" });
  });
});

/**
 * D-32 — la liste des rôles de gestion était réécrite dans deux écrans. Le sens dangereux
 * est l'AJOUT : un rôle ajouté côté serveur et oublié ici, et les écrans continuent de
 * masquer une capacité que le backend autorise — invisible, sans erreur.
 */
describe("rôles de gestion (miroir d'affichage, D-32)", () => {
  it("reflète MANAGEMENT_ROLES du backend", () => {
    expect([...MANAGEMENT_ROLES]).toEqual(["owner", "admin"]);
    expect(isManagementRole("owner")).toBe(true);
    expect(isManagementRole("admin")).toBe(true);
  });

  it("refuse tout autre rôle, y compris l'absence de rôle", () => {
    expect(isManagementRole("coach")).toBe(false);
    expect(isManagementRole(null)).toBe(false);
    expect(isManagementRole(undefined)).toBe(false);
  });
});
