import { readFileSync } from "node:fs";

import type { Reporter } from "vitest/node";
import type { UserConsoleLog } from "vitest";

// FRT-34 — cliquet des avertissements React « not wrapped in act ». La suite reste VERTE en
// leur présence (ce ne sont que des `console.error`), donc rien ne les arrête et leur nombre
// dérive en silence (70 le 2026-09-18 → 134 le 2026-09-23). Ce reporter les COMPTE au
// processus principal (via `onUserConsoleLog`, insensible au parallélisme des workers) et
// rougit le run dès que le compte dépasse un plafond versionné. Même patron que le plancher
// de couverture : on abaisse le plafond dans la PR qui améliore, jamais on ne le remonte en
// douce.
//
// ⚠ Cœur PUR (`isActWarning`, `actWarningsVerdict`) testé unitairement dans
// `actWarningsRatchet.test.ts`. Le reporter n'est qu'un branchement I/O autour de lui.

/** Le motif exact émis par React quand un état bouge hors d'un `act(...)` en test. */
export const ACT_WARNING_PATTERN = /was not wrapped in act/;

/** Vrai si une ligne de console est un avertissement « not wrapped in act ». */
export function isActWarning(content: string): boolean {
  return ACT_WARNING_PATTERN.test(content);
}

export interface RatchetVerdict {
  /** `false` rougit le run. */
  ok: boolean;
  /** Message imprimé (toujours, vert comme rouge) — porte le compte et le geste attendu. */
  message: string;
}

/**
 * Le cœur du cliquet. Quatre régimes :
 *  - `count == 0` et `ceiling > 0` → ROUGE (fil de détente) : la capture est probablement
 *    cassée (motif ou API onUserConsoleLog rompue) — un plafond > 0 ne tombe pas à 0 sans
 *    qu'un lot l'ait explicitement soldé ; sans ce fil le cliquet meurt en silence ;
 *  - `count > ceiling`  → ROUGE, delta nommé ;
 *  - `count < ceiling`  → vert, invitation à abaisser le plafond dans cette PR ;
 *  - `count == ceiling` → vert, au plafond.
 */
export function actWarningsVerdict(count: number, ceiling: number): RatchetVerdict {
  if (0 === count && ceiling > 0) {
    return {
      ok: false,
      message:
        `Cliquet act : 0 avertissement « not wrapped in act » compté alors que le plafond ` +
        `est ${ceiling}. La capture est probablement CASSÉE (motif /was not wrapped in act/ ` +
        `changé, ou API onUserConsoleLog rompue) — un plafond > 0 ne peut pas tomber à 0 sans ` +
        `qu'un lot ait explicitement abaissé act-warnings-ceiling.json.`,
    };
  }
  if (count > ceiling) {
    return {
      ok: false,
      message:
        `Cliquet act : ${count} avertissements « not wrapped in act » ` +
        `(plafond ${ceiling}, +${count - ceiling}). Un test écrit hors act après sa dernière ` +
        `assertion — enveloppe l'écriture dans act(), ou nettoie le teardown (cleanup() avant ` +
        `toute mutation de store dans un afterEach). Le plafond vit dans act-warnings-ceiling.json.`,
    };
  }
  if (count < ceiling) {
    return {
      ok: true,
      message:
        `Cliquet act : ${count} avertissements (plafond ${ceiling}). Le compte a BAISSÉ — ` +
        `abaisse le plafond à ${count} dans act-warnings-ceiling.json, dans cette même PR ` +
        `(même patron que le plancher de couverture).`,
    };
  }
  return { ok: true, message: `Cliquet act : ${count} avertissements (au plafond ${ceiling}).` };
}

/**
 * Lit et valide le plafond depuis `act-warnings-ceiling.json`. Un fichier absent ou une clé
 * `ceiling` non entière est une erreur DURE : le cliquet ne doit jamais démarrer sur un plafond
 * fantôme.
 */
export function readCeiling(url: URL): number {
  const raw = JSON.parse(readFileSync(url, "utf-8")) as { ceiling?: unknown };
  if (typeof raw.ceiling !== "number" || !Number.isInteger(raw.ceiling) || raw.ceiling < 0) {
    throw new Error(
      "act-warnings-ceiling.json : clé `ceiling` absente ou invalide (entier >= 0 attendu).",
    );
  }
  return raw.ceiling;
}

/**
 * Reporter Vitest. Compte les avertissements act sur tout le run (tous workers confondus, car
 * `onUserConsoleLog` agrège au processus principal), puis tranche en fin de run.
 *
 * ⚠ Le run rougit en posant `process.exitCode = 1` ET en levant : le code retour est le seul
 * verdict qui compte, et l'un des deux chemins survit quoi que fasse Vitest.
 */
export class ActWarningsRatchet implements Reporter {
  private count = 0;
  private readonly ceiling: number;

  constructor(ceiling: number) {
    this.ceiling = ceiling;
  }

  onUserConsoleLog(log: UserConsoleLog): void {
    if ("stderr" === log.type && isActWarning(log.content)) {
      this.count += 1;
    }
  }

  onTestRunEnd(): void {
    const verdict = actWarningsVerdict(this.count, this.ceiling);
    process.stderr.write(`\n[act-ratchet] ${verdict.message}\n`);
    if (!verdict.ok) {
      process.exitCode = 1;
      throw new Error(verdict.message);
    }
  }
}
