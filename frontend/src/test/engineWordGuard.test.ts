import { readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * UXS-17 (petites lignes) — UNE promesse : **le flux de retouche ne dit jamais « moteur » ni
 * « solveur » à un gestionnaire** ; il dit « le système » (glossaire `docs/glossary.md`, décision
 * fondateur Q4). Toasts, voile d'attente, modale d'éviction et messages de repli des appels API sont
 * des textes VISIBLES : un mot interne qui y revient est une régression de vocabulaire.
 *
 * Garde STATIQUE (patron `textOpacityGuard`) : on lit les sources du flux, on RETIRE les commentaires
 * (le code a le droit de parler de son moteur), puis tout « moteur »/« solveur » restant — dans une
 * chaîne, un gabarit ou un nœud texte JSX — rougit, avec sa ligne.
 *
 * Messages SERVEUR relayés par ces toasts (`violations[0].message`, `MoveSlotService::namedViolations`) :
 * gardés côté backend par `backend/tests/Security/SlotMoveVerdictTest.php`
 * (`testUnnamedRefusalSaysSystemNotEngine`). Hors portée délibérée : « moteur de calcul » du pipeline
 * de génération (`GenerateScheduleHandler`, ligne de roadmap) et les écrans hors retouche.
 */
const SRC_ROOT = join(import.meta.dirname, "..");

const RETOUCH_FILES = [
  "features/planning/texts.ts",
  "features/planning/EvictConfirmDialog.tsx",
  "app/ActionVeil.tsx",
  "features/planning/lib/useRetouchGestures.ts",
  "features/planning/api.ts",
];

const INTERNAL_WORD = /\b(?:moteur|solveur)s?\b/i;

/**
 * Le source sans ses commentaires — blocs (`/* … *\/`, JSX `{/* … *\/}` compris) et fins de ligne
 * `// …`. Un `//` précédé de `:` (une URL `http://`) n'ouvre pas de commentaire. Les sauts de ligne
 * des blocs sont conservés pour que les numéros de ligne restent justes.
 */
function stripComments(source: string): string {
  const noBlocks = source.replace(/\/\*[\s\S]*?\*\//g, (block) => block.replace(/[^\n]/g, " "));
  return noBlocks
    .split("\n")
    .map((line) => line.replace(/(^|[^:])\/\/.*$/, "$1"))
    .join("\n");
}

function offendingLines(source: string): string[] {
  return stripComments(source)
    .split("\n")
    .flatMap((line, index) => (INTERNAL_WORD.test(line) ? [`${index + 1}: ${line.trim()}`] : []));
}

describe("UXS-17 — aucun « moteur » / « solveur » visible dans le flux de retouche", () => {
  it.each(RETOUCH_FILES)("%s ne montre ni « moteur » ni « solveur »", (file) => {
    const offenders = offendingLines(readFileSync(join(SRC_ROOT, file), "utf8"));
    expect(offenders, `${file} : dire « le système » (glossaire, UXS-17)`).toEqual([]);
  });

  it("le garde mord : chaîne, gabarit et nœud JSX repérés ; commentaires épargnés", () => {
    expect(offendingLines('toast.error("Le moteur n\'a pas répondu — réessayez.");')).toHaveLength(1);
    expect(offendingLines("const m = `Échange refusé par le solveur.`;")).toHaveLength(1);
    expect(offendingLines("<span>Ce déplacement casse une règle du moteur.</span>")).toHaveLength(1);
    expect(offendingLines('toast.error("Le système n\'a pas répondu — réessayez.");')).toEqual([]);
    expect(offendingLines("// le moteur juge le déplacement\nconst x = 1;")).toEqual([]);
    expect(offendingLines("/**\n * verdict du moteur\n */\nconst y = 2;")).toEqual([]);
    expect(offendingLines("{/* le solveur tranche */}")).toEqual([]);
    expect(offendingLines('const url = "http://engine:8000"; // appel moteur')).toEqual([]);
  });
});
