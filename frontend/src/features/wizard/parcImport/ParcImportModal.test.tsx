import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ModalReportProviderContext } from "@/shared/components/ui/modalReportContext";
import { dayLabelLongCap, dayLabelShort } from "@/shared/lib/days";
import { formatDuration } from "@/shared/lib/duration";
import { tierGroupLabel } from "@/shared/lib/teamTiers";
import { endTime, formatMinutes } from "@/shared/lib/time";
import { renderWithProviders } from "@/test/utils";

import { PARC_IMPORT_TEXTS } from "./texts";

/**
 * PROMESSES de la modale d'import du parc (`ParcImportModal`) — ASSISTANT en 5 étapes (StepRail :
 * Prompt · Coller · Équipes · Gymnases · Réservations, refonte « G2 » PR1). Elle consomme l'APERÇU
 * (dry-run) servi par `POST /api/clubs/{id}/parc-import/analyze` et laisse le gestionnaire TRANCHER
 * par étape avant l'import réel. Le front n'invente AUCUNE règle : verdicts, raisons et libellés
 * viennent du serveur, affichés verbatim.
 *
 * Contrat servi (mocké ICI au format EXACT de l'analyzer réel) :
 *   { format, fiabilite:{taux,anomalies,bandeau}, recap, alertes:[{type,message}],
 *     sections:{ <section>:[ {index,libelle,statut,coche,cibleId,raison,miseAJour?,groupe?,gymnaseId?,candidats?} ] } }
 *   sections = equipes | gymnases | creneaux | mutualisations | reservations (plus de coachs, D11) ;
 *   statut ∈ apparie | suggere | aCreer | ambigu | decoche | alerte ; `libelle`/`raison` = DONNÉES serveur.
 *
 * Contrat du composant : `ParcImportModal({ origin:"teams"|"venues"; clubId; seasonId;
 *   teams/venues: readonly {id,name}[]; club?; onClose })`. Une ligne équipe/gymnase porte UN
 * sélecteur `Listbox` de décision (apparier à un candidat / créer / ignorer) ; les réservations et
 * mutualisations vivent dans une GRILLE par gymnase (`ParcCaseGrid`), clic → éditeur de case.
 *
 * On mute la PROD (le vrai composant) : seuls les deux hooks de données (`./queries`) et, pour le
 * groupement par rang (3d), les queries d'équipes du wizard (`@/features/wizard/queries`) sont des
 * doubles contrôlables ; `buildParcPrompt`/`PARC_IMPORT_TEXTS` sont les vrais modules de la PR.
 *
 * Deux describe : (1) l'assistant 5 étapes & lignes ambiguës ; (2) les promesses TRANSVERSES
 * ré-exprimées sur la nouvelle UI après le retrait de l'ancien describe à cases (table promesse→test
 * dans le rapport TANK) + les écarts au plan (contrats ROUGES 3a-3d, option A).
 */

interface Line {
  index: number;
  libelle: string;
  statut: "apparie" | "suggere" | "aCreer" | "decoche" | "alerte";
  coche: boolean;
  cibleId: string | null;
  raison: string | null;
  /** PR1 (D5) : candidats d'un appariement suggéré/ambigu, offerts au sélecteur de la ligne. */
  candidats?: Array<{ id: string; nom: string }>;
  miseAJour?: { champ: "seances"; de: number; a: number };
  /**
   * Équipe (retours fondateur 2026-10-26) : le nombre de séances LU dans le fichier, servi sur
   * CHAQUE ligne équipe (pas seulement quand il diffère) — amorce le champ « Séances » éditable.
   */
  seances?: number;
  /**
   * Équipe APPARIÉE (retours fondateur 2026-10-26) : le nombre de séances ACTUEL de l'équipe visée
   * — l'indication « actuellement N » et la base de comparaison du champ (valeur = actuelle → pas
   * de correction ; valeur ≠ actuelle → `corrections.seances`).
   */
  seancesActuelles?: number;
  /** Mutualisation : le libellé du bloc dérivé (ex. « CEC »), affiché et corrigeable (D8). */
  groupe?: string | null;
  /** Mutualisation (P4-363) : le gymnase résolu du nom de la case (null si à créer) — amorce le sélecteur de gymnase. */
  gymnaseId?: string | null;
  /**
   * Réservation / mutualisation (bug grille) : la POSITION du créneau de SA case dérivée. Le front
   * place la case EXCLUSIVEMENT d'après elle, jamais `payload.cases[index]` (index = rang dans la
   * liste dérivée `reservations`/`mutualisations`, pas l'index de la case du parc).
   */
  position?: { gymnase: string; jour: number; debut: string; duree: number };
  /**
   * Équipe « Créer » (refonte PR2, D9) : les SUGGESTIONS de la règle FBI unique qui pré-remplissent
   * les Select Rang + Genre et la case Entente. `suggestedGender` est `null` quand aucun motif clair
   * ne le donne (« plus MIXTE ») — le gestionnaire doit alors trancher (genre obligatoire).
   */
  suggestedTierId?: number;
  suggestedGender?: "M" | "F" | "MIXTE" | null;
  suggestedCtc?: boolean;
  /**
   * PR A (incident ALAP) — séance commune / réservation en alerte « équipe ignorée/introuvable » :
   * le code MACHINE qui accompagne la `raison` humaine (le front le lit pour bloquer/orienter, D13).
   * Le mock le porte comme l'API.
   */
  alerteCode?: string;
}

interface Recap {
  total: number;
  apparie: number;
  suggere: number;
  aCreer: number;
  decoche: number;
  alerte: number;
}

type Section = "equipes" | "gymnases" | "creneaux" | "mutualisations" | "reservations";

interface Analysis {
  format: string;
  fiabilite: { taux: number; anomalies: number; bandeau: boolean };
  recap: Record<string, Recap>;
  sections: Record<string, Line[]>;
  alertes: Array<{ type: string; message: string }>;
  /**
   * Le parc NORMALISÉ renvoyé par l'analyse (relu par `ParcPayloadValidator::parse()`, G2 D4) : le
   * front l'amorce pour les brouillons ET le poste à l'import, au lieu de re-parser le texte collé
   * (qui peut n'être lisible QUE par la passe de récupération serveur). Le mock le porte comme l'API.
   */
  payload?: unknown;
}

function mkLine(index: number, libelle: string, statut: Line["statut"], extra: Partial<Line> = {}): Line {
  const coche = "apparie" === statut || "suggere" === statut || "aCreer" === statut;
  return { index, libelle, statut, coche, cibleId: null, raison: null, ...extra };
}

function recapOf(lines: Line[]): Recap {
  const r: Recap = { total: lines.length, apparie: 0, suggere: 0, aCreer: 0, decoche: 0, alerte: 0 };
  for (const l of lines) {
    r[l.statut] += 1;
  }
  return r;
}

function analysisOf(
  partial: Partial<Record<Section, Line[]>>,
  meta: { fiabilite?: Partial<Analysis["fiabilite"]>; alertes?: Analysis["alertes"]; payload?: unknown } = {},
): Analysis {
  const sections: Record<string, Line[]> = { equipes: [], gymnases: [], creneaux: [], mutualisations: [], reservations: [] };
  for (const key of Object.keys(sections) as Section[]) {
    sections[key] = partial[key] ?? [];
  }
  const recap: Record<string, Recap> = {};
  for (const key of Object.keys(sections)) {
    recap[key] = recapOf(sections[key]);
  }
  return {
    format: "parc-v2",
    fiabilite: { taux: 1, anomalies: 0, bandeau: false, ...meta.fiabilite },
    recap,
    sections,
    alertes: meta.alertes ?? [],
    payload: meta.payload,
  };
}

/**
 * Le nom accessible du trigger `Listbox` = aria-label SUIVI de la valeur/placeholder (patron
 * `listbox.tsx`, `aria-labelledby=[labelId, valueId]`) : « Gymnase … — U11M1 + U11M2 Créer » au
 * repos. On matche donc le PRÉFIXE `venueForGroup(libelle)` (clé `texts.ts` gardée comme source) via
 * un matcher FONCTION de testing-library — pas de `RegExp` construite (convention du dépôt, Semgrep
 * `detect-non-literal-regexp` ; cf. `accentTokenParity.test.ts`).
 */
function venueForGroupTrigger(libelle: string): (accessibleName: string) => boolean {
  const prefix = PARC_IMPORT_TEXTS.venueForGroup(libelle);
  return (accessibleName) => accessibleName.startsWith(prefix);
}

const q = vi.hoisted(() => ({
  analysis: { value: null as unknown },
  analyzePending: { value: false },
  importPending: { value: false },
  importResult: { value: null as unknown },
  // Refonte PR1 — l'erreur d'IMPORT est surfacée DANS la modale (role="alert"), plus un toast (le
  // hook garde le toast pour l'ANALYSE). Le double contrôle l'expose comme le vrai hook.
  importError: { value: null as unknown },
  // D10 — comme le vrai hook : l'échec et l'attente de l'analyse se lisent sur le hook, et le composant
  // se re-rend quand ils changent (`rerender`, posé par le double à chaque rendu).
  analyzeError: { value: null as unknown },
  rerender: { fn: () => {} },
  analyzeMutate: vi.fn(),
  importMutate: vi.fn(),
}));

vi.mock("./queries", async () => {
  const { useReducer } = await vi.importActual<typeof import("react")>("react");
  return {
    useAnalyzeParc: () => {
      const [, rerender] = useReducer((n: number) => n + 1, 0);
      q.rerender.fn = rerender;
      return { mutate: q.analyzeMutate, isPending: q.analyzePending.value, data: q.analysis.value, error: q.analyzeError.value, isError: null !== q.analyzeError.value };
    },
    useImportParc: () => ({
      mutate: q.importMutate,
      isPending: q.importPending.value,
      data: q.importResult.value,
      error: q.importError.value,
      isError: null !== q.importError.value,
    }),
  };
});

// 3d — le groupe « Toutes les équipes du club » du sélecteur est rangé PAR RANG via `groupTeamsByTier`.
// NEO lit les équipes COMPLÈTES (+ rangs) via les queries EXISTANTES du wizard (`useWizardTeams` /
// `usePriorityTiers`) SANS changer les props d'ouverture de la modale (`teams: {id,name}[]`). On mute
// donc ces deux hooks (rien d'autre dans l'arbre de la modale ne les importe). Fixtures via `vi.hoisted`
// (la factory `vi.mock` est hoistée) : des équipes de rangs DIFFÉRENTS (S et D).
const w = vi.hoisted(() => ({
  tiers: [
    { id: 1, label: "S", name: "Fanion" },
    { id: 5, label: "D", name: "Départemental" },
  ],
  teams: [
    { id: "wt1", name: "SM1", priorityTierId: 1, tierOrder: 0 },
    { id: "wt2", name: "U13 Masculins", priorityTierId: 5, tierOrder: 0 },
  ],
}));

vi.mock("@/features/wizard/queries", () => ({
  useWizardTeams: () => ({ data: w.teams }),
  usePriorityTiers: () => ({ data: w.tiers }),
}));

import { ParcImportModal } from "./ParcImportModal";

const defaultProps = {
  origin: "teams" as const,
  clubId: "club-1",
  seasonId: "season-1",
  // {id,name}[] (D5) : le prompt n'en lit que les NOMS, le ré-appariement a besoin des id.
  // PLUS de `coaches` (D11, coachs retirés du format ET de l'écran).
  teams: [{ id: "t1", name: "Séniors Garçons 1" }, { id: "t2", name: "U13 Masculins" }] as const,
  venues: [{ id: "v1", name: "Gymnase Léon-Blum" }] as const,
  onClose: () => {},
};

/**
 * « Importer » n'est actif qu'une fois TOUTES les étapes visitées (finitions d'interface, 30/10) : un
 * test qui exerce le CORPS de l'import passe d'abord par chaque étape de contenu, par le rail. Il finit
 * donc sur « Réservations » : l'appeler juste avant le clic sur « Importer ».
 */
async function visitAllSteps(user: ReturnType<typeof userEvent.setup>): Promise<void> {
  const labels = [
    PARC_IMPORT_TEXTS.sectionTitle("equipes"),
    PARC_IMPORT_TEXTS.stepEntentes,
    PARC_IMPORT_TEXTS.sectionTitle("gymnases"),
    PARC_IMPORT_TEXTS.sectionTitle("mutualisations"),
    PARC_IMPORT_TEXTS.sectionTitle("reservations"),
  ];
  for (const label of labels) {
    await user.click(within(screen.getByRole("navigation")).getByRole("button", { name: (n: string) => n.includes(label) }));
  }
}

beforeEach(() => {
  q.analyzeMutate.mockReset();
  q.analysis.value = null;
  q.importResult.value = null;
  q.importError.value = null;
  q.analyzePending.value = false;
  q.analyzeError.value = null;
  q.importPending.value = false;
  // mutate(texte, { onSuccess }) rend l'analyse mockée (pré-posée par le test) — comme l'API.
  q.analyzeMutate.mockImplementation((_text: unknown, opts?: { onSuccess?: (a: unknown) => void }) => {
    q.analyzeError.value = null;
    opts?.onSuccess?.(q.analysis.value);
  });
  // onError déclenché quand un test pré-pose une erreur d'import ; sinon onSuccess (comme l'API).
  q.importMutate.mockImplementation((_sel: unknown, opts?: { onSuccess?: (r: unknown) => void; onError?: (e: unknown) => void }) => {
    if (null !== q.importError.value) {
      opts?.onError?.(q.importError.value);
    } else {
      opts?.onSuccess?.(q.importResult.value);
    }
  });
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.clearAllMocks();
});


/**
 * ─────────── Refonte de l'aperçu (lot correctif import IA « G2 » PR1, passe ui-ux-pro-max) ───────────
 *
 * La modale passe d'un empilement 4-temps à un ASSISTANT en 5 étapes (StepRail : Prompt · Coller ·
 * Équipes · Gymnases · Réservations). Nouvelles PROMESSES prouvées ici (option A — TANK écrit ROUGE,
 * NEO implémente la modale + les nouvelles clés de `texts.ts`) :
 *  - StepRail 5 étapes ; étapes 3-5 VERROUILLÉES tant que l'analyse n'a pas réussi ;
 *  - analyse réussie → passage AUTO à « Équipes » ;
 *  - une ligne AMBIGUË porte UN sélecteur `Listbox` SANS présélection (placeholder « À trancher… »)
 *    et « Importer » est `aria-disabled` AVEC sa raison tant qu'un ambigu n'est pas tranché ;
 *  - « Apparier à X » → `decision.cibleId = X` ; « Ignorer cette ligne » → `coche:false` ;
 *    « Créer » → cochée, SANS cibleId ;
 *  - une erreur d'IMPORT s'affiche DANS la modale en `role="alert"` (plus un toast — le hook garde
 *    le toast pour l'analyse) ;
 *  - « Revérifier » reposte `{payload, decisions}` à l'analyse (rejeu avec corrections).
 *
 * ⚠ Clés de `texts.ts` RÉFÉRENCÉES ET PAS ENCORE CRÉÉES (rouge légitime, NEO les crée) :
 *   `stepPaste` (« Coller »), `next`/`previous`, `lockedUntilAnalysis`, `decisionFor(libelle)`,
 *   `matchTo(nom)`/`matchToSuggested(nom)`, `createOption(libelle)`, `ignoreLine`, `toDecide`
 *   (placeholder « À trancher… »), `importBlocked(nAmbigu, nGenre)`, `verifyAction`, `importErrorHeading`.
 *   Les libellés d'étapes 3-5 réutilisent `sectionTitle("equipes"|"gymnases"|"reservations")` (existantes).
 */

/** Une ligne AMBIGUË (nouveau statut PR1) : décochée, aucune cible, mais PORTE ses candidats à trancher. */
function mkAmbiguous(index: number, libelle: string, candidats: Array<{ id: string; nom: string }>): Line {
  return {
    index,
    libelle,
    statut: "ambigu",
    coche: false,
    cibleId: null,
    raison: "Plusieurs correspondances possibles — à choisir à la main.",
    candidats,
  } as unknown as Line;
}

describe("ParcImportModal — assistant 5 étapes & lignes ambiguës (refonte import IA PR1)", () => {
  const T = PARC_IMPORT_TEXTS as unknown as Record<string, (...args: unknown[]) => string> & Record<string, string>;

  /** Matcher FONCTION (nom accessible CONTENANT le texte) — jamais une `RegExp` construite (Semgrep). */
  const stepNamed = (label: string) => (accessibleName: string) => accessibleName.includes(label);

  /**
   * Trigger `Listbox` d'une ligne de décision : son nom accessible = aria-label SUIVI de la
   * valeur/placeholder (patron `listbox.tsx`, `aria-labelledby=[labelId, valueId]` — « Décision pour
   * « Armond » À trancher… » au repos), exactement comme `venueForGroupTrigger` plus haut. On matche
   * donc le PRÉFIXE `decisionFor(libelle)` via un matcher FONCTION (adaptation MÉCANIQUE de la query
   * au contrat réel du primitive partagé — cf. rapport NEO), jamais une string exacte.
   */
  const decisionTrigger = (libelle: string) => (accessibleName: string) => accessibleName.startsWith(T.decisionFor(libelle));

  /** Dépose le collage sur l'étape « Coller » puis analyse (auto-avance ensuite vers « Équipes »). */
  async function analyzeThen(user: ReturnType<typeof userEvent.setup>, analysis: Analysis): Promise<void> {
    q.analysis.value = analysis;
    // Étape 2 « Coller » : le bouton du rail porte son NUMÉRO dans le nom accessible (« 2 Coller »,
    // patron `step-rail.tsx`), d'où le matcher FONCTION `stepNamed` (adaptation MÉCANIQUE de la query
    // au contrat réel du primitive partagé — cf. rapport NEO), comme le test « StepRail » plus bas.
    await user.click(screen.getByRole("button", { name: stepNamed(T.stepPaste as unknown as string) }));
    fireEvent.change(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel }), { target: { value: '{"format":"parc-v2","cases":[]}' } });
    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.analyze }));
  }

  it("StepRail — les étapes de contenu (Équipes/Gymnases/Mutualisations/Réservations) sont verrouillées avant l'analyse", () => {
    // Retours fondateur 2026-10-26 — les mutualisations ont désormais leur PROPRE étape de contenu,
    // et les réservations passent EN FIN : Prompt · Coller · Équipes · Gymnases · Mutualisations ·
    // Réservations. On teste par NOMS d'étapes (jamais par un compte figé : une étape « Ententes »
    // viendra en PR2 après Équipes) — chaque étape de contenu est présente MAIS verrouillée avant
    // l'analyse (bouton désactivé) : on ne peut pas la visiter tant que rien n'a été analysé.
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    for (const section of ["equipes", "gymnases", "mutualisations", "reservations"] as const) {
      const stepButton = screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle(section)) });
      expect(stepButton).toBeDisabled();
    }
  });

  it("analyse réussie → passage automatique à l'étape « Équipes »", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysisOf({ equipes: [mkLine(0, "SM1", "aCreer")] }));

    // L'étape « Équipes » devient l'étape courante (aria-current="step" posé par StepRail).
    const equipesStep = screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle("equipes")) });
    expect(equipesStep).toHaveAttribute("aria-current", "step");
  });

  it("ligne ambiguë → sélecteur « À trancher… » sans présélection, et « Importer » désactivé AVEC sa raison", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({ equipes: [mkAmbiguous(0, "Armond", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }])] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);

    // Le sélecteur de la ligne est présent, SANS valeur présélectionnée (placeholder « À trancher… »).
    const trigger = await screen.findByRole("button", { name: decisionTrigger("Armond") });
    expect(within(trigger).getByText(T.toDecide as unknown as string)).toBeInTheDocument();

    // Tant que l'ambigu n'est pas tranché, « Importer » est aria-disabled AVEC sa raison (A11Y-30).
    const importBtn = screen.getByRole("button", { name: /Importer/ });
    expect(importBtn).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByText(T.importBlocked(1, 0))).toBeInTheDocument();
  });

  it("une ligne ambiguë n'affiche sa raison QU'UNE fois (la ligne ⚠), jamais en double (défaut C)", async () => {
    // Capture réelle 03-equipes.png : sur une ligne ambiguë, la raison SERVEUR s'affichait DEUX fois
    // — en gris sous le libellé ET sur la ligne ⚠. Une seule doit rester (la ligne ⚠). La raison est
    // une DONNÉE serveur (affichée verbatim) : le test l'assert telle quelle, pas une clé de texts.
    const user = userEvent.setup();
    const raison = "Plusieurs correspondances possibles — appariement ambigu, à choisir à la main.";
    const line = { ...mkAmbiguous(0, "U15M 1", [{ id: "t1", nom: "U15F1" }, { id: "t2", nom: "U15M1" }]), raison };
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysisOf({ equipes: [line] }));

    // La ligne est rendue (son sélecteur de décision est là) puis la raison n'apparaît qu'UNE fois.
    await screen.findByRole("button", { name: decisionTrigger("U15M 1") });
    expect(screen.getAllByText(raison)).toHaveLength(1);
  });

  it("« Apparier à X » sur une ligne ambiguë → la décision porte cibleId = X", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({
      equipes: [mkAmbiguous(0, "Armond", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }])],
      gymnases: [mkLine(0, "Palais des Sports", "aCreer")], // ligne cochée pour garder « Importer » actif
    });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);

    await user.click(await screen.findByRole("button", { name: decisionTrigger("Armond") }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: T.matchTo("Armand") }));
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; coche: boolean; cibleId?: string | null }> };
    const dec = body.decisions.find((d) => "equipes" === d.section && 0 === d.index);
    expect(dec?.cibleId).toBe("t1");
    expect(dec?.coche).toBe(true);
  });

  it("« Ignorer cette ligne » sur une ligne ambiguë → la décision porte coche:false", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({
      equipes: [mkAmbiguous(0, "Armond", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }])],
      gymnases: [mkLine(0, "Palais des Sports", "aCreer")],
    });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);

    await user.click(await screen.findByRole("button", { name: decisionTrigger("Armond") }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: T.ignoreLine as unknown as string }));
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; coche: boolean; cibleId?: string | null }> };
    const dec = body.decisions.find((d) => "equipes" === d.section && 0 === d.index);
    expect(dec?.coche).toBe(false);
    expect(dec?.cibleId ?? null).toBeNull();
  });

  it("« Créer » sur une ligne ambiguë → décision cochée, SANS cibleId (nouvelle entité)", async () => {
    const user = userEvent.setup();
    // PR2 — la ligne ambiguë porte désormais ses suggestions (l'analyzer les sert sur TOUTE ligne) :
    // « Créer » ouvre le Select Genre OBLIGATOIRE ; le gestionnaire tranche un genre avant d'importer
    // (changement de contrat voulu — genre obligatoire —, pas un affaiblissement).
    const line = { ...mkAmbiguous(0, "Armond", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }]), suggestedTierId: 5, suggestedGender: null, suggestedCtc: false } as Line;
    const analysis = analysisOf({ equipes: [line] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);

    await user.click(await screen.findByRole("button", { name: decisionTrigger("Armond") }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: T.createOption("Armond") }));
    await user.selectOptions(screen.getByRole("combobox", { name: T.genderOf("Armond") }), "MIXTE");
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; coche: boolean; cibleId?: string | null }> };
    const dec = body.decisions.find((d) => "equipes" === d.section && 0 === d.index);
    expect(dec?.coche).toBe(true);
    expect(dec?.cibleId ?? null).toBeNull();
  });

  it("équipe appariée — « Séances » est un CHAMP éditable pré-rempli de la valeur du fichier, « actuellement N », et la valeur ≠ actuelle part en corrections.seances (plus de case à cocher)", async () => {
    // Retours fondateur 2026-10-26 CONFIRMÉS : la case « Séances : 2 → 1 (mise à jour proposée) » est
    // remplacée par un CHAMP NUMÉRIQUE lisible d'un coup d'œil, pré-rempli avec ce qui SERA importé (la
    // valeur du FICHIER, `seances`), corrigeable avant import, avec l'indication « actuellement N »
    // (clé `sessionsCurrent`). Fichier (3) ≠ actuelle (2) → la correction part telle quelle : l'import
    // porte `corrections.seances = 3`. Le nom accessible du champ est CONTEXTUALISÉ (clé
    // `sessionsFieldLabel`, « Séances de SM1 »). PLUS aucune case à cocher de séances.
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkLine(0, "SM1", "apparie", { cibleId: "t1", seances: 3, seancesActuelles: 2 })] }));

    // Le champ (rôle spinbutton = <input type="number">) est pré-rempli avec la valeur du FICHIER (3).
    const field = screen.getByRole("spinbutton", { name: T.sessionsFieldLabel("SM1") });
    expect(field).toHaveValue(3);
    // E6 (disposition en COLONNES) — « actuellement N » n'est plus un texte VISIBLE sous la ligne : il
    // vit dans l'INFOBULLE du champ Séances (title/aria-describedby). La promesse « l'équipe appariée
    // montre ses séances actuelles » se vérifie donc par la DESCRIPTION ACCESSIBLE du champ, toujours
    // par la CLÉ `sessionsCurrent` (jamais la phrase recopiée).
    expect(field).toHaveAccessibleDescription(new RegExp(T.sessionsCurrent(2)));
    // Plus de case à cocher de séances sur la ligne.
    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();

    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));
    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; corrections?: Record<string, unknown> }> };
    expect(body.decisions.find((d) => "equipes" === d.section && 0 === d.index)?.corrections?.seances).toBe(3);
  });

  it("équipe appariée — ramener le champ « Séances » à la valeur ACTUELLE n'émet aucune corrections.seances", async () => {
    // Le pendant : fichier (3), actuelle (2) → pré-rempli 3. Le gestionnaire ramène le champ à 2
    // (= l'actuelle) : aucune correction ne part (valeur = actuelle → rien à corriger).
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkLine(0, "SM1", "apparie", { cibleId: "t1", seances: 3, seancesActuelles: 2 })] }));

    fireEvent.change(screen.getByRole("spinbutton", { name: T.sessionsFieldLabel("SM1") }), { target: { value: "2" } });
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; corrections?: Record<string, unknown> }> };
    expect(body.decisions.find((d) => "equipes" === d.section && 0 === d.index)?.corrections?.seances).toBeUndefined();
  });

  it("une erreur d'import s'affiche DANS la modale en role=\"alert\" (plus un toast)", async () => {
    const user = userEvent.setup();
    // L'import échoue : le hook expose l'erreur, la modale la surface en role="alert" (micro-décision 13).
    q.importError.value = { response: { status: 422 }, data: { errors: ["L'équipe n°1 : appariement ambigu, à choisir."] } };
    const analysis = analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const alert = await screen.findByRole("alert");
    expect(within(alert).getByText(T.importErrorHeading as unknown as string)).toBeInTheDocument();
  });

  it("« Revérifier » reposte { payload, decisions } à l'analyse (rejeu avec corrections)", async () => {
    const user = userEvent.setup();
    const payload = { format: "parc-v2", gymnases: [{ nom: "Palais des Sports" }], cases: [] };
    // D10 — le bouton n'est actif qu'une fois l'aperçu périmé : on décoche d'abord une équipe.
    const analysis = analysisOf({ equipes: [mkLine(0, "SF1", "aCreer")], gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await user.click(await screen.findByRole("button", { name: decisionTrigger("SF1") }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: T.ignoreLine as unknown as string }));
    await user.click(await screen.findByRole("button", { name: T.verifyAction as unknown as string }));

    // La revalidation passe une ENVELOPPE { payload, decisions } (jamais le texte brut).
    const envelopeCall = q.analyzeMutate.mock.calls.find((c) => "object" === typeof c[0] && null !== c[0]);
    expect(envelopeCall, "revalider doit reposter un objet { payload, decisions }").toBeDefined();
    const body = envelopeCall?.[0] as { payload: unknown; decisions: unknown };
    expect(body).toHaveProperty("payload");
    expect(Array.isArray(body.decisions)).toBe(true);
  });

  // Visite une étape de CONTENU du rail (déverrouillée après l'analyse) — bouton nommé par son titre.
  const goToStep = async (user: ReturnType<typeof userEvent.setup>, section: "equipes" | "gymnases" | "mutualisations" | "reservations"): Promise<void> => {
    await user.click(screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle(section)) }));
  };

  // ─────────── Promesses transverses ré-exprimées sur la nouvelle UI (retrait de l'ancien describe) ───────────

  it("le prompt pré-rempli embarque le périmètre du club et se copie (étape Prompt)", async () => {
    const user = userEvent.setup();
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.copyPrompt }));

    await waitFor(() => expect(writeText).toHaveBeenCalledTimes(1));
    const copied = writeText.mock.calls[0]?.[0] as string;
    expect(copied).toContain("Séniors Garçons 1");
    expect(copied).toContain("Gymnase Léon-Blum");
  });

  it("le prompt copié embarque le contexte club (nom, ville) quand le club existe", async () => {
    const user = userEvent.setup();
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
    renderWithProviders(<ParcImportModal {...defaultProps} club={{ name: "Basket Club Réaliste", city: "Rillieux" }} />);

    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.copyPrompt }));

    await waitFor(() => expect(writeText).toHaveBeenCalledTimes(1));
    const copied = writeText.mock.calls[0]?.[0] as string;
    expect(copied).toContain("Basket Club Réaliste");
    expect(copied).toContain("Rillieux");
  });

  it("l'aide de collage recommande les assistants GPT, Gemini, Claude et Copilot", () => {
    // Contenu de la CLÉ de texte centralisée (jamais une phrase recopiée).
    for (const assistant of ["GPT", "Gemini", "Claude", "Copilot"]) {
      expect(PARC_IMPORT_TEXTS.promptHelp).toContain(assistant);
    }
  });

  it("s'ouvre aussi bien depuis Équipes que depuis Gymnases (deux portes, même assistant)", () => {
    const { unmount } = renderWithProviders(<ParcImportModal {...defaultProps} origin="teams" />);
    expect(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.copyPrompt })).toBeInTheDocument();
    unmount();

    renderWithProviders(<ParcImportModal {...defaultProps} origin="venues" />);
    expect(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.copyPrompt })).toBeInTheDocument();
  });

  it("au clic Importer, l'import reçoit { payload, decisions } au format PR2 et le récap s'affiche", async () => {
    const user = userEvent.setup();
    q.importResult.value = { recap: { gymnases: { crees: 1, apparies: 0, misAJour: 0, ignores: 0 } } };
    const payload = { format: "parc-v2", gymnases: [{ nom: "Palais des Sports" }], cases: [] };
    const analysis = analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    expect(q.importMutate).toHaveBeenCalledTimes(1);
    const body = q.importMutate.mock.calls[0]?.[0] as { payload: unknown; decisions: Array<{ section: string; index: number; coche: boolean }> };
    expect(body).toHaveProperty("payload");
    expect(Array.isArray(body.decisions)).toBe(true);
    const dec = body.decisions.find((d) => "gymnases" === d.section && 0 === d.index);
    expect(dec?.coche).toBe(true);
    // Le récap PR2 (données serveur) s'affiche ; section + compte dans le MÊME <li> → match par sous-chaîne.
    expect(await screen.findByText(PARC_IMPORT_TEXTS.sectionRecap(1, 0, 0, 0), { exact: false })).toBeInTheDocument();
  });

  it("D4 — un collage que seule la récupération serveur relit (guillemets typographiques) : l'import poste le payload NORMALISÉ de l'analyse (jamais null)", async () => {
    const user = userEvent.setup();
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "Gymnase Léon-Blum" }],
      cases: [{ gymnase: "Gymnase Léon-Blum", jour: 2, debut: "18:00", duree: 90, equipes: [] }],
    };
    q.importResult.value = { recap: {} };
    const analysis = analysisOf({ creneaux: [mkLine(0, "Gymnase Léon-Blum · jour 2 18:00", "aCreer")] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    // L'import poste le payload NORMALISÉ (que le serveur a relu), jamais le texte brut ni null.
    const body = q.importMutate.mock.calls[0]?.[0] as { payload: unknown };
    expect(body.payload).not.toBeNull();
    expect(body.payload).toEqual(payload);
  });

  it("D12 + heure — l'éditeur de case s'amorce depuis le payload (18:00), corrige gymnase et heure → cibleId + corrections.debut du créneau", async () => {
    const user = userEvent.setup();
    const venues = [{ id: "v1", name: "Gymnase Léon-Blum" }, { id: "v9", name: "Palais des Sports" }] as const;
    const libelle = "Gymnaze Leon Blum · jour 2 18:00";
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "Gymnaze Leon Blum" }],
      cases: [{ gymnase: "Gymnaze Leon Blum", jour: 2, debut: "18:00", duree: 90, equipes: [] }],
    };
    const analysis = analysisOf({ creneaux: [mkLine(0, libelle, "aCreer")] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} venues={venues} />);

    await analyzeThen(user, analysis);
    // Retours fondateur 2026-10-26 — les CRÉNEAUX (ouvertures de gymnase) vivent désormais à l'étape
    // GYMNASES (une grille par gymnase, iso-wizard), plus à l'étape Réservations.
    await goToStep(user, "gymnases");

    // La case du créneau est un BOUTON de la grille (nom accessible = le libellé) ; clic → éditeur.
    await user.click(await screen.findByRole("button", { name: /18:00/ }));
    // Amorce depuis payload.cases : l'heure de début est déjà 18:00 (jamais re-parsée du texte brut).
    expect(screen.getByDisplayValue("18:00")).toBeInTheDocument();

    // Corriger le gymnase (sélecteur de case → cibleId du créneau, D12) et l'heure.
    await user.click(screen.getByRole("button", { name: (n: string) => n.startsWith(T.editVenue(libelle)) }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: "Gymnase Léon-Blum" }));
    fireEvent.change(screen.getByLabelText(T.editStart(libelle)), { target: { value: "19:00" } });
    await user.click(screen.getByRole("button", { name: T.save as unknown as string }));

    // D10 — corriger une case périme l'aperçu : l'import ne repart qu'après la revérification, et celle-ci
    // ne perd pas la correction (l'API rend le parc collé, jamais la case corrigée).
    await user.click(screen.getByRole("button", { name: T.verifyAction as unknown as string }));
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; cibleId?: string | null; corrections?: Record<string, unknown> }> };
    const dec = body.decisions.find((d) => "creneaux" === d.section && 0 === d.index);
    expect(dec?.cibleId).toBe("v1");
    expect(dec?.corrections?.debut).toBe("19:00");
  });

  // ─────────── Promesses transverses MANQUANTES dans la refonte → contrats ROUGES (à ré-ajouter) ───────────

  it("bandeau — un gymnase « à créer » prévient qu'il naîtra sans adresse (ROUGE : bandeau retiré à la refonte)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }));
    await goToStep(user, "gymnases");

    expect(await screen.findByText(PARC_IMPORT_TEXTS.venuesCreatedWithoutAddress)).toBeInTheDocument();
  });

  it("D9 — les alertes transverses du serveur sont affichées verbatim (ROUGE : plus rendues à la refonte)", async () => {
    const user = userEvent.setup();
    const alertes = [
      { type: "equipe_deux_endroits", message: "L'équipe « U11M1 » est placée dans deux gymnases au même moment — à trancher à la main." },
      { type: "heure_implausible", message: "Un créneau commence à 06:00 (gymnase « Palais des Sports ») — horaire inhabituel à vérifier." },
    ];
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    // Les alertes sont un résumé « points à vérifier » montré sur l'étape de contenu où l'on atterrit.
    await analyzeThen(user, analysisOf({ equipes: [mkLine(0, "SM1", "aCreer")] }, { alertes }));

    for (const alerte of alertes) {
      expect(await screen.findByText(alerte.message)).toBeInTheDocument();
    }
  });

  it("D10 — un résultat peu fiable affiche un bandeau, sans jamais bloquer l'import (ROUGE : bandeau retiré à la refonte)", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }, { fiabilite: { taux: 0.2, anomalies: 4, bandeau: true } });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);

    expect(await screen.findByText(PARC_IMPORT_TEXTS.unreliableResult)).toBeInTheDocument();
    // L'import n'est PAS bloqué par la fiabilité : une ligne cochée → bouton actif.
    await visitAllSteps(user);
    expect(screen.getByRole("button", { name: /Importer/ })).not.toHaveAttribute("aria-disabled", "true");
  });

  // ─────────── Écart au plan 3b — réservations & mutualisations en GRILLE, plus de case à cocher (ROUGE) ───────────

  it("3b — l'étape Réservations ne porte AUCUNE case à cocher (la grille fait foi)", async () => {
    const user = userEvent.setup();
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "Gymnase Léon-Blum" }],
      cases: [
        { gymnase: "Gymnase Léon-Blum", jour: 2, debut: "18:00", duree: 90, equipes: ["SM1"] },
        { gymnase: "Gymnase Léon-Blum", jour: 3, debut: "17:00", duree: 90, equipes: ["U11M1", "U11M2"] },
      ],
    };
    const analysis = analysisOf(
      {
        reservations: [mkLine(0, "SM1 · Gymnase Léon-Blum 18:00", "decoche", { raison: "Réservation déjà présente dans le planning." })],
        mutualisations: [mkLine(0, "U11M1 + U11M2", "alerte", { raison: "Les séances communes dépassent les séances hebdomadaires — à ajuster à la main." })],
      },
      { payload },
    );
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "reservations");

    // Décision fondateur : réservations ET mutualisations en GRILLE par gymnase, plus de case à cocher.
    expect(screen.queryAllByRole("checkbox")).toHaveLength(0);
    // La composition d'une mutualisation n'est pas corrigeable inline (pas de champ amorcé sur l'ensemble).
    expect(screen.queryByDisplayValue("U11M1 + U11M2")).not.toBeInTheDocument();
  });

  it("3b/D8/P4-363 — une mutualisation se corrige à l'étape MUTUALISATIONS : gymnase (→ venueId) + nom de groupe (→ corrections.groupe), jamais via une case à cocher", async () => {
    // Retours fondateur 2026-10-26 — les mutualisations ont leur PROPRE étape (le gestionnaire doit
    // VOIR qu'il y a des mutualisations à vérifier). Les contrôles P4-363 (sélecteur de gymnase de la
    // réservation de groupe → `venueId`) et D8 (nom du groupe → `corrections.groupe`) vivent sur la
    // ligne de cette étape (clés EXISTANTES `venueForGroup`/`groupLabel`), pas dans un éditeur de case.
    const user = userEvent.setup();
    const venues = [{ id: "v1", name: "Gymnase Léon-Blum" }, { id: "v2", name: "Salle Mont Blanc" }] as const;
    const libelle = "U11M1 + U11M2";
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "Gymnase Léon-Blum" }],
      cases: [{ gymnase: "Gymnase Léon-Blum", jour: 3, debut: "17:00", duree: 90, equipes: ["U11M1", "U11M2"], groupe: "CEC" }],
    };
    const position = { gymnase: "Gymnase Léon-Blum", jour: 3, debut: "17:00", duree: 90 };
    const analysis = analysisOf({ mutualisations: [mkLine(0, libelle, "aCreer", { groupe: "CEC", gymnaseId: null, position })] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} venues={venues} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "mutualisations");

    // Le sélecteur de gymnase de la réservation de groupe (P4-363) sur la ligne → venueId ≠ cibleId.
    await user.click(await screen.findByRole("button", { name: venueForGroupTrigger(libelle) }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: "Salle Mont Blanc" }));
    // Et le nom de groupe (D8) → corrections.groupe.
    fireEvent.change(screen.getByRole("textbox", { name: (n: string) => n.startsWith(T.groupLabel(libelle)) }), { target: { value: "Section Loisirs" } });

    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; cibleId?: string | null; venueId?: string | null; corrections?: Record<string, unknown> }> };
    const dec = body.decisions.find((d) => "mutualisations" === d.section && 0 === d.index);
    expect(dec?.venueId).toBe("v2");
    expect(dec?.cibleId ?? null).toBeNull();
    expect(dec?.corrections?.groupe).toBe("Section Loisirs");
  });

  // ─────────── Bug grille — chaque réservation est placée sur SON créneau (position serveur, ROUGE) ───────────

  it("bug grille — une réservation est placée d'après sa `position` serveur, jamais payload.cases[index]", async () => {
    const user = userEvent.setup();
    const venues = [{ id: "v1", name: "Gymnase Alpha" }, { id: "v2", name: "Gymnase Beta" }] as const;
    // La ligne réservation porte l'index 0, mais SA position (créneau dérivé) est « Gymnase Beta jeudi
    // 20:30 / 1h » — DIFFÉRENTE de payload.cases[0] (« Gymnase Alpha lundi 18:00 / 1h30 »). Le front
    // doit placer la case d'après `position`, jamais `payload.cases[index]` (index = rang dans
    // derived['reservations'], pas la case : une case à 2 équipes séparées décale tout le reste).
    const libelle = "SM1 · Gymnase Beta 20:30";
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "Gymnase Alpha" }, { nom: "Gymnase Beta" }],
      cases: [{ gymnase: "Gymnase Alpha", jour: 1, debut: "18:00", duree: 90, equipes: ["SM1", "SF1"], terrains_separes: true }],
    };
    const position = { gymnase: "Gymnase Beta", jour: 4, debut: "20:30", duree: 60 };
    const analysis = analysisOf({ reservations: [mkLine(0, libelle, "aCreer", { position })] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} venues={venues} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "reservations");

    // La case tombe sous le gymnase de `position` (Beta), jamais celui de cases[0] (Alpha).
    expect(await screen.findByRole("heading", { name: "Gymnase Beta" })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Gymnase Alpha" })).not.toBeInTheDocument();

    // L'éditeur s'amorce avec le jour / l'heure / la durée de `position` (jeudi 20:30 / 1h), jamais
    // ceux de cases[0] (lundi 18:00 / 1h30). La case ne RÉPÈTE plus le gymnase (déjà en en-tête,
    // défaut A refondu) : son nom accessible est le libellé sans « Gymnase Beta ».
    await user.click(await screen.findByRole("button", { name: "SM1 · 20:30" }));
    expect(screen.getByDisplayValue("20:30")).toBeInTheDocument();
    expect(screen.getByLabelText(T.editDay(libelle))).toHaveValue("4");
    expect(within(screen.getByRole("combobox", { name: "Durée" })).getByRole("option", { name: formatDuration(60) })).toBeInTheDocument();
  });

  // ─────────── Écart au plan 3a — signal de création EXPLICITE sur une ligne ambiguë (ROUGE) ───────────

  it("3a — « Créer « … » » sur une ligne ambiguë envoie le signal de création explicite creer:true", async () => {
    const user = userEvent.setup();
    // PR2 — la ligne ambiguë porte ses suggestions (servies sur TOUTE ligne) : « Créer » ouvre le
    // Select Genre OBLIGATOIRE, tranché avant d'importer (changement de contrat voulu, genre obligatoire).
    const line = { ...mkAmbiguous(0, "Armond", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }]), suggestedTierId: 5, suggestedGender: null, suggestedCtc: false } as Line;
    const analysis = analysisOf({ equipes: [line] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);

    await user.click(await screen.findByRole("button", { name: decisionTrigger("Armond") }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: T.createOption("Armond") }));
    await user.selectOptions(screen.getByRole("combobox", { name: T.genderOf("Armond") }), "MIXTE");
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; coche: boolean; cibleId?: string | null; creer?: boolean }> };
    const dec = body.decisions.find((d) => "equipes" === d.section && 0 === d.index);
    // Distinct du refus d'ambiguïté serveur : « Créer » explicite porte creer:true (coché, sans cibleId).
    expect(dec?.creer).toBe(true);
    expect(dec?.coche).toBe(true);
    expect(dec?.cibleId ?? null).toBeNull();
  });

  // ─────────── Écart au plan 3c — l'éditeur de case réutilise le contrôle de durée partagé (ROUGE) ───────────

  it("3c — la durée d'une case se choisit via le contrôle partagé DurationSelect (combobox « Durée »), le même que la modale de réservation du wizard — pas un champ nombre ad hoc", async () => {
    const user = userEvent.setup();
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "Gymnase Léon-Blum" }],
      cases: [{ gymnase: "Gymnase Léon-Blum", jour: 2, debut: "18:00", duree: 90, equipes: [] }],
    };
    const analysis = analysisOf({ creneaux: [mkLine(0, "Gymnase Léon-Blum · jour 2 18:00", "aCreer")] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    // Retours fondateur 2026-10-26 — un créneau se corrige à l'étape GYMNASES (grille iso-wizard).
    await goToStep(user, "gymnases");
    await user.click(await screen.findByRole("button", { name: /18:00/ }));

    // `slotFields.DurationSelect` : un combobox « Durée » dont les options sont formatées par
    // `formatDuration` (« 1h30 ») + une option « Autre… » — jamais un `<input type="number">` ad hoc.
    const durationControl = screen.getByRole("combobox", { name: "Durée" });
    expect(within(durationControl).getByRole("option", { name: formatDuration(90) })).toBeInTheDocument();
    expect(within(durationControl).getByRole("option", { name: "Autre…" })).toBeInTheDocument();
  });

  // ─────────── Écart au plan 3d — « Toutes les équipes du club » rangé PAR RANG (ROUGE) ───────────

  it("3d — le groupe « Toutes les équipes du club » du sélecteur est rangé PAR RANG (groupTeamsByTier)", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({ equipes: [mkLine(0, "Nouvelle Équipe", "aCreer")] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await user.click(await screen.findByRole("button", { name: decisionTrigger("Nouvelle Équipe") }));

    // Les équipes du club (lues via useWizardTeams + usePriorityTiers, mockées avec des rangs S et D)
    // produisent un TITRE de groupe par rang, jamais une liste plate « Toutes les équipes du club ».
    const list = screen.getByRole("listbox");
    expect(within(list).getByText(tierGroupLabel(w.tiers[0]))).toBeInTheDocument();
    expect(within(list).getByText(tierGroupLabel(w.tiers[1]))).toBeInTheDocument();
  });
});

/**
 * ─────────── Retours fondateur sur captures (2026-10-26) — contrats ROUGES (option A) ───────────
 *
 * Trois évolutions CONFIRMÉES par le fondateur, à intégrer à la PR1 :
 *  1. Étape GYMNASES iso-wizard : chaque gymnase montre la GRILLE de ses créneaux (ouvertures), clic
 *     sur un créneau → éditeur ; la décision du gymnase (Listbox) reste en tête de son bloc. Les
 *     CRÉNEAUX quittent l'étape Réservations comme cases ÉDITABLES.
 *  2. SÉANCES en CHAMP numérique éditable (apparié ET « Créer »), remplace la case « Séances : 2 → 1 ».
 *  3. MUTUALISATIONS = leur PROPRE étape du rail (le gestionnaire doit VOIR qu'il y en a à vérifier) ;
 *     ordre final : Prompt · Coller · Équipes · Gymnases · Mutualisations · Réservations. Une case de
 *     mutualisation reste MARQUÉE (lien + nom de groupe) dans la grille Réservations ; cliquer bascule
 *     vers sa ligne à l'étape Mutualisations.
 *
 * On teste par NOMS d'étapes (jamais un compte figé : une étape « Ententes » viendra en PR2). Clés de
 * `texts.ts` RÉFÉRENCÉES ET PAS ENCORE CRÉÉES (rouge légitime, NEO les crée) : `sessionsFieldLabel(libelle)`
 * (« Séances de … », nom accessible contextualisé), `sessionsCurrent(n)` (« actuellement N »),
 * `mutualisationsEmpty` (« Aucune mutualisation lue »), `mutualisationsCount(n)`, `mutualisationCaseLabel(groupe, equipes)`
 * (nom accessible de la case marquée). Nouveaux champs de LIGNE servis par l'analyse : `seances` (valeur
 * lue, toutes les équipes), `seancesActuelles` (équipe appariée) — jumeaux backend dans
 * `ParcImportAnalyzeTest` ; l'honneur de `corrections.seances` à la création dans `ParcImportExecuteTest`.
 */
describe("ParcImportModal — retours fondateur 2026-10-26 (grille gymnases, séances en champ, étape mutualisations)", () => {
  const T = PARC_IMPORT_TEXTS as unknown as Record<string, (...args: unknown[]) => string> & Record<string, string>;

  /** Matcher FONCTION (nom accessible CONTENANT le texte) — jamais une `RegExp` construite (Semgrep). */
  const stepNamed = (label: string) => (accessibleName: string) => accessibleName.includes(label);

  /** Trigger `Listbox` d'une ligne de décision (préfixe `decisionFor(libelle)`, cf. 1er describe). */
  const decisionTrigger = (libelle: string) => (accessibleName: string) => accessibleName.startsWith(T.decisionFor(libelle));

  /** Dépose le collage puis analyse (auto-avance ensuite vers « Équipes »). */
  async function analyzeThen(user: ReturnType<typeof userEvent.setup>, analysis: Analysis): Promise<void> {
    q.analysis.value = analysis;
    await user.click(screen.getByRole("button", { name: stepNamed(T.stepPaste as unknown as string) }));
    fireEvent.change(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel }), { target: { value: '{"format":"parc-v2","cases":[]}' } });
    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.analyze }));
  }

  /** Visite une étape de CONTENU du rail (déverrouillée après l'analyse). */
  const goToStep = async (user: ReturnType<typeof userEvent.setup>, section: "equipes" | "gymnases" | "mutualisations" | "reservations"): Promise<void> => {
    await user.click(screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle(section)) }));
  };

  // ─────────── 1. Étape Gymnases iso-wizard : décision EN TÊTE + grille des créneaux ───────────

  it("1 — l'étape Gymnases montre, par gymnase, SA décision (Listbox) ET la grille de ses créneaux ; clic sur un créneau → éditeur pré-rempli", async () => {
    const user = userEvent.setup();
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "Gymnase Léon-Blum" }],
      cases: [{ gymnase: "Gymnase Léon-Blum", jour: 2, debut: "18:00", duree: 90, equipes: [] }],
    };
    const analysis = analysisOf(
      { gymnases: [mkLine(0, "Gymnase Léon-Blum", "aCreer")], creneaux: [mkLine(0, "Gymnase Léon-Blum · jour 2 18:00", "aCreer")] },
      { payload },
    );
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "gymnases");

    // La décision du gymnase reste accessible (Listbox nommé par `decisionFor`) EN TÊTE de son bloc.
    expect(screen.getByRole("button", { name: decisionTrigger("Gymnase Léon-Blum") })).toBeInTheDocument();

    // Le créneau du gymnase est une CASE cliquable de la grille (comme l'étape Réservations), plus une
    // case de l'étape Réservations : clic → éditeur pré-rempli (18:00 amorcé depuis le payload).
    await user.click(await screen.findByRole("button", { name: /18:00/ }));
    expect(screen.getByDisplayValue("18:00")).toBeInTheDocument();
  });

  it("1 — l'étape Réservations ne montre PLUS les créneaux comme cases éditables (ils vivent à l'étape Gymnases)", async () => {
    const user = userEvent.setup();
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "Gymnase Zénith" }],
      cases: [{ gymnase: "Gymnase Zénith", jour: 2, debut: "18:00", duree: 90, equipes: ["SM1"] }],
    };
    const analysis = analysisOf(
      {
        creneaux: [mkLine(0, "Gymnase Zénith · jour 2 18:00", "aCreer")],
        reservations: [mkLine(0, "SM1 · Gymnase Zénith 18:00", "aCreer", { position: { gymnase: "Gymnase Zénith", jour: 2, debut: "18:00", duree: 90 } })],
      },
      { payload },
    );
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "reservations");

    // La réservation reste une case éditable ici (son libellé sans le gymnase, déjà en en-tête).
    expect(await screen.findByRole("button", { name: /SM1/ })).toBeInTheDocument();
    // Le CRÉNEAU n'est plus une case ÉDITABLE (cliquable) de cette étape (contrat nommé : les
    // créneaux peuvent rester en fond non interactif, mais jamais un bouton d'édition ici).
    expect(screen.queryByRole("button", { name: /jour 2 18:00/ })).not.toBeInTheDocument();
  });

  // ─────────── 2. Séances en CHAMP éditable — ligne « Créer » & bornes ───────────

  it("2b — ligne « Créer » : « Séances » est un champ pré-rempli de la valeur LUE (parc), corrigeable → corrections.seances", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({ equipes: [mkLine(0, "Nouvelle Équipe", "aCreer", { seances: 2 })] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);

    const field = screen.getByRole("spinbutton", { name: T.sessionsFieldLabel("Nouvelle Équipe") });
    expect(field).toHaveValue(2);
    fireEvent.change(field, { target: { value: "3" } });
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; corrections?: Record<string, unknown> }> };
    expect(body.decisions.find((d) => "equipes" === d.section && 0 === d.index)?.corrections?.seances).toBe(3);
  });

  it("2c — le champ « Séances » est borné 1..7, porte un nom accessible contextualisé, et une valeur hors bornes n'est jamais transmise telle quelle", async () => {
    // Bornes 1..7 : décision fondateur + précédent `PeriodTeams.tsx:337` (`max={7}`). Côté serveur
    // `TeamInput::$sessionsPerWeek` n'est QUE `#[Assert\Positive]` (≥ 1, sans plafond) : les bornes
    // 1..7 sont une convention FRONT. Promesse : une saisie hors bornes est refusée OU ramenée — elle
    // n'atteint jamais `corrections.seances` hors [1, 7].
    const user = userEvent.setup();
    const analysis = analysisOf({ equipes: [mkLine(0, "U18 M2", "apparie", { cibleId: "t1", seances: 2, seancesActuelles: 2 })] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);

    const field = screen.getByRole("spinbutton", { name: T.sessionsFieldLabel("U18 M2") });
    expect(field).toHaveAttribute("min", "1");
    expect(field).toHaveAttribute("max", "7");

    fireEvent.change(field, { target: { value: "9" } });
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; corrections?: Record<string, unknown> }> };
    const s = body.decisions.find((d) => "equipes" === d.section && 0 === d.index)?.corrections?.seances;
    expect(undefined === s || ("number" === typeof s && s >= 1 && s <= 7)).toBe(true);
  });

  // ─────────── 3. Mutualisations = leur PROPRE étape du rail ───────────

  it("3 — l'étape Mutualisations liste chaque mutualisation (nom de groupe + équipes + raison ⚠ une seule fois), sans case à cocher", async () => {
    const user = userEvent.setup();
    const raison = "Les séances communes de cette mutualisation dépassent les séances hebdomadaires d'une des équipes — à ajuster à la main.";
    const position = { gymnase: "Gymnase Zénith", jour: 3, debut: "17:00", duree: 90 };
    const analysis = analysisOf({ mutualisations: [mkLine(0, "U11M1 + U11M2", "alerte", { groupe: "CEC", gymnaseId: null, raison, position })] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "mutualisations");

    // Le nom de groupe (donnée serveur `groupe`) est rendu verbatim, et seul (arbitrage du 31/10).
    expect(await screen.findByText("CEC")).toBeInTheDocument();
    expect(screen.queryByText("U11M1 + U11M2")).not.toBeInTheDocument();
    // La raison serveur n'apparaît qu'UNE fois (défaut C, comme pour les lignes équipe/gymnase).
    expect(screen.getAllByText(raison)).toHaveLength(1);
    // Garder/Ignorer sans case à cocher (Listbox ou segmenté — jamais de checkbox).
    expect(screen.queryAllByRole("checkbox")).toHaveLength(0);
  });

  it("3 — étape Mutualisations vide → EmptyHint « Aucune mutualisation lue » (jamais sautée)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysisOf({ equipes: [mkLine(0, "SM1", "aCreer")] }));
    await goToStep(user, "mutualisations");

    expect(await screen.findByText(T.mutualisationsEmpty as unknown as string)).toBeInTheDocument();
  });

  it("3 — l'en-tête de l'étape Mutualisations EN ALERTE résume leur nombre « à vérifier » (clé mutualisationsCount)", async () => {
    // Finition 2c — le pendant du cas SANS alerte (« 2c » du describe « finitions », clé
    // `mutualisationsReadCount`) : dès qu'UNE mutualisation est en alerte, le compteur bascule sur
    // « N … à vérifier. » (branche `hasAlert` de `ParcImportModal.tsx:1021`). Sans ce test, le bras
    // ALERTE du ternaire reste non prouvé (ailleurs `mutualisationsCount` n'est asserté qu'ABSENT,
    // cf. :1188) — point SENSIBLE (mutualisation, charte §1).
    const user = userEvent.setup();
    const analysis = analysisOf({ mutualisations: [mkLine(0, "U11M1 + U11M2", "alerte", { groupe: "CEC", gymnaseId: null, raison: "À ajuster.", position: { gymnase: "Gymnase Zénith", jour: 3, debut: "17:00", duree: 90 } })] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "mutualisations");

    // AVEC alerte, le compteur dit « N mutualisations à vérifier. » (« lues » réservé au cas sans alerte).
    expect(await screen.findByText(T.mutualisationsCount(1))).toBeInTheDocument();
  });

  it("3 — une mutualisation EN ALERTE marque l'étape Mutualisations du rail comme « à vérifier » (⚠)", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({ mutualisations: [mkLine(0, "U11M1 + U11M2", "alerte", { groupe: "CEC", gymnaseId: null, raison: "À ajuster.", position: { gymnase: "Gymnase Zénith", jour: 3, debut: "17:00", duree: 90 } })] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);

    // Le rail dit l'état par le nom accessible (StepRail : suffixe « — à vérifier » en warning).
    expect(
      screen.getByRole("button", { name: (n: string) => n.includes(PARC_IMPORT_TEXTS.sectionTitle("mutualisations")) && n.includes("à vérifier") }),
    ).toBeInTheDocument();
  });

  it("3 — dans la grille Réservations, une case de MUTUALISATION reste MARQUÉE (lien + nom de groupe) et son clic bascule vers l'étape Mutualisations", async () => {
    const user = userEvent.setup();
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "Gymnase Zénith" }],
      cases: [{ gymnase: "Gymnase Zénith", jour: 3, debut: "17:00", duree: 90, equipes: ["U11M1", "U11M2"], groupe: "CEC" }],
    };
    const analysis = analysisOf(
      { mutualisations: [mkLine(0, "U11M1 + U11M2", "aCreer", { groupe: "CEC", gymnaseId: null, position: { gymnase: "Gymnase Zénith", jour: 3, debut: "17:00", duree: 90 } })] },
      { payload },
    );
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "reservations");

    // La case porte un nom accessible de MUTUALISATION (pictogramme lien + nom de groupe), clé centralisée.
    const caseBtn = await screen.findByRole("button", { name: T.mutualisationCaseLabel("CEC", "U11M1 + U11M2") });
    await user.click(caseBtn);

    // Le clic bascule le rail sur l'étape Mutualisations (retour en arrière assumé), sur cette ligne.
    expect(screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle("mutualisations")) })).toHaveAttribute("aria-current", "step");
  });
});

/**
 * ─────────── Finitions de l'aperçu (captures réelles 04-07, 2026-10-26) — contrats ROUGES (option A) ───────────
 *
 * Quatre finitions CONSTATÉES sur les captures du club Rillieux, à intégrer à la refonte PR1 (TANK
 * écrit ROUGE, NEO implémente la modale + les nouvelles clés de `texts.ts`) :
 *  1. JOURS EN CLAIR (étape Gymnases) : un créneau d'ouverture s'affichait « jour 3 16:00 » (indice
 *     BRUT) dans la case ET le bouton de saut du bandeau. Promesse : le libellé d'un créneau est une
 *     PLAGE HORAIRE (`shared/lib/time.ts::formatMinutes`/`endTime`), et tout jour nommé passe par le
 *     foyer `shared/lib/days.ts` (`dayLabelShort`) — jamais l'indice « jour N ».
 *  2. MUTUALISATIONS : (a) le sélecteur de gymnase porte un libellé VISIBLE (clé `venueForGroupVisible`)
 *     et est PRÉ-REMPLI du gymnase de la `position` (« MONT BLANC (à créer) » via `gridVenueToCreate`),
 *     jamais le placeholder nu « Créer » ; (b) l'en-tête de ligne inclut le GYMNASE (« Lundi 17:30 ·
 *     MONT BLANC ») ; (c) sans alerte, le compteur dit « N mutualisations lues » (clé
 *     `mutualisationsReadCount`), « à vérifier » restant réservé aux alertes ; (d) le placeholder du nom
 *     de groupe est COURT (non tronqué dans le champ).
 *  3. ÉDITEUR DE CRÉNEAU : (a) le sélecteur de gymnase en tête porte un libellé VISIBLE (clé
 *     `editVenueVisible`) et est PRÉ-REMPLI du gymnase de la case, jamais « Créer » nu ; (b) Garder/
 *     Ignorer via le MÊME contrôle segmenté (`SegmentedControl`) que l'étape Mutualisations — AUCUNE
 *     case à cocher ; (c) l'heure se saisit avec le MÊME contrôle que l'éditeur de créneau du wizard
 *     (`steps/slotFields.tsx` : native `<input type="time">` de `VenuesStep`/`PeriodVenues` + la DURÉE
 *     via `slotFields.DurationSelect`, et la FIN calculée `slotFields.SlotEndField`/`endTime`).
 *  4. RAISON D'IMPORTER VISIBLE : quand « Importer » est désactivé, la raison comptée
 *     (`importBlocked`) est affichée EN TEXTE au pied (pas seulement dans le `<span class="sr-only">`
 *     du bouton `disabledReason`), avec un bouton « Voir » (clé `seeBlockingLine`) qui mène à la 1ʳᵉ
 *     ligne à trancher (étape + focus).
 *
 * Clés de `texts.ts` RÉFÉRENCÉES ET PAS ENCORE CRÉÉES (rouge légitime, NEO les crée, option A) :
 *   `venueForGroupVisible` (libellé visible), `mutualisationsReadCount(n)` (« N … lues »),
 *   `groupNamePlaceholder` (placeholder court), `editVenueVisible` (libellé visible), `seeBlockingLine`
 *   (« Voir »). Le gymnase « MONT BLANC » et la raison d'import sont des DONNÉES/clés existantes, non
 *   des phrases recopiées.
 *
 * ⚠ Collatéral MÉCANIQUE pour NEO (finition 1) : trois matchers de cases-créneaux EXISTANTS ciblent le
 * libellé BRUT `/jour 2 18:00/` (tests « 1 — … grille de ses créneaux », « D12 + heure », « 3c … ») —
 * ils devront viser le TEMPS (`/18:00/`) une fois le libellé passé en plage horaire.
 */
describe("ParcImportModal — finitions de l'aperçu (captures 04-07, 2026-10-26)", () => {
  const T = PARC_IMPORT_TEXTS as unknown as Record<string, (...args: unknown[]) => string> & Record<string, string>;

  /** Matcher FONCTION (nom accessible CONTENANT le texte) — jamais une `RegExp` construite (Semgrep). */
  const stepNamed = (label: string) => (accessibleName: string) => accessibleName.includes(label);
  /** Trigger `Listbox` d'une ligne de décision (préfixe `decisionFor(libelle)`, cf. describes ci-dessus). */
  const decisionTrigger = (libelle: string) => (accessibleName: string) => accessibleName.startsWith(T.decisionFor(libelle));

  /** Dépose le collage puis analyse (auto-avance ensuite vers « Équipes »). */
  async function analyzeThen(user: ReturnType<typeof userEvent.setup>, analysis: Analysis): Promise<void> {
    q.analysis.value = analysis;
    await user.click(screen.getByRole("button", { name: stepNamed(T.stepPaste as unknown as string) }));
    fireEvent.change(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel }), { target: { value: '{"format":"parc-v2","cases":[]}' } });
    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.analyze }));
  }

  /** Visite une étape de CONTENU du rail (déverrouillée après l'analyse). */
  const goToStep = async (user: ReturnType<typeof userEvent.setup>, section: "equipes" | "gymnases" | "mutualisations" | "reservations"): Promise<void> => {
    await user.click(screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle(section)) }));
  };

  // ─────────── 1. Jours en clair à l'étape Gymnases (plus de « jour N » brut) ───────────

  it("1 — le libellé d'un créneau à l'étape Gymnases est une PLAGE HORAIRE (formatMinutes), jamais l'indice brut « jour N »", async () => {
    const user = userEvent.setup();
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "LOUISON BOBET" }],
      cases: [{ gymnase: "LOUISON BOBET", jour: 3, debut: "16:00", duree: 90, equipes: [] }],
    };
    // Le libellé SERVEUR d'un créneau est l'indice brut (« … · jour 3 16:00 ») — c'est ce que la case
    // NE DOIT PLUS afficher : elle est en colonne « Mer », son texte = la plage « 16:00 → 17:30 ».
    const analysis = analysisOf({ gymnases: [mkLine(0, "LOUISON BOBET", "aCreer")], creneaux: [mkLine(0, "LOUISON BOBET · jour 3 16:00", "aCreer")] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "gymnases");

    // La case du créneau porte la PLAGE (début ET fin, via le foyer `formatMinutes`/`endTime`).
    const start = formatMinutes(16 * 60); // « 16:00 »
    const end = endTime("16:00", 90); // « 17:30 »
    await screen.findByRole("button", { name: (n: string) => n.includes(start) && n.includes(end) });
    // Et nulle part à l'étape Gymnases le texte brut « jour 3 » ne subsiste.
    expect(screen.queryAllByText((content: string) => content.includes("jour 3"))).toHaveLength(0);
  });

  it("1 — le bandeau « créneaux à vérifier » nomme le JOUR via le foyer days.ts (dayLabelShort), jamais « jour N »", async () => {
    const user = userEvent.setup();
    const raison = "Ce créneau en chevauche un autre du même gymnase ce jour-là.";
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "LOUISON BOBET" }],
      cases: [{ gymnase: "LOUISON BOBET", jour: 3, debut: "16:00", duree: 90, equipes: [] }],
    };
    const analysis = analysisOf({ gymnases: [mkLine(0, "LOUISON BOBET", "aCreer")], creneaux: [mkLine(0, "LOUISON BOBET · jour 3 16:00", "alerte", { raison })] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "gymnases");

    // Le bouton de saut du bandeau (préfixe `goToLine`) nomme le jour EN CLAIR (« Mer »), jamais « jour 3 ».
    const jump = await screen.findByRole("button", { name: (n: string) => n.startsWith(T.goToLine as unknown as string) });
    expect(jump.textContent ?? "").toContain(dayLabelShort(3)); // « Mer »
    expect(jump.textContent ?? "").not.toContain("jour 3");
  });

  // ─────────── 2. Mutualisations — sélecteur de gymnase, en-tête, compteur, placeholder ───────────

  /** Une mutualisation « à créer » (gymnase MONT BLANC non encore existant), sur sa `position` serveur. */
  const mutualAnalysis = (statut: "aCreer" | "alerte" = "aCreer", raison: string | null = null) => {
    const libelle = "U15M 1 + U15 M2";
    const position = { gymnase: "MONT BLANC", jour: 1, debut: "17:30", duree: 90 };
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "MONT BLANC" }],
      cases: [{ gymnase: "MONT BLANC", jour: 1, debut: "17:30", duree: 90, equipes: ["U15M 1", "U15 M2"], groupe: "" }],
    };
    return { libelle, analysis: analysisOf({ mutualisations: [mkLine(0, libelle, statut, { groupe: "", gymnaseId: null, position, raison })] }, { payload }) };
  };

  it("2a — le sélecteur de gymnase d'une mutualisation porte un libellé VISIBLE et est PRÉ-REMPLI (« MONT BLANC (à créer) »), jamais « Créer » nu", async () => {
    const user = userEvent.setup();
    const { libelle, analysis } = mutualAnalysis();
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "mutualisations");

    // (a1) Un libellé VISIBLE du sélecteur (nouvelle clé `venueForGroupVisible`), pas qu'un aria-label.
    expect(typeof T.venueForGroupVisible, "clé `venueForGroupVisible` à créer (libellé visible, option A)").toBe("string");
    expect(screen.getByText(T.venueForGroupVisible as unknown as string)).toBeInTheDocument();

    // (a2) Pré-rempli du gymnase de la `position` : « MONT BLANC (à créer) » (clé `gridVenueToCreate`),
    // jamais le placeholder nu « Créer » (`rematchCreate`).
    const line = screen.getByText(libelle).closest("li");
    expect(line, "la ligne de mutualisation doit exister").not.toBeNull();
    const scope = within(line as HTMLElement);
    expect(scope.getByText(T.gridVenueToCreate("MONT BLANC"))).toBeInTheDocument();
    expect(scope.queryByText(T.rematchCreate)).not.toBeInTheDocument();
  });

  it("2b — l'en-tête d'une ligne de mutualisation inclut le GYMNASE (« Lundi 17:30 · MONT BLANC »)", async () => {
    const user = userEvent.setup();
    const { libelle, analysis } = mutualAnalysis();
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "mutualisations");

    const line = screen.getByText(libelle).closest("li");
    const scope = within(line as HTMLElement);
    // L'élément d'en-tête (le <span> jour+heure, feuille) doit porter AUSSI le gymnase — pas seulement
    // le sélecteur (qui, lui, n'inclut jamais « Lundi 17:30 »). On exige donc jour + heure + gymnase
    // réunis dans UN même <span> de l'en-tête.
    const hits = scope.queryAllByText(
      (content: string, el: Element | null) =>
        "SPAN" === el?.tagName && content.includes(dayLabelLongCap(1)) && content.includes(formatMinutes(17 * 60 + 30)) && content.includes("MONT BLANC"),
    );
    expect(hits.length, "l'en-tête de ligne doit inclure le gymnase (« Lundi 17:30 · MONT BLANC »)").toBeGreaterThan(0);
  });

  it("2c — sans alerte, le compteur dit « N mutualisations lues » (« à vérifier » réservé aux alertes)", async () => {
    const user = userEvent.setup();
    const { analysis } = mutualAnalysis("aCreer");
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "mutualisations");

    // Le compteur d'ALERTE (« … à vérifier. ») ne doit PAS apparaître quand aucune n'est en alerte.
    expect(screen.queryByText(T.mutualisationsCount(1))).not.toBeInTheDocument();
    // Il dit « lues » (nouvelle clé `mutualisationsReadCount`).
    expect(typeof T.mutualisationsReadCount, "clé `mutualisationsReadCount(n)` à créer (option A)").toBe("function");
    expect(screen.getByText(T.mutualisationsReadCount(1))).toBeInTheDocument();
  });

  it("2d — le placeholder du nom de groupe est COURT (non tronqué), sans l'exemple « (ex. … ) »", async () => {
    const user = userEvent.setup();
    const { libelle, analysis } = mutualAnalysis();
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "mutualisations");

    const line = screen.getByText(libelle).closest("li");
    const scope = within(line as HTMLElement);
    const nameInput = scope.getByRole("textbox", { name: (n: string) => n.startsWith(T.groupLabel(libelle)) });
    const placeholder = nameInput.getAttribute("placeholder") ?? "";
    // Placeholder COURT, non tronqué dans le champ : plus l'exemple « (ex. « CEC ») » qui se fait couper.
    expect(placeholder).not.toContain("(ex.");
    expect(placeholder.length, "placeholder du nom de groupe trop long (tronqué dans le champ)").toBeLessThanOrEqual(20);
  });

  // ─────────── 3. Éditeur de créneau — gymnase pré-rempli, pas de case à cocher, contrôles du wizard ───────────

  /** Ouvre l'éditeur d'une RÉSERVATION sur un gymnase EXISTANT, et rend le dialog de l'éditeur. */
  async function openReservationEditor(user: ReturnType<typeof userEvent.setup>): Promise<HTMLElement> {
    const venues = [{ id: "v1", name: "Gymnase Léon-Blum" }] as const;
    const libelle = "SM1 · Gymnase Léon-Blum 18:00";
    const position = { gymnase: "Gymnase Léon-Blum", jour: 2, debut: "18:00", duree: 90 };
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "Gymnase Léon-Blum" }],
      cases: [{ gymnase: "Gymnase Léon-Blum", jour: 2, debut: "18:00", duree: 90, equipes: ["SM1"] }],
    };
    const analysis = analysisOf({ reservations: [mkLine(0, libelle, "aCreer", { gymnaseId: "v1", position })] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} venues={venues} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "reservations");
    await user.click(await screen.findByRole("button", { name: "SM1 · 18:00" }));
    return screen.getByRole("dialog", { name: T.editCaseTitle as unknown as string });
  }

  it("3a — le sélecteur de gymnase de l'éditeur porte un libellé VISIBLE et est PRÉ-REMPLI du gymnase de la case, jamais « Créer » nu", async () => {
    const user = userEvent.setup();
    const dialog = await openReservationEditor(user);
    const scope = within(dialog);

    // Un libellé VISIBLE (nouvelle clé `editVenueVisible`), pas qu'un aria-label.
    expect(typeof T.editVenueVisible, "clé `editVenueVisible` à créer (libellé visible, option A)").toBe("string");
    expect(scope.getByText(T.editVenueVisible as unknown as string)).toBeInTheDocument();
    // Pré-rempli du gymnase de la case (« Gymnase Léon-Blum »), jamais le placeholder nu « Créer ».
    expect(scope.getByText("Gymnase Léon-Blum")).toBeInTheDocument();
    expect(scope.queryByText(T.rematchCreate)).not.toBeInTheDocument();
  });

  it("3b — l'éditeur n'a AUCUNE case à cocher : Garder/Ignorer via le contrôle segmenté de l'étape Mutualisations", async () => {
    const user = userEvent.setup();
    const dialog = await openReservationEditor(user);
    const scope = within(dialog);

    // Plus de case à cocher « Garder cette ligne » : le même contrôle segmenté (role=group) que les mutualisations.
    expect(scope.queryByRole("checkbox")).not.toBeInTheDocument();
    const keep = scope.getByRole("button", { name: T.mutualKeep as unknown as string });
    expect(scope.getByRole("button", { name: T.mutualIgnore as unknown as string })).toBeInTheDocument();
    expect(keep.closest('[role="group"]'), "Garder/Ignorer doivent vivre dans UN contrôle segmenté").not.toBeNull();
  });

  it("3c — l'heure se saisit avec le contrôle du wizard (native <input type=\"time\">) et l'éditeur affiche la FIN calculée (slotFields.SlotEndField)", async () => {
    const user = userEvent.setup();
    const dialog = await openReservationEditor(user);
    const scope = within(dialog);

    // La case « Début » est bien le contrôle natif `type="time"` (celui de `VenuesStep`/`PeriodVenues`),
    // pré-rempli depuis le payload (18:00) — verrou contre un retour à un champ texte ad hoc.
    const startInput = scope.getByDisplayValue("18:00");
    expect(startInput).toHaveAttribute("type", "time");
    // La FIN calculée (foyer `shared/lib/time.ts::endTime`, 18:00 + 1h30 = 19:30) est affichée, comme
    // l'éditeur de créneau du wizard (`slotFields.SlotEndField`) — absente à la capture 07.
    expect(scope.getByText(endTime("18:00", 90))).toBeInTheDocument(); // « 19:30 »
  });

  // ─────────── 4. Raison d'« Importer » VISIBLE + bouton « Voir » vers la 1ʳᵉ ligne à trancher ───────────

  it("4 — quand « Importer » est bloqué, la raison comptée est affichée EN TEXTE (pas seulement en sr-only du bouton)", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({ equipes: [mkAmbiguous(0, "Armond", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }])] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis); // atterrit sur « Équipes », import bloqué (1 ligne à trancher)

    const reason = T.importBlocked(1, 0); // « Impossible d'importer : 1 ligne à trancher. »
    // Le seul nœud portant la raison aujourd'hui est le `<span class="sr-only">` frère du bouton
    // `disabledReason` : il en faut un AUTRE, VISIBLE (sans classe `sr-only`), au pied de la modale.
    const nodes = screen.getAllByText(reason);
    const visible = nodes.some((n) => !n.classList.contains("sr-only"));
    expect(visible, "la raison d'import bloqué doit être affichée en texte visible, pas qu'en sr-only").toBe(true);
  });

  it("4 — un bouton « Voir » mène à la 1ʳᵉ ligne à trancher : il bascule l'étape ET lui donne le focus", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({ equipes: [mkAmbiguous(0, "Armond", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }])] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "gymnases"); // on s'éloigne de la ligne à trancher (sur « Équipes »)

    // Le bouton « Voir » du pied (nouvelle clé `seeBlockingLine`) ramène à la ligne à trancher.
    expect(typeof T.seeBlockingLine, "clé `seeBlockingLine` à créer (« Voir », option A)").toBe("string");
    await user.click(screen.getByRole("button", { name: T.seeBlockingLine as unknown as string }));

    // Étape : l'assistant bascule sur « Équipes » (où vit la ligne ambiguë).
    expect(screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle("equipes")) })).toHaveAttribute("aria-current", "step");
    // Focus : le sélecteur de décision de la ligne à trancher reçoit le focus (pas l'en-tête d'étape).
    expect(screen.getByRole("button", { name: decisionTrigger("Armond") })).toHaveFocus();
  });
});

/**
 * ─────────── Refonte import IA PR2 (D9) — règle unique, genre obligatoire, étape Ententes ───────────
 *
 * Nouvelles PROMESSES (option A — TANK écrit ROUGE, NEO implémente) :
 *  - une ligne équipe « Créer » porte un Select Rang + un Select Genre PRÉ-REMPLIS depuis la règle FBI
 *    unique (le champ Séances existe déjà) ;
 *  - le GENRE est OBLIGATOIRE (§0 point 1 AMENDÉ, « plus MIXTE ») : une ligne « Créer » sans genre a un
 *    Select « À choisir » `aria-invalid` + ⚠ « Genre requis », et « Importer » est désactivé AVEC sa
 *    raison comptée (clé `importBlocked(nAmbigu, nGenre)`, déjà prête côté texts) ;
 *  - une NOUVELLE étape « Ententes (CTC) » suit « Équipes » dans le rail : les équipes « Créer » y ont
 *    une case Entente (pré-cochée si suggérée) → `corrections.ctc` ; les appariées affichent leur
 *    entente actuelle en LECTURE SEULE (l'import ne modifie pas l'existant).
 *
 * Clés texts à CRÉER par NEO (contrat, cf. rapport TANK) : `stepEntentes` (libellé d'étape),
 * `tierOf(libelle)`/`genderOf(libelle)` (noms accessibles des deux Select), `genderRequired`
 * (⚠ « Genre requis »), l'option vide « À choisir », `ententeFor(libelle)` (case Entente de l'étape).
 */
describe("ParcImportModal — PR2 (règle unique, genre obligatoire, étape Ententes)", () => {
  const T = PARC_IMPORT_TEXTS as unknown as Record<string, (...args: unknown[]) => string> & Record<string, string>;

  const stepNamed = (label: string) => (accessibleName: string) => accessibleName.includes(label);

  async function analyzeThen(user: ReturnType<typeof userEvent.setup>, analysis: Analysis): Promise<void> {
    q.analysis.value = analysis;
    await user.click(screen.getByRole("button", { name: stepNamed(T.stepPaste as unknown as string) }));
    fireEvent.change(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel }), { target: { value: '{"format":"parc-v2","cases":[]}' } });
    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.analyze }));
  }

  /** Une ligne équipe « Créer » portant ses suggestions (règle FBI unique). */
  function mkCreate(index: number, libelle: string, s: { suggestedTierId?: number; suggestedGender?: Line["suggestedGender"]; suggestedCtc?: boolean } = {}): Line {
    return mkLine(index, libelle, "aCreer", {
      suggestedTierId: s.suggestedTierId ?? 5,
      suggestedGender: "suggestedGender" in s ? s.suggestedGender : null,
      suggestedCtc: s.suggestedCtc ?? false,
    });
  }

  it("le rail porte une étape « Ententes (CTC) » juste après « Équipes » (testée par NOM, jamais par compte)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkCreate(0, "SM1", { suggestedGender: "M" })] }));

    expect(typeof T.stepEntentes, "clé `stepEntentes` à créer (libellé de l'étape, option A)").toBe("string");

    const buttons = screen.getAllByRole("button");
    const equipes = screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle("equipes")) });
    const ententes = screen.getByRole("button", { name: stepNamed(T.stepEntentes as unknown as string) });
    const gymnases = screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle("gymnases")) });

    // Ordre du rail (position dans le DOM) : Équipes → Ententes → Gymnases.
    expect(buttons.indexOf(equipes), "Ententes suit Équipes").toBeLessThan(buttons.indexOf(ententes));
    expect(buttons.indexOf(ententes), "Gymnases suit Ententes").toBeLessThan(buttons.indexOf(gymnases));
  });

  it("une ligne équipe « Créer » porte un Select Rang + un Select Genre, pré-remplis par la suggestion", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    // SM1 à créer, suggéré rang S (id 1 dans les tiers mockés) + genre M.
    await analyzeThen(user, analysisOf({ equipes: [mkCreate(0, "SM1", { suggestedTierId: 1, suggestedGender: "M" })] }));

    expect(typeof T.tierOf, "clé `tierOf(libelle)` à créer").toBe("function");
    expect(typeof T.genderOf, "clé `genderOf(libelle)` à créer").toBe("function");

    const tier = screen.getByRole("combobox", { name: T.tierOf("SM1") });
    const gender = screen.getByRole("combobox", { name: T.genderOf("SM1") });
    expect(tier, "le rang suggéré (S = id 1) pré-remplit le Select").toHaveValue("1");
    expect(gender, "le genre suggéré (M) pré-remplit le Select").toHaveValue("M");
  });

  it("genre obligatoire — une ligne « Créer » sans genre : Select « À choisir » aria-invalid, ⚠ « Genre requis », « Importer » désactivé « N équipes sans genre »", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkCreate(0, "Loisir Détente", { suggestedGender: null })] }));

    expect(typeof T.genderOf, "clé `genderOf` à créer").toBe("function");
    expect(typeof T.genderRequired, "clé `genderRequired` (⚠ « Genre requis ») à créer").toBe("string");

    const gender = screen.getByRole("combobox", { name: T.genderOf("Loisir Détente") });
    expect(gender).toHaveValue("");
    expect(gender, "un genre manquant est signalé invalide (aria-invalid)").toBeInvalid();
    expect(screen.getByText(T.genderRequired as unknown as string), "⚠ « Genre requis » sous le Select").toBeInTheDocument();

    // « Importer » bloqué AVEC sa raison comptée — la clé importBlocked gère déjà nGenre.
    const importBtn = screen.getByRole("button", { name: /Importer/ });
    expect(importBtn).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByText(T.importBlocked(0, 1)), "la raison compte « 1 équipe sans genre »").toBeInTheDocument();
  });

  it("genre tranché sur une ligne « Créer » → « Importer » réarmé, et corrections.genre + tierId partent à la création", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkCreate(0, "Loisir Détente", { suggestedTierId: 5, suggestedGender: null })] }));

    expect(typeof T.genderOf, "clé `genderOf` à créer").toBe("function");
    await user.selectOptions(screen.getByRole("combobox", { name: T.genderOf("Loisir Détente") }), "MIXTE");

    await visitAllSteps(user);
    const importBtn = screen.getByRole("button", { name: /Importer/ });
    expect(importBtn).not.toHaveAttribute("aria-disabled", "true");
    await user.click(importBtn);

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; creer?: boolean; corrections?: { genre?: string; tierId?: number } }> };
    const dec = body.decisions.find((d) => "equipes" === d.section && 0 === d.index);
    expect(dec?.creer, "une ligne « Créer » porte creer:true").toBe(true);
    expect(dec?.corrections?.genre, "le genre tranché part en corrections.genre à la création").toBe("MIXTE");
    expect(dec?.corrections?.tierId, "le rang (suggéré ou édité) part en corrections.tierId à la création").toBe(5);
  });

  it("étape Ententes — une équipe « Créer » suggérée en entente est pré-cochée et décochable (→ corrections.ctc) ; une appariée est en LECTURE SEULE", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(
      user,
      analysisOf({
        equipes: [mkCreate(0, "U15F CTC", { suggestedGender: "F", suggestedCtc: true }), mkLine(1, "SM1", "apparie", { cibleId: "t1" })],
      }),
    );

    expect(typeof T.stepEntentes, "clé `stepEntentes` à créer").toBe("string");
    expect(typeof T.ententeFor, "clé `ententeFor(libelle)` à créer").toBe("function");

    await user.click(screen.getByRole("button", { name: stepNamed(T.stepEntentes as unknown as string) }));

    // L'équipe « Créer » suggérée en entente a une case COCHÉE ; l'appariée n'a PAS de case éditable.
    const ctc = screen.getByRole("checkbox", { name: T.ententeFor("U15F CTC") });
    expect(ctc, "l'entente suggérée arrive pré-cochée").toBeChecked();
    expect(
      screen.queryByRole("checkbox", { name: T.ententeFor("SM1") }),
      "une équipe appariée affiche son entente en LECTURE SEULE, jamais une case modifiable (l'import ne touche pas l'existant)",
    ).toBeNull();

    // Décocher → corrections.ctc:false à l'import (jamais d'office, décision du gestionnaire).
    await user.click(ctc);
    await visitAllSteps(user);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; corrections?: { ctc?: boolean } }> };
    const dec = body.decisions.find((d) => "equipes" === d.section && 0 === d.index);
    expect(dec?.corrections?.ctc, "l'entente décochée part ctc:false").toBe(false);
  });

  it("étape Ententes — une équipe en entente porte le pictogramme CtcMark (role img « Entente (CTC) … »), jamais une icône de LIEN (réservée aux mutualisations)", async () => {
    // Revue fondateur 2026-10-26 (capture 03c-ententes) — l'étape posait un picto de LIEN (`Link2`)
    // devant CHAQUE équipe. Décision : le repère d'entente est le CtcMark PARTAGÉ (poignée de main),
    // posé partout où une entente est montrée ; l'icône de lien, réservée aux MUTUALISATIONS, disparaît
    // de l'étape Ententes. On vérifie les DEUX moitiés de la promesse : le repère EST là (la primitive
    // partagée `CtcMark` — role img dont le nom accessible est son propre contrat « Entente (CTC) … »,
    // gardé par ctc-mark.test.tsx, pas un texte d'écran), et AUCUNE icône de lien ne subsiste.
    const user = userEvent.setup();
    const { container } = renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkCreate(0, "U15F CTC", { suggestedGender: "F", suggestedCtc: true })] }));

    await user.click(screen.getByRole("button", { name: stepNamed(T.stepEntentes as unknown as string) }));

    // La case est pré-cochée (entente suggérée) : l'équipe est EN entente, donc son repère CtcMark est rendu.
    expect(screen.getByRole("checkbox", { name: T.ententeFor("U15F CTC") })).toBeChecked();
    // Le CtcMark (role img) est présent, nom accessible = le contrat de la primitive « Entente (CTC) … ».
    const mark = screen.getByRole("img", { name: (n: string) => n.startsWith("Entente (CTC)") });
    expect(mark, "une équipe en entente porte le repère CtcMark à l'étape Ententes").toBeInTheDocument();
    // Et PLUS aucune icône de lien (`Link2` → svg.lucide-link-2) : elle est réservée aux mutualisations.
    expect(container.querySelector("svg.lucide-link-2"), "le picto de LIEN ne doit plus apparaître à l'étape Ententes").toBeNull();
  });

  it("étape Ententes — le champ « club partenaire » borne la saisie à 120 caractères (colonne varchar(120) côté serveur)", async () => {
    // Revue sécurité PR2 — la colonne `Team.ctcPartner` est un varchar(120) : au-delà, le serveur
    // 500 (PostgreSQL 22001). Le champ de saisie borne donc l'entrée à 120 (maxLength), en défense de
    // surface — le jumeau serveur (`ParcImportExecuteTest`) refuse proprement une saisie forgée > 120.
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkCreate(0, "U15F CTC", { suggestedGender: "F", suggestedCtc: true })] }));

    await user.click(screen.getByRole("button", { name: stepNamed(T.stepEntentes as unknown as string) }));

    // Le champ partenaire (visible dès que l'entente est cochée) borne à 120 caractères.
    const partner = screen.getByRole("textbox", { name: T.ententePartnerFor("U15F CTC") });
    expect(partner).toHaveAttribute("maxlength", "120");
  });

  it("genre obligatoire aussi sur une ligne AMBIGUË : « Créer « … » » montre Select Rang + Genre (suggestions servies sur TOUTE ligne), genre vide bloque, genre choisi → corrections.{tierId,genre} + creer:true", async () => {
    // NEO signale le trou : les suggestions n'étaient servies QUE sur une ligne « aCreer », donc
    // choisir « Créer » sur une ligne AMBIGUË n'ouvrait aucun Select Genre → l'executor refusait à
    // l'import (422), piège. L'analyzer sert désormais `suggestedTierId`/`suggestedGender` sur CHAQUE
    // ligne (jumeau backend : ParcImportAnalyzeTest). Sur une ambiguë « Créer », le front montre donc
    // les MÊMES Select que sur une « aCreer » (il se câble sur `suggestedTierId`, jamais sur le statut),
    // bloque tant que le genre manque, et poste corrections.{tierId,genre} + creer:true une fois tranché.
    const user = userEvent.setup();
    // Ligne AMBIGUË PORTANT ses suggestions (ce que l'analyzer corrigé sert) : rang 5, genre null.
    const line = { ...mkAmbiguous(0, "Armond", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }]), suggestedTierId: 5, suggestedGender: null, suggestedCtc: false } as Line;
    const decisionTrigger = (libelle: string) => (accessibleName: string) => accessibleName.startsWith(T.decisionFor(libelle));
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [line] }));

    // Choisir « Créer « Armond » » dans le sélecteur de décision de la ligne ambiguë.
    await user.click(await screen.findByRole("button", { name: decisionTrigger("Armond") }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: T.createOption("Armond") }));

    // Les deux Select apparaissent (comme sur une « aCreer ») ; genre vide → invalide + ⚠ + Importer bloqué.
    expect(screen.getByRole("combobox", { name: T.tierOf("Armond") }), "le rang suggéré (5) pré-remplit le Select").toHaveValue("5");
    const gender = screen.getByRole("combobox", { name: T.genderOf("Armond") });
    expect(gender).toHaveValue("");
    expect(gender, "un genre manquant est signalé invalide").toBeInvalid();
    expect(screen.getByText(T.genderRequired as unknown as string)).toBeInTheDocument();
    const importBtn = screen.getByRole("button", { name: /Importer/ });
    expect(importBtn).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByText(T.importBlocked(0, 1)), "la raison compte « 1 équipe sans genre »").toBeInTheDocument();

    // Genre tranché → Importer réarmé ; corrections.{tierId,genre} + creer:true partent (jamais appariée).
    await user.selectOptions(gender, "MIXTE");
    await visitAllSteps(user);
    expect(importBtn).not.toHaveAttribute("aria-disabled", "true");
    await user.click(importBtn);

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; creer?: boolean; cibleId?: string | null; corrections?: { genre?: string; tierId?: number } }> };
    const dec = body.decisions.find((d) => "equipes" === d.section && 0 === d.index);
    expect(dec?.creer, "« Créer » explicite sur une ambiguë porte creer:true").toBe(true);
    expect(dec?.cibleId ?? null, "jamais apparié à un candidat").toBeNull();
    expect(dec?.corrections?.genre, "le genre tranché part en corrections.genre").toBe("MIXTE");
    expect(dec?.corrections?.tierId, "le rang suggéré part en corrections.tierId").toBe(5);
  });
});

/**
 * ─────────── PR A (incident ALAP 2026-10-29) — équipe ignorée d'une séance commune (D2/D5/D9/D10/D11/D13) ───────────
 *
 * Nouvelles PROMESSES (option A — TANK écrit ROUGE, NEO implémente) :
 *  - D2  : changer une décision ÉQUIPES marque l'aperçu PÉRIMÉ (bandeau `staleHint` + « Revérifier »).
 *  - D5  : à l'arrivée d'une erreur d'import, le bandeau d'erreur est AMENÉ à l'écran (`scrollIntoView`),
 *          SANS lui voler le focus.
 *  - D9  : une ligne IGNORÉE est rendue BARRÉE (`line-through`).
 *  - D10 : plus de bouton TEXTE « Fermer » au pied d'étape (la croix d'en-tête reste) ; Précédent avant Importer.
 *  - D11 : l'option suggérée se lit « <nom> (suggéré) », PAS « Apparier à … ».
 *  - D13 : une séance commune en alerte « équipe ignorée » ET gardée BLOQUE Importer ; « Voir » mène à
 *          l'étape Mutualisations ; la raison du blocage au pied porte le ton `text-warning`.
 */
describe("ParcImportModal — PR A (incident ALAP : équipe ignorée d'une séance commune)", () => {
  const T = PARC_IMPORT_TEXTS as unknown as Record<string, (...args: unknown[]) => string> & Record<string, string>;

  const stepNamed = (label: string) => (accessibleName: string) => accessibleName.includes(label);
  const decisionTrigger = (libelle: string) => (accessibleName: string) => accessibleName.startsWith(T.decisionFor(libelle));

  async function analyzeThen(user: ReturnType<typeof userEvent.setup>, analysis: Analysis): Promise<void> {
    q.analysis.value = analysis;
    await user.click(screen.getByRole("button", { name: stepNamed(T.stepPaste as unknown as string) }));
    fireEvent.change(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel }), { target: { value: '{"format":"parc-v2","cases":[]}' } });
    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.analyze }));
  }

  /** Séance commune en ALERTE « équipe ignorée », GARDÉE (coche:true) — le cas ALAP, côté réponse serveur. */
  function keptMutualisationAlerte(): Line {
    return {
      ...mkLine(0, "SF1 + SM shooting", "alerte"),
      coche: true,
      raison: "La séance commune cite « SM shooting », ignorée à l'étape Équipes.",
      alerteCode: "equipe_ignoree",
      groupe: "SM Shooting",
      position: { gymnase: "Léon Jouhaux", jour: 1, debut: "20:30", duree: 90 },
    };
  }

  it("D2 — changer une décision Équipes affiche le bandeau « aperçu périmé » et « Revérifier »", async () => {
    // Aujourd'hui, seul l'ÉDITEUR de case marque l'aperçu périmé ; changer une décision d'équipe
    // (Listbox) ne touche pas `dirtyCases` → aucun bandeau. Promesse : toute modification de décision
    // le marque périmé. ROUGE : le bandeau `staleHint` n'apparaît pas après le changement de décision.
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkLine(0, "SF1", "aCreer")] }));

    // On change la décision de la ligne : « Créer » → « Ignorer cette ligne ».
    await user.click(await screen.findByRole("button", { name: decisionTrigger("SF1") }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: T.ignoreLine as unknown as string }));

    expect(screen.getByText(T.staleHint(1)), "changer une décision marque l'aperçu périmé (D2)").toBeInTheDocument();
  });

  it("D5 — à l'erreur d'import, le bandeau d'erreur est amené à l'écran (scrollIntoView) sans voler le focus", async () => {
    const user = userEvent.setup();
    const scrollSpy = vi.fn();
    // jsdom n'implémente pas scrollIntoView : on le POSE pour l'espionner (restauré en fin de test).
    const original = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = scrollSpy;
    try {
      q.importError.value = { response: { status: 422 }, data: { errors: ["séance commune n°1 (SF1 + SM shooting) : l'équipe « SM shooting » est introuvable."] } };
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await analyzeThen(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }));
      await visitAllSteps(user);
      await user.click(screen.getByRole("button", { name: /Importer/ }));

      const alert = await screen.findByRole("alert");
      expect(scrollSpy, "le bandeau d'erreur est amené à l'écran (D5)").toHaveBeenCalled();
      expect(alert, "l'erreur ne vole pas le focus").not.toHaveFocus();
    } finally {
      if (undefined === original) {
        // @ts-expect-error — nettoyage du stub posé sur le prototype (jsdom ne le fournit pas).
        delete Element.prototype.scrollIntoView;
      } else {
        Element.prototype.scrollIntoView = original;
      }
    }
  });

  it("D9 — une ligne IGNORÉE est rendue barrée (line-through)", async () => {
    // Promesse : une ligne qu'on met en « Ignorer » est visuellement BARRÉE (plus « grisée/barrée »,
    // retours fondateur). ROUGE : le libellé d'une ligne ignorée n'a aucune classe `line-through`.
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkLine(0, "SF1", "aCreer")] }));

    await user.click(await screen.findByRole("button", { name: decisionTrigger("SF1") }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: T.ignoreLine as unknown as string }));

    const label = screen.getByText("SF1"); // DONNÉE serveur (libellé), affichée verbatim
    const barred = label.className.includes("line-through") || (label.parentElement?.className.includes("line-through") ?? false);
    expect(barred, "une ligne ignorée est barrée (D9)").toBe(true);
  });

  it("D10 — le pied d'étape n'a plus de bouton texte « Fermer » (la croix d'en-tête reste), Précédent avant Importer", async () => {
    // ROUGE : le pied d'une étape de contenu rend encore un bouton TEXTE « Fermer » (clé `close`) À CÔTÉ
    // de la croix d'en-tête (aria-label « Fermer » de modal.tsx) → DEUX boutons « Fermer ».
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkLine(0, "SF1", "aCreer")] }));

    const closers = screen.getAllByRole("button", { name: T.close as unknown as string });
    expect(closers, "seule la croix d'en-tête ferme (plus de bouton texte « Fermer » au pied)").toHaveLength(1);
    expect(closers[0]?.textContent ?? "", "la seule « Fermer » restante est la croix (icône, sans texte)").toBe("");

    // Précédent vient AVANT Importer dans l'ordre du document (groupés à gauche, Importer à droite).
    const prev = screen.getByRole("button", { name: T.previous as unknown as string });
    const importBtn = screen.getByRole("button", { name: /Importer/ });
    // Node.DOCUMENT_POSITION_FOLLOWING (4) : importBtn suit prev.
    expect(prev.compareDocumentPosition(importBtn) & Node.DOCUMENT_POSITION_FOLLOWING, "Précédent avant Importer").toBeTruthy();
  });

  it("D11 — l'option suggérée se lit « <nom> (suggéré) », jamais « Apparier à … »", async () => {
    // ⚠ Exception documentée à la règle « assert la CLÉ » : la clé `matchToSuggested` est précisément
    // CE qui change de valeur (de « Apparier à « X » (suggéré) » vers « X (suggéré) »), donc l'asserter
    // serait VERT vacuement. On assert la FORME du nom accessible de l'option : contient « (suggéré) »,
    // ne contient PAS « Apparier ». « (suggéré) » n'est pas une phrase recopiée mais le marqueur stable.
    const user = userEvent.setup();
    const line = mkLine(0, "SF1", "suggere", { cibleId: "t1", candidats: [{ id: "t1", nom: "U21M1" }] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [line] }));

    await user.click(await screen.findByRole("button", { name: decisionTrigger("SF1") }));
    const options = within(screen.getByRole("listbox")).getAllByRole("option");
    const suggested = options.find((o) => (o.textContent ?? "").includes("(suggéré)"));
    expect(suggested, "une option suggérée est offerte").toBeDefined();
    const name = suggested?.textContent ?? "";
    expect(name).toContain("U21M1");
    expect(name, "l'option suggérée ne commence plus par « Apparier à … » (D11)").not.toContain("Apparier");
  });

  it("D13 — une séance commune en alerte « équipe ignorée » et GARDÉE bloque « Importer »", async () => {
    // ROUGE : aujourd'hui seules les lignes équipe/gymnase ambiguës (ou un genre manquant) bloquent ;
    // une séance commune gardée mais en alerte n'empêche PAS l'import (le défaut ALAP : l'import part et 422).
    const user = userEvent.setup();
    const analysis = analysisOf({
      equipes: [mkLine(0, "U13M1", "aCreer")], // une ligne cochée → count > 0 (l'import n'est pas bloqué par « rien à importer »)
      mutualisations: [keptMutualisationAlerte()],
    });
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysis);

    const importBtn = screen.getByRole("button", { name: /Importer/ });
    expect(importBtn, "une séance commune en alerte gardée bloque l'import (D13)").toHaveAttribute("aria-disabled", "true");
  });

  it("D13 — « Voir » mène à l'étape Mutualisations (séances communes)", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({
      equipes: [mkLine(0, "U13M1", "aCreer")],
      mutualisations: [keptMutualisationAlerte()],
    });
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysis); // atterrit sur « Équipes »

    const voir = screen.queryByRole("button", { name: T.seeBlockingLine as unknown as string });
    expect(voir, "un bouton « Voir » doit mener à la séance commune bloquante (D13)").not.toBeNull();
    await user.click(voir as HTMLElement);

    expect(
      screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle("mutualisations")) }),
      "« Voir » bascule sur l'étape Mutualisations",
    ).toHaveAttribute("aria-current", "step");
  });

  it("D13 — la raison du blocage au pied porte le ton « text-warning »", async () => {
    // Patron ImportFbiDialog (raison de blocage en ORANGE). ROUGE : la raison au pied est en
    // `text-muted-foreground`, pas `text-warning`.
    const user = userEvent.setup();
    const analysis = analysisOf({ equipes: [mkAmbiguous(0, "Armond", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }])] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysis);

    const reason = screen.getByText(T.importBlocked(1, 0));
    const warning = reason.className.includes("text-warning") || (reason.parentElement?.className.includes("text-warning") ?? false);
    expect(warning, "la raison du blocage porte le ton warning (D13)").toBe(true);
  });
});

// ─────────── D3 (incident ALAP) — étape Séances communes : redéfinir la COMPOSITION d'équipes ───────────
// À l'étape « Séances communes », chaque ligne porte un SÉLECTEUR MULTIPLE d'équipes (équipes du club
// + lignes Équipes gardées) : choisir SF1 + SM1 + SM2 envoie `corrections.equipes` (liste d'ids) dans
// les décisions — à la revalidation ET à l'import ; moins de 2 équipes choisies → une RAISON VISIBLE
// qui bloque. Aligné sur le contrat backend (mes ROUGES D3 : la ligne mutualisation de /analyze reflète
// la composition dans son `libelle` et expose `seancesCommunes` ; le canal de décision est
// `corrections.equipes`). ROUGE option A — NEO implémente le sélecteur + les clés `texts.ts` ci-dessous.
// ⚠ Clés de `texts.ts` RÉFÉRENCÉES ET PAS ENCORE CRÉÉES (rouge légitime, cf. précédent ligne ~258 de
// ce fichier) : `teamsForGroup(libelle)` (nom accessible du sélecteur multi), `needsTwoTeams` (raison
// « au moins 2 équipes »). Le ROUGE de chaque test est l'assertion `toBeDefined()` qui NOMME la clé à
// créer — l'interaction en aval est la cible que NEO doit rendre verte.
describe("ParcImportModal — étape Séances communes : composition corrigée (D3, incident ALAP) — ROUGE option A", () => {
  const T = PARC_IMPORT_TEXTS as unknown as Record<string, (...a: unknown[]) => string> & Record<string, string>;
  const stepNamed = (label: string) => (n: string) => n.includes(label);
  const originalTeams = w.teams;

  afterEach(() => {
    w.teams = originalTeams;
  });

  async function analyzeThen(user: ReturnType<typeof userEvent.setup>, analysis: Analysis): Promise<void> {
    q.analysis.value = analysis;
    await user.click(screen.getByRole("button", { name: stepNamed(T.stepPaste as unknown as string) }));
    fireEvent.change(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel }), { target: { value: '{"format":"parc-v2","cases":[]}' } });
    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.analyze }));
  }
  const goToMutual = (user: ReturnType<typeof userEvent.setup>) =>
    user.click(screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle("mutualisations")) }));

  /** La séance commune ALAP « SF1 + SM shooting » (groupe « SM Shooting »), composition lue servie par /analyze. */
  function seanceCommuneLine(): Line {
    return {
      index: 0,
      libelle: "SF1 + SM shooting",
      statut: "aCreer",
      coche: true,
      cibleId: null,
      raison: null,
      groupe: "SM Shooting",
      equipes: [{ id: null, nom: "SF1" }, { id: null, nom: "SM shooting" }],
      seancesCommunes: 1,
      position: { gymnase: "Léon Jouhaux", jour: 1, debut: "20:30", duree: 90, gymnaseId: null },
    } as unknown as Line;
  }

  it("chaque séance commune porte un sélecteur MULTIPLE d'équipes (listbox multiselect)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ mutualisations: [seanceCommuneLine()] }));
    await goToMutual(user);

    expect((T as Record<string, unknown>).teamsForGroup, "clé texts.ts `teamsForGroup(libelle)` (nom accessible du sélecteur multi) à créer par NEO").toBeDefined();
    const selector = screen.getByRole("button", { name: (n: string) => n.startsWith(T.teamsForGroup("SF1 + SM shooting")) });
    await user.click(selector);
    expect(screen.getByRole("listbox")).toHaveAttribute("aria-multiselectable", "true");
  });

  it("choisir SF1 + SM1 + SM2 envoie decisions[].corrections.equipes à l'import", async () => {
    const user = userEvent.setup();
    w.teams = [
      { id: "wt-sf1", name: "SF1", priorityTierId: 1, tierOrder: 0 },
      { id: "wt-sm1", name: "SM1", priorityTierId: 1, tierOrder: 1 },
      { id: "wt-sm2", name: "SM2", priorityTierId: 1, tierOrder: 2 },
    ];
    q.importResult.value = { recap: {} };
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ mutualisations: [seanceCommuneLine()] }));
    await goToMutual(user);

    expect((T as Record<string, unknown>).teamsForGroup, "clé texts.ts `teamsForGroup(libelle)` à créer par NEO").toBeDefined();
    const selector = screen.getByRole("button", { name: (n: string) => n.startsWith(T.teamsForGroup("SF1 + SM shooting")) });
    await user.click(selector);
    const panel = screen.getByRole("listbox");
    for (const name of ["SF1", "SM1", "SM2"]) {
      await user.click(within(panel).getByRole("option", { name: (n: string) => n.startsWith(name) }));
    }
    await user.keyboard("{Escape}");
    await visitAllSteps(user);
    // D10 — le verrou : une composition changée n'importe qu'après la revérification.
    await user.click(screen.getByRole("button", { name: /Importer/ }));
    expect(q.importMutate, "composition non revérifiée : rien ne part").not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: T.verifyAction as unknown as string }));
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; corrections?: { equipes?: string[] } }> };
    const dec = body.decisions.find((d) => "mutualisations" === d.section && 0 === d.index);
    expect(dec?.corrections?.equipes).toEqual(expect.arrayContaining(["wt-sf1", "wt-sm1", "wt-sm2"]));
    expect(dec?.corrections?.equipes).toHaveLength(3);
  });

  it("moins de 2 équipes choisies → une raison VISIBLE qui bloque l'import", async () => {
    const user = userEvent.setup();
    w.teams = [{ id: "wt-sf1", name: "SF1", priorityTierId: 1, tierOrder: 0 }];
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ mutualisations: [seanceCommuneLine()] }));
    await goToMutual(user);

    expect((T as Record<string, unknown>).needsTwoTeams, "clé texts.ts `needsTwoTeams` (raison « au moins 2 équipes ») à créer par NEO").toBeDefined();
    const selector = screen.getByRole("button", { name: (n: string) => n.startsWith(T.teamsForGroup("SF1 + SM shooting")) });
    await user.click(selector);
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: (n: string) => n.startsWith("SF1") }));
    await user.keyboard("{Escape}");

    expect(screen.getByText(T.needsTwoTeams as unknown as string)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Importer/ })).toHaveAttribute("aria-disabled", "true");
  });
});

/**
 * ─────────── PR A lot 2 (essai fondateur 30/10) — Ignorer visible aux étapes de la modale ───────────
 * TANK écrit ROUGE (option A), NEO implémente :
 *  - E4 : à l'étape Séances communes, une ligne mise en « Ignorer » est BARRÉE (`line-through`) et
 *         GRISÉE (`text-muted-foreground`) — D9 étendu (aujourd'hui le libellé reste `text-foreground`).
 *  - E5 : à l'étape Équipes, une ligne IGNORÉE n'affiche plus son champ « Séances » éditable (ni Rang
 *         ni Genre — ces deux-là ne naissent que sur « Créer », déjà le cas). Aujourd'hui le champ
 *         « Séances » se rend quel que soit la décision (`ParcImportModal.tsx:1091`, non conditionné à `ignored`).
 * E2 (réservation en alerte de budget GARDÉE bloque Importer) : DÉJÀ couvert — le blocage D13 est
 * générique sur les sections de CASE (`ParcImportModal.tsx:449-459`, `:727` `pendingAlerteCode`), il
 * inclut les réservations. CITÉ, pas dédoublé (le chemin « alerte gardée bloque » n'a pas de test
 * dédié : une garde VERTE serait utile — signalé au rapport, hors périmètre « rouges »).
 */
describe("ParcImportModal — PR A lot 2 (Ignorer barré · champ Séances masqué)", () => {
  const T = PARC_IMPORT_TEXTS as unknown as Record<string, (...args: unknown[]) => string> & Record<string, string>;
  const stepNamed = (label: string) => (accessibleName: string) => accessibleName.includes(label);
  const decisionTrigger = (libelle: string) => (accessibleName: string) => accessibleName.startsWith(T.decisionFor(libelle));

  async function analyzeThen(user: ReturnType<typeof userEvent.setup>, analysis: Analysis): Promise<void> {
    q.analysis.value = analysis;
    await user.click(screen.getByRole("button", { name: stepNamed(T.stepPaste as unknown as string) }));
    fireEvent.change(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel }), { target: { value: '{"format":"parc-v2","cases":[]}' } });
    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.analyze }));
  }

  const goToStep = async (user: ReturnType<typeof userEvent.setup>, section: "equipes" | "gymnases" | "mutualisations" | "reservations"): Promise<void> => {
    await user.click(screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.sectionTitle(section)) }));
  };

  it("E4 — une séance commune mise en « Ignorer » est barrée et grisée", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({
      mutualisations: [mkLine(0, "U11M1 + U11M2", "aCreer", { groupe: "CEC", gymnaseId: null, position: { gymnase: "Gymnase Zénith", jour: 3, debut: "17:00", duree: 90 } })],
    });
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysis);
    await goToStep(user, "mutualisations");

    // Précondition : la ligne est gardée, son nom (seul à l'écran, arbitrage du 31/10) n'est pas barré.
    const before = screen.getByText("CEC");
    expect(before.className).not.toContain("line-through");

    // On bascule la ligne en « Ignorer » (contrôle segmenté Garder/Ignorer).
    await user.click(screen.getByRole("button", { name: T.mutualIgnore as unknown as string }));

    const after = screen.getByText("CEC");
    expect(after.className, "une ligne ignorée est barrée (D9 étendu)").toContain("line-through");
    expect(after.className, "une ligne ignorée est grisée").toContain("text-muted-foreground");
  });

  it("E4 — une séance commune sans nom, mise en « Ignorer », barre ses équipes", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({
      mutualisations: [mkLine(0, "U11M1 + U11M2", "aCreer", { groupe: "", gymnaseId: null, position: { gymnase: "Gymnase Zénith", jour: 3, debut: "17:00", duree: 90 } })],
    });
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysis);
    await goToStep(user, "mutualisations");

    await user.click(screen.getByRole("button", { name: T.mutualIgnore as unknown as string }));

    expect(screen.getByText("U11M1 + U11M2").className).toContain("line-through");
  });

  it("E5 — une ligne Équipes IGNORÉE n'affiche plus de champ Séances éditable", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkLine(0, "SF1", "aCreer", { seances: 2 })] }));

    // Précondition : gardée → le champ « Séances » (spinbutton) est présent.
    expect(screen.getByRole("spinbutton", { name: T.sessionsFieldLabel("SF1") })).toBeInTheDocument();

    // On IGNORE la ligne via son sélecteur de décision.
    await user.click(await screen.findByRole("button", { name: decisionTrigger("SF1") }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: T.ignoreLine as unknown as string }));

    // Le champ Séances disparaît ; Rang/Genre ne naissent que sur « Créer » (donc déjà absents ici).
    expect(screen.queryByRole("spinbutton", { name: T.sessionsFieldLabel("SF1") }), "pas de champ Séances sur une ligne ignorée (E5)").not.toBeInTheDocument();
  });
});

/**
 * D15 (PR B « Signaler depuis une modale ») — la modale d'import DÉCLARE son `reportState` : quand le
 * gestionnaire clique le drapeau « Signaler » de l'en-tête, le support reçoit l'état COMPLET de l'import
 * — l'étape courante, le parc collé, la dernière réponse d'analyse, les décisions construites et la
 * dernière erreur serveur (status + errors[]) — jamais un signalement nu depuis l'écran le plus riche.
 * Garde de la forme livrée par NEO. On injecte un `openReport` espion via le CONTEXTE neutre (le vrai
 * provider n'est pas monté par `renderWithProviders`) et on lit l'argument `modalState` (string JSON).
 */
describe("ParcImportModal — « Signaler » joint l'état complet de l'import (D15)", () => {
  /** Nom accessible du bouton de rail « Coller » = « 2 Coller » (numéro + libellé, patron step-rail). */
  const stepNamed = (label: string) => (accessibleName: string) => accessibleName.includes(label);

  it("un clic sur « Signaler » envoie modalState = { step, parc, analysis, decisions, lastError{status, errors[]} }", async () => {
    const user = userEvent.setup();
    const openReport = vi.fn();
    const pasted = '{"format":"parc-v2","cases":[]}';
    const serverError = "L'équipe n°1 : appariement ambigu, à choisir.";
    // L'analyse réussit (→ étape « Équipes », aperçu posé) ; l'import échoue en 422 (→ lastError peuplé).
    q.analysis.value = analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] });
    q.importError.value = { response: { status: 422 }, data: { errors: [serverError] } };

    renderWithProviders(
      <ModalReportProviderContext value={{ openReport }}>
        <ParcImportModal {...defaultProps} />
      </ModalReportProviderContext>,
    );

    // Coller la réponse de l'assistant → analyser (passage auto à « Équipes »).
    await user.click(screen.getByRole("button", { name: stepNamed(PARC_IMPORT_TEXTS.stepPaste) }));
    fireEvent.change(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel }), { target: { value: pasted } });
    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.analyze }));
    // Déclencher l'import : il échoue (422) → la modale retient la dernière erreur serveur.
    await visitAllSteps(user);
    await user.click(await screen.findByRole("button", { name: /Importer/ }));

    // Le drapeau « Signaler » de l'en-tête ouvre le canal avec l'instantané de CETTE modale.
    await user.click(screen.getByRole("button", { name: "Signaler" }));

    expect(openReport).toHaveBeenCalledTimes(1);
    const request = openReport.mock.calls[0][0] as { modalState?: string; screenLabel?: string };
    expect(typeof request.modalState, "modalState est une chaîne JSON, jamais undefined").toBe("string");
    const state = JSON.parse(request.modalState as string) as {
      step: string;
      parc: string;
      analysis: unknown;
      decisions: unknown[];
      lastError: { status: number | null; errors: string[] } | null;
    };
    expect(state.step, "l'étape courante").toBe("reservations");
    expect(state.parc, "le parc collé").toBe(pasted);
    expect(state.analysis, "la dernière réponse d'analyse").not.toBeNull();
    expect(Array.isArray(state.decisions), "les décisions construites").toBe(true);
    expect(state.lastError?.status, "le status de la dernière erreur serveur").toBe(422);
    expect(state.lastError?.errors, "les errors[] de la dernière erreur serveur").toContain(serverError);
  });
});

/**
 * ─────────── Finitions d'interface (essai d'onboarding, 30/10, points 7, 10, 11) : ROUGE option A ───────────
 *
 * PROMESSES :
 *  - pt 7 : un nom de groupe changé à l'étape Séances communes est repris à l'étape Réservations ;
 *  - pt 10 : un seul « Revérifier » (en-tête), le bandeau périmé n'a plus de bouton ;
 *  - pt 11 : une étape ne passe au vert qu'APRÈS avoir été visitée et sans point en attente ;
 *    « Importer » n'est actif que si TOUTES les étapes sont vertes.
 *
 * Clé de `texts.ts` à créer par NEO : `importStepsToVisit(n)` (raison visible au pied, « Voir » mène à
 * la première étape non visitée). `importBlocked` garde la priorité quand une ligne est à trancher.
 */
describe("ParcImportModal — finitions d'interface (groupe repris, un seul Revérifier, étapes visitées)", () => {
  const T = PARC_IMPORT_TEXTS as unknown as Record<string, (...args: unknown[]) => string> & Record<string, string>;
  const position = { gymnase: "Gymnase Zénith", jour: 3, debut: "17:00", duree: 90 };
  const railButton = (label: string): HTMLElement =>
    within(screen.getByRole("navigation")).getByRole("button", { name: (n: string) => n.includes(label) });
  const importButton = (): HTMLElement => screen.getByRole("button", { name: /Importer/ });

  async function analyzeThen(user: ReturnType<typeof userEvent.setup>, analysis: Analysis): Promise<void> {
    q.analysis.value = analysis;
    await user.click(railButton(PARC_IMPORT_TEXTS.stepPaste));
    fireEvent.change(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel }), { target: { value: '{"format":"parc-v2","cases":[]}' } });
    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.analyze }));
  }

  it("pt 7 — un nom de groupe changé à l'étape Séances communes est repris dans la grille Réservations", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ mutualisations: [mkLine(0, "U11M1 + U11M2", "aCreer", { groupe: "CEC", gymnaseId: null, position })] }));

    await user.click(railButton(PARC_IMPORT_TEXTS.sectionTitle("mutualisations")));
    fireEvent.change(screen.getByRole("textbox", { name: (n: string) => n.startsWith(PARC_IMPORT_TEXTS.groupLabel("U11M1 + U11M2")) }), { target: { value: "Loisirs" } });
    await user.click(railButton(PARC_IMPORT_TEXTS.sectionTitle("reservations")));

    expect(screen.getByRole("button", { name: T.mutualisationCaseLabel("Loisirs", "U11M1 + U11M2") })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: T.mutualisationCaseLabel("CEC", "U11M1 + U11M2") })).not.toBeInTheDocument();
  });

  describe("pt 7 — ce que le gestionnaire VOIT, pas seulement le nom accessible", () => {
    const start = formatMinutes(17 * 60);
    const end = formatMinutes(18 * 60 + 30);
    const groupField = (): HTMLElement => screen.getByRole("textbox", { name: (n: string) => n.startsWith(PARC_IMPORT_TEXTS.groupLabel("U11M1 + U11M2")) });
    const openMutual = async (user: ReturnType<typeof userEvent.setup>): Promise<void> => {
      await analyzeThen(user, analysisOf({ mutualisations: [mkLine(0, "U11M1 + U11M2", "aCreer", { groupe: "CEC", gymnaseId: null, position })] }));
      await user.click(railButton(PARC_IMPORT_TEXTS.sectionTitle("mutualisations")));
    };

    it("après renommage, la carte et la case de groupe montrent le nouveau nom, en texte et en infobulle", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await openMutual(user);

      fireEvent.change(groupField(), { target: { value: "Loisirs" } });
      expect(screen.getByText("Loisirs"), "le titre de la carte suit le champ").toBeInTheDocument();
      expect(screen.queryByText("CEC")).not.toBeInTheDocument();

      await user.click(railButton(PARC_IMPORT_TEXTS.sectionTitle("reservations")));
      const groupCase = screen.getByRole("button", { name: T.mutualisationCaseLabel("Loisirs", "U11M1 + U11M2") });
      expect(groupCase).toHaveTextContent(/^Loisirs$/);
      expect(groupCase).toHaveAttribute("title", T.caseTooltip("Loisirs · U11M1 + U11M2", start, end));
    });

    it("champ vidé : la carte et la case reviennent au nom lu, celui que l'import gardera", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await openMutual(user);

      fireEvent.change(groupField(), { target: { value: "" } });
      expect(screen.getByText("CEC"), "le titre de la carte revient au nom lu").toBeInTheDocument();

      await user.click(railButton(PARC_IMPORT_TEXTS.sectionTitle("reservations")));
      const groupCase = screen.getByRole("button", { name: T.mutualisationCaseLabel("CEC", "U11M1 + U11M2") });
      expect(groupCase).toHaveTextContent(/^CEC$/);
      expect(groupCase).toHaveAttribute("title", T.caseTooltip("CEC · U11M1 + U11M2", start, end));
    });
  });

  describe("le nom du groupe remplace ses équipes sur la carte, et survit à « Revérifier » (retours du 31/10)", () => {
    const groupField = (): HTMLElement => screen.getByRole("textbox", { name: (n: string) => n.startsWith(PARC_IMPORT_TEXTS.groupLabel("U11M1 + U11M2")) });
    const teamsTrigger = (): HTMLElement => screen.getByRole("button", { name: (n: string) => n.startsWith(PARC_IMPORT_TEXTS.teamsForGroup("U11M1 + U11M2")) });
    const namedLine = (groupe: string, extra: Partial<Line> = {}): Line => mkLine(0, "U11M1 + U11M2", "aCreer", { groupe, gymnaseId: null, position, ...extra });
    const openMutual = async (user: ReturnType<typeof userEvent.setup>, line: Line): Promise<void> => {
      await analyzeThen(user, analysisOf({ mutualisations: [line] }));
      await user.click(railButton(PARC_IMPORT_TEXTS.sectionTitle("mutualisations")));
    };
    /** D10 — un nom de groupe ne périme pas l'aperçu : pour revérifier, on corrige aussi le créneau. */
    const verifyAfterSlotEdit = async (user: ReturnType<typeof userEvent.setup>): Promise<void> => {
      await user.click(screen.getByRole("button", { name: T.editCaseTitle as unknown as string }));
      await user.click(screen.getByRole("button", { name: T.save as unknown as string }));
      await user.click(screen.getByRole("button", { name: T.verifyAction as unknown as string }));
    };

    it("nom lu puis nom saisi : la carte ne montre que le nom, jamais les équipes à côté", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await openMutual(user, namedLine("CEC"));

      expect(screen.getByText("CEC")).toBeInTheDocument();
      expect(screen.queryByText("U11M1 + U11M2")).not.toBeInTheDocument();

      fireEvent.change(groupField(), { target: { value: "Loisirs" } });
      expect(screen.getByText("Loisirs")).toBeInTheDocument();
      expect(screen.queryByText("U11M1 + U11M2")).not.toBeInTheDocument();
    });

    it("sans nom : la carte garde ses équipes", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await openMutual(user, namedLine(""));

      expect(screen.getByText("U11M1 + U11M2")).toBeInTheDocument();
    });

    it("les équipes d'un groupe nommé restent lisibles dans le sélecteur, même encore à créer, sans redéfinir la composition", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await openMutual(user, namedLine("CEC", { equipes: [{ id: null, nom: "U11M1" }, { id: null, nom: "U11M2" }] } as unknown as Partial<Line>));

      expect(teamsTrigger()).toHaveTextContent("U11M1");
      expect(teamsTrigger()).toHaveTextContent("U11M2");

      await verifyAfterSlotEdit(user);
      const body = q.analyzeMutate.mock.calls.at(-1)?.[0] as { decisions: Array<{ section: string; corrections?: Record<string, unknown> }> };
      expect(body.decisions.find((d) => "mutualisations" === d.section)?.corrections?.equipes).toBeUndefined();
    });

    it("« Revérifier » garde le nom saisi : champ, carte et case, et le renvoie au serveur", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await openMutual(user, namedLine("CEC"));
      fireEvent.change(groupField(), { target: { value: "Loisirs" } });

      // Comme l'API : l'analyse rejouée rend le nom LU, elle n'applique pas la correction de groupe.
      await verifyAfterSlotEdit(user);

      const body = q.analyzeMutate.mock.calls.at(-1)?.[0] as { decisions: Array<{ section: string; corrections?: Record<string, unknown> }> };
      expect(body.decisions.find((d) => "mutualisations" === d.section)?.corrections?.groupe).toBe("Loisirs");
      expect(groupField()).toHaveValue("Loisirs");
      expect(screen.getByText("Loisirs")).toBeInTheDocument();

      await user.click(railButton(PARC_IMPORT_TEXTS.sectionTitle("reservations")));
      expect(screen.getByRole("button", { name: T.mutualisationCaseLabel("Loisirs", "U11M1 + U11M2") })).toBeInTheDocument();
    });

    it("« Revérifier » après avoir vidé le champ revient au nom lu", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await openMutual(user, namedLine("CEC"));
      fireEvent.change(groupField(), { target: { value: "" } });

      await verifyAfterSlotEdit(user);

      expect(groupField()).toHaveValue("CEC");
    });

    it("une NOUVELLE analyse repart du nom lu : le nom saisi pour l'ancien collage ne se colle pas sur le nouveau groupe", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await openMutual(user, namedLine("CEC"));
      fireEvent.change(groupField(), { target: { value: "Loisirs" } });

      await openMutual(user, namedLine("Autre"));

      expect(groupField()).toHaveValue("Autre");
      expect(screen.getByText("Autre")).toBeInTheDocument();
      expect(screen.queryByText("Loisirs")).not.toBeInTheDocument();
    });
  });

  it("pt 10 — une ligne modifiée n'affiche qu'UN « Revérifier », et le bandeau périmé reste", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkLine(0, "SF1", "aCreer")] }));

    await user.click(screen.getByRole("button", { name: (n: string) => n.startsWith(T.decisionFor("SF1")) }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: PARC_IMPORT_TEXTS.ignoreLine }));

    expect(screen.getByText(T.staleHint(1))).toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: T.verifyAction as unknown as string })).toHaveLength(1);
  });

  it("pt 11 — une étape sans point en attente ne passe au vert qu'une fois visitée", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }));

    const gymnases = PARC_IMPORT_TEXTS.sectionTitle("gymnases");
    // L'étape d'atterrissage est visitée ; les autres n'ont encore ni vert ni alerte.
    expect(railButton(PARC_IMPORT_TEXTS.sectionTitle("equipes")).getAttribute("aria-label") ?? "").toContain("étape terminée");
    expect(railButton(gymnases).getAttribute("aria-label") ?? "").not.toContain("étape terminée");

    await user.click(railButton(gymnases));
    expect(railButton(gymnases).getAttribute("aria-label") ?? "").toContain("étape terminée");
  });

  it("pt 11 — « Importer » reste inactif tant qu'une étape n'est pas visitée, avec la raison visible, puis s'active", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }));

    // Atterrissage sur Équipes : restent Ententes, Gymnases, Séances communes, Réservations.
    expect(importButton()).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByText(T.importStepsToVisit(4))).toBeInTheDocument();

    await user.click(importButton());
    expect(q.importMutate, "un clic sur Importer inactif n'envoie rien").not.toHaveBeenCalled();

    await visitAllSteps(user);
    expect(importButton()).not.toHaveAttribute("aria-disabled", "true");
    await user.click(importButton());
    expect(q.importMutate).toHaveBeenCalledTimes(1);
  });

  it("pt 11 — « Voir » mène à la première étape non visitée", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }));

    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.seeBlockingLine }));

    expect(railButton(PARC_IMPORT_TEXTS.stepEntentes)).toHaveAttribute("aria-current", "step");
  });

  it("pt 11 — une étape visitée mais en alerte bloque l'import ; ignorer la ligne en cause la fait passer au vert", async () => {
    // Sans cette sortie, une alerte non corrigeable bloquerait l'import pour de bon.
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(
      user,
      analysisOf({
        // Une ligne gardée ailleurs : sans elle, « Cochez au moins une ligne » bloquerait à elle seule.
        gymnases: [mkLine(0, "Palais des Sports", "aCreer")],
        mutualisations: [mkLine(0, "U11M1 + U11M2", "alerte", { coche: true, groupe: "CEC", gymnaseId: null, raison: "À ajuster.", position })],
      }),
    );

    await visitAllSteps(user);
    const mutual = PARC_IMPORT_TEXTS.sectionTitle("mutualisations");
    expect(railButton(mutual).getAttribute("aria-label") ?? "").toContain("à vérifier");
    expect(importButton()).toHaveAttribute("aria-disabled", "true");

    await user.click(railButton(mutual));
    await user.click(screen.getByRole("button", { name: T.mutualIgnore as unknown as string }));

    expect(railButton(mutual).getAttribute("aria-label") ?? "").toContain("étape terminée");
    expect(importButton()).not.toHaveAttribute("aria-disabled", "true");
  });

  // Décision fondateur : une alerte servie déjà en « Ignorer » n'attend aucun geste, visiter l'étape suffit.
  it.each([
    ["creneaux", "gymnases"],
    ["gymnases", "gymnases"],
    ["mutualisations", "mutualisations"],
    ["reservations", "reservations"],
  ] as const)("pt 11 — une alerte %s déjà en « Ignorer » passe au vert à la seule visite de son étape, sans geste", async (section, stepId) => {
    const user = userEvent.setup();
    const ignored = mkLine("gymnases" === section ? 1 : 0, "Ligne en alerte", "alerte", { coche: false, gymnaseId: null, raison: "À ajuster.", position });
    const kept = mkLine(0, "Palais des Sports", "aCreer");
    const payload = { format: "parc-v2", cases: [{ gymnase: "Gymnase Zénith", jour: 3, debut: "17:00", duree: 90, equipes: [] }] };
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ gymnases: "gymnases" === section ? [kept, ignored] : [kept], ...("gymnases" === section ? {} : { [section]: [ignored] }) }, { payload }));

    const title = PARC_IMPORT_TEXTS.sectionTitle(stepId);
    expect(railButton(title).getAttribute("aria-label") ?? "", "pas encore vue : pas de vert").not.toContain("étape terminée");

    await visitAllSteps(user);

    expect(railButton(title).getAttribute("aria-label") ?? "").toContain("étape terminée");
    expect(importButton()).not.toHaveAttribute("aria-disabled", "true");
  });

  it.each(["creneaux", "reservations"] as const)("pt 11 — une alerte %s GARDÉE reste à vérifier après la visite et bloque l'import", async (section) => {
    const user = userEvent.setup();
    const payload = { format: "parc-v2", cases: [{ gymnase: "Gymnase Zénith", jour: 3, debut: "17:00", duree: 90, equipes: [] }] };
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")], [section]: [mkLine(0, "Ligne en alerte", "alerte", { coche: true, gymnaseId: null, raison: "À ajuster.", position })] }, { payload }));

    await visitAllSteps(user);

    expect(importButton()).toHaveAttribute("aria-disabled", "true");
  });

  it("pt 11 — « Revérifier » ne fait pas perdre les étapes déjà visitées", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ equipes: [mkLine(0, "SF1", "aCreer")], gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }));
    await visitAllSteps(user);

    await user.click(railButton(PARC_IMPORT_TEXTS.sectionTitle("equipes")));
    await user.click(screen.getByRole("button", { name: (n: string) => n.startsWith(T.decisionFor("SF1")) }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: PARC_IMPORT_TEXTS.ignoreLine }));
    await user.click(screen.getByRole("button", { name: T.verifyAction as unknown as string }));

    expect(importButton()).not.toHaveAttribute("aria-disabled", "true");
  });

  it("pt 11 — un import refusé par le serveur laisse « Importer » actif (on doit pouvoir réessayer)", async () => {
    const user = userEvent.setup();
    q.importError.value = { response: { status: 422 }, data: { errors: ["Refus du serveur."] } };
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user, analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }));
    await visitAllSteps(user);

    await user.click(importButton());
    await screen.findByRole("alert");

    expect(importButton()).not.toHaveAttribute("aria-disabled", "true");
  });
});

/**
 * ─────────── Quick wins (31/10) : D4a, D4b, D10 — ROUGE option A ───────────
 *
 * PROMESSES :
 *  - D4a : ce que dit la modale d'un groupe suit sa composition, partout où son libellé paraît ;
 *  - D4b (NR, voisin de l'axe constraint semantics) : une équipe retirée d'une séance commune ne revient
 *    jamais en silence après une revérification ; seul un nouveau collage repart de la lecture ;
 *  - D10 : on n'importe que ce qui a été vérifié. Un geste qui change le verdict du serveur périme la
 *    vérification et bloque l'import ; les autres gestes ne dérangent personne.
 */
describe("ParcImportModal — quick wins : composition d'un groupe et bouton de vérification", () => {
  const T = PARC_IMPORT_TEXTS as unknown as Record<string, (...args: unknown[]) => string> & Record<string, string>;
  const originalTeams = w.teams;
  const position = { gymnase: "Gymnase Zénith", jour: 3, debut: "17:00", duree: 90 };
  const THREE = "U11M1 + U11M2 + U11M3";
  const TWO = "U11M1 + U11M2";

  beforeEach(() => {
    w.teams = [
      { id: "wt-1", name: "U11M1", priorityTierId: 1, tierOrder: 0 },
      { id: "wt-2", name: "U11M2", priorityTierId: 1, tierOrder: 1 },
      { id: "wt-3", name: "U11M3", priorityTierId: 1, tierOrder: 2 },
    ];
  });
  afterEach(() => {
    w.teams = originalTeams;
  });

  type User = ReturnType<typeof userEvent.setup>;
  /** Une clé de texte à créer : le rouge la NOMME au lieu de chercher un bouton sans nom. */
  const key = (k: string): string => {
    expect(T[k], `clé texts.ts \`${k}\` à créer`).toBeDefined();
    return T[k];
  };
  const rail = (label: string): HTMLElement => within(screen.getByRole("navigation")).getByRole("button", { name: (n: string) => n.includes(label) });
  const goTo = (user: User, section: string): Promise<void> => user.click(rail(PARC_IMPORT_TEXTS.sectionTitle(section)));
  const importButton = (): HTMLElement => screen.getByRole("button", { name: /Importer/ });
  const teamsTrigger = (): HTMLElement => {
    // Le nom du sélecteur suit peut-être la composition : on ne vise que son préfixe.
    const prefix = T.teamsForGroup("").split("«")[0] as string;
    return screen.getByRole("button", { name: (n: string) => n.startsWith(prefix) });
  };
  const toggleTeam = async (user: User, name: string): Promise<void> => {
    await user.click(teamsTrigger());
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: (n: string) => n.startsWith(name) }));
    await user.keyboard("{Escape}");
  };
  const mutualLine = (libelle: string, extra: Partial<Line> = {}): Line =>
    mkLine(0, libelle, "aCreer", {
      groupe: "",
      gymnaseId: null,
      position,
      equipes: libelle.split(" + ").map((nom) => ({ id: `wt-${nom.slice(-1)}`, nom })),
      ...extra,
    } as Partial<Line>);
  const decor = (libelle = THREE, extra: Partial<Line> = {}): Analysis =>
    analysisOf({
      equipes: [mkLine(0, "SF1", "aCreer")],
      gymnases: [mkLine(0, "Gymnase Zénith", "aCreer")],
      mutualisations: [mutualLine(libelle, extra)],
      reservations: [mkLine(0, "SM1", "aCreer", { gymnaseId: null, position: { ...position, jour: 4 } })],
    }, { payload: { format: "parc-v2", cases: [] } });
  async function analyze(user: User, analysis: Analysis): Promise<void> {
    q.analysis.value = analysis;
    await user.click(rail(PARC_IMPORT_TEXTS.stepPaste));
    fireEvent.change(screen.getByRole("textbox", { name: PARC_IMPORT_TEXTS.pasteLabel }), { target: { value: '{"format":"parc-v2","cases":[]}' } });
    await user.click(screen.getByRole("button", { name: PARC_IMPORT_TEXTS.analyze }));
  }
  /** Analyse puis visite tout : plus rien d'autre que la vérification ne peut griser « Importer ». */
  async function ready(user: User, analysis: Analysis = decor()): Promise<void> {
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyze(user, analysis);
    await visitAllSteps(user);
  }
  const editSlot = async (user: User): Promise<void> => {
    await goTo(user, "mutualisations");
    await user.click(screen.getByRole("button", { name: T.editCaseTitle as unknown as string }));
    await user.click(screen.getByRole("button", { name: T.save as unknown as string }));
  };
  const pickDecision = async (user: User, libelle: string, option: string): Promise<void> => {
    await user.click(screen.getByRole("button", { name: (n: string) => n.startsWith(T.decisionFor(libelle)) }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: option }));
  };
  const expectStatus = (text: string): void => {
    expect(screen.getAllByRole("status").some((s) => (s.textContent ?? "").includes(text)), `status annoncé : ${text}`).toBe(true);
  };
  const expectInactive = (el: HTMLElement): void => {
    expect((el as HTMLButtonElement).disabled || "true" === el.getAttribute("aria-disabled"), "bouton inactif").toBe(true);
  };
  const expectUpToDate = (): void => {
    expectInactive(screen.getByRole("button", { name: key("verifyUpToDate") }));
    expect(screen.queryByRole("button", { name: key("verifyAction") })).not.toBeInTheDocument();
    expect(importButton()).not.toHaveAttribute("aria-disabled", "true");
  };
  const expectStale = (): void => {
    expect(screen.getByRole("button", { name: key("verifyAction") })).toBeEnabled();
    expect(importButton()).toHaveAttribute("aria-disabled", "true");
    expect(importButton()).toHaveAccessibleDescription(key("importStaleReason"));
  };
  const lastAnalyzeBody = (): { payload: unknown; decisions: Array<{ section: string; corrections?: { equipes?: string[] } }> } => q.analyzeMutate.mock.calls.at(-1)?.[0];
  const mutualOf = (body: { decisions: Array<{ section: string; corrections?: { equipes?: string[] } }> }) => body.decisions.find((d) => "mutualisations" === d.section);

  describe("D4a — le libellé d'un groupe suit sa composition", () => {
    const cardTitles = (name: string): HTMLElement[] => screen.getAllByText(name).filter((el) => null === el.closest("button"));

    it("retirer U11M2 : carte, champ de nom et en-tête de l'éditeur disent « U11M1 » ; re-cocher rend « U11M1 + U11M2 »", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await analyze(user, decor(TWO));
      await goTo(user, "mutualisations");

      await toggleTeam(user, "U11M2");
      expect(cardTitles("U11M1"), "titre de carte").toHaveLength(1);
      expect(screen.getByRole("textbox", { name: T.groupLabel("U11M1") })).toBeInTheDocument();

      await user.click(screen.getByRole("button", { name: T.editCaseTitle as unknown as string }));
      const editor = screen.getByRole("button", { name: T.save as unknown as string }).closest('[role="dialog"]') as HTMLElement;
      expect(within(editor).getByText("U11M1"), "en-tête de l'éditeur").toBeInTheDocument();
      await user.click(within(editor).getByRole("button", { name: T.cancel as unknown as string }));

      await toggleTeam(user, "U11M2");
      expect(cardTitles(TWO), "titre de carte rétabli").toHaveLength(1);
      expect(screen.getByRole("textbox", { name: T.groupLabel(TWO) })).toBeInTheDocument();
    });

    it("retirer U11M2 : la case de la grille le dit en texte, en infobulle et en nom accessible", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await analyze(user, decor(TWO));
      await goTo(user, "mutualisations");
      await toggleTeam(user, "U11M2");

      await goTo(user, "reservations");
      const groupCase = screen.getByRole("button", { name: T.mutualisationCaseLabel("", "U11M1") });
      expect(groupCase).toHaveTextContent(/^U11M1$/);
      expect(groupCase).toHaveAttribute("title", T.caseTooltip("U11M1", formatMinutes(17 * 60), formatMinutes(18 * 60 + 30)));

      await goTo(user, "mutualisations");
      await toggleTeam(user, "U11M2");
      await goTo(user, "reservations");
      expect(screen.getByRole("button", { name: T.mutualisationCaseLabel("", TWO) })).toHaveTextContent(TWO);
    });

    it("une case en alerte : son bouton de saut suit la composition", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      await analyze(user, decor(TWO, { statut: "alerte", coche: true, raison: "À ajuster." }));
      await goTo(user, "mutualisations");
      await toggleTeam(user, "U11M2");

      await goTo(user, "reservations");
      expect(screen.getByRole("button", { name: `${T.goToLine as unknown as string} — U11M1` })).toHaveTextContent("U11M1");
    });
  });

  describe("D4b — une équipe retirée ne revient pas en silence (NR)", () => {
    it("retirer une équipe puis revérifier : l'analyse et l'import reçoivent la composition redéfinie, l'écran aussi", async () => {
      const user = userEvent.setup();
      await ready(user);
      await goTo(user, "mutualisations");
      await toggleTeam(user, "U11M3");

      await user.click(screen.getByRole("button", { name: key("verifyAction") }));
      expect(mutualOf(lastAnalyzeBody())?.corrections?.equipes).toEqual(["wt-1", "wt-2"]);
      expect(teamsTrigger()).not.toHaveTextContent("U11M3");

      await user.click(importButton());
      expect(mutualOf(q.importMutate.mock.calls[0]?.[0])?.corrections?.equipes).toEqual(["wt-1", "wt-2"]);
    });

    it("la composition redéfinie survit à DEUX revérifications", async () => {
      const user = userEvent.setup();
      await ready(user);
      await goTo(user, "mutualisations");
      await toggleTeam(user, "U11M3");
      await user.click(screen.getByRole("button", { name: key("verifyAction") }));

      await editSlot(user);
      await user.click(screen.getByRole("button", { name: key("verifyAction") }));
      expect(mutualOf(lastAnalyzeBody())?.corrections?.equipes).toEqual(["wt-1", "wt-2"]);

      await user.click(importButton());
      expect(mutualOf(q.importMutate.mock.calls[0]?.[0])?.corrections?.equipes).toEqual(["wt-1", "wt-2"]);
    });

    it("un nouveau collage repart de la composition lue", async () => {
      const user = userEvent.setup();
      await ready(user);
      await goTo(user, "mutualisations");
      await toggleTeam(user, "U11M3");
      await user.click(screen.getByRole("button", { name: key("verifyAction") }));

      await analyze(user, decor());
      await visitAllSteps(user);
      await user.click(importButton());
      expect(mutualOf(q.importMutate.mock.calls[0]?.[0])?.corrections?.equipes).toBeUndefined();
    });
  });

  describe("D10 — le bouton de vérification au pied de modale", () => {
    it("les libellés sont ceux validés (pluriels compris), et l'ancienne clé a disparu", () => {
      expect(T).toMatchObject({
        verifyUpToDate: "Vérifié",
        verifyAction: "Revérifier",
        verifying: "Vérification…",
        importStaleReason: "Revérifiez vos modifications avant d'importer.",
        importVerifyingReason: "Vérification en cours.",
        verifyStatusUpToDate: "Vérification à jour.",
        verifyStatusStale: "Des modifications sont à revérifier avant d'importer.",
        verifyStatusRunning: "Vérification en cours.",
        verifyStatusFailed: "La vérification a échoué — réessayez.",
      });
      expect(T.staleHint(1)).toBe("Vous avez modifié 1 ligne : revérifiez pour mettre à jour les alertes et débloquer l'import.");
      expect(T.staleHint(3)).toBe("Vous avez modifié 3 lignes : revérifiez pour mettre à jour les alertes et débloquer l'import.");
      expect(T).not.toHaveProperty("revalidate");
    });

    it("après l'analyse : « Vérifié » inactif à côté d'« Importer » actif, status à jour, plus rien en en-tête d'étape", async () => {
      const user = userEvent.setup();
      renderWithProviders(<ParcImportModal {...defaultProps} />);
      expect(screen.queryByRole("button", { name: key("verifyUpToDate") }), "rien à vérifier avant l'analyse").not.toBeInTheDocument();
      await analyze(user, decor());
      await visitAllSteps(user);

      expectUpToDate();
      expectStatus(key("verifyStatusUpToDate"));
      expect(importButton().closest("footer")).toContainElement(screen.getByRole("button", { name: key("verifyUpToDate") }));
      const heading = screen.getByRole("heading", { name: (n: string) => n.includes(PARC_IMPORT_TEXTS.sectionTitle("reservations")) });
      expect(within(heading.parentElement as HTMLElement).queryByRole("button")).not.toBeInTheDocument();
    });

    it("éditer une case : « Revérifier » actif, « Importer » grisé avec sa raison, bandeau à jour, clic sans effet", async () => {
      const user = userEvent.setup();
      await ready(user);
      await editSlot(user);

      expectStale();
      expectStatus(key("verifyStatusStale"));
      expect(screen.getByText(T.staleHint(1))).toBeInTheDocument();
      await user.click(importButton());
      expect(q.importMutate).not.toHaveBeenCalled();
    });

    it("retirer une équipe d'une composition périme la vérification", async () => {
      const user = userEvent.setup();
      await ready(user);
      await goTo(user, "mutualisations");
      await toggleTeam(user, "U11M3");

      expectStale();
    });

    it("retirer puis remettre une équipe : reste à revérifier", async () => {
      const user = userEvent.setup();
      await ready(user);
      await goTo(user, "mutualisations");
      await toggleTeam(user, "U11M3");
      await toggleTeam(user, "U11M3");

      expectStale();
    });

    it("décocher une équipe (elle ne sera plus importée) périme la vérification", async () => {
      const user = userEvent.setup();
      await ready(user);
      await goTo(user, "equipes");
      await pickDecision(user, "SF1", T.ignoreLine as unknown as string);

      expectStale();
    });

    it("changer la cible d'une équipe sans changer sa cochabilité ne périme rien", async () => {
      const user = userEvent.setup();
      await ready(user, analysisOf({ equipes: [mkLine(0, "SF1", "suggere", { cibleId: "t1", candidats: [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }] })] }));
      await goTo(user, "equipes");
      await pickDecision(user, "SF1", T.matchTo("Armund"));

      expect(screen.queryByText(T.staleHint(1))).not.toBeInTheDocument();
      expectUpToDate();
    });

    it.each<[string, (user: User) => Promise<void>]>([
      ["la décision d'un gymnase", async (user) => {
        await goTo(user, "gymnases");
        await pickDecision(user, "Gymnase Zénith", T.ignoreLine as unknown as string);
      }],
      ["le nom d'un groupe", async (user) => {
        await goTo(user, "mutualisations");
        fireEvent.change(screen.getByRole("textbox", { name: T.groupLabel(THREE) }), { target: { value: "Loisirs" } });
      }],
      ["les séances d'une équipe", async (user) => {
        await goTo(user, "equipes");
        fireEvent.change(screen.getByRole("spinbutton", { name: T.sessionsFieldLabel("SF1") }), { target: { value: "3" } });
      }],
      ["garder ou ignorer une séance commune", async (user) => {
        await goTo(user, "mutualisations");
        await user.click(screen.getByRole("button", { name: T.mutualIgnore as unknown as string }));
      }],
      ["le gymnase d'une case", async (user) => {
        await goTo(user, "mutualisations");
        await user.click(screen.getByRole("button", { name: (n: string) => n.startsWith(T.venueForGroup(THREE)) }));
        await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: "Gymnase Léon-Blum" }));
      }],
    ])("%s ne périme pas la vérification", async (_geste, geste) => {
      const user = userEvent.setup();
      await ready(user);
      await geste(user);

      expect(screen.queryByText(T.staleHint(1)), "pas de bandeau périmé").not.toBeInTheDocument();
      expectUpToDate();
    });

    it("cliquer « Revérifier » envoie { payload, decisions } et rend « Vérifié » et « Importer »", async () => {
      const user = userEvent.setup();
      await ready(user);
      await editSlot(user);
      q.analyzeMutate.mockClear();

      await user.click(screen.getByRole("button", { name: key("verifyAction") }));

      const body = lastAnalyzeBody();
      expect(q.analyzeMutate).toHaveBeenCalledTimes(1);
      expect(body.payload).toEqual({ format: "parc-v2", cases: [] });
      expect(Array.isArray(body.decisions)).toBe(true);
      expectUpToDate();
      expect(screen.queryByText(T.staleHint(1))).not.toBeInTheDocument();
    });

    it("pendant la vérification : bouton « Vérification… » inactif, « Importer » grisé avec sa raison, status en cours", async () => {
      const user = userEvent.setup();
      await ready(user);
      await editSlot(user);
      q.analyzeMutate.mockImplementationOnce(() => {
        q.analyzePending.value = true;
        q.rerender.fn();
      });

      await user.click(screen.getByRole("button", { name: key("verifyAction") }));

      expectInactive(screen.getByRole("button", { name: key("verifying") }));
      expect(importButton()).toHaveAttribute("aria-disabled", "true");
      expect(importButton()).toHaveAccessibleDescription(key("importVerifyingReason"));
      expectStatus(key("verifyStatusRunning"));
    });

    it("la vérification échoue : on reste à revérifier, status d'échec, et réessayer débloque", async () => {
      const user = userEvent.setup();
      await ready(user);
      await editSlot(user);
      q.analyzeMutate.mockImplementationOnce((_body: unknown, opts?: { onError?: (e: unknown) => void }) => {
        const failure = new Error("échec réseau");
        q.analyzeError.value = failure;
        opts?.onError?.(failure);
        q.rerender.fn();
      });

      await user.click(screen.getByRole("button", { name: key("verifyAction") }));
      expectStale();
      expectStatus(key("verifyStatusFailed"));

      await user.click(screen.getByRole("button", { name: key("verifyAction") }));
      expectUpToDate();
    });
  });

  // Le serveur rejoue les décisions mais rend ses verdicts de LECTURE : la revérification ne doit pas
  // défaire à l'écran ce que le gestionnaire vient de trancher (ParcImportModal.tsx:303, ParcImportAnalyzer.php:94).
  describe("D10 — une revérification ne défait pas les gestes du gestionnaire", () => {
    it("une équipe ignorée le reste : à l'écran et à l'import", async () => {
      const user = userEvent.setup();
      await ready(user);
      await goTo(user, "equipes");
      await pickDecision(user, "SF1", T.ignoreLine as unknown as string);
      await user.click(screen.getByRole("button", { name: key("verifyAction") }));

      expect(screen.getByRole("button", { name: (n: string) => n.startsWith(T.decisionFor("SF1")) })).toHaveTextContent(T.ignoreLine as unknown as string);
      await user.click(importButton());
      const decisions = (q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; coche: boolean }> }).decisions;
      expect(decisions.find((d) => "equipes" === d.section)?.coche).toBe(false);
    });

    it("une ligne ambiguë tranchée ne redevient pas « à trancher »", async () => {
      const user = userEvent.setup();
      const analysis = decor();
      analysis.sections.equipes = [mkAmbiguous(0, "SF1", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }])];
      await ready(user, analysis);
      await goTo(user, "equipes");
      await pickDecision(user, "SF1", T.matchTo("Armand"));
      await editSlot(user);
      await user.click(screen.getByRole("button", { name: key("verifyAction") }));

      await user.click(importButton());
      const decisions = (q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; cibleId?: string }> }).decisions;
      expect(decisions.find((d) => "equipes" === d.section)?.cibleId).toBe("t1");
    });

    it("les séances corrigées d'une équipe restent celles saisies", async () => {
      const user = userEvent.setup();
      await ready(user);
      await goTo(user, "equipes");
      fireEvent.change(screen.getByRole("spinbutton", { name: T.sessionsFieldLabel("SF1") }), { target: { value: "3" } });
      await editSlot(user);
      await user.click(screen.getByRole("button", { name: key("verifyAction") }));

      await goTo(user, "equipes");
      expect(screen.getByRole("spinbutton", { name: T.sessionsFieldLabel("SF1") })).toHaveValue(3);
    });
  });

  describe("D10 — trancher une ligne ambiguë ne périme que si une alerte en dépend", () => {
    const ambiguousSf1 = (): Line => mkAmbiguous(0, "SF1", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }]);
    const citesSf1 = { equipes: [{ id: null, nom: "SF1" }, { id: "wt-1", nom: "U11M1" }] } as unknown as Partial<Line>;
    const withAmbiguous = (extra: Partial<Line>): Analysis => {
      const analysis = decor("SF1 + U11M1", { ...citesSf1, ...extra });
      analysis.sections.equipes = [ambiguousSf1()];
      return analysis;
    };

    it("sans alerte qui en dépende : reste « Vérifié »", async () => {
      const user = userEvent.setup();
      await ready(user, withAmbiguous({}));
      await goTo(user, "equipes");
      await pickDecision(user, "SF1", T.matchTo("Armand"));

      expect(screen.queryByText(T.staleHint(1)), "pas de bandeau périmé").not.toBeInTheDocument();
      expectUpToDate();
    });

    // La première analyse ne connaît aucune décision : l'alerte ne naît qu'au rejeu, où l'ambiguë non
    // tranchée part décochée, donc « ignorée » (ParcImportAnalyzer.php:266). Sans bouton pour recalculer,
    // cette alerte devenue fausse bloquerait « Importer » pour de bon.
    it("une alerte « équipe ignorée » qui en dépend : « Revérifier » s'active et le rejeu la lève", async () => {
      const user = userEvent.setup();
      const raison = "Cette séance commune cite « SF1 », à revoir.";
      await ready(user, withAmbiguous({}));
      await editSlot(user);
      q.analysis.value = withAmbiguous({ statut: "alerte", coche: false, raison, alerteCode: "equipe_ignoree" });
      await user.click(screen.getByRole("button", { name: key("verifyAction") }));
      expect(screen.getByText(raison)).toBeInTheDocument();

      await goTo(user, "equipes");
      await pickDecision(user, "SF1", T.createOption("SF1"));
      expectStale();

      q.analysis.value = withAmbiguous({});
      await user.click(screen.getByRole("button", { name: key("verifyAction") }));
      const equipe = (lastAnalyzeBody().decisions as Array<{ section: string; coche?: boolean }>).find((d) => "equipes" === d.section);
      expect(equipe?.coche, "le rejeu reçoit l'équipe gardée").toBe(true);
      expectUpToDate();
      await goTo(user, "mutualisations");
      expect(screen.queryByText(raison)).not.toBeInTheDocument();
    });
  });

  // L'amorce du sélecteur ne garde aujourd'hui que les équipes déjà au club (ParcImportModal.tsx:1496).
  it("D4b — retirer l'équipe existante d'un groupe mixte garde les équipes à créer, par leur `import:<index>`", async () => {
    const user = userEvent.setup();
    const analysis = decor("U11M1 + N1 + N2", { equipes: [{ id: "wt-1", nom: "U11M1" }, { id: null, nom: "N1" }, { id: null, nom: "N2" }] } as unknown as Partial<Line>);
    analysis.sections.equipes = [mkLine(3, "N1", "aCreer"), mkLine(5, "N2", "aCreer")];
    await ready(user, analysis);
    await goTo(user, "mutualisations");
    await toggleTeam(user, "U11M1");

    expect(screen.queryByText(T.needsTwoTeams as unknown as string), "il reste deux équipes : N1 et N2").not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: key("verifyAction") }));
    await user.click(importButton());
    const equipes = mutualOf(q.importMutate.mock.calls[0]?.[0])?.corrections?.equipes;
    expect(equipes).toEqual(expect.arrayContaining(["import:3", "import:5"]));
    expect(equipes).toHaveLength(2);
  });
});
