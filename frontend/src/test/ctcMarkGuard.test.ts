import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Chantier D (CTC) PR1 — le pictogramme d'entente (CTC) vit PARTOUT où le nom d'équipe s'affiche.
 *
 * Retour Rillieux #6 + précision fondateur 2026-10-24 (Q3) : « PICTOGRAMME OBLIGATOIRE devant le nom
 * de l'équipe, pour l'identifier d'un coup d'œil », « PARTOUT dans l'appli où le nom d'équipe
 * s'affiche (étape Équipes, équipes de période, tuiles planning, vue par coach, module matchs,
 * sélecteurs) ; exports PDF/XLSX NON concernés ». La primitive partagée `CtcMark`
 * (`shared/components/ui/ctc-mark.tsx`) EST ce repère ; les sélecteurs l'héritent via
 * `TeamSelect` (`ListboxOption.icon`). Un test de surface ne peut pas, à lui seul, prouver un
 * « PARTOUT » — c'est le rôle de ce garde STATIQUE, sur le modèle de `pageHeaderGuard`/`readStateGuard`
 * (liste NOMINATIVE + balayage de complétude + exemptions motivées).
 *
 * Il tient deux promesses :
 *   1. Chaque SURFACE qui affiche un nom d'équipe comme ÉTIQUETTE autonome pour le gestionnaire
 *      (liste nominative `CTC_SURFACES`) référence `CtcMark` — sinon une équipe en entente y passe
 *      sans son repère, et le « d'un coup d'œil » est faux sur cet écran.
 *   2. Balayage de complétude : toute surface de `src/features/**` qui projette un `teamLabel` (le
 *      libellé d'équipe pré-calculé des grilles/listes planning & matchs) est CLASSÉE — requise, OU
 *      référence `CtcMark`/`TeamSelect`, OU exemptée avec sa raison. Une NOUVELLE grille d'équipe ne
 *      peut pas échapper au repère en silence.
 *
 * LARGEUR DOCUMENTÉE du balayage (2) : le signal est `teamLabel`, le SEUL jeton exclusivement
 * « équipe » et rendu comme étiquette (venues = `venueLabel`, coachs = `coachLabel`). Les surfaces
 * qui rendent le nom par `teamName`/`teams.get(id)?.name`/`{team.name}` (ConflictLine,
 * TypicalWeekendGrid, MatchRowsTable, FbiEntryList, UnplacedList, les listes du wizard et des
 * doléances) n'ont PAS de jeton unique fiable : elles sont tenues par la liste nominative (1) + la
 * revue. Les PHRASES/toasts/aria qui EMBARQUENT le nom dans une phrase gardent le nom NU (D-f), les
 * pages publiques à token coach-wish sont HORS V1 (D-g), les exports et la console admin sont hors
 * périmètre — aucun ne porte `teamLabel`, donc le balayage ne les voit pas.
 *
 * PORTÉE = sources `.tsx` de `src/features/**` ; les `*.test.tsx` sont EXCLUS. Ce fichier est un
 * `.test.ts`. ROUGE tant que NEO n'a pas câblé `CtcMark` (option A).
 */
const SRC_ROOT = join(import.meta.dirname, "..");

/** Une surface référence le repère si elle importe/rend `CtcMark`. */
const CTC_MARK = /CtcMark/;
/** …ou le reçoit via le sélecteur partagé (option d'équipe). */
const TEAM_SELECT = /TeamSelect/;
/** Le libellé d'équipe pré-projeté des grilles/listes (signal du balayage de complétude). */
const TEAM_LABEL = /teamLabel/;

// Surfaces qui affichent un nom d'équipe comme ÉTIQUETTE autonome pour le gestionnaire — chacune DOIT
// référencer CtcMark (le nom servi par le serveur reste la donnée ; le picto le PRÉCÈDE).
const CTC_SURFACES = [
  // Import IA — l'étape « Ententes (CTC) » montre, équipe par équipe, qui est en entente : le repère
  // y vit comme partout ailleurs (le picto de LIEN `Link2` réservé aux mutualisations n'y a plus sa place).
  "features/wizard/parcImport/ParcImportModal.tsx",
  // Wizard — saisie et récap du parc d'équipes.
  "features/wizard/steps/TeamsStep.tsx",
  "features/wizard/steps/PeriodTeams.tsx",
  "features/wizard/steps/RecapStep.tsx",
  "features/wizard/steps/CoachesStep.tsx",
  "features/wizard/steps/SharedTrainingBlockPanel.tsx",
  "features/wizard/steps/SlotReservationModal.tsx",
  // Planning — tuiles et lignes où le gestionnaire scanne ses équipes.
  "features/planning/WeekGrid.tsx",
  "features/planning/ClubViewTable.tsx",
  "features/planning/SlotDetail.tsx",
  "features/planning/LocksPanel.tsx",
  // Matchs — grilles, listes et files d'équipes.
  "features/matches/TypicalWeekendGrid.tsx",
  "features/matches/WeekendGrid.tsx",
  "features/matches/MatchRowsTable.tsx",
  "features/matches/FbiEntryList.tsx",
  "features/matches/ConflictLine.tsx",
  "features/matches/AwayList.tsx",
  "features/matches/UnplacedList.tsx",
  "features/matches/PlacementPanel.tsx",
  "features/matches/ReviewQueue.tsx",
  "features/matches/IdealSlotsEditor.tsx",
  // Doléances — côté GESTIONNAIRE (la vue par coach / mutualisation).
  "features/coach-wishes/WishesTab.tsx",
  "features/coach-wishes/MutualizationForm.tsx",
  "features/coach-wishes/TeamPicker.tsx",
];

// Surfaces qui portent `teamLabel` SANS être une étiquette autonome — raison nominative.
const TEAM_LABEL_EXEMPTIONS: { file: string; reason: string }[] = [
  { file: "features/matches/AwayFixtureCard.tsx", reason: "le teamLabel vit dans la phrase « {équipe} joue à l'extérieur — … » (D-f : nom NU en contexte), pas une étiquette de scan" },
  { file: "features/matches/WeekWorkbench.tsx", reason: "ne REND pas le libellé : il le passe en prop à PlacementPanel (surface requise), c'est le conteneur/câblage" },
];

function isTeamLabelExempt(rel: string): boolean {
  return TEAM_LABEL_EXEMPTIONS.some((e) => e.file === rel);
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

describe("CtcMark — le repère d'entente (CTC) partout où le nom d'équipe s'affiche", () => {
  it("chaque surface d'affichage d'un nom d'équipe référence CtcMark", () => {
    const offenders: string[] = [];
    for (const rel of CTC_SURFACES) {
      const source = readFileSync(join(SRC_ROOT, rel), "utf8");
      if (!CTC_MARK.test(source)) {
        offenders.push(`${rel} → ne référence pas CtcMark (une équipe en entente y passe sans son repère)`);
      }
    }
    expect(
      offenders,
      "Chaque surface qui affiche un nom d'équipe doit rendre le repère CtcMark devant le nom (retour Rillieux #6 : « d'un coup d'œil, PARTOUT »). Voir shared/components/ui/ctc-mark.tsx.",
    ).toEqual([]);
  });

  it("toute surface portant teamLabel est classée (requise, référence CtcMark/TeamSelect, ou exemptée)", () => {
    const required = new Set(CTC_SURFACES);
    const offenders: string[] = [];
    for (const file of tsxFiles(join(SRC_ROOT, "features"))) {
      const rel = file.slice(SRC_ROOT.length + 1);
      const source = readFileSync(file, "utf8");
      if (!TEAM_LABEL.test(source)) continue;
      if (required.has(rel) || isTeamLabelExempt(rel)) continue;
      if (CTC_MARK.test(source) || TEAM_SELECT.test(source)) continue;
      offenders.push(rel);
    }
    expect(
      offenders,
      "Une surface qui projette un teamLabel n'est ni requise (CTC_SURFACES), ni porteuse de CtcMark/TeamSelect, ni exemptée (TEAM_LABEL_EXEMPTIONS avec sa raison). Classez-la : un nom d'équipe affiché sans repère d'entente est une régression du « PARTOUT ».",
    ).toEqual([]);
  });
});
