import { readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Textes centralisés — garde STATIQUE (PR7, fondation, patron `timeFormatGuard.test.ts` /
 * `pageHeaderGuard.test.ts`).
 *
 * UNE promesse : **un écran MIGRÉ ne porte plus aucun libellé français EN DUR — chaque texte vit
 * dans le module `texts.ts` de sa feature, et le test vérifie la CLÉ, jamais la phrase recopiée**
 * (charte `docs/testing/tests-par-parcours.md` § Sélecteurs front ; règle `.claude/rules/frontend.md`).
 *
 * La garde grep les SOURCES des fichiers listés dans `MIGRATED_FILES` et rougit dès qu'un libellé
 * FR y reste en dur. jsdom n'a pas de moteur de rendu : comme ses sœurs, elle MORD dans Vitest,
 * indépendamment de tout scan visuel.
 *
 * ── CE QUI EST POLICÉ (largeur DOCUMENTÉE) ───────────────────────────────────────────────────
 *   (a) un NŒUD TEXTE JSX littéral d'au moins 3 lettres, sous deux formes :
 *       • en ligne : `>…texte…</` (le `</` ferme une balise — exclut les génériques TS `Map<A, B>`) ;
 *       • sur sa propre ligne : une ligne de PROSE pure (lettres + ponctuation FR, aucun caractère
 *         de code) dont la ligne non vide PRÉCÉDENTE ferme une balise ouvrante (`…>`, ni `/>` ni
 *         `=>`). Le garde-fou « balise ouvrante avant » écarte le code (`return allMain`, `icon,`,
 *         `? null`) qu'une simple prose attraperait et que NEO ne pourrait jamais blanchir.
 *   (b) un littéral FR dans un attribut `title=` · `aria-label=` · `placeholder=` · `message=` ·
 *       `label=` (forme JSX `attr="…"`).
 *
 * Un texte est « FR » ici = contient un mot d'au moins 3 lettres ET au moins une minuscule (épargne
 * les enums/identifiants tout en capitales rendus tels quels, p.ex. « PDF »).
 *
 * ── CONTRAT red → green ──────────────────────────────────────────────────────────────────────
 * NEO verdit la garde en EXTRAYANT chaque littéral vers `texts.ts` et en le rendant par une
 * EXPRESSION : `<Foo>{TEXTS.x}</Foo>`, `aria-label={TEXTS.x}`, une ligne `{TEXTS.x}`. Chacune de ces
 * formes porte `{`/`}` ou perd son `="…"` → les trois détecteurs se taisent. La garde ne peut donc
 * PAS rester rouge « pour toujours » : elle devient verte dès l'extraction.
 *
 * ── CE QUI N'EST PAS POLICÉ (gap assumé) ─────────────────────────────────────────────────────
 * Les chaînes rendues via une fonction d'aide (`switch` qui retourne un libellé), un texte mêlé à
 * une interpolation sur la même ligne (`>Reste {n} j</`), un littéral dans un template multi-ligne.
 * NEO les extrait aussi dans la PR, mais la garde automatique ne les attrape pas — c'est la revue
 * qui tient ce reliquat. Élargir la largeur = décision, pas un ajout silencieux.
 *
 * PORTÉE = EXCLUSIVEMENT les fichiers de `MIGRATED_FILES`. `texts.ts` est HORS portée par
 * construction (c'est LUI qui détient les littéraux, une seule fois). Un écran entre dans
 * `MIGRATED_FILES` quand sa PR le migre (règle `.claude/rules/frontend.md`).
 */
const SRC_ROOT = join(import.meta.dirname, "..");

// Les écrans migrés — chemins relatifs à `src/`. Chacun DOIT être lavé de tout texte en dur.
// ⚠ Au moment où TANK pose cette garde (PR7), AUCUN n'est encore extrait : la garde est ROUGE,
// c'est VOULU — NEO la verdit en déplaçant les littéraux dans les `texts.ts` de chaque feature.
const MIGRATED_FILES = [
  // PR B « Signaler depuis une modale » (D14-D17) — la modale de signalement partagée migrée dans
  // shared/feedback/texts.ts en même temps que le drapeau de modale. La migration ne recule jamais.
  "shared/feedback/FeedbackDialog.tsx",
  // Améliorations légères #1 — l'écran d'inscription migré dans features/auth/texts.ts.
  "features/auth/RegisterPage.tsx",
  "features/cockpit/RadarPanel.tsx",
  "features/cockpit/DayDialog.tsx",
  "features/cockpit/WeekPickerDialog.tsx",
  // PR2 « Modifier les dates d'une fermeture » — le dialogue naît migré (COCKPIT_TEXTS).
  "features/cockpit/ClosureWindowDialog.tsx",
  "features/planning/PlanningPage.tsx",
  // Chantier E — le menu d'export migré dans features/planning/texts.ts en même temps que la case
  // « Afficher le nom des coachs ». La migration ne recule jamais.
  "features/planning/ExportMenu.tsx",
  "features/planning/SlotDetail.tsx",
  "features/planning/DiagnosticsPanel.tsx",
  "features/matches/ConflictLine.tsx",
  // Retours Nico (BCCL 2026-10-27), PR1 — écrans touchés par la passe de lisibilité, migrés dans
  // le même lot (la migration ne recule jamais) : file d'import, rattrapage, légende, radar, cockpit FBI.
  "features/matches/WeekendGridLegend.tsx",
  "features/matches/LeagueValidation.tsx",
  "features/matches/ReviewQueue.tsx",
  "features/matches/ReviewQueueRow.tsx",
  "features/matches/ConflictsPage.tsx",
  "features/matches/ConflictRadar.tsx",
  "features/cockpit/FbiDeadlineCard.tsx",
  "features/matches/OpponentsPage.tsx",
  "features/matches/FfbbEngagementsDialog.tsx",
  // P4-356 — l'en-tête + la navigation du module matchs, migrés en même temps que les deux props
  // de contexte (filtres actifs / plage affichée) jointes au « Signaler ».
  "features/matches/MatchesLayout.tsx",
  // Chantier A PR 2 — la modale d'import FBI, migrée en même temps que la section « Salles à domicile ».
  "features/matches/ImportFbiDialog.tsx",
  // PR2 « jours fériés » — l'éditeur des accès match d'un gymnase, migré en ajoutant la 8ᵉ ligne « Jours fériés ».
  "features/matches/MatchWindowsEditor.tsx",
  // Ouverture datée (D18) — le panneau de pose migre avec ses nouveaux textes ; la petite fenêtre naît migrée.
  "features/matches/PlacementPanel.tsx",
  "features/matches/OpenVenueDialog.tsx",
  "features/wizard/steps/SlotReservationModal.tsx",
  "features/wizard/steps/ConstraintsStep.tsx",
  "features/wizard/steps/RecapStep.tsx",
  // Chantier D (CTC) D4 — l'éditeur d'équipes et la modale Liens migrés dans
  // features/wizard/texts/teams.ts (TEAMS_TEXTS) en même temps que l'entente passe dans l'onglet
  // Liens. RED naturellement tant que NEO n'a pas extrait les littéraux. La migration ne recule jamais.
  "features/wizard/steps/TeamsStep.tsx",
  "features/wizard/steps/TeamLinksModal.tsx",
  // PR F (P4-279) — migré en même temps que la PR qui pose les toasts trajets ; la migration ne recule jamais.
  "features/wizard/steps/VenuesStep.tsx",
  // Associer une salle FFBB (retours du 31/10) : nés migrés, textes dans WIZARD_TEXTS.
  "features/wizard/steps/AssociateSalleModal.tsx",
  "features/wizard/steps/SalleResultRow.tsx",
  // Règle des salariés sans réglage (retours du 31/10) : panneau migré dans `texts/implicitRules.ts`.
  "features/wizard/steps/ImplicitRulesPanel.tsx",
  // PR A lot 2 (E12) — le panneau « Mutualisation » migré (WIZARD_TEXTS) en ajoutant le champ « Nom du groupe ».
  "features/wizard/steps/SharedTrainingBlockPanel.tsx",
  // Lot 5 PR E (P4-284) : les 3 écrans dont le `<table>` passe à la primitive sont migrés en même temps.
  "features/wizard/steps/TravelMatrixModal.tsx",
  // Chantier G PR3 — la modale d'import du parc par IA naît migrée (texts.ts de parcImport).
  "features/wizard/parcImport/ParcImportModal.tsx",
  // Refonte PR1 — la grille de réservations par gymnase naît migrée (texts.ts de parcImport).
  "features/wizard/parcImport/ParcCaseGrid.tsx",
  "features/club/ClubPage.tsx",
  "features/planning/ClubViewTable.tsx",
  // P4-346 PR A — coquille de la console superadmin migrée dans features/admin/texts/dashboard.ts.
  // Les 4 .tsx RED naturellement (texte JSX / attributs policés) ; tabsConfig.ts et queries.ts sont
  // listés pour la complétude de la migration mais la garde y est AVEUGLE (labels `attr: "…"` et
  // toasts `toast.error("…")`, hors des trois détecteurs) — leur migration relève de la revue.
  "features/admin/AdminDashboardPage.tsx",
  "features/admin/AdminShell.tsx",
  "features/admin/AdminLoginPage.tsx",
  "features/admin/AdminAuthLayout.tsx",
  "features/admin/tabs/tabsConfig.ts",
  "features/admin/queries.ts",
  // P4-346 PR B — les 9 sections (features/admin/sections/*) migrées dans
  // features/admin/texts/sections.ts, et les 3 journaux (features/admin/Journaux/*) dans
  // features/admin/texts/journaux.ts. Chacun RED naturellement (texte JSX / attributs policés).
  "features/admin/sections/AudienceSection.tsx",
  "features/admin/sections/CapacitySection.tsx",
  "features/admin/sections/ClubRequestsSection.tsx",
  "features/admin/sections/ContainersSection.tsx",
  "features/admin/sections/DemosSection.tsx",
  "features/admin/sections/DeploymentSection.tsx",
  "features/admin/sections/ExternalDepsSection.tsx",
  "features/admin/sections/FeedbackSection.tsx",
  "features/admin/sections/ReleaseNotesSection.tsx",
  "features/admin/Journaux/AuditSubtab.tsx",
  "features/admin/Journaux/MessengerFailedSubtab.tsx",
  "features/admin/Journaux/SystemErrorsSubtab.tsx",
];

// Exemptions nominatives — un fichier migré autorisé à garder un littéral en dur, AVEC sa raison.
// Plafond STRICT : 5. Idéalement VIDE. Si vous en ajoutez une, dites pourquoi la clé ne convient
// pas (raison STRUCTURELLE, jamais « pour faire taire un rouge »).
const EXEMPTIONS: { file: string; reason: string }[] = [];

const POLICED_ATTRS = ["title", "aria-label", "placeholder", "message", "label"];

/** Un texte « FR » : un mot d'au moins 3 lettres ET au moins une minuscule. */
function looksFrench(text: string): boolean {
  return /\p{L}{3,}/u.test(text) && /\p{Ll}/u.test(text);
}

/** Une ligne de PROSE pure : lettres, marques, chiffres, espaces et ponctuation FR — rien de code. */
const PROSE_LINE = /^[\p{L}\p{M}0-9\s.,!?%…«»’–—-]+$/u;

/** La ligne (non vide) précédente ferme une balise ouvrante (`…>`), ni auto-fermante ni fléchée. */
function endsWithOpeningTag(line: string): boolean {
  const t = line.trimEnd();
  return t.endsWith(">") && !t.endsWith("/>") && !t.endsWith("=>");
}

/** Scanne un contenu source et rend la liste des offenses `rel:line → raison`. */
function scan(rel: string, content: string): string[] {
  const offenders: string[] = [];
  const lines = content.split("\n");
  let prevNonEmpty = "";
  lines.forEach((line, idx) => {
    const n = idx + 1;

    // (a) nœud texte JSX en ligne : `>…texte…</`.
    for (const m of line.matchAll(/>([^<>{}]*)<\//gu)) {
      if (looksFrench(m[1])) offenders.push(`${rel}:${n} → texte JSX en dur « ${m[1].trim()} »`);
    }

    // (b) attribut policé `attr="…"`.
    for (const attr of POLICED_ATTRS) {
      const re = new RegExp(`(?:^|[\\s{(])${attr.replace("-", "\\-")}="([^"]*)"`, "g");
      for (const m of line.matchAll(re)) {
        if (looksFrench(m[1])) offenders.push(`${rel}:${n} → ${attr}="${m[1]}" en dur`);
      }
    }

    // (a bis) nœud texte JSX sur sa propre ligne, précédé d'une balise ouvrante.
    const trimmed = line.trim();
    if (trimmed.length >= 3 && PROSE_LINE.test(trimmed) && looksFrench(trimmed) && endsWithOpeningTag(prevNonEmpty)) {
      offenders.push(`${rel}:${n} → texte JSX en dur « ${trimmed} »`);
    }

    if (trimmed !== "") prevNonEmpty = line;
  });
  return offenders;
}

function isExempt(rel: string): boolean {
  return EXEMPTIONS.some((e) => rel === e.file || rel.startsWith(e.file));
}

describe("Textes centralisés — garde statique", () => {
  it("le plafond d'exemptions (≤ 5) tient", () => {
    expect(EXEMPTIONS.length).toBeLessThanOrEqual(5);
  });

  // Contrôle POSITIF/NÉGATIF (falsification des DEUX sens, patron `timeFormatGuard`) : le détecteur
  // MORD le littéral en dur et ÉPARGNE la forme extraite (`{TEXTS.x}`) + le code voisin. Déterministe,
  // il prouve que la garde n'est ni morte ni trop laxiste — indépendamment de l'état des écrans réels.
  it("mord le texte en dur, épargne la forme extraite et le code", () => {
    const bad = [
      "<Button>Reprendre</Button>", // (a) en ligne
      '<span className="x">Deux matchs sur le même créneau</span>', // (a) en ligne
      '<Foo\n  title="Planning de saison à valider"', // (b) title
      '  <input placeholder="Intitulé (optionnel)" />', // (b) placeholder
      '  <IconButton aria-label="Supprimer la semaine" />', // (b) aria-label
      '<Dialog message="Cette entrée sera retirée du calendrier." />', // (b) message
      "<Button>\n  Adapter\n</Button>", // (a bis) prose sur sa ligne après une balise ouvrante
    ];
    for (const sample of bad) {
      expect(scan("x.tsx", sample).length, `devrait mordre : ${sample}`).toBeGreaterThan(0);
    }

    const good = [
      "<Button>{COCKPIT_TEXTS.resumePlanning}</Button>", // extrait : expression
      "<Foo title={COCKPIT_TEXTS.seasonPlanToValidate} />", // attribut dynamique
      "<input placeholder={WIZARD_TEXTS.optionalTitle} aria-label={WIZARD_TEXTS.a} />",
      "<Button>\n  {COCKPIT_TEXTS.adapt}\n</Button>", // prose extraite → porte des accolades
      "function Item({ icon, title, detail }: Props) {", // destructuring, pas du texte
      "  return allMain", // code nu après un `=>`/`;`, jamais après une balise ouvrante
      "const venues: Map<string, Venue> = new Map();", // générique TS, pas un `</`
      '<div className="rounded border px-2 py-1">{value}</div>', // utilitaires + expression
      "<Button aria-label={`Déplier ${title}`}>", // template dynamique
    ];
    for (const sample of good) {
      expect(scan("x.tsx", sample), `ne devrait PAS mordre : ${sample}`).toEqual([]);
    }
  });

  // La vraie garde : aucun écran migré ne porte de texte FR en dur. ROUGE tant que NEO n'a pas
  // extrait (c'est le but de la PR7 — TANK pose la garde, NEO la verdit). Les offenses sont NOMMÉES
  // `fichier:ligne → …` pour que chaque écran listé se voie reproché ses littéraux.
  it("aucun libellé français en dur dans un écran migré (texts.ts fait foi)", () => {
    const offenders: string[] = [];
    for (const rel of MIGRATED_FILES) {
      if (isExempt(rel)) continue;
      offenders.push(...scan(`src/${rel}`, readFileSync(join(SRC_ROOT, rel), "utf8")));
    }
    expect(
      offenders,
      "Un écran de MIGRATED_FILES porte encore du texte FR en dur. Déplacez chaque libellé dans le `texts.ts` de sa feature (clé camelCase par intention) et rendez-le par une expression `{FEATURE_TEXTS.clé}` ; le test vérifie la CLÉ, jamais la phrase. Voir docs/testing/tests-par-parcours.md et .claude/rules/frontend.md.",
    ).toEqual([]);
  });
});
