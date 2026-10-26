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

const keyOf = (section: string, index: number): string => `${section}:${index}`;

/**
 * Lot correctif import IA « G2 » PR1 — la modale d'import du parc par IA devient un ASSISTANT en 5
 * étapes (StepRail : Prompt · Coller · Équipes · Gymnases · Réservations). Le gestionnaire (1) copie
 * le prompt, (2) colle la réponse de l'assistant et l'analyse, puis tranche l'aperçu par étape : (3)
 * ses équipes, (4) ses gymnases, (5) ses réservations (grille par gymnase). Les étapes 3-5 restent
 * VERROUILLÉES tant que l'analyse n'a pas réussi ; une analyse réussie ouvre et passe à « Équipes ».
 *
 * Le front n'invente AUCUNE règle : verdicts, raisons et libellés viennent du serveur, affichés
 * verbatim. Une ligne équipe/gymnase AMBIGUË (≥ 2 candidats) porte un sélecteur `Listbox` SANS
 * présélection (« À trancher… ») et bloque « Importer » tant qu'elle n'est pas tranchée (apparier à
 * un candidat, créer, ou ignorer). L'erreur d'import est surfacée DANS la modale (NoticeBanner
 * `role="alert"`), pas en toast — le toast reste la conduite de l'ANALYSE (hook).
 *
 * Décisions d'interaction (passe `ui-ux-pro-max`, corpus a11y/feedback) :
 *  - CLÔTURE jamais bloquée (dry-run, rien écrit avant l'import) ; seule la double-soumission est
 *    empêchée (boutons désarmés pendant un appel).
 *  - FOCUS : au changement d'étape, sur le `<h3 tabIndex={-1}>` « Étape n/5 · … » ; aucun vol de
 *    focus ailleurs — les états nouveaux s'ANNONCENT en `aria-live` plutôt que de voler le focus.
 *  - « Importer N » compte les lignes retenues ; bloqué, il DIT pourquoi (`disabledReason` →
 *    `aria-disabled` focalisable + raison découvrable, A11Y-30), jamais un clic mort.
 */
export function ParcImportModal(props: ParcImportModalProps) {
  const { clubId, teams, venues, club, onClose } = props;
  const analyze = useAnalyzeParc(clubId);
  const importMut = useImportParc(clubId);

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
  // Lignes cochées des sections NON-entités (créneaux / réservations / mutualisations).
  const [checked, setChecked] = useState<Record<string, boolean>>({});
  const [slotDrafts, setSlotDrafts] = useState<Record<number, SlotDraft>>({});
  const [slotVenue, setSlotVenue] = useState<Record<number, string>>({});
  const [groupDrafts, setGroupDrafts] = useState<Record<number, string>>({});
  const [groupVenue, setGroupVenue] = useState<Record<number, string>>({});
  const [editingCase, setEditingCase] = useState<number | null>(null);
  // Nombre de cases corrigées depuis la dernière analyse → aperçu périmé (bandeau « Revalider »).
  const [dirtyCases, setDirtyCases] = useState<Set<number>>(new Set());
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

  const applyAnalysis = (data: ParcAnalysis): void => {
    setAnalysis(data);
    setResult(null);
    setDirtyCases(new Set());

    const nextDecisions: Record<string, string> = {};
    const nextChecked: Record<string, boolean> = {};
    const nextSlotVenue: Record<number, string> = {};
    const nextGroupDrafts: Record<number, string> = {};
    const nextGroupVenue: Record<number, string> = {};
    for (const section of Object.keys(data.sections)) {
      for (const line of data.sections[section] ?? []) {
        if (REMATCH_SECTIONS.has(section)) {
          nextDecisions[keyOf(section, line.index)] = initialDecision(line);
        } else {
          nextChecked[keyOf(section, line.index)] = line.coche;
        }
        if ("creneaux" === section) {
          nextSlotVenue[line.index] = line.cibleId ?? "";
        }
        if ("mutualisations" === section) {
          nextGroupDrafts[line.index] = line.groupe ?? "";
          nextGroupVenue[line.index] = line.gymnaseId ?? "";
        }
      }
    }
    setDecisions(nextDecisions);
    setChecked(nextChecked);
    setSlotVenue(nextSlotVenue);
    setGroupDrafts(nextGroupDrafts);
    setGroupVenue(nextGroupVenue);

    // Amorce des champs éditables des CRÉNEAUX depuis les CASES du parc NORMALISÉ que l'analyse
    // renvoie (relu par `ParcPayloadValidator::parse()`, G2 D4) — jamais le texte brut collé.
    const payload = data.payload;
    const parsed: ParsedParc | null = null !== payload && "object" === typeof payload ? (payload as ParsedParc) : null;
    setParc(payload ?? null);
    const drafts: Record<number, SlotDraft> = {};
    const cases: ParsedCase[] = null !== parsed && Array.isArray(parsed.cases) ? parsed.cases : [];
    for (const line of data.sections.creneaux ?? []) {
      const slot = cases[line.index];
      if (undefined !== slot) {
        drafts[line.index] = {
          jour: "number" === typeof slot.jour ? slot.jour : 1,
          debut: "string" === typeof slot.debut ? slot.debut : "",
          duree: "number" === typeof slot.duree ? slot.duree : 0,
        };
      }
    }
    setSlotDrafts(drafts);
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
            out.push({ section, index: line.index, coche: true });
          } else if (IGNORE === value || "" === value) {
            out.push({ section, index: line.index, coche: false });
          } else {
            out.push({ section, index: line.index, coche: true, cibleId: value });
          }
        } else if ("creneaux" === section) {
          const draft = slotDrafts[line.index];
          const chosen = slotVenue[line.index];
          out.push({
            section,
            index: line.index,
            coche: checked[key] ?? line.coche,
            cibleId: undefined !== chosen && "" !== chosen ? chosen : undefined,
            corrections: undefined !== draft ? { jour: draft.jour, debut: draft.debut, duree: draft.duree } : undefined,
          });
        } else if ("mutualisations" === section) {
          const groupe = (groupDrafts[line.index] ?? "").trim();
          const chosenVenue = groupVenue[line.index];
          out.push({
            section,
            index: line.index,
            coche: checked[key] ?? line.coche,
            venueId: undefined !== chosenVenue && "" !== chosenVenue ? chosenVenue : undefined,
            corrections: "" !== groupe ? { groupe } : undefined,
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

  const toggleChecked = (section: string, index: number): void =>
    setChecked((prev) => ({ ...prev, [keyOf(section, index)]: !(prev[keyOf(section, index)] ?? false) }));

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

  // Éditeur de créneau (Modal imbriquée) — `caseIndex` est narrowé non-null, pour servir de clé stable.
  let caseEditor: ReactNode = null;
  if (null !== editingCase) {
    const caseIndex = editingCase;
    caseEditor = (
      <CaseEditor
        libelle={analysis?.sections.creneaux?.find((l) => l.index === caseIndex)?.libelle ?? ""}
        draft={slotDrafts[caseIndex]}
        venueId={slotVenue[caseIndex] ?? ""}
        venues={venues}
        onCancel={() => setEditingCase(null)}
        onSave={(draft, venueId) => {
          setSlotDrafts((prev) => ({ ...prev, [caseIndex]: draft }));
          setSlotVenue((prev) => ({ ...prev, [caseIndex]: venueId }));
          setDirtyCases((prev) => new Set(prev).add(caseIndex));
          setEditingCase(null);
        }}
      />
    );
  }

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
                  teams={teams}
                  venues={venues}
                  decisionValue={decisionValue}
                  onDecision={setDecision}
                />
              ) : null}
              {"reservations" === step && null !== analysis ? (
                <ReservationsSection
                  analysis={analysis}
                  parc={parc}
                  checked={checked}
                  slotDrafts={slotDrafts}
                  groupDrafts={groupDrafts}
                  groupVenue={groupVenue}
                  venues={venues}
                  onToggle={toggleChecked}
                  onEditCase={setEditingCase}
                  onGroupChange={(index, value) => setGroupDrafts((prev) => ({ ...prev, [index]: value }))}
                  onGroupVenueChange={(index, value) => setGroupVenue((prev) => ({ ...prev, [index]: value }))}
                />
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
  teams,
  venues,
  decisionValue,
  onDecision,
}: {
  section: "equipes" | "gymnases";
  lines: ParcAnalysisLine[];
  teams: readonly ParcEntity[];
  venues: readonly ParcEntity[];
  decisionValue: (section: string, line: ParcAnalysisLine) => string;
  onDecision: (section: string, index: number, value: string) => void;
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
          entities={"equipes" === section ? teams : venues}
          value={decisionValue(section, line)}
          onChange={(value) => onDecision(section, line.index, value)}
        />
      ))}
    </ul>
  );
}

function DecisionRow({
  section,
  line,
  entities,
  value,
  onChange,
}: {
  section: "equipes" | "gymnases";
  line: ParcAnalysisLine;
  entities: readonly ParcEntity[];
  value: string;
  onChange: (value: string) => void;
}) {
  const reasonId = useId();
  const groups = useMemo(() => decisionGroups(section, line, entities), [section, line, entities]);
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
function decisionGroups(section: "equipes" | "gymnases", line: ParcAnalysisLine, entities: readonly ParcEntity[]): ListboxGroup[] {
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

  const all = [...entities]
    .filter((e) => !candidats.some((c) => c.id === e.id) && e.id !== suggested)
    .sort((a, b) => a.name.localeCompare(b.name, "fr"));
  if (all.length > 0) {
    groups.push({
      id: "all",
      label: "equipes" === section ? T.allTeamsGroup : T.allVenuesGroup,
      options: all.map((e) => ("equipes" === section ? { value: e.id, label: e.name } : { value: e.id, label: e.name, swatch: null })),
    });
  }

  return groups;
}

/** L'étape « Réservations » : la grille par gymnase (créneaux) + réservations + mutualisations. */
function ReservationsSection({
  analysis,
  parc,
  checked,
  slotDrafts,
  groupDrafts,
  groupVenue,
  venues,
  onToggle,
  onEditCase,
  onGroupChange,
  onGroupVenueChange,
}: {
  analysis: ParcAnalysis;
  parc: unknown;
  checked: Record<string, boolean>;
  slotDrafts: Record<number, SlotDraft>;
  groupDrafts: Record<number, string>;
  groupVenue: Record<number, string>;
  venues: readonly ParcEntity[];
  onToggle: (section: string, index: number) => void;
  onEditCase: (index: number) => void;
  onGroupChange: (index: number, value: string) => void;
  onGroupVenueChange: (index: number, value: string) => void;
}) {
  const creneaux = analysis.sections.creneaux ?? [];
  const reservations = analysis.sections.reservations ?? [];
  const mutualisations = analysis.sections.mutualisations ?? [];
  const parsedCases: ParsedCase[] = null !== parc && "object" === typeof parc && Array.isArray((parc as ParsedParc).cases) ? (parc as ParsedParc).cases ?? [] : [];

  const venueNameOf = (index: number): string => {
    const gym = parsedCases[index]?.gymnase;
    return "string" === typeof gym ? gym : "";
  };

  const gridCases: ParcCaseGridCase[] = creneaux.map((line) => {
    const draft = slotDrafts[line.index];
    return {
      index: line.index,
      venueName: venueNameOf(line.index),
      jour: draft?.jour ?? 1,
      debut: draft?.debut ?? "",
      duree: draft?.duree ?? 0,
      libelle: line.libelle,
      alerte: "alerte" === line.statut ? line.raison : null,
    };
  });

  const gymnaseLines = analysis.sections.gymnases ?? [];
  const seen = new Set<string>();
  const gridVenues: ParcCaseGridVenue[] = [];
  for (const line of creneaux) {
    const name = venueNameOf(line.index);
    if ("" === name || seen.has(name)) {
      continue;
    }
    seen.add(name);
    const gymLine = gymnaseLines.find((g) => g.libelle === name);
    gridVenues.push({ name, toCreate: undefined !== gymLine && "aCreer" === gymLine.statut });
  }

  return (
    <div className="flex flex-col gap-4">
      {creneaux.length > 0 ? <ParcCaseGrid venues={gridVenues} cases={gridCases} onEditCase={onEditCase} /> : null}

      {reservations.length > 0 ? (
        <section className="flex flex-col gap-1">
          <h4 className="text-xs font-semibold text-muted-foreground uppercase">{T.sectionTitle("reservations")}</h4>
          <ul className="flex flex-col gap-1">
            {reservations.map((line) => (
              <LineCheckbox key={line.index} section="reservations" line={line} checked={checked[keyOf("reservations", line.index)] ?? false} onToggle={onToggle} />
            ))}
          </ul>
        </section>
      ) : null}

      {mutualisations.length > 0 ? (
        <section className="flex flex-col gap-1">
          <h4 className="text-xs font-semibold text-muted-foreground uppercase">{T.sectionTitle("mutualisations")}</h4>
          <ul className="flex flex-col gap-2">
            {mutualisations.map((line) => (
              <li key={line.index} className="flex flex-col gap-1 border-b border-border/60 pb-2">
                <LineCheckbox section="mutualisations" line={line} checked={checked[keyOf("mutualisations", line.index)] ?? false} onToggle={onToggle} />
                {"alerte" === line.statut ? null : (
                  <div className="flex flex-col gap-1 pl-6 md:max-w-xs">
                    <Input
                      aria-label={T.groupLabel(line.libelle)}
                      placeholder={T.groupLabelPlaceholder}
                      value={groupDrafts[line.index] ?? ""}
                      onChange={(e) => onGroupChange(line.index, e.target.value)}
                    />
                    <Listbox
                      aria-label={T.venueForGroup(line.libelle)}
                      wrapperClassName="w-full"
                      placeholder={T.rematchCreate}
                      value={groupVenue[line.index] ?? ""}
                      onValueChange={(value) => onGroupVenueChange(line.index, value)}
                      options={venues.map((v) => ({ value: v.id, label: v.name, swatch: null }))}
                    />
                  </div>
                )}
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </div>
  );
}

function LineCheckbox({ section, line, checked, onToggle }: { section: string; line: ParcAnalysisLine; checked: boolean; onToggle: (section: string, index: number) => void }) {
  return (
    <li className="flex flex-col gap-0.5">
      <label className="flex items-start gap-2 text-sm">
        <input type="checkbox" aria-label={line.libelle} className="mt-1 size-4 shrink-0" checked={checked} onChange={() => onToggle(section, line.index)} />
        <span className="text-foreground">{line.libelle}</span>
      </label>
      {null === line.raison ? null : <p className="pl-6 text-xs text-muted-foreground">{line.raison}</p>}
    </li>
  );
}

/** Éditeur de créneau (Modal imbriquée, brouillon transactionnel) : gymnase · jour · heure · durée. */
function CaseEditor({
  libelle,
  draft,
  venueId,
  venues,
  onCancel,
  onSave,
}: {
  libelle: string;
  draft: SlotDraft | undefined;
  venueId: string;
  venues: readonly ParcEntity[];
  onCancel: () => void;
  onSave: (draft: SlotDraft, venueId: string) => void;
}) {
  const [local, setLocal] = useState<SlotDraft>(draft ?? { jour: 1, debut: "", duree: 0 });
  const [venue, setVenue] = useState(venueId);

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
          <Button size="sm" onClick={() => onSave(local, venue)}>
            {T.save}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">{libelle}</p>
        <Listbox
          aria-label={T.editVenue(libelle)}
          wrapperClassName="w-full"
          placeholder={T.rematchCreate}
          value={venue}
          onValueChange={setVenue}
          options={venues.map((v) => ({ value: v.id, label: v.name, swatch: null }))}
        />
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
          <div className="w-24">
            <Input type="number" min={1} aria-label={T.editDuration(libelle)} value={local.duree} onChange={(e) => setLocal((p) => ({ ...p, duree: Number(e.target.value) }))} />
          </div>
        </div>
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
