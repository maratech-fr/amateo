import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * FRT-38 — le DOUBLE toast d'erreur. Le filet global `MutationCache.onError`
 * (`src/shared/lib/queryClient.ts`) ne se DÉSARME que sur un `onError` de NIVEAU HOOK
 * (`mutation.options.onError`) : un `onError` passé à `mutate(vars, { onError })` ne le voit pas.
 * Donc un `onError` de niveau `mutate()` qui TOASTE une erreur en fait DEUX (le filet global
 * toaste déjà) — ou double le toast du hook quand celui-ci en a un. Le contrat : **le toast
 * d'erreur vit UNE fois, au niveau hook** (survit aussi au démontage, contrairement au niveau
 * `mutate()`) ; l'appelant ne re-toaste pas.
 *
 * Ce garde STATIQUE (patron `deleteConfirmGuard.test.ts`) tient le contrat sans rendu : un
 * fichier de `src/features/**` ou `src/app/**` qui passe à `.mutate(`/`.mutateAsync(` un `onError`
 * dont le CORPS référence `toast.` rougit — sauf exemption NOMINATIVE motivée ci-dessous. Un
 * `onError` de niveau `mutate()` qui ne fait que poser de l'état local (`setError`, `setHttpError`)
 * ou appeler un handler NOMMÉ n'est PAS attrapé : seul un toast inline l'est (c'est lui qui double).
 *
 * PORTÉE = sources `.ts`/`.tsx` de `src/features/` ET `src/app/` ; les `*.test.*` sont EXCLUS.
 * On n'inspecte QUE le texte d'un appel `.mutate(`/`.mutateAsync(` (jamais un `onError` de hook),
 * et DANS cet appel, uniquement le corps de la clé `onError` (jamais un `onSuccess: toast.success`).
 */
const FEATURES_ROOT = join(import.meta.dirname, "..", "features");
const APP_ROOT = join(import.meta.dirname, "..", "app");

interface Exemption {
  path: string;
  reason: string;
}

// Toasts d'erreur de niveau `mutate()` LÉGITIMES, hors contrat « un seul toast au hook ».
const EXEMPTIONS: Exemption[] = [
  {
    path: "features/planning/lib/useRetouchGestures.ts",
    reason:
      "patron split-feedback DOCUMENTÉ (planning/queries.ts:51-74) : les hooks move/place/dryRun/group TAISENT délibérément les erreurs métier (isBusinessSlotEditError) pour que la PAGE les toaste avec CONTEXTE (noms d'équipes, timeout NOMMÉ, surlignage des conflits) — pas de double toast (le hook ne toaste que le transport, que la page, elle, ne touche pas). 3 cas de double toast PARTIEL sur erreur transport (runUndo, place imbriqué, placeEvictedShortcut) → ligne roadmap P4-306, correctif non mécanique",
  },
  {
    path: "features/admin/AdminDashboardPage.tsx",
    reason:
      "messages CONTEXTUELS runtime (job.label, action.label, club.name) qu'un onError de hook générique ne peut pas porter — décision fondateur FRT-38 : on garde le contexte au niveau mutate()",
  },
];

const MUTATE_INVOKE = /\.mutate(?:Async)?\(/g;

/**
 * À partir de l'index d'une parenthèse/accolade ouvrante, renvoie l'index de la fermante
 * correspondante — en SAUTANT le contenu des chaînes (`'`/`"`/`` ` ``) pour qu'une parenthèse dans
 * un libellé ne déséquilibre pas le compte. Sans regex dynamique (gate Semgrep).
 */
function matchClose(source: string, openIdx: number): number {
  const open = source[openIdx];
  const close = "(" === open ? ")" : "}";
  let depth = 0;
  let quote = "";
  for (let i = openIdx; i < source.length; i += 1) {
    const c = source[i];
    if ("" !== quote) {
      if (c === quote && "\\" !== source[i - 1]) quote = "";
      continue;
    }
    if ("'" === c || '"' === c || "`" === c) {
      quote = c;
      continue;
    }
    if (c === open) depth += 1;
    else if (c === close) {
      depth -= 1;
      if (0 === depth) return i;
    }
  }
  return -1;
}

/** Le corps de la clé `onError:` d'un appel (du `:` jusqu'au `,`/`}` de tête), chaînes sautées. */
function onErrorBody(call: string): string | null {
  const key = call.search(/\bonError\s*:/);
  if (-1 === key) return null;
  let i = call.indexOf(":", key) + 1;
  let depth = 0;
  let quote = "";
  const start = i;
  for (; i < call.length; i += 1) {
    const c = call[i];
    if ("" !== quote) {
      if (c === quote && "\\" !== call[i - 1]) quote = "";
      continue;
    }
    if ("'" === c || '"' === c || "`" === c) {
      quote = c;
      continue;
    }
    if ("(" === c || "[" === c || "{" === c) depth += 1;
    else if (")" === c || "]" === c || "}" === c) {
      if (0 === depth) break;
      depth -= 1;
    } else if ("," === c && 0 === depth) break;
  }
  return call.slice(start, i);
}

/** Un fichier OFFENSE s'il passe à `.mutate(`/`.mutateAsync(` un `onError` dont le corps toaste. */
function hasMutateLevelErrorToast(source: string): boolean {
  for (const m of source.matchAll(MUTATE_INVOKE)) {
    const openIdx = source.indexOf("(", m.index);
    const closeIdx = matchClose(source, openIdx);
    if (-1 === closeIdx) continue;
    const call = source.slice(openIdx, closeIdx + 1);
    const body = onErrorBody(call);
    if (null !== body && body.includes("toast.")) return true;
  }
  return false;
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

describe("FRT-38 — un seul toast d'erreur, au niveau hook", () => {
  it("aucun onError de niveau mutate() ne toaste dans features/ ni app/, hors exemption nominative", () => {
    const offenders: string[] = [];
    for (const [root, prefix] of [
      [FEATURES_ROOT, "features"],
      [APP_ROOT, "app"],
    ] as const) {
      for (const file of sourceFiles(root)) {
        const rel = `${prefix}/${file.slice(root.length + 1)}`;
        if (isExempt(rel)) continue;
        if (hasMutateLevelErrorToast(readFileSync(file, "utf8"))) {
          offenders.push(`src/${rel}`);
        }
      }
    }
    expect(
      offenders,
      "Un onError passé à mutate() ne doit PAS toaster (le filet global le ferait déjà → double toast) : porter le toast au niveau HOOK (useMutation({ onError })), ou entrer dans EXEMPTIONS avec sa raison.",
    ).toEqual([]);
  });

  it("le garde mord : un onError mutate() qui toaste est repéré, pas un onSuccess ni un setState", () => {
    const toasting = 'save.mutate(x, { onError: () => toast.error("boom") });';
    const toastingBlock = "save.mutate(x, {\n  onSuccess: () => toast.success('ok'),\n  onError: (e) => { log(e); toast.error(e.message); },\n});";
    const successOnly = "save.mutate(x, { onSuccess: () => toast.success('ok') });";
    const setStateOnError = "save.mutate(x, { onError: (e) => void errorMessage(e).then(setError) });";
    const namedHandler = "save.mutate(x, { onError: noteWindowConflict });";

    expect(hasMutateLevelErrorToast(toasting)).toBe(true);
    expect(hasMutateLevelErrorToast(toastingBlock)).toBe(true);
    expect(hasMutateLevelErrorToast(successOnly)).toBe(false);
    expect(hasMutateLevelErrorToast(setStateOnError)).toBe(false);
    expect(hasMutateLevelErrorToast(namedHandler)).toBe(false);
  });
});
