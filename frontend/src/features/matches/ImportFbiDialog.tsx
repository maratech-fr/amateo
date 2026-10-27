import { Inbox } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { FilePicker } from "@/shared/components/ui/file-picker";
import { Listbox, type ListboxOption } from "@/shared/components/ui/listbox";
import { Modal } from "@/shared/components/ui/modal";
import { snapshotFile } from "@/shared/lib/fileSnapshot";
import { TabPanel, Tabs } from "@/shared/components/ui/tabs";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { toast } from "@/shared/stores/toastStore";

import type { FbiMapping, ImportAnalysisDivision, ImportFbiAnalysis, ImportFbiResult, ImportVenueChoice, ImportVenueMapping, PriorityTier, Team } from "./api";
import { classifyDivision, type DivisionFamily, FAMILY_LABEL, FAMILY_ORDER } from "./lib/divisionFamily";
import { useAnalyzeFbiFixtures, useImportFbiFixtures } from "./queries";
import { MATCHES_TEXTS as M } from "./texts";

/** Terrain neutre (« pas chez nous ») — la sentinelle `venueId` de l'appariement neutre. */
const NEUTRAL = "NEUTRAL";

interface ImportFbiDialogProps {
  teams: Team[];
  tiers: PriorityTier[];
  /** Les gymnases du club — cibles de l'appariement « salle à domicile → gymnase ». */
  venues?: { id: string; name: string }[];
  onClose: () => void;
}

/** One mapping row of the analysis table is keyed division + FBI club-team
 * label (the label only exists when two club teams share a division). */
const divisionKey = (d: { name: string; fbiTeamLabel: string | null }): string => `${d.name}|${d.fbiTeamLabel ?? ""}`;

/**
 * One-pass FBI import (cadrage P1-4, décision fondateur 2026-08-02) : choose
 * the CLUB-WIDE export → the file is analyzed (dry-run) into a mapping table
 * pre-filled from the persisted Division↔team mappings → the manager completes
 * the new ones → « Importer » sends file + mappings in a single request.
 * Stays open afterwards to show the report (created / updated / warnings),
 * which is the point of the feedback.
 *
 * Chantier A PR 2 — « pas de salles appariées, pas d'import » : la section « Salles
 * à domicile » relie chaque salle du fichier à un gymnase du club (ou la déclare
 * terrain neutre), et « Importer » est grisé (avec sa raison) tant qu'un libellé
 * reste sans gymnase. L'import envoie `venueMappings` + `venueChoices`.
 */
export function ImportFbiDialog({ teams, tiers, venues = [], onClose }: ImportFbiDialogProps) {
  const analyzeFbi = useAnalyzeFbiFixtures();
  const importFbi = useImportFbiFixtures();
  const navigate = useNavigate();
  const [file, setFile] = useState<File | null>(null);
  const [analysis, setAnalysis] = useState<ImportFbiAnalysis | null>(null);
  const [choices, setChoices] = useState<Record<string, string>>({});
  // Chantier A PR 2 — le gymnase choisi par libellé à domicile et par division sans salle
  // (uuid, ou la sentinelle NEUTRAL). Pré-remplis à l'analyse (suggestion / confirmé / neutre).
  const [venueChoices, setVenueChoices] = useState<Record<string, string>>({});
  const [homeChoices, setHomeChoices] = useState<Record<string, string>>({});
  const [report, setReport] = useState<ImportFbiResult | null>(null);
  // Onglet de famille actif — state SÉPARÉ, jamais réinitialisé par `onFileChange`
  // (un re-dépôt corrigeant une seule ligne doit garder le gestionnaire sur son
  // onglet). Réconcilié au succès de chaque (ré-)analyse : conservé si la famille
  // existe encore, sinon repli sur la première non vide de `FAMILY_ORDER`.
  const [activeFamily, setActiveFamily] = useState<DivisionFamily | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);

  const teamName = (id: string | null): string => teams.find((t) => t.id === id)?.name ?? "?";

  // A suggestion is usable ONLY when its team is offerable by the select —
  // otherwise the select would render blank while the submit sent an invisible
  // value (« what the select displays is what gets imported »).
  const usableSuggestion = (d: { suggestedTeamId: string | null }): string | null =>
    null !== d.suggestedTeamId && teams.some((t) => t.id === d.suggestedTeamId) ? d.suggestedTeamId : null;

  // Apparié = division à sélection EFFECTIVE, même sémantique que `buildMappings` :
  // teamId persisté, OU choix du gestionnaire, OU suggestion FFBB affichée non touchée.
  const isPaired = (d: ImportAnalysisDivision): boolean => null !== d.teamId || "" !== (choices[divisionKey(d)] ?? usableSuggestion(d) ?? "");

  // Les familles présentes, dans l'ordre d'affichage, familles vides écartées.
  const presentFamilies = (divisions: ImportAnalysisDivision[]): DivisionFamily[] => FAMILY_ORDER.filter((family) => divisions.some((d) => classifyDivision(d.name) === family));
  // Dans un onglet, les divisions se lisent par ordre alphabétique NATUREL (DFU9 avant
  // DFU11), jamais dans l'ordre d'apparition du fichier (demande fondateur, 2026-09-12).
  const sortedByName = (divisions: ImportAnalysisDivision[]): ImportAnalysisDivision[] => [...divisions].sort((a, b) => a.name.localeCompare(b.name, "fr", { numeric: true, sensitivity: "base" }));

  const applyAnalysis = (a: ImportFbiAnalysis): void => {
    setAnalysis(a);
    const families = presentFamilies(a.divisions);
    setActiveFamily((current) => (null !== current && families.includes(current) ? current : (families[0] ?? null)));
    // Pré-remplissage des salles : un libellé déjà neutre → neutre ; sinon le gymnase confirmé
    // (alias) ou la suggestion floue ; rien à défaut (le gestionnaire choisit). Les domiciles
    // sans salle n'ont pas de suggestion (vide) — il faut un choix explicite.
    const vc: Record<string, string> = {};
    for (const row of a.venues ?? []) {
      vc[row.label] = row.neutral ? NEUTRAL : (row.confirmedVenueId ?? row.suggestedVenueId ?? "");
    }
    setVenueChoices(vc);
    const hc: Record<string, string> = {};
    for (const home of a.homesWithoutVenue ?? []) {
      hc[home.division] = "";
    }
    setHomeChoices(hc);
  };

  // On fige un SNAPSHOT mémoire du fichier à la sélection et on l'envoie aux deux appels
  // (analyse puis import) : sans lui, un export rouvert/ré-enregistré dans Excel entre les
  // deux fait rejeter le second fetch par Chrome (`ERR_UPLOAD_FILE_CHANGED`), traduit à
  // tort en « Problème de connexion ». Le pourquoi complet vit dans `snapshotFile` (maison
  // unique, `shared/lib/fileSnapshot.ts`).
  const onFileChange = async (next: File | null): Promise<void> => {
    setAnalysis(null);
    setChoices({});
    setVenueChoices({});
    setHomeChoices({});
    setReport(null);
    if (null === next) {
      setFile(null);
      return;
    }
    let snapshot: File;
    try {
      snapshot = await snapshotFile(next);
    } catch {
      setFile(null);
      toast.error(M.importFbiReadError);
      return;
    }
    setFile(snapshot);
    analyzeFbi.mutate(snapshot, { onSuccess: applyAnalysis });
  };

  // Only the NEW choices ride along — already-resolved divisions are persisted.
  // A FFBB suggestion left untouched IS the choice shown on screen (F2, 6.3):
  // what the select displays is what gets imported. When the suggestion is kept,
  // its competitionId rides along so the PAIRED competition is reused server-side
  // (refs, expectation, poule) instead of duplicated.
  const buildMappings = (a: ImportFbiAnalysis): FbiMapping[] =>
    a.divisions
      .filter((d) => null === d.teamId)
      .flatMap((d) => {
        const suggestion = usableSuggestion(d);
        const teamId = choices[divisionKey(d)] ?? suggestion ?? "";
        if ("" === teamId) {
          return [];
        }
        const competitionId = teamId === suggestion ? d.suggestedCompetitionId : null;
        return [{ division: d.name, fbiTeamLabel: d.fbiTeamLabel, teamId, competitionId }];
      });

  const venueRows = analysis?.venues ?? [];
  const homeRows = analysis?.homesWithoutVenue ?? [];

  // L'appariement de salles envoyé à l'import : un libellé/division avec un choix (gymnase
  // ou NEUTRAL) part ; un choix vide est omis (et bloque « Importer » via `venuesIncomplete`).
  const buildVenueMappings = (): ImportVenueMapping[] =>
    venueRows.flatMap((row) => {
      const v = venueChoices[row.label] ?? "";
      return "" === v ? [] : [{ label: row.label, venueId: v }];
    });
  const buildVenueChoices = (): ImportVenueChoice[] =>
    homeRows.flatMap((home) => {
      const v = homeChoices[home.division] ?? "";
      return "" === v ? [] : [{ division: home.division, venueId: v }];
    });

  // « pas de salles appariées, pas d'import » : tant qu'un libellé à domicile (ou un domicile
  // sans salle) n'a pas de gymnase (ni terrain neutre), « Importer » est grisé avec sa raison.
  const venuesIncomplete = venueRows.some((row) => "" === (venueChoices[row.label] ?? "")) || homeRows.some((home) => "" === (homeChoices[home.division] ?? ""));

  // L'import part avec le fichier, les mappings et la couverture de salles.
  const doImport = (): void => {
    if (null === file || null === analysis) {
      return;
    }
    importFbi.mutate({ file, mappings: buildMappings(analysis), venueMappings: buildVenueMappings(), venueChoices: buildVenueChoices() }, { onSuccess: setReport });
  };

  // Les divisions SANS sélection effective : elles ne seront pas importées
  // (`FbiFixtureImporter` ignore les lignes non mappées — ni créées ni erreurs).
  const unpairedDivisions = null !== analysis ? analysis.divisions.filter((d) => !isPaired(d)) : [];

  const submit = (): void => {
    if (null === file || null === analysis) {
      return;
    }
    // Bouton actif, mais des divisions sans équipe déclenchent une confirmation
    // qui les NOMME (leurs rencontres resteront à associer au prochain dépôt).
    if (unpairedDivisions.length > 0) {
      setConfirmOpen(true);
      return;
    }
    doImport();
  };

  const divisionLabel = (d: { name: string; fbiTeamLabel: string | null }): string => `${d.name}${null !== d.fbiTeamLabel ? ` (${d.fbiTeamLabel})` : ""}`;

  // « A, B et C » — énumération française (virgules puis « et » avant le dernier).
  const frenchEnum = (items: string[]): string => (items.length <= 1 ? (items[0] ?? "") : `${items.slice(0, -1).join(", ")} et ${items[items.length - 1]}`);

  const canImport = null !== file && null !== analysis && !importFbi.isPending && !analyzeFbi.isPending;
  const families = null !== analysis ? presentFamilies(analysis.divisions) : [];
  const currentFamily = null !== activeFamily && families.includes(activeFamily) ? activeFamily : (families[0] ?? null);

  // Les options d'un sélecteur de salle : les gymnases du club + « terrain neutre ».
  const venueOptions: ListboxOption[] = [...venues.map((v): ListboxOption => ({ value: v.id, label: v.name })), { value: NEUTRAL, label: M.importFbiNeutralGround }];

  // Mapper à l'aveugle crée des matchs sur la MAUVAISE équipe (§6bis B3) : le nom de
  // division + son `fbiTeamLabel` (présent SEULEMENT quand deux équipes du club partagent
  // la division) ne sont pas tronqués — ils s'enroulent, avec un `title` de secours. La
  // valeur du select (§6bis B4) se lit sans l'ouvrir.
  const renderDivisionRow = (division: ImportAnalysisDivision) => {
    const rowTitle = `${divisionLabel(division)} · ${M.importFbiRowMatchCount(division.rowCount)}`;
    const selected = choices[divisionKey(division)] ?? usableSuggestion(division) ?? "";
    return (
      // M2 (retours Nico, BCCL 2026-10-27) : surligner la ligne quand SON sélecteur a le focus
      // (`focus-within`) pour lier visuellement le championnat et l'équipe choisie — teinte de
      // surbrillance seulement (préfixée, le repos reste sur la surface opaque du conteneur).
      <li key={divisionKey(division)} className="flex items-center justify-between gap-2 rounded -mx-1 px-1 py-0.5 focus-within:bg-accent/10">
        <span className="min-w-0 flex-1" title={rowTitle}>
          {division.name}
          {null !== division.fbiTeamLabel ? <span className="text-muted-foreground"> ({division.fbiTeamLabel})</span> : null}
          <span className="text-muted-foreground"> · {M.importFbiRowMatchCount(division.rowCount)}</span>
        </span>
        {null !== division.teamId ? (
          <span className="shrink-0 text-xs text-muted-foreground">→ {teamName(division.teamId)}</span>
        ) : (
          <span className="flex shrink-0 flex-col items-end gap-0.5">
            <TeamSelect
              aria-label={M.importFbiTeamFor(divisionLabel(division))}
              title={"" !== selected ? teamName(selected) : M.importFbiAssociateTo}
              wrapperClassName="w-52 shrink-0"
              teams={teams}
              tiers={tiers}
              placeholder={M.importFbiAssociateTo}
              value={selected}
              onValueChange={(v) => setChoices((prev) => ({ ...prev, [divisionKey(division)]: v }))}
            />
            {null !== usableSuggestion(division) && undefined === choices[divisionKey(division)] ? (
              <span className="rounded bg-muted px-1 text-xs uppercase tracking-wide text-muted-foreground">{M.importFbiSuggestedByFfbb}</span>
            ) : null}
          </span>
        )}
      </li>
    );
  };

  return (
    <>
      <Modal
        label={M.importFbiModalLabel}
        title={M.importFbiTitle}
        onClose={onClose}
        size="xl"
        footer={
          <>
            <Button variant="outline" size="sm" onClick={onClose}>
              {M.importFbiClose}
            </Button>
            {null === report ? (
              <Button
                size="sm"
                disabled={!canImport || venuesIncomplete}
                disabledReason={canImport && venuesIncomplete ? M.importFbiVenueMissingReason : undefined}
                onClick={submit}
              >
                {M.importFbiImport}
              </Button>
            ) : null}
          </>
        }
      >
        <div className="flex flex-col gap-3">
          <p className="text-xs text-muted-foreground">{M.importFbiIntro}</p>

          <FilePicker label={M.importFbiFileAria} accept=".xlsx" onFileChange={(f) => void onFileChange(f)} />

          {analyzeFbi.isPending ? <p className="text-xs text-muted-foreground">{M.importFbiAnalyzing}</p> : null}

          {null !== analysis && null === report ? (
            <div className="flex flex-col gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
              <p className="text-xs text-muted-foreground">{M.importFbiAnalysisSummary(analysis.totalRows, analysis.divisions.length, analysis.exempted)}</p>
              {/* 50 divisions dans une seule liste = illisible (mesure terrain). On les range
                  en onglets par famille ; le compteur du libellé (appariées/total) bouge en
                  direct. La Modale (`size=xl`) est l'UNIQUE zone défilante — plus de scroll
                  imbriqué qui rognait le panneau du TeamSelect. Un onglet entièrement apparié
                  reste rendu. Les totaux et les diagnostics ci-dessous restent GLOBAUX. */}
              {families.length > 0 ? (
                <Tabs
                  tabs={families.map((family) => ({
                    id: family,
                    label: `${FAMILY_LABEL[family]} (${analysis.divisions.filter((d) => classifyDivision(d.name) === family && isPaired(d)).length}/${analysis.divisions.filter((d) => classifyDivision(d.name) === family).length})`,
                  }))}
                  activeTab={currentFamily ?? ""}
                  onTabChange={(id) => setActiveFamily(id as DivisionFamily)}
                  ariaLabel={M.importFbiFamiliesAria}
                  idPrefix="fbi-family"
                />
              ) : null}
              {families.map((family) => (
                <TabPanel key={family} tabId={family} idPrefix="fbi-family" active={family === currentFamily} className="pt-1">
                  <ul className="flex flex-col gap-1">{sortedByName(analysis.divisions.filter((d) => classifyDivision(d.name) === family)).map(renderDivisionRow)}</ul>
                </TabPanel>
              ))}

              {/* Chantier A PR 2 — « Salles à domicile » : chaque salle du fichier → un gymnase
                  du club, ou terrain neutre. « Importer » reste grisé tant qu'un libellé manque. */}
              {venueRows.length > 0 || homeRows.length > 0 ? (
                <div className="flex flex-col gap-1.5 border-t border-border pt-2">
                  <p className="text-sm font-medium">{M.importFbiVenuesTitle}</p>
                  <p className="text-xs text-muted-foreground">{M.importFbiVenuesIntro}</p>
                  {/* Raison du blocage EN CLAIR à côté (doctrine disabledReason, A11Y-30) : le
                      compte des salles restantes rend la portée du blocage découvrable sans
                      survoler « Importer » ni parcourir chaque ligne. */}
                  {venuesIncomplete ? <p className="text-xs text-warning">{M.importFbiVenuesRemaining(venueRows.filter((row) => "" === (venueChoices[row.label] ?? "")).length + homeRows.filter((home) => "" === (homeChoices[home.division] ?? "")).length)}</p> : null}
                  <ul className="flex flex-col gap-1.5">
                    {venueRows.map((row) => (
                      <li key={`v-${row.label}`} className="flex items-center justify-between gap-2">
                        <span className="min-w-0 flex-1 text-sm" title={row.label}>
                          {row.label}
                          <span className="text-muted-foreground"> · {M.importFbiRowMatchCount(row.rowCount)}</span>
                        </span>
                        <Listbox
                          aria-label={M.importFbiVenueFor(row.label)}
                          wrapperClassName="w-56 shrink-0"
                          options={venueOptions}
                          placeholder={M.importFbiChooseVenue}
                          value={venueChoices[row.label] ?? ""}
                          onValueChange={(v) => setVenueChoices((prev) => ({ ...prev, [row.label]: v }))}
                        />
                      </li>
                    ))}
                    {homeRows.map((home) => (
                      <li key={`h-${home.division}`} className="flex items-center justify-between gap-2">
                        <span className="min-w-0 flex-1 text-sm" title={home.division}>
                          {home.division}
                          {null !== home.fbiTeamLabel ? <span className="text-muted-foreground"> ({home.fbiTeamLabel})</span> : null}
                          <span className="text-muted-foreground"> · {M.importFbiHomesWithoutVenueCount(home.rowCount)}</span>
                        </span>
                        <Listbox
                          aria-label={M.importFbiHomesWithoutVenueFor(home.division)}
                          wrapperClassName="w-56 shrink-0"
                          options={venueOptions}
                          placeholder={M.importFbiChooseVenue}
                          value={homeChoices[home.division] ?? ""}
                          onValueChange={(v) => setHomeChoices((prev) => ({ ...prev, [home.division]: v }))}
                        />
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}

              {/* P1-4 PR F2 (6.1) — poule guard verdicts of the dry-run. */}
              {analysis.divisions.some((d) => null !== d.pouleError || d.pouleUnknownOpponents.length > 0) ? (
                // A11Y-26 — une liste QUI DÉFILE (max-h + overflow) doit être atteignable au clavier (WCAG 2.1.1).
                // eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex -- région défilante nommée, non-interactive mais focusable
                <ul aria-label={M.importFbiPouleCheckAria} tabIndex={0} className="max-h-32 flex-col gap-0.5 overflow-y-auto text-xs">
                  {analysis.divisions
                    .filter((d) => null !== d.pouleError)
                    .map((d) => (
                      <li key={`pe-${divisionKey(d)}`} className="text-destructive">
                        {d.pouleError}
                      </li>
                    ))}
                  {analysis.divisions
                    .filter((d) => null === d.pouleError && d.pouleUnknownOpponents.length > 0)
                    .map((d) => (
                      <li key={`pw-${divisionKey(d)}`} className="text-warning">
                        {M.importFbiOutOfPoule(d.name, d.pouleUnknownOpponents.join(", "))}
                      </li>
                    ))}
                </ul>
              ) : null}
              {analysis.errors.length > 0 ? (
                // eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex -- liste défilante nommée, atteignable au clavier (WCAG 2.1.1, A11Y-26)
                <ul aria-label={M.importFbiAnalysisErrorsAria} tabIndex={0} className="max-h-32 list-inside list-disc overflow-y-auto text-xs text-destructive">
                  {analysis.errors.map((error, i) => (
                    <li key={i}>{error}</li>
                  ))}
                </ul>
              ) : null}
            </div>
          ) : null}

          {null !== report ? (
            <div className="flex flex-col gap-1 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
              <p className="font-medium">{M.importFbiReportSummary(report.created, report.updated, report.unchanged, report.exempted)}</p>
              {report.warnings.length > 0 ? (
                // eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex -- liste défilante nommée, atteignable au clavier (WCAG 2.1.1, A11Y-26)
                <ul aria-label={M.importFbiWarningsAria} tabIndex={0} className="max-h-40 list-inside list-disc overflow-y-auto text-xs text-warning">
                  {report.warnings.map((warning, i) => (
                    <li key={i}>{warning.message}</li>
                  ))}
                </ul>
              ) : null}
              {report.completeness.length > 0 ? (
                <ul className="text-xs text-muted-foreground">
                  {report.completeness.map((c) => (
                    <li key={c.competitionId}>{M.importFbiCompleteness(c.name, c.imported, c.expected)}</li>
                  ))}
                </ul>
              ) : null}
              {report.unmappedDivisions.length > 0 ? (
                <p className="text-xs text-muted-foreground">{M.importFbiUnmapped(report.unmappedDivisions.map((d) => `${d.name}${null !== d.fbiTeamLabel ? ` (${d.fbiTeamLabel})` : ""} (${d.rowCount})`).join(", "))}</p>
              ) : null}
              {report.errors.length > 0 ? (
                // eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex -- liste défilante nommée, atteignable au clavier (WCAG 2.1.1, A11Y-26)
                <ul aria-label={M.importFbiReportErrorsAria} tabIndex={0} className="max-h-40 list-inside list-disc overflow-y-auto text-xs text-destructive">
                  {report.errors.map((error, i) => (
                    <li key={i}>{error}</li>
                  ))}
                </ul>
              ) : null}

              {/* PR-3b (D2) — les écarts domicile ≠ fichier sont CONSIGNÉS sur les
                  rencontres (OUT_OF_SYNC) : on les compte et on ouvre la file. */}
              {report.unresolvedDeviations.length > 0 ? (
                <div className="mt-1 flex flex-col items-start gap-1 border-t border-border pt-2">
                  <p className="text-xs text-muted-foreground">{M.importFbiDeviations(report.unresolvedDeviations.length)}</p>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                      onClose();
                      void navigate("/matchs/importer");
                    }}
                  >
                    <Inbox className="size-4" />
                    {M.importFbiOpenQueue}
                  </Button>
                </div>
              ) : null}

              {/* Le placement automatique (solveur) ne se propose PAS ici : c'est un geste
                  de l'onglet Semaine (« Placer automatiquement »), pas une suite de l'import
                  (retour fondateur 2026-09-13 : « il ne fait pas sens pour moi »). */}
            </div>
          ) : null}
        </div>
      </Modal>

      {/* Bouton Importer TOUJOURS actif (hors couverture de salles) : des divisions sans équipe
          ne bloquent pas, elles se confirment. Leurs rencontres ne seront « ni créées ni erreurs »
          côté serveur (`FbiFixtureImporter`) — la confirmation le dit et les NOMME.
          `destructive={false}` : rien n'est détruit, on choisit juste de laisser des lignes de côté. */}
      <ConfirmDialog
        open={confirmOpen}
        title={M.importFbiUnmappedTitle}
        description={M.importFbiUnmappedConfirm(frenchEnum(unpairedDivisions.map(divisionLabel)))}
        confirmLabel={M.importFbiImportAnyway}
        destructive={false}
        onConfirm={() => {
          setConfirmOpen(false);
          doImport();
        }}
        onCancel={() => setConfirmOpen(false)}
      />
    </>
  );
}
