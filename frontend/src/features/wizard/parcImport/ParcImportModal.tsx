import { useMemo, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { FIELD_CLASS } from "@/shared/components/ui/field";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Select } from "@/shared/components/ui/select";
import { Spinner } from "@/shared/components/ui/spinner";
import { DAYS, dayLabelLongCap } from "@/shared/lib/days";

import { buildParcPrompt } from "./promptTemplate";
import {
  useAnalyzeParc,
  useImportParc,
  type ParcAnalysis,
  type ParcAnalysisLine,
  type ParcImportDecision,
  type ParcImportResult,
  type ParcLineStatus,
} from "./queries";
import { PARC_IMPORT_TEXTS as T } from "./texts";

interface ParcImportModalProps {
  /** D'où la modale est ouverte (Équipes ou Gymnases) — le même composant, deux portes d'entrée. */
  origin: "teams" | "venues";
  clubId: string;
  /** Saison de travail — résolue côté serveur via l'en-tête `X-Season-Id`, ici pour la cohérence du contrat. */
  seasonId: string;
  teams: readonly string[];
  venues: readonly string[];
  coaches: readonly string[];
  onClose: () => void;
}

/** L'ordre d'affichage des sections de l'aperçu (fixe, indépendant de l'ordre des clés serveur). */
const SECTION_ORDER = ["equipes", "gymnases", "creneaux", "mutualisations", "coachs", "reservations"] as const;

/** La teinte de la pastille par statut — PRÉSENTATION du verdict serveur (le texte le porte aussi). */
const STATUS_VARIANT: Record<ParcLineStatus, "success" | "accent" | "warning" | "neutral"> = {
  apparie: "success",
  suggere: "accent",
  aCreer: "accent",
  decoche: "neutral",
  alerte: "warning",
};

/** La forme (partielle) du parc collé — on ne lit que ce qui amorce une correction sur place. */
interface ParsedSlot {
  jour?: unknown;
  debut?: unknown;
  duree?: unknown;
}
interface ParsedParc {
  creneaux?: ParsedSlot[];
}

interface SlotDraft {
  jour: number;
  debut: string;
  duree: number;
}

const keyOf = (section: string, index: number): string => `${section}:${index}`;

/**
 * Chantier G PR3 — la modale d'import du parc par IA (onboarding, SAISON seule). Quatre temps dans
 * UNE modale : (1) copier le prompt pré-rempli du périmètre déjà saisi, (2) coller la réponse de
 * l'assistant et l'analyser, (3) vérifier l'aperçu cochable rendu par le serveur (dry-run), (4)
 * importer les lignes cochées et lire le récap.
 *
 * Le front n'invente AUCUNE règle : les verdicts (apparié / suggéré / à créer / décoché / alerte),
 * les raisons et les libellés viennent du serveur et sont affichés verbatim. La correction sur place
 * ne concerne QUE les champs simples d'un créneau À CRÉER (jour / heure / durée, amorcés depuis le
 * parc collé) — jamais la composition d'une structure (une mutualisation en alerte se décoche et
 * renvoie à la saisie manuelle), jamais les valeurs d'une entité déjà appariée.
 *
 * Décisions d'interaction (passe `ui-ux-pro-max`, corpus a11y/feedback) :
 *  - CLÔTURE jamais bloquée (dry-run, rien n'est écrit avant l'import) : « Fermer » + Esc + overlay
 *    restent ouverts ; seule la double-soumission est empêchée (champs/boutons désarmés pendant un appel).
 *  - FOCUS : initial + piège par la primitive `Modal` (`useModalA11y`) ; aucun vol de focus entre les
 *    temps — chaque état nouveau s'ANNONCE en `aria-live` (role="status", poli) plutôt que de voler le focus.
 *  - ANNONCES : « Analyse en cours », l'aperçu prêt et le récap final sont `role="status"` ; les erreurs
 *    HTTP vivent au niveau HOOK (toast, une fois) ; les raisons serveur sont du TEXTE (jamais couleur seule).
 *  - BOUTON : « Importer N », compte vivant ; à 0 il est désactivé AVEC une raison découvrable
 *    (`disabledReason` → `aria-disabled` focalisable + annonce, A11Y-30), pas un clic mort.
 */
export function ParcImportModal(props: ParcImportModalProps) {
  // `origin` et `seasonId` font partie du contrat (la saison voyage dans l'en-tête `X-Season-Id`,
  // l'origine servira au récap de PR2) ; ils ne pilotent encore aucun comportement ici.
  const { clubId, teams, venues, coaches, onClose } = props;
  const analyze = useAnalyzeParc(clubId);
  const importMut = useImportParc(clubId);

  const prompt = useMemo(() => buildParcPrompt({ teams, venues, coaches }), [teams, venues, coaches]);

  const [copied, setCopied] = useState(false);
  const [rawText, setRawText] = useState("");
  const [analysis, setAnalysis] = useState<ParcAnalysis | null>(null);
  // Le parc collé, re-décodé une fois l'analyse réussie : il amorce les corrections sur place et
  // repart tel quel comme `payload` à l'import (le serveur réécrit depuis la source, pas l'aperçu).
  const [parc, setParc] = useState<unknown>(null);
  const [checked, setChecked] = useState<Record<string, boolean>>({});
  const [slotDrafts, setSlotDrafts] = useState<Record<number, SlotDraft>>({});
  const [result, setResult] = useState<ParcImportResult | null>(null);

  const analyzing = analyze.isPending;
  const importing = importMut.isPending;

  const copyPrompt = (): void => {
    void navigator.clipboard.writeText(prompt).then(() => setCopied(true));
  };

  const applyAnalysis = (data: ParcAnalysis, text: string): void => {
    setAnalysis(data);
    setResult(null);

    const nextChecked: Record<string, boolean> = {};
    for (const section of Object.keys(data.sections)) {
      for (const line of data.sections[section] ?? []) {
        nextChecked[keyOf(section, line.index)] = line.coche;
      }
    }
    setChecked(nextChecked);

    // Amorce des champs éditables des CRÉNEAUX depuis le parc collé (correction sur place).
    let parsed: ParsedParc | null = null;
    try {
      const raw: unknown = JSON.parse(text);
      if (null !== raw && "object" === typeof raw) {
        parsed = raw as ParsedParc;
      }
    } catch {
      parsed = null;
    }
    setParc(parsed);
    const drafts: Record<number, SlotDraft> = {};
    const slots: ParsedSlot[] = null !== parsed && Array.isArray(parsed.creneaux) ? parsed.creneaux : [];
    for (const line of data.sections.creneaux ?? []) {
      const slot = slots[line.index];
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
    const text = rawText;
    analyze.mutate(text, { onSuccess: (data) => applyAnalysis(data, text) });
  };

  const toggle = (section: string, index: number): void =>
    setChecked((prev) => ({ ...prev, [keyOf(section, index)]: !prev[keyOf(section, index)] }));

  const updateSlot = (index: number, patch: Partial<SlotDraft>): void =>
    setSlotDrafts((prev) => ({ ...prev, [index]: { ...prev[index], ...patch } }));

  const count = Object.values(checked).filter(Boolean).length;

  const venuesToCreate = (analysis?.sections.gymnases ?? []).some((line) => "aCreer" === line.statut);

  const doImport = (): void => {
    if (null === analysis) {
      return;
    }
    const decisions: ParcImportDecision[] = [];
    for (const section of Object.keys(analysis.sections)) {
      for (const line of analysis.sections[section] ?? []) {
        const draft = "creneaux" === section ? slotDrafts[line.index] : undefined;
        decisions.push({
          section,
          index: line.index,
          coche: checked[keyOf(section, line.index)] ?? false,
          cibleId: line.cibleId,
          corrections: undefined === draft ? undefined : { jour: draft.jour, debut: draft.debut, duree: draft.duree },
        });
      }
    }
    importMut.mutate({ payload: parc, decisions }, { onSuccess: setResult });
  };

  const showPreview = null !== analysis && null === result;

  return (
    <Modal
      label={T.modalTitle}
      title={T.modalTitle}
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>
            {T.close}
          </Button>
          {showPreview ? (
            <Button
              size="sm"
              disabled={0 === count || importing}
              disabledReason={0 === count ? T.nothingToImport : undefined}
              onClick={doImport}
            >
              {importing ? <Spinner className="size-4" /> : null}
              {T.importAction(count)}
            </Button>
          ) : null}
        </>
      }
    >
      <div className="flex flex-col gap-5">
        {/* Temps 1 — le prompt pré-rempli du périmètre déjà saisi, à copier dans un assistant. */}
        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-semibold">{T.promptHeading}</h3>
          <p className="text-sm text-muted-foreground">{T.promptHelp}</p>
          <pre className="max-h-40 overflow-auto rounded-md border border-border bg-muted px-3 py-2 text-xs whitespace-pre-wrap text-foreground">{prompt}</pre>
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={copyPrompt}>
              {T.copyPrompt}
            </Button>
            {copied ? (
              <span role="status" className="text-xs text-muted-foreground">
                {T.promptCopied}
              </span>
            ) : null}
          </div>
        </section>

        {/* Temps 2 — collage de la réponse de l'assistant + analyse (dry-run). */}
        <section className="flex flex-col gap-2">
          <h3 className="text-sm font-semibold">{T.pasteHeading}</h3>
          <label className="flex flex-col gap-1">
            <span className="text-sm text-muted-foreground">{T.pasteLabel}</span>
            <textarea
              aria-label={T.pasteLabel}
              placeholder={T.pastePlaceholder}
              className={FIELD_CLASS}
              rows={4}
              value={rawText}
              disabled={analyzing}
              onChange={(e) => setRawText(e.target.value)}
            />
          </label>
          <div className="flex items-center gap-2">
            <Button size="sm" disabled={analyzing || "" === rawText.trim()} onClick={doAnalyze}>
              {analyzing ? <Spinner className="size-4" /> : null}
              {T.analyze}
            </Button>
            {analyzing ? (
              <span role="status" className="text-xs text-muted-foreground">
                {T.analyzing}
              </span>
            ) : null}
          </div>
        </section>

        {/* Temps 3 — l'aperçu cochable (verdicts du serveur, affichés verbatim). */}
        {showPreview ? (
          <section className="flex flex-col gap-3" role="status">
            <div className="flex flex-col gap-1">
              <h3 className="text-sm font-semibold">{T.previewHeading}</h3>
              <p className="text-sm text-muted-foreground">{T.previewHelp}</p>
            </div>
            {venuesToCreate ? <NoticeBanner tone="warning" role="status" message={T.venuesCreatedWithoutAddress} /> : null}
            {SECTION_ORDER.map((section) => {
              const lines = analysis.sections[section] ?? [];
              if (0 === lines.length) {
                return null;
              }
              return (
                <div key={section} className="flex flex-col gap-1">
                  <h4 className="text-xs font-semibold text-muted-foreground uppercase">{T.sectionTitle(section)}</h4>
                  <ul className="flex flex-col gap-1">
                    {lines.map((line) => (
                      <li key={line.index} className="rounded px-1 py-1">
                        <label className="flex items-start gap-2 text-sm">
                          <input
                            type="checkbox"
                            aria-label={line.libelle}
                            className="mt-1 size-4 shrink-0"
                            checked={checked[keyOf(section, line.index)] ?? false}
                            onChange={() => toggle(section, line.index)}
                          />
                          <span className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                            <StatusPill variant={STATUS_VARIANT[line.statut]}>{T.statusLabel(line.statut)}</StatusPill>
                            <span>{line.libelle}</span>
                            {undefined === line.miseAJour ? null : (
                              <span className="text-xs text-muted-foreground">{T.sessionsUpdate(line.miseAJour.de, line.miseAJour.a)}</span>
                            )}
                          </span>
                        </label>
                        {null === line.raison ? null : <p className="mt-0.5 pl-6 text-xs text-muted-foreground">{line.raison}</p>}
                        {"creneaux" === section ? <SlotEditor line={line} draft={slotDrafts[line.index]} onChange={(patch) => updateSlot(line.index, patch)} /> : null}
                      </li>
                    ))}
                  </ul>
                </div>
              );
            })}
          </section>
        ) : null}

        {/* Temps 4 — le récap de l'import réel (contrat PR2, provisoire). */}
        {null === result ? null : (
          <section role="status" className="flex flex-col gap-2 text-sm">
            <p className="font-medium">{result.message ?? T.importDone}</p>
            {undefined === result.recap ? null : (
              <ul className="flex flex-col gap-1 text-xs text-muted-foreground">
                {Object.entries(result.recap).map(([section, r]) => (
                  <li key={section}>
                    {T.sectionTitle(section)} — {T.sectionRecap(r.crees, r.apparies, r.misAJour, r.ignores)}
                  </li>
                ))}
              </ul>
            )}
          </section>
        )}
      </div>
    </Modal>
  );
}

/** Correction sur place des champs simples d'un créneau À CRÉER — jour / heure / durée. */
function SlotEditor({ line, draft, onChange }: { line: ParcAnalysisLine; draft: SlotDraft | undefined; onChange: (patch: Partial<SlotDraft>) => void }) {
  if (undefined === draft) {
    return null;
  }
  return (
    <div className="mt-1 flex flex-wrap items-center gap-2 pl-6">
      <Select
        aria-label={T.editDay(line.libelle)}
        wrapperClassName="w-32"
        value={draft.jour}
        onChange={(e) => onChange({ jour: Number(e.target.value) })}
      >
        {DAYS.map((d) => (
          <option key={d.n} value={d.n}>
            {dayLabelLongCap(d.n)}
          </option>
        ))}
      </Select>
      <div className="w-28">
        <Input type="time" aria-label={T.editStart(line.libelle)} value={draft.debut} onChange={(e) => onChange({ debut: e.target.value })} />
      </div>
      <div className="w-24">
        <Input type="number" min={1} aria-label={T.editDuration(line.libelle)} value={draft.duree} onChange={(e) => onChange({ duree: Number(e.target.value) })} />
      </div>
    </div>
  );
}
