import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Nom unique « planning de période » — garde STATIQUE (PR 2 « Moyennes », patron
 * `hardcodedTextGuard.test.ts` / `timeFormatGuard.test.ts`).
 *
 * UNE promesse : **à l'écran, un planning qui couvre une période s'appelle « planning de période »
 * et celui de la saison « planning de saison » — jamais « planning secondaire », « plan de période »
 * ni « planning principal »**. Le gestionnaire ne doit jamais croiser deux noms pour la même chose.
 *
 * ── CE QUI EST POLICÉ (largeur DOCUMENTÉE) ───────────────────────────────────────────────────
 * Portée : TOUS les `src/**\/*.{ts,tsx}` hors `*.test.*` (les `texts*.ts`, `queries.ts` et leurs
 * toasts compris). Les commentaires sont RETIRÉS d'abord : blocs `/* … *\/` (y compris `{/* … *\/}`
 * multi-lignes), lignes `//`, et `//` de FIN de ligne (hors `://`). Puis, sur chaque ligne (motifs
 * BORNÉS À UNE LIGNE, jamais multi-lignes), dans les littéraux `"…"` · `'…'` · `` `…` `` et le texte
 * JSX `>…<`, la liste FERMÉE (casse ignorée) :
 *   `plannings? secondaires?` · `plans? secondaires?` · `calendriers? secondaires?` ·
 *   `planning principal` · `plans? de période` · formes DÉCOUPÉES par un pluriel interpolé
 *   `planning${…} secondaire` / `plan${…} de période`.
 * Et, dans les modules de textes (`features/**\/texts*.ts`, `features/**\/texts/*.ts`), une clé dont
 * la valeur EST `"principal"` (l'ancienne pastille du planning de saison) — une valeur qui ne fait que
 * CONTENIR « principal » (rôle de coach `? "principal" : "adjoint"`) passe.
 *
 * ── CE QUI N'EST PAS POLICÉ (gap assumé) ─────────────────────────────────────────────────────
 * Les commentaires (vocabulaire de code, D10), « le planning de la période » (la période en cours
 * d'édition, D15), le texte JSX sur sa propre ligne sans `>`/`<` (couvert dans les écrans migrés par
 * `hardcodedTextGuard`), et les messages SERVEUR (gardés côté backend par `PeriodPlanNamingTest`).
 */
const SRC_ROOT = join(import.meta.dirname, "..");

const FORBIDDEN: RegExp[] = [
  /plannings? secondaires?/iu,
  /plans? secondaires?/iu,
  /calendriers? secondaires?/iu,
  /planning principal/iu,
  /\bplans? de période/iu,
  /planning\$\{[^}]*\} secondaire/iu,
  /plan\$\{[^}]*\} de période/iu,
];

/** Une clé de module de textes dont la valeur est exactement `"principal"`. */
const PRINCIPAL_VALUE = /^\s*\w+:\s*"principal"/u;

/** Littéraux et texte JSX, chacun borné à UNE ligne. */
const CANDIDATES = [/"[^"\n]*"/gu, /'[^'\n]*'/gu, /`[^`\n]*`/gu, />[^<>\n]*</gu];

function isTextsModule(rel: string): boolean {
  return /^features\/.+\/texts[^/]*\.ts$/u.test(rel) || /^features\/.+\/texts\/[^/]+\.ts$/u.test(rel);
}

/** Retire les commentaires en gardant la numérotation des lignes. */
function stripComments(content: string): string {
  const withoutBlocks = content.replace(/\/\*[\s\S]*?\*\//gu, (block) => block.replace(/[^\n]/gu, ""));
  return withoutBlocks
    .split("\n")
    .map((line) => (/^\s*\/\//u.test(line) ? "" : line.replace(/(?<!:)\/\/.*$/u, "")))
    .join("\n");
}

/** Scanne un contenu source et rend la liste des offenses `rel:ligne → raison`. */
function scan(rel: string, content: string): string[] {
  const offenders: string[] = [];
  const textsModule = isTextsModule(rel);
  stripComments(content)
    .split("\n")
    .forEach((line, idx) => {
      const n = idx + 1;
      const hit = CANDIDATES.flatMap((re) => [...line.matchAll(re)].map((m) => m[0])).find((literal) =>
        FORBIDDEN.some((re) => re.test(literal)),
      );
      if (hit !== undefined) offenders.push(`${rel}:${n} → ancien nom dans « ${hit} »`);
      if (textsModule && PRINCIPAL_VALUE.test(line)) offenders.push(`${rel}:${n} → valeur "principal"`);
    });
  return offenders;
}

function sourceFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) out.push(...sourceFiles(full));
    else if (/\.tsx?$/u.test(entry.name) && !/\.test\./u.test(entry.name)) out.push(full);
  }
  return out;
}

describe("Nom unique « planning de période » — garde statique", () => {
  // Falsification des DEUX sens, indépendante de l'état des écrans réels : le détecteur MORD chaque
  // forme de la liste fermée (dont les trois phrases d'origine recopiées) et ÉPARGNE les voisins légitimes.
  it("mord chaque ancien nom, épargne les tournures légitimes et les commentaires", () => {
    const bad: [string, string][] = [
      ["x.tsx", 'const a = "Planning secondaire validé";'],
      ["x.tsx", "toast.success('2 plannings secondaires supprimés');"],
      ["x.tsx", 'const b = "plan secondaire";'],
      ["x.tsx", 'const c = "calendrier secondaire";'],
      ["x.tsx", 'const d = "Validez le planning principal";'],
      ["x.tsx", 'toast.success("Dates modifiées — plans de période ajustés.");'],
      ["x.tsx", "const e = `${n} plan${n > 1 ? \"s\" : \"\"} de période`;"],
      // SeasonPlanBanner.tsx:112 (forme découpée, backticks imbriqués)
      [
        "x.tsx",
        '{overlayCount > 0 ? <span>{` · ${overlayCount} planning${overlayCount > 1 ? "s" : ""} secondaire${overlayCount > 1 ? "s" : ""}${openOverlayCount > 0 ? ` (${openOverlayCount} en cours)` : ""}`}</span> : null}',
      ],
      // PlanningPage.tsx:1480 (description du dialogue Rouvrir)
      [
        "x.tsx",
        'description={`Rouvrir « ${displayedPlanName ?? "ce planning"} » supprimera ${reopenOverlayCount ?? 0} planning${(reopenOverlayCount ?? 0) > 1 ? "s" : ""} secondaire${(reopenOverlayCount ?? 0) > 1 ? "s" : ""} (à refaire ensuite).`}',
      ],
      // CockpitPage.tsx:94 (texte JSX non guillemeté, entre `>` et `<`)
      [
        "x.tsx",
        '  Planning de saison <strong className="text-foreground">non validé</strong> — validez-le pour débloquer les <strong className="text-foreground">matchs</strong> et les <strong className="text-foreground">plannings secondaires</strong>.',
      ],
      ["features/planning/texts.ts", '  seasonPlanBadge: "principal",'],
      ["features/coach-wishes/texts/manager.ts", '  roleBadge: "principal",'],
    ];
    for (const [rel, sample] of bad) {
      expect(scan(rel, sample).length, `devrait mordre : ${sample}`).toBeGreaterThan(0);
    }

    const good: [string, string][] = [
      ["x.tsx", '  noMainCoach: "Aucune équipe n\'a de coach principal",'], // coach-wishes/texts/manager.ts:47
      ["x.tsx", 'const p = "2 plannings de période (1 en cours)";'],
      ["x.tsx", 'const q = "le planning de la période";'], // D15
      [
        "features/coach-wishes/texts/manager.ts",
        '  formStaffMemberLegend: (f: string, role: "MAIN" | "ASSISTANT") => `${f} — ${"MAIN" === role ? "principal" : "adjoint"}`,',
      ], // manager.ts:71 / public.ts:84
      ["x.tsx", '      assign(id, "MAIN" === link.role ? "principal" : "assistant", coachId);'], // hors module de textes
      ["x.tsx", "      return true; // un planning secondaire VALIDÉ existe : cette semaine EST différente"], // RadarPanel.tsx:331
      ["x.tsx", "  // Suppression d'un planning SECONDAIRE : jamais le socle"],
      ["x.tsx", "/** Planning secondaire (plan d'une période) ; sinon c'est LE planning principal. */"],
      [
        "x.tsx",
        "                  {/* ★ = LE planning principal (le plan de la saison), par opposition aux\n                      plannings secondaires de période. Ce n'est pas « la version choisie ». */}",
      ], // SeasonSchedulesModal.tsx:210-211
      ["x.tsx", "                {/* Suppression : plannings SECONDAIRES uniquement (jamais le socle). */}"], // SeasonSchedulesModal.tsx:251
      ["x.tsx", '  const url = "https://example.org/a"; // plannings secondaires'], // `://` d'une chaîne conservé
      // Commentaires qui CITENT un ancien nom entre guillemets — seul le retrait des commentaires les épargne.
      ["x.tsx", '  /** Schedules query still in flight — don\'t flash "aucun planning principal". */'], // SeasonPlanBanner.tsx:26
      ["x.tsx", '  return n; // jadis « "2 plannings secondaires" » à l\'écran'],
      ["x.tsx", '  {/* l\'ancien bandeau disait "plans de période"\n      et <b>plannings secondaires</b> */}'],
      ["x.tsx", '  // "Planning secondaire validé" était la clé d\'avant'],
      // `"principal"` hors d'un module de textes (rôle de coach, donnée) n'est pas la pastille.
      ["features/matches/lib/matchFilter.ts", '    role: "principal",'],
      ["x.tsx", "  * nom produit, un titre, un corps, UN geste principal + un secondaire, une ligne"], // system-screen.tsx:11 (« geste secondaire »)
    ];
    for (const [rel, sample] of good) {
      expect(scan(rel, sample), `ne devrait PAS mordre : ${sample}`).toEqual([]);
    }
  });

  // Témoin de portée : le balayage voit bien les modules non-écran que le plan nomme (sinon un vert
  // pourrait venir d'une portée vide).
  it("balaie tous les sources non-test, modules de textes et queries compris", () => {
    const rels = sourceFiles(SRC_ROOT).map((f) => f.slice(SRC_ROOT.length + 1));
    for (const expected of [
      "features/coach-wishes/texts/manager.ts",
      "features/coach-wishes/texts/public.ts",
      "features/cockpit/texts.ts",
      "features/planning/queries.ts",
      "features/cockpit/SeasonPlanBanner.tsx",
    ]) {
      expect(rels, `portée : ${expected}`).toContain(expected);
    }
    expect(rels.some((r) => r.includes(".test."))).toBe(false);
  });

  it("aucun ancien nom (« secondaire », « plan de période », « principal ») dans un texte de l'application", () => {
    const offenders: string[] = [];
    for (const file of sourceFiles(SRC_ROOT)) {
      const rel = file.slice(SRC_ROOT.length + 1);
      offenders.push(...scan(rel, readFileSync(file, "utf8")).map((o) => `src/${o}`));
    }
    expect(
      offenders,
      "Un texte de l'application porte encore un ancien nom. Le planning d'une période s'appelle « planning de période » (pluriel « plannings de période »), celui de la saison « planning de saison » ; la pastille du planning de saison dit « saison ». Corrigez la valeur dans le `texts.ts` de la feature.",
    ).toEqual([]);
  });
});
