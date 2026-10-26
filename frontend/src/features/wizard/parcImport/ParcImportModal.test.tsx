import { fireEvent, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { formatDuration } from "@/shared/lib/duration";
import { tierGroupLabel } from "@/shared/lib/teamTiers";
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
  miseAJour?: { champ: "seances"; de: number; a: number };
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
  analyzeMutate: vi.fn(),
  importMutate: vi.fn(),
}));

vi.mock("./queries", () => ({
  useAnalyzeParc: () => ({ mutate: q.analyzeMutate, isPending: q.analyzePending.value, data: q.analysis.value }),
  useImportParc: () => ({
    mutate: q.importMutate,
    isPending: q.importPending.value,
    data: q.importResult.value,
    error: q.importError.value,
    isError: null !== q.importError.value,
  }),
}));

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

beforeEach(() => {
  q.analysis.value = null;
  q.importResult.value = null;
  q.importError.value = null;
  q.analyzePending.value = false;
  q.importPending.value = false;
  // mutate(texte, { onSuccess }) rend l'analyse mockée (pré-posée par le test) — comme l'API.
  q.analyzeMutate.mockImplementation((_text: unknown, opts?: { onSuccess?: (a: unknown) => void }) => {
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
 *  - « Revalider » reposte `{payload, decisions}` à l'analyse (rejeu avec corrections).
 *
 * ⚠ Clés de `texts.ts` RÉFÉRENCÉES ET PAS ENCORE CRÉÉES (rouge légitime, NEO les crée) :
 *   `stepPaste` (« Coller »), `next`/`previous`, `lockedUntilAnalysis`, `decisionFor(libelle)`,
 *   `matchTo(nom)`/`matchToSuggested(nom)`, `createOption(libelle)`, `ignoreLine`, `toDecide`
 *   (placeholder « À trancher… »), `importBlocked(nAmbigu, nGenre)`, `revalidate`, `importErrorHeading`.
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

  it("StepRail — 5 étapes, et les étapes 3-5 (Équipes/Gymnases/Réservations) sont verrouillées avant l'analyse", () => {
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    // Avant toute analyse, les trois étapes de contenu sont présentes dans le rail MAIS verrouillées
    // (bouton désactivé) : on ne peut pas les visiter tant que rien n'a été analysé.
    for (const section of ["equipes", "gymnases", "reservations"] as const) {
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
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; coche: boolean; cibleId?: string | null }> };
    const dec = body.decisions.find((d) => "equipes" === d.section && 0 === d.index);
    expect(dec?.coche).toBe(false);
    expect(dec?.cibleId ?? null).toBeNull();
  });

  it("« Créer » sur une ligne ambiguë → décision cochée, SANS cibleId (nouvelle entité)", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({
      equipes: [mkAmbiguous(0, "Armond", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }])],
    });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);

    await user.click(await screen.findByRole("button", { name: decisionTrigger("Armond") }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: T.createOption("Armond") }));
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const body = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; coche: boolean; cibleId?: string | null }> };
    const dec = body.decisions.find((d) => "equipes" === d.section && 0 === d.index);
    expect(dec?.coche).toBe(true);
    expect(dec?.cibleId ?? null).toBeNull();
  });

  it("une équipe appariée dont le parc propose un autre nombre de séances : la mise à jour est proposée sur la ligne et, acceptée, part en corrections.seances", async () => {
    // Régression du chantier G à RESTAURER (la refonte a perdu la proposition de mise à jour des
    // séances d'une équipe APPARIÉE — le back honore toujours `corrections.seances`). La ligne doit
    // AFFICHER la proposition (clé `sessionsUpdate`) et offrir une case d'acceptation dédiée (la ligne
    // équipe est un `Listbox`, plus une case d'inclusion comme avant G — d'où une case « Mettre à jour »
    // NOMMÉE par la proposition). Non acceptée → aucune `corrections.seances` ; acceptée → `=3`.
    const mkApparied = (): Analysis => analysisOf({ equipes: [mkLine(0, "SM1", "apparie", { cibleId: "t1", miseAJour: { champ: "seances", de: 2, a: 3 } })] });

    // (1) non acceptée → la décision ne porte PAS corrections.seances.
    const user1 = userEvent.setup();
    const { unmount } = renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user1, mkApparied());
    expect(screen.getByText(PARC_IMPORT_TEXTS.sessionsUpdate(2, 3))).toBeInTheDocument();
    await user1.click(screen.getByRole("button", { name: /Importer/ }));
    const body1 = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; corrections?: Record<string, unknown> }> };
    expect(body1.decisions.find((d) => "equipes" === d.section && 0 === d.index)?.corrections?.seances).toBeUndefined();
    unmount();
    q.importMutate.mockClear();

    // (2) acceptée (case dédiée) → corrections.seances = 3.
    const user2 = userEvent.setup();
    renderWithProviders(<ParcImportModal {...defaultProps} />);
    await analyzeThen(user2, mkApparied());
    await user2.click(screen.getByRole("checkbox", { name: PARC_IMPORT_TEXTS.sessionsUpdate(2, 3) }));
    await user2.click(screen.getByRole("button", { name: /Importer/ }));
    const body2 = q.importMutate.mock.calls[0]?.[0] as { decisions: Array<{ section: string; index: number; corrections?: Record<string, unknown> }> };
    expect(body2.decisions.find((d) => "equipes" === d.section && 0 === d.index)?.corrections?.seances).toBe(3);
  });

  it("une erreur d'import s'affiche DANS la modale en role=\"alert\" (plus un toast)", async () => {
    const user = userEvent.setup();
    // L'import échoue : le hook expose l'erreur, la modale la surface en role="alert" (micro-décision 13).
    q.importError.value = { response: { status: 422 }, data: { errors: ["L'équipe n°1 : appariement ambigu, à choisir."] } };
    const analysis = analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await user.click(screen.getByRole("button", { name: /Importer/ }));

    const alert = await screen.findByRole("alert");
    expect(within(alert).getByText(T.importErrorHeading as unknown as string)).toBeInTheDocument();
  });

  it("« Revalider » reposte { payload, decisions } à l'analyse (rejeu avec corrections)", async () => {
    const user = userEvent.setup();
    const payload = { format: "parc-v2", gymnases: [{ nom: "Palais des Sports" }], cases: [] };
    const analysis = analysisOf({ gymnases: [mkLine(0, "Palais des Sports", "aCreer")] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);
    await user.click(await screen.findByRole("button", { name: T.revalidate as unknown as string }));

    // La revalidation passe une ENVELOPPE { payload, decisions } (jamais le texte brut).
    const envelopeCall = q.analyzeMutate.mock.calls.find((c) => "object" === typeof c[0] && null !== c[0]);
    expect(envelopeCall, "revalider doit reposter un objet { payload, decisions }").toBeDefined();
    const body = envelopeCall?.[0] as { payload: unknown; decisions: unknown };
    expect(body).toHaveProperty("payload");
    expect(Array.isArray(body.decisions)).toBe(true);
  });

  // Visite une étape de CONTENU du rail (déverrouillée après l'analyse) — bouton nommé par son titre.
  const goToStep = async (user: ReturnType<typeof userEvent.setup>, section: "equipes" | "gymnases" | "reservations"): Promise<void> => {
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
    await goToStep(user, "reservations");

    // La case du créneau est un BOUTON de la grille (nom accessible = le libellé) ; clic → éditeur.
    await user.click(await screen.findByRole("button", { name: /jour 2 18:00/ }));
    // Amorce depuis payload.cases : l'heure de début est déjà 18:00 (jamais re-parsée du texte brut).
    expect(screen.getByDisplayValue("18:00")).toBeInTheDocument();

    // Corriger le gymnase (sélecteur de case → cibleId du créneau, D12) et l'heure.
    await user.click(screen.getByRole("button", { name: (n: string) => n.startsWith(T.editVenue(libelle)) }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: "Gymnase Léon-Blum" }));
    fireEvent.change(screen.getByLabelText(T.editStart(libelle)), { target: { value: "19:00" } });
    await user.click(screen.getByRole("button", { name: T.save as unknown as string }));

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

  it("3b/D8/P4-363 — une mutualisation s'édite dans l'éditeur de case : gymnase (→ venueId) + nom de groupe (→ corrections.groupe), jamais via une case à cocher", async () => {
    const user = userEvent.setup();
    const venues = [{ id: "v1", name: "Gymnase Léon-Blum" }, { id: "v2", name: "Salle Mont Blanc" }] as const;
    const libelle = "U11M1 + U11M2";
    const payload = {
      format: "parc-v2",
      gymnases: [{ nom: "Gymnase Léon-Blum" }],
      cases: [{ gymnase: "Gymnase Léon-Blum", jour: 3, debut: "17:00", duree: 90, equipes: ["U11M1", "U11M2"], groupe: "CEC" }],
    };
    const analysis = analysisOf({ mutualisations: [mkLine(0, libelle, "aCreer", { groupe: "CEC", gymnaseId: null })] }, { payload });
    renderWithProviders(<ParcImportModal {...defaultProps} venues={venues} />);

    await analyzeThen(user, analysis);
    await goToStep(user, "reservations");

    // La mutualisation est une case de la grille (bouton nommé par son libellé) → clic → éditeur.
    await user.click(await screen.findByRole("button", { name: /U11M1 \+ U11M2/ }));
    // Dans l'éditeur : le sélecteur de gymnase de la réservation de groupe (P4-363) → venueId ≠ cibleId.
    await user.click(screen.getByRole("button", { name: venueForGroupTrigger(libelle) }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: "Salle Mont Blanc" }));
    // Et le nom de groupe (D8) → corrections.groupe.
    fireEvent.change(screen.getByRole("textbox", { name: (n: string) => n.startsWith(T.groupLabel(libelle)) }), { target: { value: "Section Loisirs" } });
    await user.click(screen.getByRole("button", { name: T.save as unknown as string }));

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
    // ceux de cases[0] (lundi 18:00 / 1h30).
    await user.click(await screen.findByRole("button", { name: libelle }));
    expect(screen.getByDisplayValue("20:30")).toBeInTheDocument();
    expect(screen.getByLabelText(T.editDay(libelle))).toHaveValue("4");
    expect(within(screen.getByRole("combobox", { name: "Durée" })).getByRole("option", { name: formatDuration(60) })).toBeInTheDocument();
  });

  // ─────────── Écart au plan 3a — signal de création EXPLICITE sur une ligne ambiguë (ROUGE) ───────────

  it("3a — « Créer « … » » sur une ligne ambiguë envoie le signal de création explicite creer:true", async () => {
    const user = userEvent.setup();
    const analysis = analysisOf({ equipes: [mkAmbiguous(0, "Armond", [{ id: "t1", nom: "Armand" }, { id: "t2", nom: "Armund" }])] });
    renderWithProviders(<ParcImportModal {...defaultProps} />);

    await analyzeThen(user, analysis);

    await user.click(await screen.findByRole("button", { name: decisionTrigger("Armond") }));
    await user.click(within(screen.getByRole("listbox")).getByRole("option", { name: T.createOption("Armond") }));
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
    await goToStep(user, "reservations");
    await user.click(await screen.findByRole("button", { name: /jour 2 18:00/ }));

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
