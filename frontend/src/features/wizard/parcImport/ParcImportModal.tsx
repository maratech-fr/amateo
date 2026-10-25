import { useMemo, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { FIELD_CLASS } from "@/shared/components/ui/field";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Select } from "@/shared/components/ui/select";
import { Spinner } from "@/shared/components/ui/spinner";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { VenueSelect } from "@/shared/components/ui/venue-select";
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

/** L'ordre d'affichage des sections de l'aperçu (fixe, indépendant de l'ordre des clés serveur). */
const SECTION_ORDER = ["equipes", "gymnases", "creneaux", "mutualisations", "reservations"] as const;

/** Les sections d'ENTITÉS : une ligne apparie/suggere/aCreer y porte un sélecteur de ré-appariement (D5). */
const REMATCH_SECTIONS = new Set(["equipes", "gymnases"]);

/** Un statut dont la ligne est cochée par défaut — seul cas qui porte un sélecteur de ré-appariement. */
const isRematchStatus = (statut: ParcLineStatus): boolean => "apparie" === statut || "suggere" === statut || "aCreer" === statut;

/** La teinte de la pastille par statut — PRÉSENTATION du verdict serveur (le texte le porte aussi). */
const STATUS_VARIANT: Record<ParcLineStatus, "success" | "accent" | "warning" | "neutral"> = {
  apparie: "success",
  suggere: "accent",
  aCreer: "accent",
  decoche: "neutral",
  alerte: "warning",
};

/** La forme (partielle) du parc collé — on ne lit que ce qui amorce une correction sur place. */
interface ParsedCase {
  jour?: unknown;
  debut?: unknown;
  duree?: unknown;
}
interface ParsedParc {
  /** parc-v2 porte des CASES (plus de section `creneaux`) : le brouillon du créneau dérivé s'y amorce. */
  cases?: ParsedCase[];
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
  const { clubId, teams, venues, club, onClose } = props;
  const analyze = useAnalyzeParc(clubId);
  const importMut = useImportParc(clubId);

  // Le prompt ne lit que les NOMS (+ le contexte club) ; les id des entités servent au ré-appariement (D5).
  const prompt = useMemo(
    () => buildParcPrompt({ teams: teams.map((t) => t.name), venues: venues.map((v) => v.name), club }),
    [teams, venues, club],
  );

  const [copied, setCopied] = useState(false);
  const [rawText, setRawText] = useState("");
  const [analysis, setAnalysis] = useState<ParcAnalysis | null>(null);
  // Le parc NORMALISÉ renvoyé par l'analyse (relu par `ParcPayloadValidator::parse`, G2 D4) : il
  // amorce les corrections sur place et repart tel quel comme `payload` à l'import (le serveur
  // réécrit depuis la source, pas l'aperçu) — jamais le texte brut collé re-parsé côté front.
  const [parc, setParc] = useState<unknown>(null);
  const [checked, setChecked] = useState<Record<string, boolean>>({});
  const [slotDrafts, setSlotDrafts] = useState<Record<number, SlotDraft>>({});
  // Ré-appariement par ligne (D5) — l'id choisi pour une ligne équipe/gymnase. "" = « Créer »
  // (aucun cibleId envoyé). Amorcé depuis `line.cibleId` du verdict serveur.
  const [rematch, setRematch] = useState<Record<string, string>>({});
  // D12 — le gymnase choisi pour une CASE (ligne créneau), indexé par `line.index`. "" = aucun →
  // le serveur résout par le nom du parc. Amorcé depuis `line.cibleId`.
  const [slotVenue, setSlotVenue] = useState<Record<number, string>>({});
  // D8 — le libellé du groupe mutualisé, corrigeable, indexé par `line.index`. Amorcé depuis `line.groupe`.
  const [groupDrafts, setGroupDrafts] = useState<Record<number, string>>({});
  const [result, setResult] = useState<ParcImportResult | null>(null);

  const analyzing = analyze.isPending;
  const importing = importMut.isPending;

  const copyPrompt = (): void => {
    void navigator.clipboard.writeText(prompt).then(() => setCopied(true));
  };

  const applyAnalysis = (data: ParcAnalysis): void => {
    setAnalysis(data);
    setResult(null);

    const nextChecked: Record<string, boolean> = {};
    const nextRematch: Record<string, string> = {};
    const nextSlotVenue: Record<number, string> = {};
    const nextGroupDrafts: Record<number, string> = {};
    for (const section of Object.keys(data.sections)) {
      for (const line of data.sections[section] ?? []) {
        nextChecked[keyOf(section, line.index)] = line.coche;
        if (REMATCH_SECTIONS.has(section) && isRematchStatus(line.statut)) {
          nextRematch[keyOf(section, line.index)] = line.cibleId ?? "";
        }
        if ("creneaux" === section && isRematchStatus(line.statut)) {
          nextSlotVenue[line.index] = line.cibleId ?? "";
        }
        if ("mutualisations" === section && isRematchStatus(line.statut)) {
          nextGroupDrafts[line.index] = line.groupe ?? "";
        }
      }
    }
    setChecked(nextChecked);
    setRematch(nextRematch);
    setSlotVenue(nextSlotVenue);
    setGroupDrafts(nextGroupDrafts);

    // Amorce des champs éditables des CRÉNEAUX depuis les CASES du parc NORMALISÉ que l'analyse
    // renvoie (relu par `ParcPayloadValidator::parse()`, G2 D4) — jamais le texte brut collé, qui peut
    // n'être lisible QUE par la passe de récupération serveur (guillemets typographiques, bloc ```json).
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
    analyze.mutate(rawText, { onSuccess: applyAnalysis });
  };

  const toggle = (section: string, index: number): void =>
    setChecked((prev) => ({ ...prev, [keyOf(section, index)]: !prev[keyOf(section, index)] }));

  const updateSlot = (index: number, patch: Partial<SlotDraft>): void =>
    setSlotDrafts((prev) => ({ ...prev, [index]: { ...prev[index], ...patch } }));

  const updateRematch = (section: string, index: number, value: string): void =>
    setRematch((prev) => ({ ...prev, [keyOf(section, index)]: value }));

  const updateSlotVenue = (index: number, value: string): void => setSlotVenue((prev) => ({ ...prev, [index]: value }));

  const updateGroup = (index: number, value: string): void => setGroupDrafts((prev) => ({ ...prev, [index]: value }));

  const count = Object.values(checked).filter(Boolean).length;

  const venuesToCreate = (analysis?.sections.gymnases ?? []).some((line) => "aCreer" === line.statut);

  const doImport = (): void => {
    if (null === analysis) {
      return;
    }
    const decisions: ParcImportDecision[] = [];
    for (const section of Object.keys(analysis.sections)) {
      for (const line of analysis.sections[section] ?? []) {
        const key = keyOf(section, line.index);
        const hasRematch = REMATCH_SECTIONS.has(section) && isRematchStatus(line.statut);
        // D12 — une case (créneau) porte son propre sélecteur de gymnase ; son choix pilote le cibleId.
        const hasSlotVenue = "creneaux" === section && isRematchStatus(line.statut);
        // Cible choisie ("" = « Créer » / aucun → le serveur résout par le nom) ; sinon le verdict serveur.
        const chosen = hasRematch ? rematch[key] : hasSlotVenue ? slotVenue[line.index] : undefined;
        const cibleId = hasRematch || hasSlotVenue ? ("" === chosen || undefined === chosen ? undefined : chosen) : line.cibleId;
        const draft = "creneaux" === section ? slotDrafts[line.index] : undefined;
        // D8 — le libellé du groupe mutualisé CORRIGÉ voyage dans `corrections.groupe` (le serveur
        // l'honore comme label du bloc, prioritaire sur le groupe brut de la case).
        const groupe = "mutualisations" === section && isRematchStatus(line.statut) ? (groupDrafts[line.index] ?? "").trim() : "";
        const corrections =
          undefined !== draft
            ? { jour: draft.jour, debut: draft.debut, duree: draft.duree }
            : "" !== groupe
              ? { groupe }
              : undefined;
        decisions.push({
          section,
          index: line.index,
          coche: checked[key] ?? false,
          cibleId,
          corrections,
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
            {/* D10 — fiabilité peu sûre : on prévient, on ne bloque JAMAIS l'import. */}
            {analysis.fiabilite.bandeau ? <NoticeBanner tone="warning" role="status" message={T.unreliableResult} /> : null}
            {/* D9 — alertes transverses : les messages sont des DONNÉES serveur, affichés verbatim. */}
            {0 === analysis.alertes.length ? null : (
              <NoticeBanner tone="warning" role="status">
                <p className="font-medium">{T.alertsHeading}</p>
                <ul className="flex list-disc flex-col gap-1 pl-5">
                  {analysis.alertes.map((alerte, i) => (
                    <li key={`${alerte.type}:${i}`}>{alerte.message}</li>
                  ))}
                </ul>
              </NoticeBanner>
            )}
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
                        {REMATCH_SECTIONS.has(section) && isRematchStatus(line.statut) ? (
                          <RematchRow
                            section={section}
                            line={line}
                            value={rematch[keyOf(section, line.index)] ?? ""}
                            teams={teams}
                            venues={venues}
                            onChange={(v) => updateRematch(section, line.index, v)}
                          />
                        ) : null}
                        {"creneaux" === section && isRematchStatus(line.statut) ? (
                          <VenueSelect
                            aria-label={T.venueForSlot(line.libelle)}
                            wrapperClassName="mt-1 ml-6 w-full max-w-xs"
                            placeholder={T.rematchCreate}
                            value={slotVenue[line.index] ?? ""}
                            onValueChange={(v) => updateSlotVenue(line.index, v)}
                            venues={venues.map((v) => ({ id: v.id, name: v.name, color: null }))}
                          />
                        ) : null}
                        {"creneaux" === section ? <SlotEditor line={line} draft={slotDrafts[line.index]} onChange={(patch) => updateSlot(line.index, patch)} /> : null}
                        {"mutualisations" === section && isRematchStatus(line.statut) ? (
                          <div className="mt-1 w-full max-w-xs pl-6">
                            <Input
                              aria-label={T.groupLabel(line.libelle)}
                              placeholder={T.groupLabelPlaceholder}
                              value={groupDrafts[line.index] ?? ""}
                              onChange={(e) => updateGroup(line.index, e.target.value)}
                            />
                          </div>
                        ) : null}
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

/**
 * Ré-appariement d'une ligne d'entité (D5) : un sélecteur riche listant les entités EXISTANTES de la
 * section (par nom, valeur = id) plus « Créer » (le placeholder, valeur ""). Équipes → `TeamSelect`,
 * gymnases → `VenueSelect`. Le nom accessible du déclencheur est contextualisé par le libellé de la
 * ligne (A11Y-28). Choisir une entité envoie son id ; « Créer » n'envoie rien.
 */
function RematchRow({
  section,
  line,
  value,
  teams,
  venues,
  onChange,
}: {
  section: string;
  line: ParcAnalysisLine;
  value: string;
  teams: readonly ParcEntity[];
  venues: readonly ParcEntity[];
  onChange: (value: string) => void;
}) {
  const label = T.rematchLabel(line.libelle);
  const common = { value, onValueChange: onChange, placeholder: T.rematchCreate, "aria-label": label, wrapperClassName: "mt-1 ml-6 w-full max-w-xs" } as const;

  if ("equipes" === section) {
    return <TeamSelect {...common} teams={teams.map((t) => ({ id: t.id, name: t.name, priorityTierId: 0, tierOrder: 0 }))} tiers={[]} />;
  }
  return <VenueSelect {...common} venues={venues.map((v) => ({ id: v.id, name: v.name, color: null }))} />;
}
