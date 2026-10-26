import { AlertTriangle } from "lucide-react";
import { type ReactNode, type RefObject, useEffect, useId, useMemo, useRef, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { FIELD_CLASS } from "@/shared/components/ui/field";
import { Input } from "@/shared/components/ui/input";
import { Listbox, type ListboxGroup } from "@/shared/components/ui/listbox";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Select } from "@/shared/components/ui/select";
import { Spinner } from "@/shared/components/ui/spinner";
import { StepRail, type StepRailState, type StepRailStep } from "@/shared/components/ui/step-rail";
import { DAYS, dayLabelLongCap } from "@/shared/lib/days";
import { groupTeamsByTier, type TeamLike, type TierLike, tierGroupLabel } from "@/shared/lib/teamTiers";

import { usePriorityTiers, useWizardTeams } from "@/features/wizard/queries";

import { DurationSelect } from "../steps/slotFields";
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

/** Les étapes du rail, dans l'ordre. 1-2 toujours libres ; 3-5 verrouillées avant l'analyse. */
type StepId = "prompt" | "paste" | "equipes" | "gymnases" | "reservations";
const STEP_ORDER: StepId[] = ["prompt", "paste", "equipes", "gymnases", "reservations"];
const CONTENT_STEPS = new Set<StepId>(["equipes", "gymnases", "reservations"]);

/** Les sections d'ENTITÉS : une ligne y porte le sélecteur de décision (apparier / créer / ignorer). */
const REMATCH_SECTIONS = new Set(["equipes", "gymnases"]);

/** Les sections de CASES : rendues en GRILLE par gymnase (plus de case à cocher), clic → éditeur. */
const CASE_SECTIONS = ["creneaux", "reservations", "mutualisations"] as const;
const CASE_SECTION_SET = new Set<string>(CASE_SECTIONS);

/** Sentinelles de décision du sélecteur de ligne (valeurs du `Listbox`, jamais un id réel). */
const CREATE = "__create__";
const IGNORE = "__ignore__";

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

/**
 * Lot correctif import IA « G2 » PR1 — la modale d'import du parc par IA est un ASSISTANT en 5 étapes
 * (StepRail : Prompt · Coller · Équipes · Gymnases · Réservations). Le gestionnaire (1) copie le
 * prompt, (2) colle la réponse de l'assistant et l'analyse, puis tranche l'aperçu par étape : (3) ses
 * équipes, (4) ses gymnases, (5) ses réservations (grille par gymnase). Les étapes 3-5 restent
 * VERROUILLÉES tant que l'analyse n'a pas réussi ; une analyse réussie ouvre et passe à « Équipes ».
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
  const allTeams = (useWizardTeams().data ?? []) as TeamLike[];
  const tiers = (usePriorityTiers().data ?? []) as TierLike[];

  const prompt = useMemo(
    () => buildParcPrompt({ teams: teams.map((t) => t.name), venues: venues.map((v) => v.name), club }),
    [teams, venues, club],
  );

  const [step, setStep] = useState<StepId>("prompt");
  const [copied, setCopied] = useState(false);
  const [rawText, setRawText] = useState("");
  const [analysis, setAnalysis] = useState<ParcAnalysis | null>(null);
  const [parc, setParc] = useState<unknown>(null);
  // Décision par ligne équipe/gymnase (valeur du `Listbox`) : id (apparier) · CREATE · IGNORE · ""
  // (ambigu non tranché). Indexée par `section:index`, amorcée depuis le verdict serveur.
  const [decisions, setDecisions] = useState<Record<string, string>>({});
  // Lignes gardées des sections de CASES (créneaux / réservations / mutualisations), par `section:index`.
  const [checked, setChecked] = useState<Record<string, boolean>>({});
  // Brouillons d'édition d'une case, par `section:index`.
  const [slotDrafts, setSlotDrafts] = useState<Record<string, SlotDraft>>({});
  const [caseVenue, setCaseVenue] = useState<Record<string, string>>({});
  const [groupDrafts, setGroupDrafts] = useState<Record<string, string>>({});
  // Acceptation de la mise à jour de séances proposée sur une équipe APPARIÉE, par `section:index`.
  const [sessionAccepted, setSessionAccepted] = useState<Record<string, boolean>>({});
  const [editingCase, setEditingCase] = useState<EditingCase | null>(null);
  // Cases corrigées depuis la dernière analyse (clés `section:index`) → aperçu périmé (« Revalider »).
  const [dirtyCases, setDirtyCases] = useState<Set<string>>(new Set());
  const [result, setResult] = useState<ParcImportResult | null>(null);

  const headingRef = useRef<HTMLHeadingElement>(null);

  const analyzing = analyze.isPending;
  const importing = importMut.isPending;
  const isRecap = null !== result;

  // Focus l'en-tête d'étape au changement d'étape (jamais de vol ailleurs).
  useEffect(() => {
    headingRef.current?.focus();
  }, [step, isRecap]);

  const copyPrompt = (): void => {
    void navigator.clipboard.writeText(prompt).then(() => setCopied(true));
  };

  const parsedCasesOf = (payload: unknown): ParsedCase[] => {
    const parsed: ParsedParc | null = null !== payload && "object" === typeof payload ? (payload as ParsedParc) : null;

    return null !== parsed && Array.isArray(parsed.cases) ? parsed.cases : [];
  };

  const applyAnalysis = (data: ParcAnalysis): void => {
    setAnalysis(data);
    setResult(null);
    setDirtyCases(new Set());
    setSessionAccepted({});

    const cases = parsedCasesOf(data.payload);
    setParc(data.payload ?? null);

    const nextDecisions: Record<string, string> = {};
    const nextChecked: Record<string, boolean> = {};
    const nextSlotDrafts: Record<string, SlotDraft> = {};
    const nextCaseVenue: Record<string, string> = {};
    const nextGroupDrafts: Record<string, string> = {};
    for (const section of Object.keys(data.sections)) {
      for (const line of data.sections[section] ?? []) {
        const key = keyOf(section, line.index);
        if (REMATCH_SECTIONS.has(section)) {
          nextDecisions[key] = initialDecision(line);
        } else {
          nextChecked[key] = line.coche;
        }
        if (CASE_SECTION_SET.has(section)) {
          // Amorce de la case (position + brouillons) depuis le parc NORMALISÉ renvoyé par l'analyse
          // (relu par `ParcPayloadValidator::parse()`, G2 D4) — jamais le texte brut collé.
          const slot = cases[line.index];
          nextSlotDrafts[key] = {
            jour: "number" === typeof slot?.jour ? slot.jour : 1,
            debut: "string" === typeof slot?.debut ? slot.debut : "",
            duree: "number" === typeof slot?.duree ? slot.duree : 0,
          };
          if ("creneaux" === section) {
            nextCaseVenue[key] = line.cibleId ?? "";
          } else if ("mutualisations" === section) {
            nextCaseVenue[key] = line.gymnaseId ?? "";
            nextGroupDrafts[key] = line.groupe ?? "";
          }
        }
      }
    }
    setDecisions(nextDecisions);
    setChecked(nextChecked);
    setSlotDrafts(nextSlotDrafts);
    setCaseVenue(nextCaseVenue);
    setGroupDrafts(nextGroupDrafts);
  };

  const doAnalyze = (): void => {
    analyze.mutate(rawText, {
      onSuccess: (data) => {
        applyAnalysis(data);
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
          if (CREATE === value) {
            // « Créer » explicite (3a) : coché, sans cible, signal `creer:true` (lève le refus d'ambiguïté).
            out.push({ section, index: line.index, coche: true, creer: true });
          } else if (IGNORE === value || "" === value) {
            out.push({ section, index: line.index, coche: false });
          } else {
            // Appariement à un candidat : la mise à jour de séances proposée part SI elle a été acceptée.
            const decision: ParcImportDecision = { section, index: line.index, coche: true, cibleId: value };
            if ((sessionAccepted[key] ?? false) && undefined !== line.miseAJour) {
              decision.corrections = { seances: line.miseAJour.a };
            }
            out.push(decision);
          }
        } else if ("mutualisations" === section) {
          const groupe = (groupDrafts[key] ?? "").trim();
          const chosenVenue = caseVenue[key];
          out.push({
            section,
            index: line.index,
            coche: checked[key] ?? line.coche,
            venueId: undefined !== chosenVenue && "" !== chosenVenue ? chosenVenue : undefined,
            corrections: "" !== groupe ? { groupe } : undefined,
          });
        } else if (CASE_SECTION_SET.has(section)) {
          const draft = slotDrafts[key];
          const chosen = caseVenue[key];
          out.push({
            section,
            index: line.index,
            coche: checked[key] ?? line.coche,
            cibleId: undefined !== chosen && "" !== chosen ? chosen : undefined,
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

  const setDecision = (section: string, index: number, value: string): void =>
    setDecisions((prev) => ({ ...prev, [keyOf(section, index)]: value }));

  const toggleSession = (section: string, index: number): void =>
    setSessionAccepted((prev) => ({ ...prev, [keyOf(section, index)]: !(prev[keyOf(section, index)] ?? false) }));

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

  const importDecisions = buildDecisions();
  const count = importDecisions.filter((d) => d.coche).length;
  const blocked = pendingAmbiguous > 0 || 0 === count;

  const doImport = (): void => {
    if (null === analysis || blocked) {
      return;
    }
    importMut.mutate({ payload: parc, decisions: buildDecisions() }, { onSuccess: setResult });
  };

  const doRevalidate = (): void => {
    if (null === analysis) {
      return;
    }
    analyze.mutate({ payload: parc, decisions: buildDecisions() }, { onSuccess: applyAnalysis });
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
    return hasPending(id) ? "warning" : "done";
  };

  const hasPending = (id: StepId): boolean => {
    if (null === analysis) {
      return false;
    }
    if ("reservations" === id) {
      return ["creneaux", "reservations", "mutualisations"].some((s) => (analysis.sections[s] ?? []).some((l) => "alerte" === l.statut));
    }
    return (analysis.sections[id] ?? []).some((l) => "alerte" === l.statut || "" === decisionValue(id, l));
  };

  const steps: StepRailStep<StepId>[] = STEP_ORDER.map((id) => ({
    id,
    label: "prompt" === id ? T.stepPrompt : "paste" === id ? T.stepPaste : T.sectionTitle(id),
    state: stepStateOf(id),
    locked: isLocked(id),
  }));

  const stepNumber = STEP_ORDER.indexOf(step) + 1;
  const stepTitle = "prompt" === step ? T.promptHeading : "paste" === step ? T.pasteHeading : T.sectionTitle(step);

  // Grille des CASES (créneaux + réservations + mutualisations), avec un index SYNTHÉTIQUE par case
  // (`ParcCaseGrid` ne connaît qu'un index numérique) remonté à sa (section, rang) par `ref`.
  const gridData = useMemo(() => {
    const gridCases: ParcCaseGridCase[] = [];
    const ref: Record<number, EditingCase> = {};
    const venueToCreate = new Map<string, boolean>();
    if (null !== analysis) {
      const cases = parsedCasesOf(parc);
      const gymLines = analysis.sections.gymnases ?? [];
      let gid = 0;
      for (const section of CASE_SECTIONS) {
        for (const line of analysis.sections[section] ?? []) {
          const key = keyOf(section, line.index);
          const slot = cases[line.index];
          const venueName = "string" === typeof slot?.gymnase ? slot.gymnase : "";
          const draft = slotDrafts[key];
          const g = gid++;
          ref[g] = { section, index: line.index };
          gridCases.push({
            index: g,
            venueName,
            jour: draft?.jour ?? 1,
            debut: draft?.debut ?? "",
            duree: draft?.duree ?? 0,
            libelle: line.libelle,
            alerte: "alerte" === line.statut ? line.raison : null,
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
  }, [analysis, parc, slotDrafts]);

  // Éditeur de case (Modal imbriquée) — brouillon transactionnel, clé stable `section:index`.
  let caseEditor: ReactNode = null;
  if (null !== editingCase) {
    const { section, index } = editingCase;
    const key = keyOf(section, index);
    const line = analysis?.sections[section]?.find((l) => l.index === index);
    caseEditor = (
      <CaseEditor
        key={key}
        section={section}
        libelle={line?.libelle ?? ""}
        draft={slotDrafts[key]}
        venueId={caseVenue[key] ?? ""}
        groupe={groupDrafts[key] ?? ""}
        keep={checked[key] ?? line?.coche ?? true}
        venues={venues}
        onCancel={() => setEditingCase(null)}
        onSave={(next) => {
          setSlotDrafts((prev) => ({ ...prev, [key]: next.draft }));
          setCaseVenue((prev) => ({ ...prev, [key]: next.venueId }));
          setGroupDrafts((prev) => ({ ...prev, [key]: next.groupe }));
          setChecked((prev) => ({ ...prev, [key]: next.keep }));
          setDirtyCases((prev) => new Set(prev).add(key));
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
      footer={
        isRecap ? (
          <Button size="sm" onClick={onClose}>
            {T.close}
          </Button>
        ) : (
          <>
            <Button variant="outline" size="sm" onClick={onClose}>
              {T.close}
            </Button>
            {"prompt" !== step ? (
              <Button variant="ghost" size="sm" onClick={() => goTo(-1)}>
                {T.previous}
              </Button>
            ) : null}
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
            {"prompt" === step ? (
              <Button size="sm" onClick={() => goTo(1)}>
                {T.next}
              </Button>
            ) : null}
            {CONTENT_STEPS.has(step) ? (
              <>
                {"reservations" !== step ? (
                  <Button variant="outline" size="sm" onClick={() => goTo(1)}>
                    {T.next}
                  </Button>
                ) : null}
                <Button
                  size="sm"
                  disabled={blocked || importing}
                  disabledReason={blocked ? T.importBlocked(pendingAmbiguous, 0) : undefined}
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
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 ref={headingRef} tabIndex={-1} className="text-sm font-semibold outline-none">
                  {CONTENT_STEPS.has(step) ? T.stepHeading(stepNumber, STEP_ORDER.length, stepTitle) : stepTitle}
                </h3>
                {CONTENT_STEPS.has(step) ? (
                  <Button variant="ghost" size="sm" onClick={doRevalidate} disabled={analyzing}>
                    {analyzing ? <Spinner className="size-4" /> : null}
                    {T.revalidate}
                  </Button>
                ) : null}
              </div>

              {/* Erreur d'import — dans la modale, jamais un toast (le hook garde le toast à l'analyse). */}
              {CONTENT_STEPS.has(step) && importMut.isError ? <ImportErrorBanner error={importMut.error} /> : null}

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

              {/* Aperçu périmé après une correction : inviter à revalider (sans bloquer l'import). */}
              {CONTENT_STEPS.has(step) && dirtyCases.size > 0 ? (
                <NoticeBanner tone="accent">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span>{T.staleHint(dirtyCases.size)}</span>
                    <Button variant="outline" size="sm" onClick={doRevalidate} disabled={analyzing}>
                      {T.revalidate}
                    </Button>
                  </div>
                </NoticeBanner>
              ) : null}

              {"prompt" === step ? <PromptStep prompt={prompt} copied={copied} onCopy={copyPrompt} /> : null}
              {"paste" === step ? <PasteStep rawText={rawText} analyzing={analyzing} onChange={setRawText} /> : null}
              {"equipes" === step || "gymnases" === step ? (
                <EntitySection
                  section={step}
                  lines={analysis?.sections[step] ?? []}
                  venues={venues}
                  allTeams={allTeams}
                  tiers={tiers}
                  decisionValue={decisionValue}
                  onDecision={setDecision}
                  sessionAccepted={sessionAccepted}
                  onSessionToggle={toggleSession}
                />
              ) : null}
              {"reservations" === step && null !== analysis ? (
                gridData.gridCases.length > 0 ? (
                  <ParcCaseGrid
                    venues={gridData.gridVenues}
                    cases={gridData.gridCases}
                    onEditCase={(g) => setEditingCase(gridData.ref[g] ?? null)}
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
  sessionAccepted,
  onSessionToggle,
}: {
  section: "equipes" | "gymnases";
  lines: ParcAnalysisLine[];
  venues: readonly ParcEntity[];
  allTeams: TeamLike[];
  tiers: TierLike[];
  decisionValue: (section: string, line: ParcAnalysisLine) => string;
  onDecision: (section: string, index: number, value: string) => void;
  sessionAccepted: Record<string, boolean>;
  onSessionToggle: (section: string, index: number) => void;
}) {
  if (0 === lines.length) {
    return <p className="text-sm text-muted-foreground">{T.previewHelp}</p>;
  }
  return (
    <ul className="flex flex-col">
      {lines.map((line) => (
        <DecisionRow
          key={line.index}
          section={section}
          line={line}
          venues={venues}
          allTeams={allTeams}
          tiers={tiers}
          value={decisionValue(section, line)}
          onChange={(value) => onDecision(section, line.index, value)}
          sessionAccepted={sessionAccepted[keyOf(section, line.index)] ?? false}
          onSessionToggle={() => onSessionToggle(section, line.index)}
        />
      ))}
    </ul>
  );
}

function DecisionRow({
  section,
  line,
  venues,
  allTeams,
  tiers,
  value,
  onChange,
  sessionAccepted,
  onSessionToggle,
}: {
  section: "equipes" | "gymnases";
  line: ParcAnalysisLine;
  venues: readonly ParcEntity[];
  allTeams: TeamLike[];
  tiers: TierLike[];
  value: string;
  onChange: (value: string) => void;
  sessionAccepted: boolean;
  onSessionToggle: () => void;
}) {
  const reasonId = useId();
  const groups = useMemo(() => decisionGroups(section, line, venues, allTeams, tiers), [section, line, venues, allTeams, tiers]);
  const pending = "ambigu" === line.statut && "" === value;

  return (
    <li className="grid gap-x-4 gap-y-1 border-b border-border/60 py-2 md:grid-cols-[minmax(0,1fr)_20rem]">
      <div className="flex flex-col gap-0.5">
        <span className="text-sm font-medium text-foreground">{line.libelle}</span>
        {null === line.raison ? null : <span className="text-xs text-muted-foreground">{line.raison}</span>}
        {pending ? (
          <span id={reasonId} className="flex items-center gap-1 text-xs text-foreground">
            <AlertTriangle aria-hidden className="size-3.5 text-warning" />
            {line.raison ?? T.toDecide}
          </span>
        ) : null}
        {/* Équipe APPARIÉE dont le parc propose un autre nombre de séances : la mise à jour est
            PROPOSÉE sur la ligne, acceptée par cette case dédiée (→ corrections.seances à l'import). */}
        {undefined !== line.miseAJour ? (
          <label className="mt-0.5 flex items-center gap-2 text-xs text-foreground">
            <input type="checkbox" className="size-4 shrink-0" checked={sessionAccepted} onChange={onSessionToggle} />
            <span>{T.sessionsUpdate(line.miseAJour.de, line.miseAJour.a)}</span>
          </label>
        ) : null}
      </div>
      <Listbox
        aria-label={T.decisionFor(line.libelle)}
        aria-describedby={pending ? reasonId : undefined}
        wrapperClassName="w-full"
        placeholder={T.toDecide}
        value={value}
        onValueChange={onChange}
        groups={groups}
      />
    </li>
  );
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
 * Éditeur de case (Modal imbriquée, brouillon transactionnel) : Garder/Ignorer pour toute case ;
 * pour un créneau/une réservation, gymnase · jour · heure · durée (`DurationSelect`) ; pour une
 * mutualisation, le gymnase de la réservation de groupe (P4-363, → `venueId`) et le nom de groupe
 * (D8, → `corrections.groupe`).
 */
function CaseEditor({
  section,
  libelle,
  draft,
  venueId,
  groupe,
  keep,
  venues,
  onCancel,
  onSave,
}: {
  section: string;
  libelle: string;
  draft: SlotDraft | undefined;
  venueId: string;
  groupe: string;
  keep: boolean;
  venues: readonly ParcEntity[];
  onCancel: () => void;
  onSave: (next: { draft: SlotDraft; venueId: string; groupe: string; keep: boolean }) => void;
}) {
  const [local, setLocal] = useState<SlotDraft>(draft ?? { jour: 1, debut: "", duree: 0 });
  const [venue, setVenue] = useState(venueId);
  const [group, setGroup] = useState(groupe);
  const [keepLocal, setKeepLocal] = useState(keep);
  const isMutual = "mutualisations" === section;

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
          <Button size="sm" onClick={() => onSave({ draft: local, venueId: venue, groupe: group, keep: keepLocal })}>
            {T.save}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">{libelle}</p>
        <Listbox
          aria-label={isMutual ? T.venueForGroup(libelle) : T.editVenue(libelle)}
          wrapperClassName="w-full"
          placeholder={T.rematchCreate}
          value={venue}
          onValueChange={setVenue}
          options={venues.map((v) => ({ value: v.id, label: v.name, swatch: null }))}
        />
        {isMutual ? (
          <Input
            aria-label={T.groupLabel(libelle)}
            placeholder={T.groupLabelPlaceholder}
            value={group}
            onChange={(e) => setGroup(e.target.value)}
          />
        ) : (
          <div className="flex flex-wrap items-end gap-2">
            <Select aria-label={T.editDay(libelle)} wrapperClassName="w-32" value={local.jour} onChange={(e) => setLocal((p) => ({ ...p, jour: Number(e.target.value) }))}>
              {DAYS.map((d) => (
                <option key={d.n} value={d.n}>
                  {dayLabelLongCap(d.n)}
                </option>
              ))}
            </Select>
            <div className="w-28">
              <Input type="time" aria-label={T.editStart(libelle)} value={local.debut} onChange={(e) => setLocal((p) => ({ ...p, debut: e.target.value }))} />
            </div>
            <DurationSelect value={local.duree} present={[local.duree]} onChange={(minutes) => setLocal((p) => ({ ...p, duree: minutes }))} />
          </div>
        )}
        <label className="flex items-center gap-2 text-sm text-foreground">
          <input type="checkbox" className="size-4 shrink-0" checked={keepLocal} onChange={() => setKeepLocal((v) => !v)} />
          <span>{T.caseKeep}</span>
        </label>
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
