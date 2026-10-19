import { describe, expect, it } from "vitest";

import {
  ADMIN_TABS,
  DEFAULT_SUBTAB,
  DEFAULT_TAB,
  resolveActiveSubTab,
  resolveActiveTab,
} from "./tabsConfig";
import { ADMIN_DASHBOARD_TEXTS } from "../texts/dashboard";

/**
 * La charpente des 5 onglets de la console superadmin (réorganisation PR1) et le report
 * des anciens liens. Promesses protégées, au plus bas niveau (fonctions pures de config,
 * aucun DOM) :
 *  - 5 onglets, DANS l'ordre `clubs · statistiques · sante · incidents · exploitation`,
 *    défaut `clubs` ;
 *  - Incidents a ses 4 sous-onglets `signalements` (défaut) · `errors` · `messenger` ·
 *    `audit` (dernier) ;
 *  - un ancien lien (`?tab=` ou `localStorage`) retombe sur le bon nouvel onglet, et le
 *    sous-onglet d'un ancien « journaux » est conservé.
 */
describe("tabsConfig — charpente des 5 onglets", () => {
  it("expose exactement 5 onglets dans l'ordre, défaut clubs", () => {
    expect(ADMIN_TABS.map((t) => t.id)).toEqual([
      "clubs",
      "statistiques",
      "sante",
      "incidents",
      "exploitation",
    ]);
    expect(DEFAULT_TAB).toBe("clubs");
  });

  it("nomme Santé et Incidents (les deux onglets porteurs de pastille)", () => {
    const byId = (id: string) => ADMIN_TABS.find((t) => t.id === id);
    expect(byId("sante")?.label).toBe(ADMIN_DASHBOARD_TEXTS.tabSante);
    expect(byId("incidents")?.label).toBe(ADMIN_DASHBOARD_TEXTS.tabIncidents);
  });

  it("Incidents porte 4 sous-onglets : signalements (défaut) · errors · messenger · audit (dernier)", () => {
    const incidents = ADMIN_TABS.find((t) => t.id === "incidents");
    expect(incidents).toBeDefined();
    expect(incidents?.subTabs?.map((s) => s.id)).toEqual([
      "signalements",
      "errors",
      "messenger",
      "audit",
    ]);
    // Le sous-onglet par défaut est le premier (signalements), jamais le dernier (audit).
    expect(DEFAULT_SUBTAB).toBe("signalements");
  });
});

describe("resolveActiveTab — report des anciens liens vers les 5 nouveaux onglets", () => {
  it("laisse passer les 5 ids canoniques", () => {
    for (const id of ["clubs", "statistiques", "sante", "incidents", "exploitation"]) {
      expect(resolveActiveTab(id, null)).toBe(id);
    }
  });

  it("reporte chaque ancien onglet sur son nouveau foyer", () => {
    expect(resolveActiveTab("vue-densemble", null)).toBe("statistiques");
    expect(resolveActiveTab("infrastructure", null)).toBe("sante");
    expect(resolveActiveTab("jobs", null)).toBe("sante");
    expect(resolveActiveTab("referentiels", null)).toBe("sante");
    expect(resolveActiveTab("demos", null)).toBe("clubs");
    expect(resolveActiveTab("signalements", null)).toBe("incidents");
    expect(resolveActiveTab("journaux", null)).toBe("incidents");
  });

  it("un id inconnu ou absent retombe sur clubs", () => {
    expect(resolveActiveTab("n-existe-pas", null)).toBe("clubs");
    expect(resolveActiveTab(null, null)).toBe("clubs");
  });

  it("l'URL prime sur le localStorage, le localStorage sert de repli (ancien compris)", () => {
    expect(resolveActiveTab("sante", "clubs")).toBe("sante");
    // Repli localStorage : un ancien id stocké est reporté comme un ancien id d'URL.
    expect(resolveActiveTab(null, "vue-densemble")).toBe("statistiques");
    expect(resolveActiveTab(null, "journaux")).toBe("incidents");
  });
});

describe("resolveActiveSubTab — sous-onglet Incidents, report inclus", () => {
  it("défaut signalements sous Incidents", () => {
    expect(resolveActiveSubTab("incidents", null)).toBe("signalements");
  });

  it("laisse passer un sous-onglet Incidents canonique", () => {
    for (const sub of ["signalements", "errors", "messenger", "audit"]) {
      expect(resolveActiveSubTab("incidents", sub)).toBe(sub);
    }
  });

  it("conserve le sous-onglet d'un ancien « journaux » (audit/messenger/errors)", () => {
    expect(resolveActiveSubTab("journaux", "audit")).toBe("audit");
    expect(resolveActiveSubTab("journaux", "messenger")).toBe("messenger");
    expect(resolveActiveSubTab("journaux", "errors")).toBe("errors");
  });

  it("un ancien « journaux » SANS sous-onglet (ou invalide) retombe sur signalements", () => {
    expect(resolveActiveSubTab("journaux", null)).toBe("signalements");
    expect(resolveActiveSubTab("journaux", "n-existe-pas")).toBe("signalements");
  });

  it("l'ancien « signalements » arrive sur le sous-onglet signalements", () => {
    expect(resolveActiveSubTab("signalements", null)).toBe("signalements");
  });
});
