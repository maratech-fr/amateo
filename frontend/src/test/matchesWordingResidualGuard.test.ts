import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Retours Nico (BCCL, 2026-10-27), M3 — garde STATIQUE du vocabulaire du module Matchs
 * (patron `timeFormatGuard.test.ts` / `hardcodedTextGuard.test.ts`).
 *
 * UNE promesse : **plus aucun « validé ligue » ni « Attesté » LU par un gestionnaire ne
 * subsiste dans `features/matches` + `features/cockpit`** — le jargon fédéral que même le
 * fondateur a dû faire expliquer a cédé à « déjà dans FBI ». Les libellés positifs exacts,
 * eux, sont pinés par leurs tests JUMEAUX (point sensible « matchs », doublon VOULU — §1 de
 * la charte) : `features/matches/lib/fixtureStatusLabel.test.ts` (la table d'états :
 * VALIDATED = « Déjà dans FBI », aucun « Attesté ») et
 * `features/matches/lib/leagueValidation.test.ts` (`LEAGUE_VALIDATION_CONFIRM_LABEL` +
 * l'intro). Cette garde est le FILET DE COMPLÉTUDE qui attrape les occurrences dispersées
 * NON testées une à une (`LeagueValidation.tsx`, `FbiDeadlineCard.tsx`, …).
 *
 * jsdom n'a pas de moteur de rendu : comme ses sœurs, la garde MORD dans Vitest, hors de tout
 * scan visuel.
 *
 * ── CE QUI EST POLICÉ (largeur DOCUMENTÉE) ───────────────────────────────────────────────────
 * Le TEXTE source de chaque fichier, COMMENTAIRES RETIRÉS d'abord (`/* … * /` multi-lignes — les
 * commentaires JSX `{/* … * /}` compris — puis `// …` en fin de ligne). On cherche ensuite deux
 * tournures, insensibles à la casse :
 *   - « validé ligue » (et accords : « validée ligue », « Validé ligue ») ;
 *   - « Attesté » (le mot fédéral de l'ex-statut VALIDATED).
 * Ces deux tournures n'ont AUCUN emploi légitime dans le module : les trouver = un résidu à
 * reformuler. ⚠ On NE police PAS « à valider » / « Tout valider » : ils ont des emplois légitimes
 * hors Matchs (p.ex. cockpit « Planning de saison à valider ») — leur reformulation propre à la
 * file d'import est pinée par les tests de composant (`ReviewQueue` / `ReviewQueueRow`).
 *
 * ── CONTRAT red → green ──────────────────────────────────────────────────────────────────────
 * ROUGE tant que NEO n'a pas reformulé (TANK pose la garde, NEO la verdit). Dès que le dernier
 * libellé visible est reformulé, la garde VERDIT — elle ne peut pas rester rouge « pour toujours ».
 * Un résidu laissé en COMMENTAIRE ne la tient pas rouge (les commentaires sont retirés) : on garde
 * la substance du commentaire, seule la copie LUE par l'utilisateur doit changer.
 *
 * ── GAP ASSUMÉ ───────────────────────────────────────────────────────────────────────────────
 * Un `//` à l'intérieur d'une chaîne (URL) ou un `/*` dans une chaîne seraient retirés à tort —
 * aucun des fichiers visés n'en porte près d'une tournure policée. Élargir = décision, pas un
 * ajout silencieux.
 */

// Les deux dossiers du module Matchs (boucle + cockpit) — relatifs à `src/`.
const ROOTS = ["features/matches", "features/cockpit"];
const SRC_ROOT = join(import.meta.dirname, "..");

/** Les tournures bannies (user-facing, propres au module, sans emploi légitime). */
const FORBIDDEN: { re: RegExp; what: string }[] = [
  { re: /valid\p{L}* ligue/giu, what: "« validé ligue »" },
  { re: /Attest\p{L}*/giu, what: "« Attesté »" },
];

/** Retire les commentaires (`/* … * /` multi-lignes, `{/* … * /}` compris, puis `// …`) avant de scanner. */
function stripComments(src: string): string {
  return src.replace(/\/\*[\s\S]*?\*\//g, " ").replace(/\/\/[^\n]*/g, " ");
}

/** Scanne un contenu (commentaires retirés) et rend les offenses `rel → tournure`. */
function scan(rel: string, content: string): string[] {
  const code = stripComments(content);
  const offenders: string[] = [];
  for (const { re, what } of FORBIDDEN) {
    re.lastIndex = 0;
    if (re.test(code)) {
      offenders.push(`${rel} → ${what} encore lisible`);
    }
  }
  return offenders;
}

/** Les fichiers source `.ts`/`.tsx` d'un dossier, récursivement, hors fichiers de test. */
function sourceFiles(rel: string): string[] {
  const out: string[] = [];
  for (const dirent of readdirSync(join(SRC_ROOT, rel), { withFileTypes: true })) {
    const childRel = `${rel}/${dirent.name}`;
    if (dirent.isDirectory()) {
      out.push(...sourceFiles(childRel));
    } else if (/\.tsx?$/.test(dirent.name) && !/\.test\.tsx?$/.test(dirent.name)) {
      out.push(childRel);
    }
  }
  return out;
}

describe("Vocabulaire Matchs — garde statique (M3, BCCL 2026-10-27)", () => {
  // Falsification des DEUX sens (patron `timeFormatGuard`) : le détecteur MORD la tournure LUE et
  // ÉPARGNE le commentaire + les tournures légitimes. Déterministe, il prouve que la garde n'est ni
  // morte ni trop laxiste, indépendamment de l'état des écrans réels.
  it("mord un résidu LU, épargne les commentaires et les tournures légitimes", () => {
    const bad = [
      'const X = "Marquer « validé ligue »";',
      "<span>à confirmer « validé ligue »</span>",
      '{ VALIDATED: "Attesté FBI" }',
      "<Foo title=\"N matchs à confirmer « Validé ligue »\" />",
    ];
    for (const sample of bad) {
      expect(scan("x.tsx", sample).length, `devrait mordre : ${sample}`).toBeGreaterThan(0);
    }

    const good = [
      "// Lot O — le rattrapage « validé ligue » : commentaire, pas un texte lu.",
      "/* « Validé ligue » en lot (bloc de commentaire). */",
      "{/* la file « validé ligue » (commentaire JSX multi-ligne\n   sur deux lignes) */}",
      '<span>{toValidate} à valider</span>', // « à valider » n'est PAS banni (emploi légitime)
      '<Button>Tout valider</Button>',
      'const label = "Déjà dans FBI";', // la cible reformulée
    ];
    for (const sample of good) {
      expect(scan("x.tsx", sample), `ne devrait PAS mordre : ${sample}`).toEqual([]);
    }
  });

  // La vraie garde : aucune tournure bannie LUE ne subsiste dans Matchs + cockpit. ROUGE tant que
  // NEO n'a pas reformulé (fixtureStatusLabel, leagueValidation/LeagueValidation, FbiDeadlineCard…).
  it("aucun « validé ligue » ni « Attesté » lisible dans features/matches + features/cockpit", () => {
    const offenders: string[] = [];
    for (const root of ROOTS) {
      for (const rel of sourceFiles(root)) {
        offenders.push(...scan(`src/${rel}`, readFileSync(join(SRC_ROOT, rel), "utf8")));
      }
    }
    expect(
      offenders,
      "Un écran de Matchs/cockpit porte encore « validé ligue » ou « Attesté » LU par l'utilisateur. Reformulez en « déjà dans FBI » (retours Nico, BCCL 2026-10-27, M3). Un commentaire peut garder la substance ; seule la copie affichée doit changer.",
    ).toEqual([]);
  });
});
