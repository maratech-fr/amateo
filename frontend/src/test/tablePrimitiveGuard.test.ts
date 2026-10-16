import { readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Primitive de tableau — garde STATIQUE (lot 5 PR E / P4-284, patron `timeFormatGuard.test.ts` /
 * `hardcodedTextGuard.test.ts`).
 *
 * UNE promesse : **un écran listé ne recode plus un `<table>` à la main — il CONSOMME la primitive
 * partagée `shared/components/ui/table.tsx`** (`Table`/`TableHeader`/`TableBody`/`TableRow`/
 * `TableHead`/`TableCell`/`TableCaption`), « même chose, au même endroit, de la même façon »
 * (règle `.claude/rules/frontend.md` § « réutiliser le PARTAGÉ » ; charte
 * `docs/testing/tests-par-parcours.md`).
 *
 * Pourquoi une garde STATIQUE et pas un test de rendu : le RENDU iso (mêmes `columnheader`/
 * `rowheader`/cellules) est DÉJÀ prouvé, écran par écran, par les filets de rôles existants —
 *   • `features/wizard/steps/TravelMatrixModal.test.tsx` (columnheader + rowheader par gymnase) ;
 *   • `features/planning/ClubViewTable.test.tsx` (columnheader « Lundi », rowheader, button /Matéo/) ;
 *   • `features/club/ClubPage.test.tsx` (le contenu des deux tableaux d'usage, par getByText).
 * Ces filets MORDENT si la table disparaît ou perd sa structure. Ce que AUCUN d'eux ne peut voir,
 * c'est si la table est encore un `<table>` BRUT ou la primitive : la primitive rend son `<table>`
 * en INTERNE (dans `table.tsx`, hors portée de cette garde), donc un écran migré ne porte plus
 * aucune balise de tableau en minuscules. C'est exactement ce que cette garde vérifie — au plus
 * bas coût (grep des sources), avant tout rendu.
 *
 * jsdom n'a pas de moteur de rendu : comme ses sœurs (`timeFormatGuard`, `hardcodedTextGuard`),
 * elle MORD dans Vitest, indépendamment de tout scan visuel.
 *
 * ── CONTRAT red → green ──────────────────────────────────────────────────────────────────────
 * ROUGE tant que les 3 écrans portent un `<table>`/`<thead>`/`<tr>`/… brut. NEO la verdit en
 * remplaçant chaque balise de tableau en minuscules par la primitive capitalisée
 * (`<Table>`, `<TableHead scope>`, `<TableCell>`, …) — rendu ISO, sans extension de la primitive.
 * La primitive, capitalisée, n'est JAMAIS attrapée (la détection est sensible à la casse).
 */
const SRC_ROOT = join(import.meta.dirname, "..");

// Les écrans qui DOIVENT consommer la primitive — chemins relatifs à `src/`.
// ⚠ Au moment où TANK pose cette garde (PR E), AUCUN n'est encore migré : la garde est ROUGE,
// c'est VOULU — NEO la verdit en remplaçant les `<table>` bruts par la primitive partagée.
const TABLE_FILES = [
  "features/wizard/steps/TravelMatrixModal.tsx",
  "features/club/ClubPage.tsx",
  "features/planning/ClubViewTable.tsx",
];

// Exemptions nominatives — un écran listé autorisé à garder un `<table>` brut, AVEC sa raison
// STRUCTURELLE (jamais « pour faire taire un rouge »). Plafond STRICT : 5. Idéalement VIDE.
const EXEMPTIONS: { file: string; reason: string }[] = [];

// Les balises de tableau NATIVES (minuscules) : leur présence prouve un `<table>` fait main.
// La primitive les rend en interne (`table.tsx`, hors portée) et s'écrit capitalisée (`<Table …>`),
// donc jamais attrapée ici (regex sensible à la casse). Longues d'abord pour une alternance nette.
const NATIVE_TAGS = ["table", "thead", "tbody", "tfoot", "caption", "colgroup", "col", "tr", "th", "td"];

// `<tag` suivi d'une frontière de balise (` `, `/`, `>`) — exclut `<thead>` capté comme `<th`,
// les génériques TS `Map<string, T>` (pas de balise native suivie d'une frontière), et la
// primitive capitalisée.
const NATIVE_TAG_RE = new RegExp(`<(${NATIVE_TAGS.join("|")})(?=[\\s/>])`, "g");

/** Scanne un contenu source et rend la liste des offenses `rel:line → balise brute`. */
function scan(rel: string, content: string): string[] {
  const offenders: string[] = [];
  content.split("\n").forEach((line, idx) => {
    for (const m of line.matchAll(NATIVE_TAG_RE)) {
      offenders.push(`${rel}:${idx + 1} → <${m[1]}> brut (utilisez la primitive Table/TableHead/TableCell/…)`);
    }
  });
  return offenders;
}

function isExempt(rel: string): boolean {
  return EXEMPTIONS.some((e) => rel === e.file || rel.startsWith(e.file));
}

describe("Primitive de tableau — garde statique", () => {
  it("le plafond d'exemptions (≤ 5) tient", () => {
    expect(EXEMPTIONS.length).toBeLessThanOrEqual(5);
  });

  // Contrôle POSITIF/NÉGATIF (falsification des DEUX sens, patron `timeFormatGuard` /
  // `hardcodedTextGuard`) : le détecteur MORD une balise de tableau brute et ÉPARGNE la primitive
  // capitalisée + le code voisin. Déterministe, il prouve que la garde n'est ni morte ni trop
  // laxiste — indépendamment de l'état des écrans réels (TANK ne joue PAS la suite : worktree, PR
  // concurrente sur le dépôt principal — le rouge réel est établi par lecture des 3 sources).
  it("mord une balise de tableau brute, épargne la primitive et le code", () => {
    const bad = [
      '<table className="w-full">',
      "<thead>",
      "<tbody>",
      "<tr>",
      '<th scope="col">Équipe</th>',
      '<td className="p-0.5">',
      '<caption className="sr-only">x</caption>',
    ];
    for (const sample of bad) {
      expect(scan("x.tsx", sample).length, `devrait mordre : ${sample}`).toBeGreaterThan(0);
    }

    const good = [
      "<Table>", // primitive capitalisée
      '<TableHead scope="col">{TEXTS.team}</TableHead>',
      "<TableHeader>",
      "<TableBody>",
      "<TableRow>",
      '<TableCell className="p-0.5">',
      "<TableCaption>x</TableCaption>",
      "const venues: Map<string, Venue> = new Map();", // générique TS, pas une balise
      "useState<TravelRow>(null);", // générique capitalisé
      "a < table.length ? a : b", // comparaison avec espace après `<`
    ];
    for (const sample of good) {
      expect(scan("x.tsx", sample), `ne devrait PAS mordre : ${sample}`).toEqual([]);
    }
  });

  // La vraie garde : aucun écran listé ne porte de balise de tableau brute. ROUGE tant que NEO n'a
  // pas migré (c'est le but de PR E — TANK pose la garde, NEO la verdit). Les offenses sont NOMMÉES
  // `fichier:ligne → …` pour que chaque écran listé se voie reproché ses `<table>` faits main.
  it("aucune balise de tableau brute dans un écran listé (la primitive est consommée)", () => {
    const offenders: string[] = [];
    for (const rel of TABLE_FILES) {
      if (isExempt(rel)) continue;
      offenders.push(...scan(`src/${rel}`, readFileSync(join(SRC_ROOT, rel), "utf8")));
    }
    expect(
      offenders,
      "Un écran listé recode encore un `<table>` à la main. Remplacez chaque balise de tableau par la primitive partagée `shared/components/ui/table.tsx` (`Table`/`TableHeader`/`TableBody`/`TableRow`/`TableHead`/`TableCell`/`TableCaption`), à rendu ISO, sans étendre la primitive. Voir docs/testing/tests-par-parcours.md et .claude/rules/frontend.md.",
    ).toEqual([]);
  });
});
