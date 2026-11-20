import { describe, expect, it } from "vitest";

import { COACH_UNKNOWN, coachFullName, coachNameParts, coachShortName } from "./coachName";
import { isManagementRole } from "./roles";

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

/** La forme courte qui laisse deux coachs lisibles dans une case de planning, sans jamais perdre le coach. */
describe("coachShortName — « Prénom N. »", () => {
  it("réduit le nom de famille à son initiale, en majuscule", () => {
    expect(coachShortName({ firstName: "Alex", lastName: "Dupont" })).toBe("Alex D.");
    expect(coachShortName({ firstName: " Alex ", lastName: " dupont " })).toBe("Alex D.");
  });

  it("une moitié manquante ne laisse ni point ni espace parasite", () => {
    expect(coachShortName({ firstName: "Alex", lastName: null })).toBe("Alex");
    expect(coachShortName({ firstName: null, lastName: "Dupont" })).toBe("D.");
  });

  it("un coach absent ou sans nom rend le libellé commun", () => {
    expect(coachShortName(null)).toBe(COACH_UNKNOWN);
    expect(coachShortName({ firstName: "", lastName: "" })).toBe(COACH_UNKNOWN);
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
 * Deux rôles seulement (admin, member) : seul le Gestionnaire se voit offrir les gestes de
 * gestion. Une ancienne valeur (`owner`, convertie en base) ne rouvre jamais la main à l'écran.
 * Jumeau serveur : `backend/tests/CrossStack/ManagementRolesMatchBackendTest.php` (le rôle de
 * gestion du front est le seul que `ClubRole::isManager()` reconnaît).
 */
describe("rôles de gestion (miroir d'affichage, deux rôles)", () => {
  it("seul le Gestionnaire (admin) gère", () => {
    expect(isManagementRole("admin")).toBe(true);
    expect(isManagementRole("member")).toBe(false);
  });

  it("refuse toute autre valeur — ancienne (owner, editor, viewer) ou inventée — et l'absence de rôle", () => {
    for (const role of ["owner", "editor", "viewer", "coach"]) {
      expect(isManagementRole(role)).toBe(false);
    }
    expect(isManagementRole(null)).toBe(false);
    expect(isManagementRole(undefined)).toBe(false);
  });
});
