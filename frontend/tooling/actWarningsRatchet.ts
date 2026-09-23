import { readFileSync } from "node:fs";

import type { Reporter, TestModule, Vitest } from "vitest/node";
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
 * Le cœur du cliquet.
 *
 * ⚠ Un cliquet ne TRANCHE (rouge/vert) et n'INVITE à abaisser que sur une suite **COMPLÈTE** :
 * le compte n'est un plafond que s'il porte sur TOUS les tests. Sur un run **partiel** (filtré
 * sur un fichier, un dossier ou un `-t`), le compte ne mesure qu'un sous-ensemble — le geste le
 * plus fréquent du dev (`vitest run un-seul-fichier`) ne doit donc ni rougir (un fichier propre
 * rendrait 0 et déclencherait le fil de détente à tort) ni inviter à abaisser (le sous-total
 * poserait un plafond famélique qui rougirait la CI). Sur un partiel : compte affiché à titre
 * INDICATIF, jamais de verdict, exit inchangé (`ok: true`).
 *
 * Sur une suite complète, quatre régimes :
 *  - `count == 0` et `ceiling > 0` → ROUGE (fil de détente) : la capture est probablement
 *    cassée (motif ou API onUserConsoleLog rompue) — un plafond > 0 ne tombe pas à 0 sans
 *    qu'un lot l'ait explicitement soldé ; sans ce fil le cliquet meurt en silence ;
 *  - `count > ceiling`  → ROUGE, delta nommé ;
 *  - `count < ceiling`  → vert, invitation à abaisser le plafond dans cette PR ;
 *  - `count == ceiling` → vert, au plafond.
 *
 * `complete` par défaut à `true` : un appelant qui l'omet obtient le régime STRICT (verdict
 * rendu) — l'oubli penche du côté qui ENFORCE, jamais du côté qui se tait.
 */
export function actWarningsVerdict(count: number, ceiling: number, complete = true): RatchetVerdict {
  if (!complete) {
    return {
      ok: true,
      message:
        `Cliquet act : ${count} avertissement(s) « not wrapped in act » sur un run PARTIEL ` +
        `(filtré) — indicatif seulement, aucun verdict. Le plafond ${ceiling} et l'invitation ` +
        `à l'abaisser ne valent que sur la suite COMPLÈTE (\`make -C frontend test\`).`,
    };
  }
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
  private vitest?: Vitest;

  constructor(ceiling: number) {
    this.ceiling = ceiling;
  }

  onInit(vitest: Vitest): void {
    this.vitest = vitest;
  }

  onUserConsoleLog(log: UserConsoleLog): void {
    if ("stderr" === log.type && isActWarning(log.content)) {
      this.count += 1;
    }
  }

  async onTestRunEnd(testModules: ReadonlyArray<TestModule>): Promise<void> {
    const complete = await this.isCompleteRun(testModules);
    const verdict = actWarningsVerdict(this.count, this.ceiling, complete);
    process.stderr.write(`\n[act-ratchet] ${verdict.message}\n`);
    if (!verdict.ok) {
      process.exitCode = 1;
      throw new Error(verdict.message);
    }
  }

  /**
   * Un run est COMPLET quand TOUS les tests de la config ont tourné. Deux façons pour un dev de
   * réduire l'ensemble, deux signaux à croiser — c'est ce que Vitest lui-même consulte pour se
   * savoir filtré :
   *
   *  1. **moins de FICHIERS** — filtre positionnel (`vitest run un.test.ts`), dossier, `--changed`,
   *     `--shard`, `--project`. Signal FIABLE et PUBLIC : le nombre de modules joués
   *     (`testModules`, ce que le run a réellement exécuté) comparé à l'univers complet
   *     `globTestSpecifications()` (re-glob SANS filtre — la méthode que Vitest emploie déjà,
   *     `filters=[]` ⇒ tous les fichiers de tous les projets, typecheck désactivé ici). Moins de
   *     modules joués que collectés ⇒ partiel.
   *  2. **moins de TESTS par fichier** — `-t`/`testNamePattern`. Ce filtre-là ne réduit PAS le
   *     nombre de modules (ils se chargent tous), donc le comptage ci-dessus le manque : on le lit
   *     directement dans `config.testNamePattern` (champ PUBLIC typé de `ResolvedConfig`).
   *
   * Pourquoi PAS `config.filters` ni `vitest.filenamePattern` (les « CLI filters ») : `config.filters`
   * n'est JAMAIS peuplé par Vitest 4 (déclaré optionnel, aucune affectation dans `resolveConfig`), et
   * `filenamePattern` est marqué `@internal`, absent du `.d.ts` public et posé APRÈS `onInit` — un
   * signal fragile et non typé. La comparaison de modules, elle, n'utilise que l'API publique et
   * capture toute réduction du jeu de FICHIERS, pas seulement les positionnels de la ligne de commande.
   *
   * Sans instance Vitest (contexte absent), on retombe sur COMPLET : l'incertitude penche vers le
   * régime qui enforce.
   */
  private async isCompleteRun(testModules: ReadonlyArray<TestModule>): Promise<boolean> {
    const vitest = this.vitest;
    if (undefined === vitest) {
      return true;
    }
    if (undefined !== vitest.config.testNamePattern) {
      return false;
    }
    const collected = await vitest.globTestSpecifications();
    return testModules.length >= collected.length;
  }
}
