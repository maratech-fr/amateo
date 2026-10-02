import { afterEach, describe, expect, it } from "vitest";

import { applyServerToday, setTodayOverride, toISODate, todayISO } from "./clock";

afterEach(() => {
  setTodayOverride(null);
  applyServerToday(null);
});

describe("clock — le « aujourd'hui » du front", () => {
  it("sans override, rend la date réelle du jour", () => {
    expect(todayISO()).toBe(toISODate(new Date()));
  });

  it("l'override fixe la date, et null la relâche", () => {
    setTodayOverride("2026-12-20");
    expect(todayISO()).toBe("2026-12-20");

    setTodayOverride(null);
    expect(todayISO()).toBe(toISODate(new Date()));
  });

  // Le point qui compte : les filtres du cockpit comparent des CHAÎNES ISO. Une valeur
  // malformée qui passerait ferait des comparaisons lexicographiques silencieusement
  // fausses (« hier » > « 2026-… » est VRAI) — un écran qui ment vaut moins qu'un
  // paramètre sans effet.
  it("ignore une valeur malformée plutôt que de la propager", () => {
    setTodayOverride("hier");
    expect(todayISO()).toBe(toISODate(new Date()));

    setTodayOverride("2026-13");
    expect(todayISO()).toBe(toISODate(new Date()));
  });

  // La FORME ne suffit pas (revue #344) : `2026-13-01` passait la regex et, triant après
  // toute date réelle, vidait le radar — « Rien à l'horizon » sur un club qui a du travail.
  it("refuse une date bien formée mais IMPOSSIBLE", () => {
    setTodayOverride("2026-13-01"); // 13ᵉ mois
    expect(todayISO()).toBe(toISODate(new Date()));

    setTodayOverride("2026-02-31"); // 31 février — le constructeur le reporterait au 3 mars
    expect(todayISO()).toBe(toISODate(new Date()));

    setTodayOverride("2026-00-10"); // mois zéro
    expect(todayISO()).toBe(toISODate(new Date()));
  });

  it("accepte un 29 février d'année bissextile (et refuse celui d'une année commune)", () => {
    setTodayOverride("2028-02-29");
    expect(todayISO()).toBe("2028-02-29");

    setTodayOverride("2027-02-29");
    expect(todayISO()).toBe(toISODate(new Date()));
  });

  // P4-16/P2-4 — la date SERVEUR d'un club démo (/api/me → simulatedToday).
  it("applyServerToday pose la date du club démo, et null la relâche", () => {
    applyServerToday("2026-12-15");
    expect(todayISO()).toBe("2026-12-15");

    applyServerToday(null); // vrai club : simulatedToday null → horloge réelle
    expect(todayISO()).toBe(toISODate(new Date()));
  });

  it("applyServerToday ignore une date irréelle, comme l'override de dev", () => {
    applyServerToday("2026-02-31");
    expect(todayISO()).toBe(toISODate(new Date()));
  });

  it("toISODate rend la date LOCALE (pas le décalage UTC de toISOString)", () => {
    // 1er janvier 00:30 heure locale : toISOString basculerait au 31 décembre sur un
    // fuseau à l'est de Greenwich.
    expect(toISODate(new Date(2026, 0, 1, 0, 30))).toBe("2026-01-01");
  });
});
