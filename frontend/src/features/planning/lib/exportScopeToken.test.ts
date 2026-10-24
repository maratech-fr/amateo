import { describe, expect, it } from "vitest";

import { exportScopeToken } from "./exportScopeToken";

/**
 * Chantier E — SYNCHRO du nom de fichier, côté FRONT. La page qui attend l'export PDF ne
 * télécharge QUE l'URL dont le nom de fichier se termine par le jeton de SA demande
 * (`url.endsWith(exportScopeToken(...))`, `queries.ts` `useScheduleExport`) : sans ça, deux
 * exports simultanés du même planning (autre onglet, autre portée, autre choix de coachs)
 * se voleraient leur fichier. Le jeton DOIT refléter EXACTEMENT le nom que le back écrit
 * (`PdfGenerator::generate` → `schedule-<id>-<scope>-coaches.pdf` / `schedule-<id>-<scope>.pdf`,
 * gardé l'un l'autre par `backend/.../PdfExportFilenameTest.php`).
 *
 * ⚠ Fonction PROPOSÉE (option A, TANK écrit AVANT NEO) : un foyer PUR `exportScopeToken(venueId,
 * showCoaches): string` dans `lib/` (sœur de `exportPrefs.ts`), que `queries.ts` IMPORTE au lieu
 * de reconstruire le jeton en ligne. NEO peut adapter mécaniquement le nom/emplacement ; la RÈGLE
 * (portée `-all`/`-<8 car.>`, suffixe `-coaches` ssi coché, extension `.pdf`) ne bouge pas.
 */
describe("exportScopeToken — synchro avec le nom de fichier du back", () => {
  it("coché porte -coaches, décoché non (portée « tous les gymnases »)", () => {
    expect(exportScopeToken(null, true)).toBe("-all-coaches.pdf");
    expect(exportScopeToken(null, false)).toBe("-all.pdf");
  });

  it("un gymnase distingue aussi le fichier par ses 8 premiers caractères", () => {
    expect(exportScopeToken("venue-1234-xyz", false)).toBe("-venue-12.pdf");
    expect(exportScopeToken("venue-1234-xyz", true)).toBe("-venue-12-coaches.pdf");
  });

  it("le run COCHÉ n'accepte pas l'URL d'un run décoché, mais accepte la sienne", () => {
    const uncheckedUrl = "/exports/schedule-s1-all.pdf";
    expect(uncheckedUrl.endsWith(exportScopeToken(null, true))).toBe(false);
    expect(uncheckedUrl.endsWith(exportScopeToken(null, false))).toBe(true);
  });

  it("le run DÉCOCHÉ n'accepte pas l'URL d'un run coché, mais accepte la sienne", () => {
    const checkedUrl = "/exports/schedule-s1-all-coaches.pdf";
    expect(checkedUrl.endsWith(exportScopeToken(null, false))).toBe(false);
    expect(checkedUrl.endsWith(exportScopeToken(null, true))).toBe(true);
  });
});
