import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Uniformité des écrans (série « uniformité », PR 6/7, 2026-10-01) — norme fondateur : les jours
 * de la semaine ont UN SEUL foyer de libellés, `shared/lib/days.ts` (`DAYS` court, `dayLabelLong`/
 * `dayLabelLongCap` long), et le sélecteur multi-jours UNIQUE est la primitive partagée
 * `shared/components/ui/day-multi-picker.tsx`. Une table de jours RECOPIÉE dans une feature est le
 * germe exact du bug D-22 (une copie « s'arrêtait au samedi », planning à six colonnes donné pour
 * complet) et de la dérive d'uniformité (chaque écran son « Lun…Dim »).
 *
 * Ce garde STATIQUE (modèle `pillPrimitiveGuard`/`deleteConfirmGuard`/`textOpacityGuard`) rougit dès
 * qu'un fichier de `src/features/**` ou `src/app/**` porte une TABLE LOCALE de jours — signature :
 * trois libellés de jours CONSÉCUTIFS en littéraux (court « Lun »/« Mar »/« Mer », long minuscule
 * « lundi »/« mardi »/« mercredi », ou long capitalisé « Lundi »/« Mardi »/« Mercredi »). Un seul
 * nom de jour isolé (« le samedi » dans une phrase) ne déclenche pas : il faut le TRIPLET d'une table.
 *
 * PORTÉE délibérée :
 *  - `shared/lib/days.ts` est LE foyer : hors scan (on ne scanne que features/ et app/).
 *  - Les lettres seules d'un en-tête de calendrier (`["L","M","M","J","V","S","D"]`, `MonthCalendar`)
 *    ne sont PAS une table de libellés de jours (pas de « Lun »/« lundi ») — non attrapées, voulu.
 *  - Les `*.test.ts(x)` sont EXCLUS (ils peuvent asserter des libellés littéraux).
 *  - Une table LÉGITIME hors sélecteur (formatage d'une date affichée, jamais un picker) entre dans
 *    EXEMPTIONS avec sa raison.
 *
 * Regex LITTÉRALES uniquement (pas de `new RegExp(<variable>)`, gate Semgrep).
 */
const ROOTS = [join(import.meta.dirname, "..", "features"), join(import.meta.dirname, "..", "app")];

interface Exemption {
  path: string;
  reason: string;
}

// Aucune exemption à ce jour : les six tables locales (wizard, matchs, doléances, club, gymnases,
// créneaux idéaux) ont toutes été absorbées par `shared/lib/days.ts` (PR 6/7). Une table de
// FORMATAGE légitime (pas un sélecteur) s'ajouterait ici, nommée et motivée.
const EXEMPTIONS: Exemption[] = [];

// Trois formes d'une table de jours, chacune repérée par son TRIPLET consécutif (littéral).
const SHORT_TABLE = [/"Lun"/, /"Mar"/, /"Mer"/];
const LONG_LOWER_TABLE = [/"lundi"/, /"mardi"/, /"mercredi"/];
const LONG_CAP_TABLE = [/"Lundi"/, /"Mardi"/, /"Mercredi"/];

/** Un fichier porte une table LOCALE de jours s'il contient l'un des trois triplets au complet. */
function hasLocalDayTable(source: string): boolean {
  return [SHORT_TABLE, LONG_LOWER_TABLE, LONG_CAP_TABLE].some((triplet) => triplet.every((re) => re.test(source)));
}

function isExempt(rel: string): boolean {
  return EXEMPTIONS.some((e) => rel.includes(e.path));
}

function sourceFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...sourceFiles(full));
    } else if (/\.tsx?$/.test(entry.name) && !/\.test\.tsx?$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

describe("Tables de jours locales → shared/lib/days + DayMultiPicker (série uniformité, PR 6/7)", () => {
  it("aucune table de jours recopiée dans features/ ou app/, hors exemption nominative", () => {
    const offenders: string[] = [];
    for (const root of ROOTS) {
      for (const file of sourceFiles(root)) {
        const rel = file.slice(file.indexOf("/src/") + 5);
        if (isExempt(rel)) continue;
        if (hasLocalDayTable(readFileSync(file, "utf8"))) {
          offenders.push(rel);
        }
      }
    }
    expect(
      offenders,
      "Table de jours recopiée : utiliser `DAYS`/`dayLabelLong`/`dayLabelLongCap`/`dayLabelShort` de shared/lib/days.ts, et le sélecteur multi-jours DayMultiPicker. Un foyer unique, jamais une copie (germe du bug D-22). Un formatage légitime (pas un sélecteur) entre dans EXEMPTIONS.",
    ).toEqual([]);
  });

  it("le garde mord : un triplet de jours est repéré, un nom de jour isolé ne l'est pas", () => {
    const shortTable = 'const DAYS = [{ n: 1, label: "Lun" }, { n: 2, label: "Mar" }, { n: 3, label: "Mer" }];';
    const longLower = 'const D = ["", "lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche"];';
    const longCap = 'const D: Record<number, string> = { 1: "Lundi", 2: "Mardi", 3: "Mercredi" };';
    const isolated = 'const msg = `Pas de match le samedi à ${venue}.`;';
    const calendarLetters = 'const WEEKDAYS = ["L", "M", "M", "J", "V", "S", "D"];';

    expect(hasLocalDayTable(shortTable)).toBe(true);
    expect(hasLocalDayTable(longLower)).toBe(true);
    expect(hasLocalDayTable(longCap)).toBe(true);
    expect(hasLocalDayTable(isolated)).toBe(false);
    expect(hasLocalDayTable(calendarLetters)).toBe(false);
  });
});
