import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Uniformité des écrans (série « uniformité », PR 2/7) — norme fondateur N2 : **toute suppression
 * passe par une confirmation** (`ConfirmDialog` / `DeleteConfirm`), patron `matches/ConstraintsPage`.
 * Une seule EXCEPTION validée : retirer une pastille de LIAISON (coach/joueur ↔ équipe dans
 * `CoachesStep`) reste immédiat.
 *
 * Ce garde STATIQUE (sur le modèle de `pageHeaderGuard.test.ts`) tient la promesse sans dépendre
 * d'un rendu : un fichier de `src/features/**` qui LIE un hook de suppression (`useDelete…`) ET
 * l'INVOQUE (`.mutate(`/`.mutateAsync(`) DOIT référencer un composant de confirmation
 * (`ConfirmDialog` ou `DeleteConfirm`), sinon il figure dans les exemptions NOMINATIVES ci-dessous
 * avec sa raison. Sans lui, un nouvel écran de suppression sèche (le défaut que la série corrige)
 * passerait vert.
 *
 * ⚠ Granularité FICHIER : un fichier qui référence un composant de confirmation passe, même s'il
 * porte aussi une suppression immédiate LÉGITIME (les pastilles de liaison de `CoachesStep`, qui
 * utilise déjà `DeleteConfirm` pour la suppression du coach lui-même). C'est voulu : la finesse
 * ligne-à-ligne serait fragile ; la confirmation à l'échelle du fichier suffit à attraper la
 * régression « écran de suppression sans confirmation ».
 *
 * PORTÉE = sources `.tsx` de `src/features/` ; les `*.test.tsx` sont EXCLUS. Ce fichier est un
 * `.test.ts`, hors du glob `.tsx`.
 */
const FEATURES_ROOT = join(import.meta.dirname, "..", "features");

interface Exemption {
  path: string;
  reason: string;
}

// Suppressions LÉGITIMES sans ConfirmDialog : soit ce n'est pas une suppression destructive, soit
// la confirmation est un garde-fou plus fort.
const LEGIT_EXEMPTIONS: Exemption[] = [
  { path: "features/admin/", reason: "console superadmin — hors périmètre des écrans de l'app club (même exemption de périmètre que pageHeaderGuard)" },
  { path: "features/wizard/steps/PeriodConstraints.tsx", reason: "`del` retire l'OVERRIDE de période = retour à la valeur par défaut d'une bascule à trois états, pas une suppression destructive" },
  { path: "features/wizard/steps/PeriodTeams.tsx", reason: "`del` retire l'OVERRIDE d'équipe de période = retour au défaut d'une bascule, pas une suppression destructive" },
  { path: "features/wizard/steps/SlotReservationModal.tsx", reason: "les retraits sont mis EN ATTENTE dans un brouillon (`removed`) et appliqués seulement au clic « Valider » de la modale — pas une suppression immédiate" },
  { path: "features/profile/ProfilePage.tsx", reason: "suppression de compte RGPD confirmée par RÉ-AUTHENTIFICATION (saisie du mot de passe) — garde-fou plus fort qu'une ConfirmDialog" },
];

// Vraies lacunes N2 hors périmètre de CETTE PR (2/7) — à traiter dans une PR ultérieure de la série.
const DEFERRED_GAPS: Exemption[] = [
  { path: "features/wizard/steps/RecapStep.tsx", reason: "retrait immédiat d'une réservation / lot mutualisé du récap, non confirmé — lacune N2 hors périmètre PR 2/7" },
  { path: "features/matches/WeekWorkbench.tsx", reason: "suppression de match : la liste des extérieurs (enfant `AwayList`) confirme déjà, mais le panneau de match sélectionné supprime sans confirmation — lacune N2 hors périmètre PR 2/7" },
  { path: "features/coach-wishes/CoachWishesModal.tsx", reason: "retrait immédiat d'un souhait sur la page publique coach (à token), non confirmé — lacune N2 hors périmètre PR 2/7" },
];

const EXEMPTIONS: Exemption[] = [...LEGIT_EXEMPTIONS, ...DEFERRED_GAPS];

const CONFIRM_REF = /\b(?:ConfirmDialog|DeleteConfirm)\b/;
// Regex LITTÉRALES uniquement (pas de `new RegExp(<variable>)`, gate Semgrep
// detect-non-literal-regexp) : on collecte les bindings puis les invocations, et on
// croise les deux ensembles — la capture `(\w+)` avant `.mutate` borne l'identifiant
// complet (donc `xremove.mutate` ne compte pas pour un binding `remove`).
const DELETE_BINDING = /const\s+(\w+)\s*=\s*useDelete\w*\s*\(/g;
const MUTATE_INVOKE = /(\w+)\.mutate(?:Async)?\(/g;

/** Un fichier « supprime » s'il LIE un hook `useDelete…` ET invoque ce binding via `.mutate(`/`.mutateAsync(`. */
function bindsAndInvokesDelete(source: string): boolean {
  const bindings = new Set<string>();
  for (const match of source.matchAll(DELETE_BINDING)) {
    bindings.add(match[1]);
  }
  if (0 === bindings.size) {
    return false;
  }
  for (const match of source.matchAll(MUTATE_INVOKE)) {
    if (bindings.has(match[1])) {
      return true;
    }
  }
  return false;
}

function referencesConfirm(source: string): boolean {
  return CONFIRM_REF.test(source);
}

function isExempt(rel: string): boolean {
  return EXEMPTIONS.some((e) => rel.includes(e.path));
}

function tsxFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...tsxFiles(full));
    } else if (/\.tsx$/.test(entry.name) && !/\.test\.tsx$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

describe("N2 — toute suppression passe par une confirmation", () => {
  it("chaque fichier features/ qui invoque une suppression référence ConfirmDialog/DeleteConfirm, hors exemption nominative", () => {
    const offenders: string[] = [];
    for (const file of tsxFiles(FEATURES_ROOT)) {
      const rel = `features/${file.slice(FEATURES_ROOT.length + 1)}`;
      const source = readFileSync(file, "utf8");
      if (!bindsAndInvokesDelete(source)) continue;
      if (referencesConfirm(source)) continue;
      if (isExempt(rel)) continue;
      offenders.push(`src/${rel}`);
    }
    expect(
      offenders,
      "Un écran qui supprime doit passer par ConfirmDialog/DeleteConfirm (norme N2), ou entrer dans EXEMPTIONS avec sa raison (suppression non destructive, ré-authentification, lacune déférée nommée).",
    ).toEqual([]);
  });

  it("le garde mord : un delete non confirmé est repéré, un delete confirmé et un non-delete ne le sont pas", () => {
    const unconfirmed = 'const remove = useDeleteThing();\nremove.mutate(x);';
    const confirmed = 'import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";\nconst remove = useDeleteThing();\nremove.mutate(x);';
    const notADelete = 'const list = useThings();\nlist.refetch();';

    expect(bindsAndInvokesDelete(unconfirmed) && !referencesConfirm(unconfirmed)).toBe(true);
    expect(bindsAndInvokesDelete(confirmed) && !referencesConfirm(confirmed)).toBe(false);
    expect(bindsAndInvokesDelete(notADelete)).toBe(false);
  });
});
