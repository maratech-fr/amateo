import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * P4-151 — la palette de la console superadmin a UNE maison : les jetons `--console-*`
 * de `src/index.css` (bloc « Console superadmin », décision UXC-12).
 *
 * ⚠ Cette surface N'A AUCUN GARDE VISUEL : aucun test ne mesure une couleur ici, donc rien
 * n'attraperait une nuance qui dérive. La sûreté vient de la CONSTRUCTION — un jeton est un
 * ALIAS d'une nuance Tailwind (`var(--color-slate-500)`), en BIJECTION (1 nuance = 1 jeton).
 * Ce test est le seul filet possible : il interdit qu'une classe de palette Tailwind BRUTE
 * (`text-slate-500`, `bg-cyan-300/10`, `border-amber-300/20`…) réapparaisse dans
 * `features/admin/`. Une classe brute échoue ici, en nommant le fichier et la ligne ; le
 * correctif est d'employer le jeton correspondant (`text-console-muted`, `bg-console-accent/10`…).
 *
 * Le motif policé = `famille-nuance` pour toutes les familles de la palette Tailwind. Il ne
 * touche PAS `white`/`black` (nuances sans échelle numérique, hors du geste de centralisation)
 * ni les jetons `console-*` (qui ne contiennent aucun nom de famille suivi d'un nombre).
 *
 * PORTÉE = fichiers SOURCE de `features/admin/` (les `*.test.ts(x)` sont EXCLUS, choix
 * documenté). La surface centralisée est le code qui PEINT la console ; un test, lui, peut
 * légitimement affirmer une classe brute possédée par un composant PARTAGÉ hors zone — p. ex.
 * la peau `variant="console"` des onglets vit dans `shared/components/ui/tabs.tsx`
 * (`ring-cyan-300/20`, `text-slate-400`), que ce geste n'a pas le droit de toucher. Policer les
 * tests forcerait ces assertions vers des jetons que le composant partagé n'émet jamais — des
 * assertions mortes. Le garde protège donc la SOURCE admin ; les onglets ont leur propre test.
 */
const ADMIN_ROOT = import.meta.dirname;

const PALETTE_FAMILIES = [
  "slate",
  "gray",
  "zinc",
  "neutral",
  "stone",
  "red",
  "orange",
  "amber",
  "yellow",
  "lime",
  "green",
  "emerald",
  "teal",
  "cyan",
  "sky",
  "blue",
  "indigo",
  "violet",
  "purple",
  "fuchsia",
  "pink",
  "rose",
];

// `\b(famille)-\d{2,3}` : une nuance Tailwind brute, quel que soit le préfixe utilitaire
// (bg-/text-/border-/ring-/from-/to-/divide-…) ou le modificateur d'opacité (`…/20`).
const RAW_PALETTE = new RegExp(String.raw`\b(?:${PALETTE_FAMILIES.join("|")})-\d{2,3}\b`);

function sourceFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...sourceFiles(full));
    } else if (/\.(ts|tsx)$/.test(entry.name) && !/\.test\.(ts|tsx)$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

describe("console superadmin palette", () => {
  it("ne laisse AUCUNE classe de palette Tailwind brute dans features/admin/", () => {
    const offenders: string[] = [];
    for (const file of sourceFiles(ADMIN_ROOT)) {
      // Le fichier de garde lui-même énumère les familles dans sa documentation.
      if (file.endsWith("consolePalette.guard.test.ts")) continue;
      const lines = readFileSync(file, "utf8").split("\n");
      lines.forEach((line, index) => {
        if (!RAW_PALETTE.test(line)) return;
        offenders.push(`${file.replace(`${ADMIN_ROOT}/`, "")}:${index + 1}`);
      });
    }
    expect(
      offenders,
      "Classe de palette brute dans features/admin/ : employer le jeton --console-* correspondant (voir le bloc « Console superadmin » de src/index.css).",
    ).toEqual([]);
  });
});
