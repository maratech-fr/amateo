import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * P4-290 — `LEVEL_LABEL` (le libellé FR d'un `TeamLevel`) n'a qu'UNE maison :
 * `shared/lib/teamIdentity.ts`, aux côtés du vocabulaire de niveau qu'il décrit
 * (🔴 `.claude/rules/frontend.md` : « réutiliser le partagé, jamais réinventer »).
 * La copie `features/wizard/lib/labels.ts` — dix lignes byte-identiques — a disparu ;
 * ses consommateurs (`wizard/lib/tagLabels.ts`, `wizard/steps/RecapStep.tsx`) lisent la
 * maison partagée. Deux copies d'une même table de libellés finissent toujours par diverger
 * en silence : cette garde LÉGÈRE (un grep de sources, pas de machinerie) rend rouge toute
 * ré-introduction d'un second `export const LEVEL_LABEL`.
 */
const SRC_ROOT = join(import.meta.dirname, "..");

function sourceFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...sourceFiles(full));
    } else if (/\.tsx?$/.test(entry.name) && !/\.d\.ts$/.test(entry.name) && !/\.test\.tsx?$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

describe("LEVEL_LABEL — une seule maison (P4-290)", () => {
  it("n'est défini qu'une fois, dans shared/lib/teamIdentity.ts", () => {
    const homes = sourceFiles(SRC_ROOT)
      .filter((f) => /export const LEVEL_LABEL\b/.test(readFileSync(f, "utf8")))
      .map((f) => f.slice(SRC_ROOT.length + 1).replace(/\\/g, "/"))
      .sort();

    expect(homes).toEqual(["shared/lib/teamIdentity.ts"]);
  });
});
