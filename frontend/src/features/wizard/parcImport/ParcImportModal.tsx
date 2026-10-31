import { AlertTriangle, CheckCircle2, Info, Link2 } from "lucide-react";
import { type ReactNode, type RefObject, useCallback, useEffect, useId, useMemo, useRef, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { CtcMark } from "@/shared/components/ui/ctc-mark";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { FIELD_CLASS } from "@/shared/components/ui/field";
import { Input } from "@/shared/components/ui/input";
import { Listbox, type ListboxGroup, type ListboxOption } from "@/shared/components/ui/listbox";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { SegmentedControl } from "@/shared/components/ui/segmented-control";
import { Select } from "@/shared/components/ui/select";
import { Spinner } from "@/shared/components/ui/spinner";
import { StepRail, type StepRailState, type StepRailStep } from "@/shared/components/ui/step-rail";
import { DAYS, dayLabelLongCap } from "@/shared/lib/days";
import { groupTeamsByTier, type TeamLike, type TierLike, tierGroupLabel } from "@/shared/lib/teamTiers";
import { formatMinutes } from "@/shared/lib/time";
import { cn } from "@/shared/lib/utils";

import { usePriorityTiers, useWizardTeams } from "@/features/wizard/queries";

import { DurationSelect, SlotEndField } from "../steps/slotFields";
import { ParcCaseGrid, type ParcCaseGridCase, type ParcCaseGridVenue } from "./ParcCaseGrid";
import { buildParcPrompt } from "./promptTemplate";
import {
  useAnalyzeParc,
  useImportParc,
  type ParcAnalysis,
  type ParcAnalysisLine,
  type ParcImportDecision,
  type ParcImportResult,
} from "./queries";
import { PARC_IMPORT_TEXTS as T } from "./texts";

/** Une entité DÉJÀ saisie du club — son nom nourrit le prompt, son id le ré-appariement (D5). */
export interface ParcEntity {
  id: string;
  name: string;
}

interface ParcImportModalProps {
  /** D'où la modale est ouverte (Équipes ou Gymnases) — le même composant, deux portes d'entrée. */
  origin: "teams" | "venues";
  clubId: string;
  /** Saison de travail — résolue côté serveur via l'en-tête `X-Season-Id`, ici pour la cohérence du contrat. */
  seasonId: string;
  teams: readonly ParcEntity[];
  venues: readonly ParcEntity[];
  /** Contexte du club de la session (nom, ville) — embarqué dans le prompt, utile en terrain vierge. */
  club?: { name?: string; city?: string; sport?: string };
  onClose: () => void;
}

// Retours fondateur 2026-10-26 — « plus on découpe, plus c'est simple » : les mutualisations ont leur
// PROPRE étape, et les Réservations passent EN FIN. Ordre : Prompt · Coller · Équipes · Gymnases ·
// Mutualisations · Réservations. Les étapes de CONTENU sont verrouillées avant l'analyse.
// Refonte PR2 (D9) — une étape « Ententes (CTC) » vient juste APRÈS Équipes (dépend de la règle de
// suggestion de PR2 : équipes « Créer » cochables en entente).
type StepId = "prompt" | "paste" | "equipes" | "ententes" | "gymnases" | "mutualisations" | "reservations";
const STEP_ORDER: StepId[] = ["prompt", "paste", "equipes", "ententes", "gymnases", "mutualisations", "reservations"];
const CONTENT_STEPS = new Set<StepId>(["equipes", "ententes", "gymnases", "mutualisations", "reservations"]);

/** Les sections d'ENTITÉS : une ligne y porte le sélecteur de décision (apparier / créer / ignorer). */
const REMATCH_SECTIONS = new Set(["equipes", "gymnases"]);

/** Les sections de CASES : rendues en GRILLE par gymnase (plus de case à cocher), clic → éditeur. */
const CASE_SECTIONS = ["creneaux", "reservations", "mutualisations"] as const;
const CASE_SECTION_SET = new Set<string>(CASE_SECTIONS);

/** Sentinelles de décision du sélecteur de ligne (valeurs du `Listbox`, jamais un id réel). */
const CREATE = "__create__";
const IGNORE = "__ignore__";
/**
 * Finitions — valeur du sélecteur de gymnase d'une case quand le gymnase est ENCORE À CRÉER (option
 * « <nom> (à créer) ») : elle affiche le nom lu, mais ne porte AUCUN id. L'import la résout comme un
 * `venueId`/`cibleId` VIDE (l'executor rattache alors par le NOM de la case — aucun contrat serveur
 * nouveau), exactement comme l'absence de choix.
 */
const VENUE_TO_CREATE = "__venue_to_create__";
const resolveVenueId = (chosen: string | undefined): string | undefined =>
  undefined !== chosen && "" !== chosen && VENUE_TO_CREATE !== chosen ? chosen : undefined;

/** Id STABLE du déclencheur du sélecteur de décision d'une ligne (pour le focus « Voir », finition 4). */
const decisionTriggerId = (section: string, index: number): string => `parc-decision-${section}-${index}`;

interface SlotDraft {
  jour: number;
  debut: string;
  duree: number;
}

interface ParsedCase {
  gymnase?: unknown;
  jour?: unknown;
  debut?: unknown;
  duree?: unknown;
}
interface ParsedParc {
  cases?: ParsedCase[];
}

/** La case en cours d'édition (section + rang de ligne), résolue depuis la grille. */
interface EditingCase {
  section: string;
  index: number;
}

const keyOf = (section: string, index: number): string => `${section}:${index}`;

/** Séances hebdomadaires : défaut (quand le fichier n'en donne pas) et bornes FRONT 1..7 (P4 PeriodTeams). */
const DEFAULT_SESSIONS = 2;
const MIN_SESSIONS = 1;
const MAX_SESSIONS = 7;
const clampSessions = (n: number): number => (Number.isFinite(n) ? Math.min(MAX_SESSIONS, Math.max(MIN_SESSIONS, Math.round(n))) : DEFAULT_SESSIONS);

/**
 * E6 (essai fondateur 30/10) — gabarit de COLONNES partagé par l'en-tête et chaque ligne de l'étape
 * Équipes (Nom · Séances · Décision · Rang · Genre), pour qu'ils s'alignent. Desktop seulement
 * (`md:`) : à l'étroit, la ligne retombe en pile (desktop-first, garde-fous 360 px).
 */
const EQUIPES_GRID_COLS = "md:grid-cols-[minmax(0,1fr)_5rem_minmax(0,16rem)_7rem_8rem]";

/**
 * Lot correctif import IA « G2 » — la modale d'import du parc par IA est un ASSISTANT en 7 étapes
 * (StepRail : Prompt · Coller · Équipes · Ententes (CTC) · Gymnases · Mutualisations · Réservations ;
 * l'étape « Ententes » est ajoutée par la refonte PR2, D9). Le gestionnaire
 * (1) copie le prompt, (2) colle la réponse de l'assistant et l'analyse, puis tranche l'aperçu par
 * étape : (3) ses équipes, (4) ses gymnases et leurs créneaux (grille par gymnase), (5) ses
 * mutualisations, (6) ses réservations (grille par gymnase). Les étapes 3-6 restent VERROUILLÉES tant
 * que l'analyse n'a pas réussi ; une analyse réussie ouvre et passe à « Équipes ».
 *
 * Le front n'invente AUCUNE règle : verdicts, raisons et libellés viennent du serveur, affichés
 * verbatim. Une ligne équipe/gymnase AMBIGUË (≥ 2 candidats) porte un sélecteur `Listbox` SANS
 * présélection (« À trancher… ») et bloque « Importer » tant qu'elle n'est pas tranchée (apparier à
 * un candidat, CRÉER explicitement — `creer:true` — ou ignorer). Le groupe « Toutes les équipes du
 * club » du sélecteur d'équipe est rangé PAR RANG (`groupTeamsByTier`, équipes + rangs lus via les
 * queries du wizard). Réservations ET mutualisations vivent dans la GRILLE (plus de case à cocher) ;
 * une mise à jour de séances proposée sur une équipe APPARIÉE s'accepte par une case dédiée.
 * L'erreur d'import est surfacée DANS la modale (NoticeBanner `role="alert"`), pas en toast — le
 * toast reste la conduite de l'ANALYSE (hook).
 */
export function ParcImportModal(props: ParcImportModalProps) {
  const { clubId, teams, venues, club, onClose } = props;
  const analyze = useAnalyzeParc(clubId);
  const importMut = useImportParc(clubId);
  // 3d — équipes COMPLÈTES + rangs du club, lus DANS la modale (sans changer ses props d'ouverture) :
  // le groupe « Toutes les équipes du club » du sélecteur d'équipe est rangé par rang.
  const wizardTeams = useWizardTeams().data;
  const allTeams = useMemo(() => (wizardTeams ?? []) as TeamLike[], [wizardTeams]);
  const tiers = (usePriorityTiers().data ?? []) as TierLike[];

  const prompt = useMemo(
    () => buildParcPrompt({ teams: teams.map((t) => t.name), venues: venues.map((v) => v.name), club }),
    [teams, venues, club],
  );

  const [step, setCurrentStep] = useState<StepId>("prompt");
  // Les étapes déjà ouvertes depuis la dernière analyse d'un parc collé. « Revérifier » les conserve.
  const [visitedSteps, setVisitedSteps] = useState<ReadonlySet<StepId>>(new Set());
  const setStep = (id: StepId): void => {
    setCurrentStep(id);
    setVisitedSteps((prev) => (prev.has(id) ? prev : new Set(prev).add(id)));
  };
  const [copied, setCopied] = useState(false);
  const [rawText, setRawText] = useState("");
  const [analysis, setAnalysis] = useState<ParcAnalysis | null>(null);
  const [parc, setParc] = useState<unknown>(null);
  // Décision par ligne équipe/gymnase (valeur du `Listbox`) : id (apparier) · CREATE · IGNORE · ""
  // (ambigu non tranché). Indexée par `section:index`, amorcée depuis le verdict serveur.
  const [decisions, setDecisions] = useState<Record<string, string>>({});
  // Lignes gardées des sections de CASES (créneaux / réservations / mutualisations), par `section:index`.
  const [checked, setChecked] = useState<Record<string, boolean>>({});
  // Garder/Ignorer tranchés à la main : une revérification ne les défait pas, alors que le serveur peut
  // décocher (ou recocher) de lui-même une ligne dont l'alerte naît (ou se lève).
  // Ref : lue au retour de la vérification, elle voit aussi un geste fait pendant le vol.
  const keepByHand = useRef<Record<string, boolean>>({});
  /** L'amorce des noms de groupe de la dernière analyse : un brouillon qui lui est égal n'a pas été saisi. */
  const groupSeeds = useRef<Record<string, string>>({});
  // Brouillons d'édition d'une case, par `section:index`.
  const [slotDrafts, setSlotDrafts] = useState<Record<string, SlotDraft>>({});
  const [caseVenue, setCaseVenue] = useState<Record<string, string>>({});
  const [groupDrafts, setGroupDrafts] = useState<Record<string, string>>({});
  // D3 (incident ALAP) — composition d'équipes REDÉFINIE d'une séance commune, par `mutualisations:index`.
  // Absente = non touchée (le serveur garde la composition lue) ; définie = `corrections.equipes` envoyé.
  const [teamCompositionDrafts, setTeamCompositionDrafts] = useState<Record<string, string[]>>({});
  // Retours fondateur 2026-10-26 — le nombre de séances d'une ligne équipe, en CHAMP éditable
  // (pré-rempli de la valeur LUE), par `equipes:index`. Remplace l'ancienne case « mise à jour ».
  const [sessionDrafts, setSessionDrafts] = useState<Record<string, number>>({});
  // Refonte PR2 (D9) — rang / genre / entente d'une ligne équipe « Créer », par `equipes:index`,
  // pré-remplis des suggestions de la règle FBI unique. Le genre "" est le « À choisir » obligatoire.
  const [tierDrafts, setTierDrafts] = useState<Record<string, number>>({});
  const [genderDrafts, setGenderDrafts] = useState<Record<string, string>>({});
  const [ctcDrafts, setCtcDrafts] = useState<Record<string, boolean>>({});
  const [ctcPartnerDrafts, setCtcPartnerDrafts] = useState<Record<string, string>>({});
  const [editingCase, setEditingCase] = useState<EditingCase | null>(null);
  // Gestes qui changent le verdict du serveur depuis la dernière vérification (clés `section:index`) :
  // tant qu'il en reste, « Revérifier » est proposé et « Importer » attend. Un geste défait à la main y reste.
  const [dirtyCases, setDirtyCases] = useState<Set<string>>(new Set());
  // Les gestes faits pendant une vérification en vol (null hors vol) : elle ne les a pas vus, ils restent à
  // vérifier à son retour, même sur une ligne déjà périmée au lancement.
  const gesturesInFlight = useRef<Set<string> | null>(null);
  // Une REVÉRIFICATION a échoué et aucun geste n'a suivi. Pas `analyze.isError` : ce drapeau vaut aussi pour
  // une nouvelle analyse refusée à l'étape Coller, qui ne dit rien de la vérification.
  const [verifyFailed, setVerifyFailed] = useState(false);
  const [result, setResult] = useState<ParcImportResult | null>(null);

  const headingRef = useRef<HTMLHeadingElement>(null);
  // Finition 4 — « Voir » vise une ligne à trancher : on mémorise SON sélecteur (ref, pas d'état — pas
  // de setState dans l'effet) pour le focaliser à la place de l'en-tête une fois la ligne rendue.
  const pendingFocusRef = useRef<{ section: string; index: number } | null>(null);
  // D5 (incident ALAP) — le bandeau d'erreur d'import est en TÊTE de la zone défilante ; DÈS QU'IL
  // APPARAÎT on l'AMÈNE à l'écran (cas Safari du club : jamais ramené), SANS lui voler le focus.
  // Callback ref : il se déclenche au MONTAGE du nœud (= « à l'arrivée » de l'erreur), déjà posé dans
  // le DOM — on défile donc directement (pas de `requestAnimationFrame` : le nœud est committé, il n'y
  // a rien à attendre, et un rAF repousserait le défilement hors du cycle, invisible à l'utilisateur
  // comme au test). Pas de `focus` : l'erreur s'annonce, elle ne vole pas l'attention.
  const scrollErrorIntoView = useCallback((node: HTMLDivElement | null): void => {
    node?.scrollIntoView?.({ block: "nearest", behavior: "smooth" });
  }, []);

  const analyzing = analyze.isPending;
  const importing = importMut.isPending;
  const isRecap = null !== result;
  // Le bandeau d'erreur d'import n'existe QUE sur une étape de contenu : D5 défile vers lui dès qu'il
  // est RENDU (pas seulement au flanc montant de `isError`, qui peut déjà être vrai au montage).
  const showImportError = CONTENT_STEPS.has(step) && importMut.isError;

  // Focus au changement d'étape : le sélecteur de la ligne visée par « Voir » (finition 4) s'il y en a
  // un en attente, sinon l'en-tête d'étape (jamais de vol ailleurs). Le `ref` se désarme ici (pas de
  // setState dans un effet).
  useEffect(() => {
    const pending = pendingFocusRef.current;
    if (null !== pending) {
      pendingFocusRef.current = null;
      document.getElementById(decisionTriggerId(pending.section, pending.index))?.focus();
      return;
    }
    headingRef.current?.focus();
  }, [step, isRecap]);

  const copyPrompt = (): void => {
    void navigator.clipboard.writeText(prompt).then(() => setCopied(true));
  };

  const parsedCasesOf = (payload: unknown): ParsedCase[] => {
    const parsed: ParsedParc | null = null !== payload && "object" === typeof payload ? (payload as ParsedParc) : null;

    return null !== parsed && Array.isArray(parsed.cases) ? parsed.cases : [];
  };

  /**
   * `replay` = une revérification : le serveur rejoue les décisions mais rend ses verdicts de LECTURE,
   * donc tout ce que le gestionnaire a tranché survit. Seule une NOUVELLE analyse repart de la lecture.
   */
  const applyAnalysis = (data: ParcAnalysis, replay: boolean): void => {
    setAnalysis(data);
    setResult(null);
    setDirtyCases(new Set(replay ? gesturesInFlight.current : []));
    gesturesInFlight.current = null;
    setVerifyFailed(false);

    const cases = parsedCasesOf(data.payload);
    setParc(data.payload ?? null);

    const nextDecisions: Record<string, string> = {};
    const nextChecked: Record<string, boolean> = {};
    const nextSlotDrafts: Record<string, SlotDraft> = {};
    const nextCaseVenue: Record<string, string> = {};
    const nextGroupDrafts: Record<string, string> = {};
    const nextSessionDrafts: Record<string, number> = {};
    const nextTier: Record<string, number> = {};
    const nextGender: Record<string, string> = {};
    const nextCtc: Record<string, boolean> = {};
    const nextCtcPartner: Record<string, string> = {};
    for (const section of Object.keys(data.sections)) {
      for (const line of data.sections[section] ?? []) {
        const key = keyOf(section, line.index);
        if (REMATCH_SECTIONS.has(section)) {
          nextDecisions[key] = initialDecision(line);
        } else {
          nextChecked[key] = line.coche;
        }
        // Champ « Séances » d'une équipe : pré-rempli de la valeur LUE (ce qui SERA importé), défaut 2.
        if ("equipes" === section) {
          nextSessionDrafts[key] = clampSessions(line.seances ?? DEFAULT_SESSIONS);
          // Refonte PR2 — rang / genre / entente pré-remplis des suggestions (lignes « à créer »).
          if (undefined !== line.suggestedTierId) {
            nextTier[key] = line.suggestedTierId;
            nextGender[key] = line.suggestedGender ?? "";
            nextCtc[key] = line.suggestedCtc ?? false;
          }
        }
        if (CASE_SECTION_SET.has(section)) {
          // Amorce du brouillon de case. Les créneaux restent indexés sur la case du parc normalisé
          // (`payload.cases[index]`, relu par `ParcPayloadValidator::parse()`, G2 D4). Réservations et
          // mutualisations s'amorcent EXCLUSIVEMENT d'après leur `position` SERVEUR (le créneau de leur
          // case dérivée) — leur index est le rang dans la liste dérivée, pas celui de la case.
          const pos = "creneaux" === section ? undefined : line.position;
          const slot = cases[line.index];
          nextSlotDrafts[key] = pos
            ? { jour: pos.jour, debut: pos.debut, duree: pos.duree }
            : {
                jour: "number" === typeof slot?.jour ? slot.jour : 1,
                debut: "string" === typeof slot?.debut ? slot.debut : "",
                duree: "number" === typeof slot?.duree ? slot.duree : 0,
              };
          // Gymnase pré-rempli de la case (finitions 2a/3a) : l'id résolu par le serveur s'il existe,
          // sinon la sentinelle « à créer » (affiche le nom lu, reste un venueId VIDE à l'import).
          const slotGym = "string" === typeof slot?.gymnase ? slot.gymnase : "";
          const posGym = pos ? pos.gymnase : "";
          if ("creneaux" === section) {
            nextCaseVenue[key] = line.cibleId ?? ("" !== slotGym ? VENUE_TO_CREATE : "");
          } else if ("mutualisations" === section) {
            nextCaseVenue[key] = line.gymnaseId ?? ("" !== posGym ? VENUE_TO_CREATE : "");
            nextGroupDrafts[key] = groupSeed(line);
          } else {
            // Réservations : même amorce « gymnase de la case » que les autres sections de cases.
            nextCaseVenue[key] = line.gymnaseId ?? ("" !== posGym ? VENUE_TO_CREATE : "");
          }
        }
      }
    }
    const seededUnlessReplay = <V,>(seed: Record<string, V>) => (current: Record<string, V>): Record<string, V> => (replay ? { ...seed, ...current } : seed);
    setDecisions(seededUnlessReplay(nextDecisions));
    setChecked(replay ? { ...nextChecked, ...keepByHand.current } : nextChecked);
    setSlotDrafts(seededUnlessReplay(nextSlotDrafts));
    setCaseVenue(seededUnlessReplay(nextCaseVenue));
    // Un nom de groupe vidé revient à l'amorce : c'est déjà ce que l'import enverrait. Seul un nom SAISI
    // survit à la revérification : une ancienne amorce conservée se changerait en correction si la
    // nouvelle analyse en sert une autre (ligne devenue appariée à un groupe existant).
    const previousGroupSeeds = groupSeeds.current;
    groupSeeds.current = nextGroupDrafts;
    setGroupDrafts((current) => {
      if (!replay) {
        return nextGroupDrafts;
      }
      const typed = Object.entries(current).filter(([key, name]) => "" !== name.trim() && name.trim() !== (previousGroupSeeds[key] ?? "").trim());
      return { ...nextGroupDrafts, ...Object.fromEntries(typed) };
    });
    setSessionDrafts(seededUnlessReplay(nextSessionDrafts));
    setTierDrafts(seededUnlessReplay(nextTier));
    setGenderDrafts(seededUnlessReplay(nextGender));
    setCtcDrafts(seededUnlessReplay(nextCtc));
    setCtcPartnerDrafts(seededUnlessReplay(nextCtcPartner));
    if (!replay) {
      keepByHand.current = {};
      setTeamCompositionDrafts({});
    }
  };

  const doAnalyze = (): void => {
    analyze.mutate(rawText, {
      onSuccess: (data) => {
        applyAnalysis(data, false);
        setVisitedSteps(new Set());
        setStep("equipes");
      },
    });
  };

  const buildDecisions = (): ParcImportDecision[] => {
    if (null === analysis) {
      return [];
    }
    const out: ParcImportDecision[] = [];
    for (const section of Object.keys(analysis.sections)) {
      for (const line of analysis.sections[section] ?? []) {
        const key = keyOf(section, line.index);
        if (REMATCH_SECTIONS.has(section)) {
          const value = decisionValue(section, line);
          // Champ « Séances » (équipes seulement) : la valeur du champ, bornée 1..7.
          const seances = "equipes" === section ? clampSessions(sessionDrafts[key] ?? line.seances ?? DEFAULT_SESSIONS) : null;
          if (CREATE === value) {
            // « Créer » explicite (3a) : coché, sans cible, signal `creer:true` (lève le refus d'ambiguïté).
            // La CRÉATION utilise le nombre de séances du champ (ce que l'IA crée, corrigé) et, pour une
            // ligne PR2 (le serveur a servi des suggestions `suggestedTierId`), le rang / genre / entente
            // édités — `corrections.{tierId, genre, ctc, ctcPartner}` (le genre obligatoire est posé ici).
            const corrections: Record<string, unknown> = {};
            if (null !== seances) {
              corrections.seances = seances;
            }
            if ("equipes" === section && undefined !== line.suggestedTierId) {
              corrections.tierId = tierDrafts[key] ?? line.suggestedTierId;
              const g = genderDrafts[key] ?? line.suggestedGender ?? "";
              if ("" !== g) {
                corrections.genre = g;
              }
              const ctc = ctcDrafts[key] ?? line.suggestedCtc ?? false;
              corrections.ctc = ctc;
              const partner = (ctcPartnerDrafts[key] ?? "").trim();
              if (ctc && "" !== partner) {
                corrections.ctcPartner = partner;
              }
            }
            out.push({ section, index: line.index, coche: true, creer: true, corrections: Object.keys(corrections).length > 0 ? corrections : undefined });
          } else if (IGNORE === value || "" === value) {
            out.push({ section, index: line.index, coche: false });
          } else {
            // Appariement à un candidat : le champ « Séances » ne part en corrections QUE s'il DIFFÈRE
            // des séances actuelles de l'équipe visée (valeur = actuelle → rien à corriger).
            const decision: ParcImportDecision = { section, index: line.index, coche: true, cibleId: value };
            if (null !== seances && undefined !== line.seancesActuelles && seances !== line.seancesActuelles) {
              decision.corrections = { seances };
            }
            out.push(decision);
          }
        } else if ("mutualisations" === section) {
          const groupe = (groupDrafts[key] ?? "").trim();
          // D3 — la composition REDÉFINIE (si l'utilisateur a touché le sélecteur multiple) part en
          // `corrections.equipes` (liste d'ids d'équipe du club / `import:<index>`), sinon le serveur
          // garde la composition lue.
          const composition = teamCompositionDrafts[key];
          const corrections: Record<string, unknown> = {};
          // Seul un nom SAISI part : ni le champ vide, ni l'amorce, que le serveur n'a pas à réécrire.
          if ("" !== groupe && groupe !== groupSeed(line).trim()) {
            corrections.groupe = groupe;
          }
          if (undefined !== composition) {
            corrections.equipes = composition;
          }
          out.push({
            section,
            index: line.index,
            coche: checked[key] ?? line.coche,
            venueId: resolveVenueId(caseVenue[key]),
            corrections: Object.keys(corrections).length > 0 ? corrections : undefined,
          });
        } else if (CASE_SECTION_SET.has(section)) {
          const draft = slotDrafts[key];
          out.push({
            section,
            index: line.index,
            coche: checked[key] ?? line.coche,
            cibleId: resolveVenueId(caseVenue[key]),
            corrections: undefined !== draft ? { jour: draft.jour, debut: draft.debut, duree: draft.duree } : undefined,
          });
        } else {
          out.push({ section, index: line.index, coche: checked[key] ?? line.coche });
        }
      }
    }
    return out;
  };

  const decisionValue = (section: string, line: ParcAnalysisLine): string => decisions[keyOf(section, line.index)] ?? initialDecision(line);

  const markStale = (key: string): void => {
    gesturesInFlight.current?.add(key);
    setVerifyFailed(false);
    setDirtyCases((prev) => new Set(prev).add(key));
  };

  const setDecision = (section: string, index: number, value: string): void => {
    const key = keyOf(section, index);
    const previous = decisions[key] ?? "";
    setDecisions((prev) => ({ ...prev, [key]: value }));
    // Le serveur ne regarde d'une décision que sa cochabilité, et seulement à l'étape Équipes : seule
    // une équipe qui entre ou sort de l'import peut faire naître ou lever une alerte (au rejeu).
    if ("equipes" !== section || isKeptDecision(previous) === isKeptDecision(value)) {
      return;
    }
    // Trancher une ambiguë ne périme que sous une alerte « équipe ignorée », née du rejeu où elle partait
    // décochée : sinon Importer resterait bloqué sans bouton. La ligne de réservation ne dit pas son équipe
    // autrement que dans sa phrase : toute alerte de ce code compte, sans chercher celle qui cite la ligne.
    if ("" === previous && !hasIgnoredTeamAlert(analysis)) {
      return;
    }
    markStale(key);
  };

  const setKeep = (key: string, keep: boolean): void => {
    setChecked((prev) => ({ ...prev, [key]: keep }));
    keepByHand.current[key] = keep;
  };

  const setSessionDraft = (index: number, value: number): void =>
    setSessionDrafts((prev) => ({ ...prev, [keyOf("equipes", index)]: value }));

  const setTierDraft = (index: number, value: number): void =>
    setTierDrafts((prev) => ({ ...prev, [keyOf("equipes", index)]: value }));

  const setGenderDraft = (index: number, value: string): void =>
    setGenderDrafts((prev) => ({ ...prev, [keyOf("equipes", index)]: value }));

  // Compte des lignes équipe/gymnase AMBIGUËS non tranchées (valeur "") — elles bloquent l'import.
  const pendingAmbiguous = useMemo(() => {
    if (null === analysis) {
      return 0;
    }
    let n = 0;
    for (const section of ["equipes", "gymnases"]) {
      for (const line of analysis.sections[section] ?? []) {
        if ("" === decisionValue(section, line)) {
          n += 1;
        }
      }
    }
    return n;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [analysis, decisions]);

  // Refonte PR2 — compte des lignes équipe « Créer » (PR2, avec suggestions) dont le genre OBLIGATOIRE
  // n'est pas tranché ("") : elles bloquent l'import, avec la raison comptée.
  const pendingGender = useMemo(() => {
    if (null === analysis) {
      return 0;
    }
    let n = 0;
    for (const line of analysis.sections.equipes ?? []) {
      const key = keyOf("equipes", line.index);
      if (undefined !== line.suggestedTierId && CREATE === decisionValue("equipes", line) && "" === (genderDrafts[key] ?? line.suggestedGender ?? "")) {
        n += 1;
      }
    }
    return n;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [analysis, decisions, genderDrafts]);

  // PR A (D13) — une ligne de CASE (séance commune / réservation) en alerte « équipe ignorée ou
  // introuvable » (`alerteCode`) ET GARDÉE bloque l'import : sinon l'import part et le serveur 422
  // (le défaut ALAP). On lit le code MACHINE servi par l'analyse, jamais la phrase FR.
  const pendingAlerteCode = useMemo(() => {
    if (null === analysis) {
      return 0;
    }
    let n = 0;
    for (const section of ["mutualisations", "reservations"] as const) {
      for (const line of analysis.sections[section] ?? []) {
        if (undefined !== line.alerteCode && "" !== line.alerteCode && (checked[keyOf(section, line.index)] ?? line.coche)) {
          n += 1;
        }
      }
    }
    return n;
  }, [analysis, checked]);

  // D3 — une séance commune dont la composition REDÉFINIE compte moins de 2 équipes bloque l'import
  // (la raison vit sur la ligne, `needsTwoTeams`). Une composition non touchée ne bloque jamais.
  const pendingComposition = useMemo(() => {
    if (null === analysis) {
      return 0;
    }
    let n = 0;
    for (const line of analysis.sections.mutualisations ?? []) {
      const draft = teamCompositionDrafts[keyOf("mutualisations", line.index)];
      if (undefined !== draft && draft.length < 2 && (checked[keyOf("mutualisations", line.index)] ?? line.coche)) {
        n += 1;
      }
    }
    return n;
  }, [analysis, teamCompositionDrafts, checked]);

  const importDecisions = buildDecisions();
  const count = importDecisions.filter((d) => d.coche).length;
  const blockedByLines = pendingAmbiguous > 0 || pendingGender > 0 || pendingAlerteCode > 0 || pendingComposition > 0 || 0 === count;

  const doImport = (): void => {
    if (null === analysis || blocked || dirty || analyzing) {
      return;
    }
    importMut.mutate({ payload: parc, decisions: buildDecisions() }, { onSuccess: setResult });
  };

  const doRevalidate = (): void => {
    if (null === analysis) {
      return;
    }
    gesturesInFlight.current = new Set();
    setVerifyFailed(false);
    analyze.mutate(
      { payload: parc, decisions: buildDecisions() },
      {
        onSuccess: (data) => applyAnalysis(data, true),
        onError: () => {
          gesturesInFlight.current = null;
          setVerifyFailed(true);
        },
      },
    );
  };

  // Finition 4 — « Voir » : aller à l'étape de la 1ʳᵉ ligne à trancher et donner le focus à SON sélecteur.
  const seeBlocking = (): void => {
    if (null === analysis) {
      return;
    }
    for (const section of ["equipes", "gymnases"] as const) {
      for (const line of analysis.sections[section] ?? []) {
        if ("" === decisionValue(section, line)) {
          if (section === step) {
            // Déjà sur la bonne étape : la ligne est rendue, focalisons-la directement.
            document.getElementById(decisionTriggerId(section, line.index))?.focus();
          } else {
            pendingFocusRef.current = { section, index: line.index };
            setStep(section);
          }
          return;
        }
      }
    }
    // PR2 — un genre OBLIGATOIRE manquant sur une ligne « Créer » : l'étape Équipes.
    for (const line of analysis.sections.equipes ?? []) {
      const key = keyOf("equipes", line.index);
      if (undefined !== line.suggestedTierId && CREATE === decisionValue("equipes", line) && "" === (genderDrafts[key] ?? line.suggestedGender ?? "")) {
        setStep("equipes");
        return;
      }
    }
    // D13 — une séance commune / réservation en alerte « équipe ignorée » et GARDÉE : son étape.
    for (const section of ["mutualisations", "reservations"] as const) {
      for (const line of analysis.sections[section] ?? []) {
        if (undefined !== line.alerteCode && "" !== line.alerteCode && (checked[keyOf(section, line.index)] ?? line.coche)) {
          setStep(section);
          return;
        }
      }
    }
    // Plus aucune ligne à trancher : la première étape qui n'est pas encore verte.
    if (undefined !== stepsToClear[0]) {
      setStep(stepsToClear[0]);
    }
  };

  const goTo = (direction: 1 | -1): void => {
    const current = STEP_ORDER.indexOf(step);
    for (let i = current + direction; i >= 0 && i < STEP_ORDER.length; i += direction) {
      if (!isLocked(STEP_ORDER[i])) {
        setStep(STEP_ORDER[i]);
        return;
      }
    }
  };

  const isLocked = (id: StepId): boolean => (CONTENT_STEPS.has(id) ? null === analysis : false) || isRecap;

  const stepStateOf = (id: StepId): StepRailState | undefined => {
    if (isRecap) {
      return "done";
    }
    if (null === analysis || !CONTENT_STEPS.has(id)) {
      return null === analysis ? undefined : "done";
    }
    if (importMut.isError && id === step) {
      return "error";
    }
    if (hasPending(id)) {
      return "warning";
    }
    return visitedSteps.has(id) ? "done" : undefined;
  };

  const hasPending = (id: StepId): boolean => {
    if (null === analysis) {
      return false;
    }
    // Une ligne en « Ignorer » n'attend plus rien, sinon une alerte non corrigeable bloquerait l'import
    // pour de bon. Mais le serveur sert toute alerte décochée : tant que l'étape n'a pas été vue, l'alerte
    // reste signalée au rail. Une ligne d'entité porte sa décision, une ligne de case Garder/Ignorer.
    const unseen = !visitedSteps.has(id);
    const entityAlert = (section: string, l: ParcAnalysisLine): boolean => "alerte" === l.statut && (unseen || IGNORE !== decisionValue(section, l));
    const caseAlert = (section: string, l: ParcAnalysisLine): boolean => "alerte" === l.statut && (unseen || (checked[keyOf(section, l.index)] ?? l.coche));
    // Les CRÉNEAUX vivent à l'étape Gymnases (leurs alertes la marquent) ; les Réservations et les
    // Mutualisations ont désormais chacune leur propre étape (retours fondateur 2026-10-26).
    if ("gymnases" === id) {
      return (analysis.sections.gymnases ?? []).some((l) => entityAlert("gymnases", l) || "" === decisionValue("gymnases", l)) || (analysis.sections.creneaux ?? []).some((l) => caseAlert("creneaux", l));
    }
    if ("mutualisations" === id) {
      return (analysis.sections.mutualisations ?? []).some((l) => caseAlert("mutualisations", l));
    }
    if ("reservations" === id) {
      return (analysis.sections.reservations ?? []).some((l) => caseAlert("reservations", l));
    }
    // L'étape Ententes (CTC) est optionnelle : jamais une décision EN ATTENTE (pas de warning rail).
    if ("ententes" === id) {
      return false;
    }
    // Équipes : alerte, ambigu non tranché, OU un genre OBLIGATOIRE manquant sur une ligne « Créer » (PR2).
    if ("equipes" === id) {
      return (analysis.sections.equipes ?? []).some(
        (l) =>
          entityAlert("equipes", l) ||
          "" === decisionValue("equipes", l) ||
          (undefined !== l.suggestedTierId && CREATE === decisionValue("equipes", l) && "" === (genderDrafts[keyOf("equipes", l.index)] ?? l.suggestedGender ?? "")),
      );
    }
    return (analysis.sections[id] ?? []).some((l) => entityAlert(id, l) || "" === decisionValue(id, l));
  };

  // « Importer » n'est actif que si TOUTES les étapes de contenu sont vertes : visitées, même vides, et
  // sans point en attente.
  const stepsToClear = STEP_ORDER.filter((id) => CONTENT_STEPS.has(id) && (!visitedSteps.has(id) || hasPending(id)));
  const blocked = blockedByLines || stepsToClear.length > 0;
  const dirty = dirtyCases.size > 0;
  const verifyState: VerifyState = analyzing ? "running" : !dirty ? "upToDate" : verifyFailed ? "failed" : "stale";

  const steps: StepRailStep<StepId>[] = STEP_ORDER.map((id) => ({
    id,
    label: "prompt" === id ? T.stepPrompt : "paste" === id ? T.stepPaste : "ententes" === id ? T.stepEntentes : T.sectionTitle(id),
    state: stepStateOf(id),
    locked: isLocked(id),
  }));

  const stepNumber = STEP_ORDER.indexOf(step) + 1;
  const stepTitle = "prompt" === step ? T.promptHeading : "paste" === step ? T.pasteHeading : "ententes" === step ? T.stepEntentes : T.sectionTitle(step);

  // Grilles des CASES, avec un index SYNTHÉTIQUE par case (`ParcCaseGrid` ne connaît qu'un index
  // numérique) remonté à sa (section, rang) par `ref`. Retours fondateur 2026-10-26 — deux grilles
  // distinctes : les CRÉNEAUX (ouvertures) vivent à l'étape Gymnases (iso-wizard), les RÉSERVATIONS
  // et les mutualisations (marquées) à l'étape Réservations.
  const { creneauxGrid, reservationsGrid } = useMemo(() => {
    const build = (sectionsToUse: readonly string[]): { gridCases: ParcCaseGridCase[]; gridVenues: ParcCaseGridVenue[]; ref: Record<number, EditingCase> } => {
      const gridCases: ParcCaseGridCase[] = [];
      const ref: Record<number, EditingCase> = {};
      const venueToCreate = new Map<string, boolean>();
      if (null !== analysis) {
        const cases = parsedCasesOf(parc);
        const gymLines = analysis.sections.gymnases ?? [];
        let gid = 0;
        for (const section of sectionsToUse) {
          for (const line of analysis.sections[section] ?? []) {
            const key = keyOf(section, line.index);
            // Créneaux : la case du parc (index = case). Réservations/mutualisations : la `position`
            // SERVEUR de leur case dérivée — jamais `payload.cases[index]` (index décalé, bug grille).
            const pos = "creneaux" === section ? undefined : line.position;
            const slot = cases[line.index];
            const venueName = pos ? pos.gymnase : "string" === typeof slot?.gymnase ? slot.gymnase : "";
            const draft = slotDrafts[key];
            const g = gid++;
            ref[g] = { section, index: line.index };
            gridCases.push({
              index: g,
              venueName,
              jour: draft?.jour ?? 1,
              debut: draft?.debut ?? "",
              duree: draft?.duree ?? 0,
              libelle: compositionLabel(teamCompositionDrafts[key], line, allTeams, analysis.sections.equipes ?? []),
              alerte: "alerte" === line.statut ? line.raison : null,
              mutual: "mutualisations" === section,
              // Le nom corrigé à l'étape Séances communes, repris tel quel à l'étape Réservations.
              groupe: "mutualisations" === section ? shownGroupName(groupDrafts[key], line) : null,
              // Les CRÉNEAUX (ouvertures) s'affichent par leur plage horaire, pas par « jour N » (finition 1).
              opening: "creneaux" === section,
            });
            if ("" !== venueName && !venueToCreate.has(venueName)) {
              const gymLine = gymLines.find((gl) => gl.libelle === venueName);
              venueToCreate.set(venueName, undefined !== gymLine && "aCreer" === gymLine.statut);
            }
          }
        }
      }
      const gridVenues: ParcCaseGridVenue[] = [...venueToCreate].map(([name, toCreate]) => ({ name, toCreate }));
      return { gridCases, gridVenues, ref };
    };
    return { creneauxGrid: build(["creneaux"]), reservationsGrid: build(["reservations", "mutualisations"]) };
  }, [analysis, parc, slotDrafts, groupDrafts, teamCompositionDrafts, allTeams]);

  // Éditeur de case (Modal imbriquée) — brouillon transactionnel, clé stable `section:index`.
  let caseEditor: ReactNode = null;
  if (null !== editingCase) {
    const { section, index } = editingCase;
    const key = keyOf(section, index);
    const line = analysis?.sections[section]?.find((l) => l.index === index);
    // Pour une MUTUALISATION, le gymnase et le nom de groupe se corrigent EN LIGNE à l'étape
    // Mutualisations (retours fondateur 2026-10-26) ; l'éditeur ne porte que le créneau (jour/heure/
    // durée). Il ne réécrit donc ni le gymnase ni l'inclusion de ces lignes.
    const isMutualCase = "mutualisations" === section;
    // Gymnase de la case pour l'option « <nom> (à créer) » : la `position` serveur (réservation), ou
    // le gymnase de la case du parc (créneau). Finition 3a — le sélecteur est pré-rempli, jamais « Créer » nu.
    const editorVenueToCreate = "creneaux" === section ? (() => {
      const sc = parsedCasesOf(parc)[index];
      return "string" === typeof sc?.gymnase ? sc.gymnase : "";
    })() : (line?.position?.gymnase ?? "");
    caseEditor = (
      <CaseEditor
        key={key}
        section={section}
        libelle={undefined !== line ? compositionLabel(teamCompositionDrafts[key], line, allTeams, analysis?.sections.equipes ?? []) : ""}
        draft={slotDrafts[key]}
        venueId={caseVenue[key] ?? ""}
        venueToCreateName={editorVenueToCreate}
        keep={checked[key] ?? line?.coche ?? true}
        venues={venues}
        onCancel={() => setEditingCase(null)}
        onSave={(next) => {
          const previousDraft = slotDrafts[key];
          const previousKeep = checked[key] ?? line?.coche ?? true;
          const slotChanged = next.draft.jour !== previousDraft?.jour || next.draft.debut !== previousDraft?.debut || next.draft.duree !== previousDraft?.duree;
          const onlyVenueOrKeep = !slotChanged && !isMutualCase && (next.venueId !== (caseVenue[key] ?? "") || next.keep !== previousKeep);
          setSlotDrafts((prev) => ({ ...prev, [key]: next.draft }));
          if (!isMutualCase) {
            setCaseVenue((prev) => ({ ...prev, [key]: next.venueId }));
            if (next.keep !== previousKeep) {
              setKeep(key, next.keep);
            }
          }
          // Le gymnase d'une case et sa coche n'entrent pas dans le verdict du serveur : changés seuls, ils
          // ne périment rien. Tout autre enregistrement de l'éditeur porte sur le créneau et périme.
          if (!onlyVenueOrKeep) {
            markStale(key);
          }
          setEditingCase(null);
        }}
      />
    );
  }

  const hasVenueToCreate = null !== analysis && (analysis.sections.gymnases ?? []).some((l) => "aCreer" === l.statut);

  return (
    <Modal
      label={T.modalTitle}
      title={T.modalTitle}
      size="xl"
      onClose={onClose}
      // D15 (PR B) — l'état COMPLET de l'import déclaré au drapeau « Signaler » : étape, parc collé,
      // dernière réponse d'analyse, décisions construites et dernière erreur serveur (status + errors[]).
      // Évalué AU CLIC (jamais à chaque rendu) ; borné/tronqué côté serveur (plafond 100 000).
      reportState={() => ({
        step,
        parc: rawText,
        analysis,
        decisions: buildDecisions(),
        lastError: importMut.isError
          ? { status: (importMut.error as { response?: { status?: number } } | null)?.response?.status ?? null, errors: extractErrors(importMut.error) }
          : null,
      })}
      footer={
        isRecap ? (
          <Button size="sm" onClick={onClose}>
            {T.close}
          </Button>
        ) : (
          <>
            {/* D10 (incident ALAP) — plus de bouton TEXTE « Fermer » au pied (la croix d'en-tête suffit) :
                Précédent/Suivant GROUPÉS à gauche (`mr-auto`), l'action primaire (Analyser/Importer) à droite. */}
            <div className="mr-auto flex flex-wrap items-center gap-2">
              {"prompt" !== step ? (
                <Button variant="ghost" size="sm" onClick={() => goTo(-1)}>
                  {T.previous}
                </Button>
              ) : null}
              {"prompt" === step || (CONTENT_STEPS.has(step) && "reservations" !== step) ? (
                <Button variant="outline" size="sm" onClick={() => goTo(1)}>
                  {T.next}
                </Button>
              ) : null}
              {/* Finition 4 / D13 — la raison d'import bloqué est VISIBLE au pied (ton warning, patron
                  ImportFbiDialog), avec un bouton « Voir » qui mène à la 1ʳᵉ ligne à trancher / séance commune. */}
              {CONTENT_STEPS.has(step) && (pendingAmbiguous > 0 || pendingGender > 0 || pendingAlerteCode > 0 || 0 === count) ? (
                <span className="flex items-center gap-2 text-xs text-warning">
                  <span>{T.importBlocked(pendingAmbiguous, pendingGender, pendingAlerteCode)}</span>
                  {pendingAmbiguous > 0 || pendingGender > 0 || pendingAlerteCode > 0 ? (
                    <Button variant="ghost" size="sm" onClick={seeBlocking}>
                      {T.seeBlockingLine}
                    </Button>
                  ) : null}
                </span>
              ) : CONTENT_STEPS.has(step) && stepsToClear.length > 0 ? (
                <span className="flex items-center gap-2 text-xs text-warning">
                  <span>{T.importStepsToVisit(stepsToClear.length)}</span>
                  <Button variant="ghost" size="sm" onClick={seeBlocking}>
                    {T.seeBlockingLine}
                  </Button>
                </span>
              ) : null}
            </div>
            {"paste" === step ? (
              <Button
                size="sm"
                disabled={analyzing || "" === rawText.trim()}
                disabledReason={"" === rawText.trim() ? T.analyzeDisabledHint : undefined}
                onClick={doAnalyze}
              >
                {analyzing ? <Spinner className="size-4" /> : null}
                {T.analyze}
              </Button>
            ) : null}
            {CONTENT_STEPS.has(step) ? (
              <>
                <VerifyButton state={verifyState} onVerify={doRevalidate} />
                <Button
                  size="sm"
                  disabled={blocked || importing || dirty || analyzing}
                  disabledReason={analyzing ? T.importVerifyingReason : dirty ? T.importStaleReason : blocked ? T.importDisabledHint : undefined}
                  onClick={doImport}
                >
                  {importing ? <Spinner className="size-4" /> : null}
                  <span className="tabular-nums">{T.importAction(count)}</span>
                </Button>
              </>
            ) : null}
          </>
        )
      }
    >
      <div className="flex flex-col gap-4 md:flex-row md:gap-6">
        <StepRail steps={steps} currentId={step} onSelect={setStep} />
        <div className="min-w-0 flex-1">
          {isRecap ? (
            <RecapView result={result} headingRef={headingRef} />
          ) : (
            <div className="flex flex-col gap-4">
              {/* En-tête d'étape, focalisé au changement d'étape. */}
              <div>
                <h3 ref={headingRef} tabIndex={-1} className="text-sm font-semibold outline-none">
                  {CONTENT_STEPS.has(step) ? T.stepHeading(stepNumber, STEP_ORDER.length, stepTitle) : stepTitle}
                </h3>
              </div>

              {/* Erreur d'import — dans la modale, jamais un toast (le hook garde le toast à l'analyse).
                  Le ref porte le scrollIntoView de D5 (bandeau amené à l'écran, sans voler le focus). */}
              {showImportError ? (
                <div ref={scrollErrorIntoView}>
                  <ImportErrorBanner error={importMut.error} />
                </div>
              ) : null}

              {/* D10 — résultat peu fiable (informatif, jamais bloquant), en tête de l'aperçu. */}
              {CONTENT_STEPS.has(step) && null !== analysis && analysis.fiabilite.bandeau ? (
                <NoticeBanner tone="warning">{T.unreliableResult}</NoticeBanner>
              ) : null}

              {/* D9 — les alertes transverses du serveur, affichées verbatim (points à vérifier). */}
              {CONTENT_STEPS.has(step) && null !== analysis && analysis.alertes.length > 0 ? (
                <NoticeBanner tone="warning">
                  <p className="font-medium">{T.alertsHeading}</p>
                  <ul className="flex list-disc flex-col gap-1 pl-5">
                    {analysis.alertes.map((alerte, i) => (
                      <li key={`${alerte.type}:${i}`}>{alerte.message}</li>
                    ))}
                  </ul>
                </NoticeBanner>
              ) : null}

              {/* Un gymnase « à créer » naîtra sans adresse — à renseigner ensuite (étape Gymnases). */}
              {"gymnases" === step && hasVenueToCreate ? <NoticeBanner tone="accent">{T.venuesCreatedWithoutAddress}</NoticeBanner> : null}

              {/* Vérification périmée après un geste : le bouton « Revérifier » est au pied de la modale. */}
              {CONTENT_STEPS.has(step) && dirty ? (
                <NoticeBanner tone="warning">{T.staleHint(dirtyCases.size)}</NoticeBanner>
              ) : null}

              {"prompt" === step ? <PromptStep prompt={prompt} copied={copied} onCopy={copyPrompt} /> : null}
              {"paste" === step ? <PasteStep rawText={rawText} analyzing={analyzing} onChange={setRawText} /> : null}
              {"equipes" === step ? (
                <EntitySection
                  section="equipes"
                  lines={analysis?.sections.equipes ?? []}
                  venues={venues}
                  allTeams={allTeams}
                  tiers={tiers}
                  decisionValue={decisionValue}
                  onDecision={setDecision}
                  sessionDrafts={sessionDrafts}
                  onSessionChange={setSessionDraft}
                  tierDrafts={tierDrafts}
                  genderDrafts={genderDrafts}
                  onTierChange={setTierDraft}
                  onGenderChange={setGenderDraft}
                />
              ) : null}
              {"ententes" === step && null !== analysis ? (
                <EntentesStep
                  lines={analysis.sections.equipes ?? []}
                  decisionValue={decisionValue}
                  ctcDrafts={ctcDrafts}
                  ctcPartnerDrafts={ctcPartnerDrafts}
                  onCtcChange={(index, v) => setCtcDrafts((prev) => ({ ...prev, [keyOf("equipes", index)]: v }))}
                  onCtcPartnerChange={(index, v) => setCtcPartnerDrafts((prev) => ({ ...prev, [keyOf("equipes", index)]: v }))}
                />
              ) : null}
              {/* Retours fondateur 2026-10-26 — l'étape Gymnases montre, par gymnase, SA décision EN
                  TÊTE puis la grille de ses créneaux (iso-wizard), clic sur un créneau → éditeur. */}
              {"gymnases" === step && null !== analysis ? (
                <GymnasesStep
                  lines={analysis.sections.gymnases ?? []}
                  grid={creneauxGrid}
                  venues={venues}
                  allTeams={allTeams}
                  tiers={tiers}
                  decisionValue={decisionValue}
                  onDecision={setDecision}
                  onEditCase={(g) => setEditingCase(creneauxGrid.ref[g] ?? null)}
                />
              ) : null}
              {/* Retours fondateur 2026-10-26 — les mutualisations ont leur PROPRE étape (le
                  gestionnaire doit VOIR qu'il y en a à vérifier). */}
              {"mutualisations" === step && null !== analysis ? (
                <MutualisationsStep
                  lines={analysis.sections.mutualisations ?? []}
                  equipesLines={analysis.sections.equipes ?? []}
                  venues={venues}
                  allTeams={allTeams}
                  caseVenue={caseVenue}
                  groupDrafts={groupDrafts}
                  checked={checked}
                  compositionDrafts={teamCompositionDrafts}
                  decisionValue={decisionValue}
                  onVenueChange={(index, v) => setCaseVenue((prev) => ({ ...prev, [keyOf("mutualisations", index)]: v }))}
                  onGroupChange={(index, v) => setGroupDrafts((prev) => ({ ...prev, [keyOf("mutualisations", index)]: v }))}
                  onKeepChange={(index, keep) => setKeep(keyOf("mutualisations", index), keep)}
                  onCompositionChange={(index, v) => {
                    setTeamCompositionDrafts((prev) => ({ ...prev, [keyOf("mutualisations", index)]: v }));
                    markStale(keyOf("mutualisations", index));
                  }}
                  onEditSlot={(index) => setEditingCase({ section: "mutualisations", index })}
                />
              ) : null}
              {"reservations" === step && null !== analysis ? (
                reservationsGrid.gridCases.length > 0 ? (
                  <ParcCaseGrid
                    venues={reservationsGrid.gridVenues}
                    cases={reservationsGrid.gridCases}
                    onEditCase={(g) => {
                      const target = reservationsGrid.ref[g];
                      if (undefined === target) {
                        return;
                      }
                      // Une case de MUTUALISATION ne s'édite pas ici : son clic bascule sur l'étape
                      // Mutualisations (retour en arrière assumé), sur sa ligne.
                      if ("mutualisations" === target.section) {
                        setStep("mutualisations");
                      } else {
                        setEditingCase(target);
                      }
                    }}
                  />
                ) : (
                  <p className="text-sm text-muted-foreground">{T.previewHelp}</p>
                )
              ) : null}
            </div>
          )}
        </div>
      </div>

      {caseEditor}
    </Modal>
  );
}

type VerifyState = "upToDate" | "stale" | "running" | "failed";

/**
 * Le bouton de vérification, au pied à côté d'« Importer » : il dit l'état de la vérification et n'agit
 * que si elle est à refaire. L'état s'annonce à part (`role="status"`), le libellé seul ne le dirait pas.
 */
function VerifyButton({ state, onVerify }: { state: VerifyState; onVerify: () => void }) {
  const status = { upToDate: T.verifyStatusUpToDate, stale: T.verifyStatusStale, running: T.verifyStatusRunning, failed: T.verifyStatusFailed }[state];
  const toRedo = "stale" === state || "failed" === state;
  return (
    <>
      <Button
        variant="outline"
        size="sm"
        disabled={!toRedo}
        className={cn("text-foreground", toRedo && "border-warning", "upToDate" === state && "border-success/50 disabled:cursor-default disabled:opacity-100")}
        onClick={onVerify}
      >
        {/* Masqué : le nom du bouton reste son libellé, l'attente s'annonce par le status. */}
        {"running" === state ? (
          <span aria-hidden className="inline-flex">
            <Spinner className="size-4" />
          </span>
        ) : null}
        {"upToDate" === state ? <CheckCircle2 aria-hidden className="text-success" /> : null}
        {toRedo ? <AlertTriangle aria-hidden className="text-warning" /> : null}
        {"running" === state ? T.verifying : "upToDate" === state ? T.verifyUpToDate : T.verifyAction}
      </Button>
      <span role="status" className="sr-only">
        {status}
      </span>
    </>
  );
}

/**
 * Présentation seule, aucune règle : ce que la ligne dit d'elle quand sa composition a été redéfinie
 * (les noms des équipes choisies), sinon son libellé serveur. Le serveur garde la main à l'import.
 */
function compositionLabel(draft: string[] | undefined, line: ParcAnalysisLine, allTeams: TeamLike[], equipesLines: ParcAnalysisLine[]): string {
  if (undefined === draft) {
    return line.libelle;
  }
  const names = draft.map((id) => (id.startsWith("import:") ? equipesLines.find((l) => `import:${l.index}` === id)?.libelle : allTeams.find((t) => t.id === id)?.name));
  const label = names.filter((name) => undefined !== name).join(" + ");
  return "" !== label ? label : line.libelle;
}

/** Une décision qui fait entrer la ligne dans l'import (« Ignorer » et « à trancher » partent décochées). */
function isKeptDecision(value: string): boolean {
  return "" !== value && IGNORE !== value;
}

/** Une alerte « équipe ignorée » est affichée, à lever par une revérification (code machine, jamais la phrase). */
function hasIgnoredTeamAlert(analysis: ParcAnalysis | null): boolean {
  return ["mutualisations", "reservations"].some((section) => (analysis?.sections[section] ?? []).some((l) => "equipe_ignoree" === l.alerteCode));
}

/** L'amorce du champ de groupe : le vrai nom du groupe existant, à défaut le nom lu dans le fichier. */
function groupSeed(line: ParcAnalysisLine): string {
  return line.blocLabel ?? line.groupe ?? "";
}

/**
 * Le nom de groupe à l'écran : le brouillon, ou l'amorce si le champ est vide. Un champ vide ne part pas
 * en correction : le serveur garde le nom du groupe existant, ou prend le nom lu à la création.
 */
function shownGroupName(draft: string | undefined, line: ParcAnalysisLine): string | null {
  const trimmed = (draft ?? "").trim();
  return "" !== trimmed ? trimmed : (line.blocLabel ?? line.groupe ?? null);
}

/** La valeur initiale du sélecteur d'une ligne équipe/gymnase, d'après son verdict serveur. */
function initialDecision(line: ParcAnalysisLine): string {
  if ("ambigu" === line.statut) {
    return "";
  }
  if ("apparie" === line.statut || "suggere" === line.statut) {
    return line.cibleId ?? CREATE;
  }
  if ("aCreer" === line.statut) {
    return CREATE;
  }
  return IGNORE;
}

function PromptStep({ prompt, copied, onCopy }: { prompt: string; copied: boolean; onCopy: () => void }) {
  return (
    <section className="flex flex-col gap-2">
      <p className="text-sm text-muted-foreground">{T.promptHelp}</p>
      <pre className="overflow-auto rounded-md border border-border bg-muted px-3 py-2 text-xs whitespace-pre-wrap text-foreground">{prompt}</pre>
      <div className="flex items-center gap-2">
        <Button size="sm" onClick={onCopy}>
          {T.copyPrompt}
        </Button>
        {copied ? (
          <span role="status" className="text-xs text-muted-foreground">
            {T.promptCopied}
          </span>
        ) : null}
      </div>
    </section>
  );
}

function PasteStep({ rawText, analyzing, onChange }: { rawText: string; analyzing: boolean; onChange: (value: string) => void }) {
  return (
    <section className="flex flex-col gap-2">
      <label className="flex flex-col gap-1">
        <span className="text-sm text-muted-foreground">{T.pasteLabel}</span>
        <textarea
          aria-label={T.pasteLabel}
          placeholder={T.pastePlaceholder}
          className={FIELD_CLASS}
          rows={12}
          value={rawText}
          disabled={analyzing}
          onChange={(e) => onChange(e.target.value)}
        />
      </label>
      <p className="text-xs text-muted-foreground">{T.lockedUntilAnalysis}</p>
      {analyzing ? (
        <span role="status" className="text-xs text-muted-foreground">
          {T.analyzing}
        </span>
      ) : null}
    </section>
  );
}

/** Une étape équipes/gymnases : la liste des lignes, chacune avec son sélecteur de décision. */
function EntitySection({
  section,
  lines,
  venues,
  allTeams,
  tiers,
  decisionValue,
  onDecision,
  sessionDrafts,
  onSessionChange,
  tierDrafts,
  genderDrafts,
  onTierChange,
  onGenderChange,
}: {
  section: "equipes" | "gymnases";
  lines: ParcAnalysisLine[];
  venues: readonly ParcEntity[];
  allTeams: TeamLike[];
  tiers: TierLike[];
  decisionValue: (section: string, line: ParcAnalysisLine) => string;
  onDecision: (section: string, index: number, value: string) => void;
  /** Champ « Séances » éditable (équipes) : brouillon par ligne + sa mise à jour. Absent pour gymnases. */
  sessionDrafts?: Record<string, number>;
  onSessionChange?: (index: number, value: number) => void;
  /** Rang / genre d'une ligne « Créer » (équipes, PR2) : brouillons + setters. Absent pour gymnases. */
  tierDrafts?: Record<string, number>;
  genderDrafts?: Record<string, string>;
  onTierChange?: (index: number, value: number) => void;
  onGenderChange?: (index: number, value: string) => void;
}) {
  if (0 === lines.length) {
    return <p className="text-sm text-muted-foreground">{T.previewHelp}</p>;
  }
  const rows = lines.map((line) => (
    <DecisionRow
      key={line.index}
      section={section}
      line={line}
      venues={venues}
      allTeams={allTeams}
      tiers={tiers}
      value={decisionValue(section, line)}
      onChange={(value) => onDecision(section, line.index, value)}
      sessionValue={sessionDrafts?.[keyOf(section, line.index)] ?? line.seances ?? DEFAULT_SESSIONS}
      onSessionChange={onSessionChange}
      tierValue={tierDrafts?.[keyOf(section, line.index)]}
      genderValue={genderDrafts?.[keyOf(section, line.index)]}
      onTierChange={onTierChange}
      onGenderChange={onGenderChange}
    />
  ));
  // E6 — étape Équipes en COLONNES : un en-tête UNIQUE (Nom · Séances · Décision · Rang · Genre),
  // partagé avec les lignes via `EQUIPES_GRID_COLS`. L'étape Gymnases garde sa liste simple.
  if ("equipes" === section) {
    return (
      <div className="flex flex-col">
        <div className={`hidden gap-x-4 border-b border-border px-0 pb-1 text-xs font-semibold text-muted-foreground md:grid ${EQUIPES_GRID_COLS}`}>
          <span>{T.colName}</span>
          <span>{T.sessionsLabel}</span>
          <span>{T.colDecision}</span>
          <span>{T.tierFieldLabel}</span>
          <span>{T.genderFieldLabel}</span>
        </div>
        <ul className="flex flex-col">{rows}</ul>
      </div>
    );
  }
  return <ul className="flex flex-col">{rows}</ul>;
}

function DecisionRow({
  section,
  line,
  venues,
  allTeams,
  tiers,
  value,
  onChange,
  sessionValue,
  onSessionChange,
  tierValue,
  genderValue,
  onTierChange,
  onGenderChange,
}: {
  section: "equipes" | "gymnases";
  line: ParcAnalysisLine;
  venues: readonly ParcEntity[];
  allTeams: TeamLike[];
  tiers: TierLike[];
  value: string;
  onChange: (value: string) => void;
  sessionValue: number;
  onSessionChange?: (index: number, value: number) => void;
  tierValue?: number;
  genderValue?: string;
  onTierChange?: (index: number, value: number) => void;
  onGenderChange?: (index: number, value: string) => void;
}) {
  const reasonId = useId();
  const sessionsDescId = useId();
  const groups = useMemo(() => decisionGroups(section, line, venues, allTeams, tiers), [section, line, venues, allTeams, tiers]);
  const pending = "ambigu" === line.statut && "" === value;
  // D9 (incident ALAP) — une ligne mise en « Ignorer » est rendue BARRÉE (retours fondateur : grisée/barrée).
  const ignored = IGNORE === value;
  // Refonte PR2 (D9) — une ligne équipe « Créer » PORTANT des suggestions (le serveur a servi
  // `suggestedTierId`) montre un Select Rang + un Select Genre (le genre est OBLIGATOIRE : vide = ⚠).
  const showCreateFields = "equipes" === section && CREATE === value && undefined !== line.suggestedTierId && undefined !== onGenderChange && undefined !== onTierChange;
  const genderVal = genderValue ?? line.suggestedGender ?? "";
  const genderMissing = "" === genderVal;

  // La cellule « Nom » : libellé (barré si ignoré) + la raison en ⓘ (hors « à trancher ») + le ⚠ d'une
  // ligne à trancher. Partagée par les deux dispositions (Équipes en colonnes, Gymnases en 2 colonnes).
  const nameCell = (
    <div className="flex min-w-0 flex-col gap-0.5">
      <span className="flex min-w-0 items-center gap-1.5">
        <span className={ignored ? "truncate text-sm font-medium text-muted-foreground line-through" : "truncate text-sm font-medium text-foreground"}>{line.libelle}</span>
        {/* E6 — la raison d'une ligne NON « à trancher » vit dans une infobulle ⓘ à côté du nom (donnée serveur = nom accessible). */}
        {null === line.raison || pending ? null : (
          <span role="img" aria-label={line.raison} title={line.raison} className="inline-flex shrink-0 text-muted-foreground">
            <Info aria-hidden className="size-3.5" />
          </span>
        )}
      </span>
      {pending ? (
        <span id={reasonId} className="flex items-center gap-1 text-xs text-foreground">
          <AlertTriangle aria-hidden className="size-3.5 text-warning" />
          {line.raison ?? T.toDecide}
        </span>
      ) : null}
    </div>
  );

  const decisionCell = (
    <Listbox
      id={decisionTriggerId(section, line.index)}
      aria-label={T.decisionFor(line.libelle)}
      aria-describedby={pending ? reasonId : undefined}
      wrapperClassName="w-full"
      placeholder={T.toDecide}
      value={value}
      onValueChange={onChange}
      groups={groups}
    />
  );

  // Étape Gymnases : disposition simple à 2 colonnes (Nom | Décision), inchangée (pas de colonnes
  // Séances/Rang/Genre sans objet).
  if ("equipes" !== section) {
    return (
      <li className="grid items-center gap-x-4 gap-y-0.5 border-b border-border/60 py-1 md:grid-cols-[minmax(0,1fr)_20rem]">
        {nameCell}
        {decisionCell}
      </li>
    );
  }

  // E6 — étape Équipes en COLONNES : Nom · Séances · Décision · Rang · Genre, alignées sur l'en-tête.
  // « actuellement N » n'est plus un texte visible : il vit dans l'infobulle du champ Séances
  // (`title` + `aria-describedby`). Plus de libellé « Séances » répété par ligne (l'en-tête le porte).
  return (
    <li className={`grid items-center gap-x-4 gap-y-1 border-b border-border/60 py-1.5 ${EQUIPES_GRID_COLS}`}>
      {nameCell}
      <div className="flex items-center">
        {undefined !== onSessionChange && !ignored ? (
          <>
            <Input
              type="number"
              compact
              min={MIN_SESSIONS}
              max={MAX_SESSIONS}
              aria-label={T.sessionsFieldLabel(line.libelle)}
              aria-describedby={undefined !== line.seancesActuelles ? sessionsDescId : undefined}
              title={undefined !== line.seancesActuelles ? T.sessionsCurrent(line.seancesActuelles) : undefined}
              className="w-16"
              value={sessionValue}
              onChange={(e) => onSessionChange(line.index, Number(e.target.value))}
            />
            {undefined !== line.seancesActuelles ? (
              <span id={sessionsDescId} className="sr-only">
                {T.sessionsCurrent(line.seancesActuelles)}
              </span>
            ) : null}
          </>
        ) : null}
      </div>
      <div className="min-w-0">{decisionCell}</div>
      <div className="flex items-center">
        {showCreateFields ? (
          <Select aria-label={T.tierOf(line.libelle)} wrapperClassName="w-full" value={String(tierValue ?? line.suggestedTierId)} onChange={(e) => onTierChange?.(line.index, Number(e.target.value))}>
            {tiers.map((t) => (
              <option key={t.id} value={String(t.id)}>
                {tierGroupLabel(t)}
              </option>
            ))}
          </Select>
        ) : null}
      </div>
      <div className="flex flex-col gap-0.5">
        {showCreateFields ? (
          <>
            <Select
              aria-label={T.genderOf(line.libelle)}
              wrapperClassName="w-full"
              aria-invalid={genderMissing || undefined}
              value={genderVal}
              onChange={(e) => onGenderChange?.(line.index, e.target.value)}
            >
              <option value="">{T.genderPlaceholder}</option>
              <option value="M">{T.genderMasculin}</option>
              <option value="F">{T.genderFeminin}</option>
              <option value="MIXTE">{T.genderMixte}</option>
            </Select>
            {genderMissing ? (
              <span className="flex items-center gap-1 text-xs text-foreground">
                <AlertTriangle aria-hidden className="size-3.5 text-warning" />
                {T.genderRequired}
              </span>
            ) : null}
          </>
        ) : null}
      </div>
    </li>
  );
}

/**
 * Étape Ententes (CTC) (refonte PR2, D9) — juste après Équipes. Les équipes « à créer » y portent une
 * case Entente (pré-cochée si la règle FBI la suggère) + un champ « club partenaire » → `corrections.ctc`
 * / `corrections.ctcPartner`. Les équipes DÉJÀ au club (appariées/suggérées) s'affichent en LECTURE
 * SEULE : l'import ne touche jamais l'entente de l'existant. Aucune équipe à créer → `EmptyHint`.
 */
function EntentesStep({
  lines,
  decisionValue,
  ctcDrafts,
  ctcPartnerDrafts,
  onCtcChange,
  onCtcPartnerChange,
}: {
  lines: ParcAnalysisLine[];
  decisionValue: (section: string, line: ParcAnalysisLine) => string;
  ctcDrafts: Record<string, boolean>;
  ctcPartnerDrafts: Record<string, string>;
  onCtcChange: (index: number, value: boolean) => void;
  onCtcPartnerChange: (index: number, value: string) => void;
}) {
  const isCreate = (line: ParcAnalysisLine): boolean => CREATE === decisionValue("equipes", line) && undefined !== line.suggestedTierId;
  const createLines = lines.filter(isCreate);
  const otherLines = lines.filter((l) => !isCreate(l));
  if (0 === createLines.length) {
    return <EmptyHint>{T.ententesNoCreations}</EmptyHint>;
  }
  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">{T.ententesHelp}</p>
      <ul className="flex flex-col gap-2">
        {createLines.map((line) => {
          const key = keyOf("equipes", line.index);
          const ctc = ctcDrafts[key] ?? line.suggestedCtc ?? false;
          return (
            <li key={line.index} className="flex flex-col gap-2 rounded-lg border border-border bg-card p-3">
              <label className="flex items-center gap-2 text-sm text-foreground">
                <input
                  type="checkbox"
                  className="size-4 shrink-0"
                  aria-label={T.ententeFor(line.libelle)}
                  checked={ctc}
                  onChange={() => onCtcChange(line.index, !ctc)}
                />
                {ctc ? <CtcMark partner={ctcPartnerDrafts[key]} /> : null}
                <span className="font-medium">{line.libelle}</span>
                <span className="text-muted-foreground">{T.ententeCheckboxLabel}</span>
              </label>
              {ctc ? (
                <Input
                  aria-label={T.ententePartnerFor(line.libelle)}
                  placeholder={T.ententePartnerPlaceholder}
                  className="w-64"
                  maxLength={120}
                  value={ctcPartnerDrafts[key] ?? ""}
                  onChange={(e) => onCtcPartnerChange(line.index, e.target.value)}
                />
              ) : null}
            </li>
          );
        })}
      </ul>
      {otherLines.length > 0 ? (
        <ul className="flex flex-col gap-1">
          {otherLines.map((line) => (
            <li key={line.index} className="flex flex-wrap items-center gap-2 text-sm">
              <span className="font-medium text-foreground">{line.libelle}</span>
              <span className="text-muted-foreground">{T.ententeExistingManaged}</span>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

/**
 * Étape Gymnases (retours fondateur 2026-10-26, iso-wizard) — par gymnase : SA décision (`DecisionRow`)
 * EN TÊTE de son bloc, puis la grille de ses créneaux d'ouverture (`ParcCaseGrid`), clic → éditeur.
 * Les créneaux d'un gymnase sont ceux de la grille dont le `venueName` est le libellé du gymnase.
 */
function GymnasesStep({
  lines,
  grid,
  venues,
  allTeams,
  tiers,
  decisionValue,
  onDecision,
  onEditCase,
}: {
  lines: ParcAnalysisLine[];
  grid: { gridCases: ParcCaseGridCase[]; gridVenues: ParcCaseGridVenue[] };
  venues: readonly ParcEntity[];
  allTeams: TeamLike[];
  tiers: TierLike[];
  decisionValue: (section: string, line: ParcAnalysisLine) => string;
  onDecision: (section: string, index: number, value: string) => void;
  onEditCase: (index: number) => void;
}) {
  // Un bloc par gymnase : les gymnases DÉCIDABLES (lignes serveur) d'abord, dans leur ordre, puis les
  // gymnases qui n'existent que par leurs créneaux (sans ligne de décision — ex. un parc tout en
  // créneaux). Chaque bloc = la décision EN TÊTE (si elle existe) + la grille des créneaux du gymnase.
  const venueNames: string[] = [];
  const add = (name: string): void => {
    if ("" !== name && !venueNames.includes(name)) {
      venueNames.push(name);
    }
  };
  for (const line of lines) {
    add(line.libelle);
  }
  for (const v of grid.gridVenues) {
    add(v.name);
  }

  if (0 === venueNames.length) {
    return <p className="text-sm text-muted-foreground">{T.previewHelp}</p>;
  }

  return (
    <div className="flex flex-col gap-6">
      {venueNames.map((name) => {
        const line = lines.find((l) => l.libelle === name);
        const venueCases = grid.gridCases.filter((c) => c.venueName === name);
        const toCreate = undefined !== line ? "aCreer" === line.statut : (grid.gridVenues.find((v) => v.name === name)?.toCreate ?? false);
        return (
          <section key={name} className="flex flex-col gap-2">
            {undefined !== line ? (
              <ul className="flex flex-col">
                <DecisionRow
                  section="gymnases"
                  line={line}
                  venues={venues}
                  allTeams={allTeams}
                  tiers={tiers}
                  value={decisionValue("gymnases", line)}
                  onChange={(value) => onDecision("gymnases", line.index, value)}
                  sessionValue={DEFAULT_SESSIONS}
                />
              </ul>
            ) : null}
            {venueCases.length > 0 ? <ParcCaseGrid venues={[{ name, toCreate }]} cases={venueCases} onEditCase={onEditCase} /> : null}
          </section>
        );
      })}
    </div>
  );
}

/**
 * Étape Mutualisations (retours fondateur 2026-10-26) — une ligne par mutualisation (picto lien + nom
 * de groupe + équipes + créneau, raison ⚠ une seule fois) : Garder/Ignorer SANS case à cocher
 * (`SegmentedControl`), sélecteur de gymnase de la réservation de groupe (P4-363, → `venueId`), nom de
 * groupe (D8, → `corrections.groupe`) et accès à l'éditeur de créneau (jour/heure/durée). Vide →
 * `EmptyHint`. Le gestionnaire novice doit VOIR qu'il y a des mutualisations à vérifier.
 */
function MutualisationsStep({
  lines,
  equipesLines,
  venues,
  allTeams,
  caseVenue,
  groupDrafts,
  checked,
  compositionDrafts,
  decisionValue,
  onVenueChange,
  onGroupChange,
  onKeepChange,
  onCompositionChange,
  onEditSlot,
}: {
  lines: ParcAnalysisLine[];
  equipesLines: ParcAnalysisLine[];
  venues: readonly ParcEntity[];
  allTeams: TeamLike[];
  caseVenue: Record<string, string>;
  groupDrafts: Record<string, string>;
  checked: Record<string, boolean>;
  compositionDrafts: Record<string, string[]>;
  decisionValue: (section: string, line: ParcAnalysisLine) => string;
  onVenueChange: (index: number, value: string) => void;
  onGroupChange: (index: number, value: string) => void;
  onKeepChange: (index: number, keep: boolean) => void;
  onCompositionChange: (index: number, values: string[]) => void;
  onEditSlot: (index: number) => void;
}) {
  if (0 === lines.length) {
    return <EmptyHint>{T.mutualisationsEmpty}</EmptyHint>;
  }
  // Finition 2c — « à vérifier » réservé aux alertes ; sinon « N mutualisations lues ».
  const hasAlert = lines.some((l) => "alerte" === l.statut);
  // D3 — les équipes offertes au sélecteur de composition : les équipes du club + les lignes Équipes
  // GARDÉES « à créer » de ce run (référencées par `import:<index>`, créées à l'import).
  const teamOptions: ListboxOption[] = [
    ...allTeams.map((t) => ({ value: t.id, label: t.name })),
    ...equipesLines
      .filter((l) => CREATE === decisionValue("equipes", l))
      .map((l) => ({ value: `import:${l.index}`, label: l.libelle })),
  ];
  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground tabular-nums">{hasAlert ? T.mutualisationsCount(lines.length) : T.mutualisationsReadCount(lines.length)}</p>
      <ul className="flex flex-col gap-3">
        {lines.map((line) => {
          const key = keyOf("mutualisations", line.index);
          const keep = checked[key] ?? line.coche;
          const inAlert = "alerte" === line.statut;
          const groupName = shownGroupName(groupDrafts[key], line);
          const label = compositionLabel(compositionDrafts[key], line, allTeams, equipesLines);
          const pos = line.position;
          // Finition 2a — sélecteur de gymnase pré-rempli : l'id résolu, ou l'option « <nom> (à créer) »
          // (valeur `VENUE_TO_CREATE`, affiche le nom lu sans porter d'id) pour un gymnase pas encore existant.
          const posGym = pos?.gymnase ?? "";
          const venueOptions = [
            ...(null == line.gymnaseId && "" !== posGym ? [{ value: VENUE_TO_CREATE, label: T.gridVenueToCreate(posGym), swatch: null }] : []),
            ...venues.map((v) => ({ value: v.id, label: v.name, swatch: null })),
          ];
          // D3 — composition : amorcée des équipes lues, ou le brouillon redéfini. Une équipe sans id est
          // une équipe à créer de ce run : elle s'y retrouve par son `import:<index>` (comme dans `teamOptions`).
          const toCreateRef = (nom: string): string | undefined => {
            const created = equipesLines.find((l) => l.libelle === nom && CREATE === decisionValue("equipes", l));
            return undefined !== created ? `import:${created.index}` : undefined;
          };
          const readComposition = (line.equipes ?? []).map((e) => e.id ?? toCreateRef(e.nom)).filter((id): id is string => undefined !== id);
          const composition = compositionDrafts[key] ?? readComposition;
          const compositionTooFew = undefined !== compositionDrafts[key] && compositionDrafts[key].length < 2;
          return (
            <li key={line.index} className="flex flex-col gap-2 rounded-lg border border-border bg-card p-3">
              <div className="flex flex-wrap items-center gap-2">
                <Link2 aria-hidden className="size-4 shrink-0 text-accent" />
                {/* Un groupe nommé ne montre que son nom : ses équipes se lisent dans le sélecteur.
                    E4 (essai fondateur 30/10) — une séance commune mise en « Ignorer » est barrée + grisée (D9 étendu). */}
                <span className={keep ? "text-sm font-medium text-foreground" : "text-sm font-medium text-muted-foreground line-through"}>{null === groupName || "" === groupName ? label : groupName}</span>
                {/* Finition 2b — l'en-tête de ligne inclut le GYMNASE (« Lundi 17:30 · MONT BLANC »). */}
                {undefined !== pos ? <span className="text-xs text-muted-foreground">{`${dayLabelLongCap(pos.jour)} ${formatMinutes(minutesOfSlot(pos.debut))} · ${pos.gymnase}`}</span> : null}
              </div>
              {/* Défaut C — la raison serveur n'est rendue QU'UNE fois (⚠). */}
              {inAlert && null !== line.raison ? (
                <span className="flex items-center gap-1 text-xs text-foreground">
                  <AlertTriangle aria-hidden className="size-3.5 text-warning" />
                  {line.raison}
                </span>
              ) : null}
              <div className="flex flex-wrap items-end gap-2">
                <SegmentedControl
                  mode="single"
                  label={T.mutualDecision(label)}
                  value={keep ? "keep" : "ignore"}
                  onValueChange={(v) => onKeepChange(line.index, "keep" === v)}
                  options={[
                    { key: "keep", label: T.mutualKeep },
                    { key: "ignore", label: T.mutualIgnore },
                  ]}
                />
                {/* D3 (incident ALAP) — sélecteur MULTIPLE : redéfinir la composition d'équipes (le
                    nom du groupe avait pu être lu comme une équipe). → `corrections.equipes`. */}
                <div className="flex flex-col gap-0.5">
                  <span className="text-xs text-muted-foreground">{T.sectionTitle("equipes")}</span>
                  <Listbox
                    mode="multiple"
                    aria-label={T.teamsForGroup(label)}
                    wrapperClassName="w-56"
                    values={composition}
                    placeholder={(line.equipes ?? []).map((e) => e.nom).join(", ")}
                    onValuesChange={(v) => onCompositionChange(line.index, v)}
                    options={teamOptions}
                  />
                </div>
                <div className="flex flex-col gap-0.5">
                  <span className="text-xs text-muted-foreground">{T.venueForGroupVisible}</span>
                  <Listbox
                    aria-label={T.venueForGroup(label)}
                    wrapperClassName="w-56"
                    value={caseVenue[key] ?? ""}
                    onValueChange={(v) => onVenueChange(line.index, v)}
                    options={venueOptions}
                  />
                </div>
                <Input
                  aria-label={T.groupLabel(label)}
                  placeholder={T.groupNamePlaceholder}
                  className="w-48"
                  value={groupDrafts[key] ?? ""}
                  onChange={(e) => onGroupChange(line.index, e.target.value)}
                />
                <Button variant="outline" size="sm" onClick={() => onEditSlot(line.index)}>
                  {T.editCaseTitle}
                </Button>
              </div>
              {/* D3 — moins de 2 équipes choisies : raison VISIBLE (l'import est bloqué au pied). */}
              {compositionTooFew ? (
                <span className="flex items-center gap-1 text-xs text-warning">
                  <AlertTriangle aria-hidden className="size-3.5" />
                  {T.needsTwoTeams}
                </span>
              ) : null}
            </li>
          );
        })}
      </ul>
    </div>
  );
}

/** Minutes d'un « HH:MM » (foyer d'affichage `formatMinutes`), 0 en repli (gouttière tolérante). */
function minutesOfSlot(hhmm: string): number {
  const m = /^(\d{1,2}):(\d{2})$/.exec(hhmm);
  return null === m ? 0 : Number(m[1]) * 60 + Number(m[2]);
}

/** Les groupes du sélecteur d'une ligne, dans l'ordre : suggestion · candidats · autre choix · tout. */
function decisionGroups(
  section: "equipes" | "gymnases",
  line: ParcAnalysisLine,
  venues: readonly ParcEntity[],
  allTeams: TeamLike[],
  tiers: TierLike[],
): ListboxGroup[] {
  const groups: ListboxGroup[] = [];
  const candidats = line.candidats ?? [];
  const suggested = ("suggere" === line.statut || "apparie" === line.statut) && null !== line.cibleId ? line.cibleId : null;

  if (null !== suggested) {
    const nom = candidats.find((c) => c.id === suggested)?.nom ?? line.libelle;
    groups.push({
      id: "suggestion",
      label: T.suggestionGroup,
      options: [{ value: suggested, label: T.matchToSuggested(nom), sub: line.raison ?? undefined }],
    });
  }

  const others = candidats.filter((c) => c.id !== suggested);
  if (others.length > 0) {
    groups.push({ id: "candidats", label: T.candidatesGroup, options: others.map((c) => ({ value: c.id, label: T.matchTo(c.nom) })) });
  }

  groups.push({
    id: "autre",
    label: T.otherChoiceGroup,
    options: [
      { value: CREATE, label: T.createOption(line.libelle) },
      { value: IGNORE, label: T.ignoreLine },
    ],
  });

  const excluded = (id: string): boolean => candidats.some((c) => c.id === id) || id === suggested;
  if ("equipes" === section) {
    // 3d — « Toutes les équipes du club » rangé PAR RANG (une maison unique `groupTeamsByTier`).
    for (const tierGroup of groupTeamsByTier(allTeams.filter((t) => !excluded(t.id)), tiers)) {
      groups.push({
        id: `tier:${tierGroup.tier?.id ?? "autres"}`,
        label: tierGroupLabel(tierGroup.tier),
        options: tierGroup.teams.map((t) => ({ value: t.id, label: t.name })),
      });
    }
  } else {
    const all = [...venues].filter((e) => !excluded(e.id)).sort((a, b) => a.name.localeCompare(b.name, "fr"));
    if (all.length > 0) {
      groups.push({ id: "all", label: T.allVenuesGroup, options: all.map((e) => ({ value: e.id, label: e.name, swatch: null })) });
    }
  }

  return groups;
}

/**
 * Éditeur de case (Modal imbriquée, brouillon transactionnel) — le CRÉNEAU : jour · heure · durée
 * (`DurationSelect`). Pour un créneau/une réservation, il porte aussi le gymnase (P4-363/D12, →
 * `cibleId`) et Garder/Ignorer. Pour une MUTUALISATION, le gymnase et le nom de groupe se corrigent
 * EN LIGNE à l'étape Mutualisations (retours fondateur 2026-10-26) — l'éditeur n'édite alors que le
 * créneau, sans réécrire le gymnase ni l'inclusion de la ligne.
 */
function CaseEditor({
  section,
  libelle,
  draft,
  venueId,
  venueToCreateName,
  keep,
  venues,
  onCancel,
  onSave,
}: {
  section: string;
  libelle: string;
  draft: SlotDraft | undefined;
  venueId: string;
  /** Nom du gymnase de la case pour l'option « <nom> (à créer) » (pré-remplissage, finition 3a). */
  venueToCreateName: string;
  keep: boolean;
  venues: readonly ParcEntity[];
  onCancel: () => void;
  onSave: (next: { draft: SlotDraft; venueId: string; keep: boolean }) => void;
}) {
  const [local, setLocal] = useState<SlotDraft>(draft ?? { jour: 1, debut: "", duree: 0 });
  const [venue, setVenue] = useState(venueId);
  const [keepLocal, setKeepLocal] = useState(keep);
  const isMutual = "mutualisations" === section;
  // Finition 3a — sélecteur pré-rempli : option « <nom> (à créer) » (pas d'id) + les gymnases existants.
  const venueOptions = [
    ...("" !== venueToCreateName ? [{ value: VENUE_TO_CREATE, label: T.gridVenueToCreate(venueToCreateName), swatch: null }] : []),
    ...venues.map((v) => ({ value: v.id, label: v.name, swatch: null })),
  ];

  return (
    <Modal
      label={T.editCaseTitle}
      title={T.editCaseTitle}
      size="md"
      onClose={onCancel}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onCancel}>
            {T.cancel}
          </Button>
          <Button size="sm" onClick={() => onSave({ draft: local, venueId: venue, keep: keepLocal })}>
            {T.save}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">{libelle}</p>
        {isMutual ? null : (
          <div className="flex flex-col gap-0.5">
            <span className="text-xs text-muted-foreground">{T.editVenueVisible}</span>
            <Listbox aria-label={T.editVenue(libelle)} wrapperClassName="w-full" value={venue} onValueChange={setVenue} options={venueOptions} />
          </div>
        )}
        {/* Même disposition que l'éditeur de créneau du wizard (`VenuesStep`) : Jour · Début · Durée · Fin calculée. */}
        <div className="flex flex-wrap items-end gap-3">
          <label className="text-xs text-muted-foreground">
            {T.editDayLabel}
            <Select aria-label={T.editDay(libelle)} className="mt-0.5 h-9" wrapperClassName="w-28" value={local.jour} onChange={(e) => setLocal((p) => ({ ...p, jour: Number(e.target.value) }))}>
              {DAYS.map((d) => (
                <option key={d.n} value={d.n}>
                  {dayLabelLongCap(d.n)}
                </option>
              ))}
            </Select>
          </label>
          <label className="text-xs text-muted-foreground">
            {T.editStartLabel}
            <Input type="time" aria-label={T.editStart(libelle)} className="mt-0.5 h-9 w-28" value={local.debut} onChange={(e) => setLocal((p) => ({ ...p, debut: e.target.value }))} />
          </label>
          <label className="text-xs text-muted-foreground">
            {T.editDurationLabel}
            <DurationSelect aria-label={T.editDurationLabel} className="mt-0.5 h-9" wrapperClassName="w-28" value={local.duree} present={[local.duree]} startTime={local.debut} onChange={(minutes) => setLocal((p) => ({ ...p, duree: minutes }))} />
          </label>
          <SlotEndField startTime={local.debut} duration={local.duree} />
        </div>
        {isMutual ? null : (
          <SegmentedControl
            mode="single"
            label={T.caseKeep}
            value={keepLocal ? "keep" : "ignore"}
            onValueChange={(v) => setKeepLocal("keep" === v)}
            options={[
              { key: "keep", label: T.mutualKeep },
              { key: "ignore", label: T.mutualIgnore },
            ]}
          />
        )}
      </div>
    </Modal>
  );
}

/** La bannière d'erreur d'import — dans la modale, `role="alert"`, avec la liste des `errors[]`. */
function ImportErrorBanner({ error }: { error: unknown }) {
  const errors = extractErrors(error);
  return (
    <NoticeBanner tone="destructive" role="alert">
      <p className="font-medium">{T.importErrorHeading}</p>
      {errors.length > 0 ? (
        <ul className="flex list-disc flex-col gap-1 pl-5">
          {errors.map((message, i) => (
            <li key={`${i}:${message}`}>{message}</li>
          ))}
        </ul>
      ) : (
        <p>{T.importErrorGeneric}</p>
      )}
    </NoticeBanner>
  );
}

/** Lit la liste `errors[]` d'une erreur ky (4xx), sinon aucune (repli générique). */
function extractErrors(error: unknown): string[] {
  const status = (error as { response?: { status?: number } } | null)?.response?.status;
  if ("number" === typeof status && status >= 500) {
    return [];
  }
  const data = (error as { data?: { errors?: unknown } } | null)?.data;
  const errors = data?.errors;
  return Array.isArray(errors) ? errors.filter((m): m is string => "string" === typeof m && "" !== m.trim()) : [];
}

/** Le récap de l'import réel — remplace le contenu, pied « Fermer » primaire (pas de 6ᵉ étape). */
function RecapView({ result, headingRef }: { result: ParcImportResult; headingRef: RefObject<HTMLHeadingElement | null> }) {
  return (
    <section className="flex flex-col gap-3">
      <h3 ref={headingRef} tabIndex={-1} className="text-base font-semibold outline-none">
        {result.message ?? T.importDone}
      </h3>
      {undefined === result.recap ? null : (
        <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
          {Object.entries(result.recap).map(([section, r]) => (
            <li key={section}>
              {T.sectionTitle(section)} — {T.sectionRecap(r.crees, r.apparies, r.misAJour, r.ignores)}
            </li>
          ))}
        </ul>
      )}
      <p className="text-sm text-muted-foreground">{T.recapNext}</p>
    </section>
  );
}
