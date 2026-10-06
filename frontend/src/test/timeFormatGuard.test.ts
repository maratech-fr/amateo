import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Format horaire & durée UNIQUES (uniformité des écrans, GO fondateur 2026-09-30) — garde
 * STATIQUE sur le modèle de `pageHeaderGuard.test.ts` / `textOpacityGuard.test.ts`.
 *
 * Deux normes tranchées sur captures A/B :
 *   • N3 — une HEURE affichée s'écrit « 21:00 », via le foyer unique `shared/lib/time.formatMinutes`
 *     (jamais « 21h », « 09h », « 9h05 »).
 *   • Une DURÉE s'écrit « 1h30 » (compact), via le foyer unique `shared/lib/duration.formatDuration`
 *     (jamais la forme aérée « 1 h 30 » de feu `time.formatDurationMinutes`).
 *
 * Le risque : un écran REFABRIQUE une heure ou une durée à la main (`${h}h`, `.padStart(2,"0")}h`,
 * `.replace(":","h")`, `+ "h"`, forme aérée `${h} h ${m}`) au lieu de passer par le foyer. jsdom n'a
 * pas de moteur de rendu — cette garde grep les SOURCES, elle mord dans Vitest avant tout scan visuel.
 *
 * PORTÉE = sources `.ts`/`.tsx` de `src/`, `*.test.*` EXCLUS (ce fichier est un `.test.ts`).
 * Les DEUX FOYERS (`shared/lib/time.ts`, `shared/lib/duration.ts`) sont hors portée : ce sont EUX qui
 * ont le droit — et le devoir — de fabriquer le caractère `h` / le `:` une seule fois.
 *
 * ⚠ CHOIX documenté : on ne police PAS la forme AÉRÉE isolée « ${x} h » (un `} h` sans minutes
 * accolées ni `padStart`), car c'est la forme des métriques d'ATTENTE de la console superadmin
 * (`CapacitySection`/`AdminDashboardPage` : « 3 h » de latence, « 2 j »), et des heures cumulées d'un
 * gymnase (`venueStats.formatHours` : « 7,5 h ») — des DURÉES ÉCOULÉES/CUMULÉES, jamais une heure de
 * pendule ni une durée de créneau. Ce qui est policé, ci-dessous, ne les atteint pas.
 */
const SRC_ROOT = join(import.meta.dirname, "..");

// Les deux foyers : hors portée par construction (ils fabriquent le format, une seule fois).
const HELPER_FILES = ["shared/lib/time.ts", "shared/lib/duration.ts"];

// Exemptions nominatives — un fichier autorisé à fabriquer une heure/durée à la main, AVEC sa raison.
// Idéalement VIDE : si vous en ajoutez une, dites pourquoi le foyer ne convient pas.
const EXEMPTIONS: { file: string; reason: string }[] = [];

// UXC-30 — une DURÉE DE CRÉNEAU affichée passe par `formatDuration` (« 30 min », « 1h30 »), jamais
// un `${minutes} min` fabriqué à la main. Mais « N min » reste la forme LÉGITIME d'une durée de
// TRAJET (estimation aller approchée) ou de LATENCE/ÉCOULÉE — même famille que `venueStats.formatHours`
// et les métriques de la console déjà carve-out ci-dessus, et hors de la norme de durée de créneau
// (changer « 90 min » de trajet en « 1h30 » serait un changement de sens, pas d'uniformité). Ces
// fichiers sont donc exemptés du SEUL motif « N min ».
const MIN_PATTERN = "durée de créneau collée à « min » (`} min`)";
const MIN_FAMILY_EXEMPT = [
  "features/matches/OpponentsPage.tsx",
  "features/matches/AwayTravelChip.tsx",
  "features/matches/AwayFixtureCard.tsx",
  "features/matches/lib/awayColumn.ts",
  "features/matches/lib/awayTravelTitle.ts",
  "features/wizard/steps/TravelMatrixModal.tsx",
  "features/admin/AdminDashboardPage.tsx",
  "shared/components/ui/dev-incident-details.tsx",
];

/** Les motifs interdits d'heure/durée fabriquée à la main. */
const BANNED: { name: string; re: RegExp }[] = [
  // `${hour}h`, `${hour}h${m}` — l'heure/durée collée au caractère « h » (frClock, compact main).
  { name: "heure/durée collée à « h » (`}h`)", re: /\}h(?![A-Za-z])/ },
  // `${String(n).padStart(2,"0")}h` / `…} h` — graduation d'axe « 09h », heure « 08 h » à la main.
  { name: "nombre zéro-paddé suivi de « h » (`padStart(2…)}h`)", re: /padStart\(\s*2[^)]*\)\}\s*h(?![A-Za-z])/ },
  // `+ "h"` / `"h" +` — concaténation d'un « h » horaire.
  { name: 'concaténation de « h » (`+ "h"`)', re: /(\+\s*["'`]h["'`])|(["'`]h["'`]\s*\+)/ },
  // `.replace(":", "h")` — « 18:30 » retourné en « 18h30 ».
  { name: 'remplacement « : » → « h » (`replace(":", "h")`)', re: /replace\(\s*["'`]:["'`]\s*,\s*["'`]h["'`]\s*\)/ },
  // `${h} h ${m}` — la forme AÉRÉE avec minutes (feu `formatDurationMinutes`).
  { name: "durée aérée avec minutes (`} h ${`)", re: /\}\s+h\s+\$\{/ },
  // `${minutes} min` / `{slot.durationMinutes} min)` — une durée de créneau collée à « min » à la
  // main (UXC-30). La négation `(?![A-Za-z=])` épargne l'attribut HTML `min={…}` (borne d'un
  // `<input type="date"/number">`) et le mot `minutes`.
  { name: MIN_PATTERN, re: /\}\s*min(?![A-Za-z=])/ },
];

function isExcluded(rel: string): boolean {
  return HELPER_FILES.includes(rel) || EXEMPTIONS.some((e) => rel === e.file || rel.startsWith(e.file));
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

describe("Format horaire/durée unique — garde statique", () => {
  // Contrôle POSITIF/NÉGATIF : la garde MORD sur un cas fabriqué à la main, et ÉPARGNE le foyer +
  // les métriques écoulées de la console. Sans lui, une regex trop laxiste (ou morte) passerait vert.
  it("les motifs mordent le fabriqué-main et épargnent le légitime", () => {
    const bad = [
      "`${hour}h`",
      "`${hour}h${m}`",
      '`${String(h).padStart(2, "0")}h`',
      '`${String(h).padStart(2, "0")} h`',
      'label + "h"',
      'toHourMinute(t).replace(":", "h")',
      "`${h} h ${String(m).padStart(2, \"0\")}`",
      "`${slot.durationMinutes} min`",
      "({sl.durationMinutes} min)",
    ];
    for (const sample of bad) {
      expect(BANNED.some((b) => b.re.test(sample)), `devrait mordre : ${sample}`).toBe(true);
    }
    const good = [
      "formatMinutes(total)", // le foyer horaire
      "formatDuration(minutes)", // le foyer durée
      '`${rounded.toLocaleString("fr-FR")} h`', // heures cumulées d'un gymnase (venueStats)
      '`${Math.round(minutes / 60)} h`', // latence écoulée console (aérée isolée, choix documenté)
      '`${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`', // « HH:MM » via padStart+« : »
      '`${date.getFullYear()}-${String(month).padStart(2, "0")}`', // une date, pas une heure
      'value.replace(":", "-")', // pas un « h »
      '<Input type="date" value={startDate} min={floor} max={max} />', // attribut HTML min={…}, pas une durée
      "const minutes = diff;", // le mot « minutes », pas « } min »
    ];
    for (const sample of good) {
      expect(BANNED.some((b) => b.re.test(sample)), `ne devrait PAS mordre : ${sample}`).toBe(false);
    }
  });

  it("aucune heure/durée fabriquée à la main dans src/ (hors foyers et exemptions)", () => {
    const offenders: string[] = [];
    for (const file of sourceFiles(SRC_ROOT)) {
      const rel = file.slice(SRC_ROOT.length + 1);
      if (isExcluded(rel)) continue;
      const source = readFileSync(file, "utf8");
      for (const { name, re } of BANNED) {
        if (name === MIN_PATTERN && MIN_FAMILY_EXEMPT.includes(rel)) continue; // trajet/latence : « N min » légitime
        if (re.test(source)) {
          offenders.push(`src/${rel} → ${name}`);
        }
      }
    }
    expect(
      offenders,
      "Une heure passe par `shared/lib/time.formatMinutes` (« 21:00 »), une durée par `shared/lib/duration.formatDuration` (« 1h30 »). Fabriquer « 21h »/« 1 h 30 » à la main est interdit — voir la garde.",
    ).toEqual([]);
  });
});
