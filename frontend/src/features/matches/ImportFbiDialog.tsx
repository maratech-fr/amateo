import { Inbox, Wand2 } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Modal } from "@/shared/components/ui/modal";
import { TabPanel, Tabs } from "@/shared/components/ui/tabs";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { useCredits } from "@/shared/credits/useCredits";
import { toast } from "@/shared/stores/toastStore";

import type { FbiMapping, ImportAnalysisDivision, ImportFbiAnalysis, ImportFbiResult, PriorityTier, Team } from "./api";
import { classifyDivision, type DivisionFamily, FAMILY_LABEL, FAMILY_ORDER } from "./lib/divisionFamily";
import { placementToastMessage } from "./lib/placementToast";
import { useAnalyzeFbiFixtures, useImportFbiFixtures, usePlaceMatches } from "./queries";

interface ImportFbiDialogProps {
  teams: Team[];
  tiers: PriorityTier[];
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
 */
export function ImportFbiDialog({ teams, tiers, onClose }: ImportFbiDialogProps) {
  const analyzeFbi = useAnalyzeFbiFixtures();
  const importFbi = useImportFbiFixtures();
  const navigate = useNavigate();
  // RMM-1 PR2 — au rapport RÉUSSI, on propose de placer les matchs importés en UN
  // clic (jamais automatique). Même rail et même gate crédits que le bouton
  // principal de la boucle : solde dans le libellé, grisé à 0 mais JAMAIS masqué
  // (décision fondateur — on voit pourquoi on ne peut pas).
  const credits = useCredits();
  const placeMatches = usePlaceMatches();
  const placeCreditSuffix = null !== credits ? ` (${credits.remaining} crédit${credits.remaining > 1 ? "s" : ""})` : "";
  const placeCreditsBlocked = null !== credits && !credits.canPlaceMatches;
  const [file, setFile] = useState<File | null>(null);
  const [analysis, setAnalysis] = useState<ImportFbiAnalysis | null>(null);
  const [choices, setChoices] = useState<Record<string, string>>({});
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
  };

  // Le `File` d'un <input> n'est qu'un HANDLE : son contenu est RELU sur le disque à
  // CHAQUE envoi. Si l'export est rouvert/ré-enregistré dans Excel entre l'analyse et
  // l'import, Chrome rejette le second fetch (`ERR_UPLOAD_FILE_CHANGED` → `TypeError`),
  // que l'app traduit à tort en « Problème de connexion » (aucune requête n'atteint le
  // serveur). On fige donc un SNAPSHOT mémoire à la sélection et on l'envoie aux deux
  // appels (même nom, même type — multipart et nom de champ inchangés).
  const onFileChange = async (next: File | null): Promise<void> => {
    setAnalysis(null);
    setChoices({});
    setReport(null);
    if (null === next) {
      setFile(null);
      return;
    }
    let snapshot: File;
    try {
      const buffer = await next.arrayBuffer();
      snapshot = new File([buffer], next.name, { type: next.type });
    } catch {
      setFile(null);
      toast.error("Le fichier n'a pas pu être lu — est-il ouvert dans un autre logiciel ?");
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

  // PR-3b — l'import part TOUJOURS sans décisions (`{file, mappings}`, D2). Les
  // écarts domicile ≠ fichier ne se tranchent plus dans un détour : ils sont
  // PERSISTÉS sur les rencontres (état OUT_OF_SYNC) et se traitent dans la file de
  // l'onglet Importer. Le rapport en place les COMPTE et propose d'ouvrir la file.
  const doImport = (): void => {
    if (null === file || null === analysis) {
      return;
    }
    importFbi.mutate({ file, mappings: buildMappings(analysis) }, { onSuccess: setReport });
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

  // Mapper à l'aveugle crée des matchs sur la MAUVAISE équipe (§6bis B3) : le nom de
  // division + son `fbiTeamLabel` (présent SEULEMENT quand deux équipes du club partagent
  // la division) ne sont pas tronqués — ils s'enroulent, avec un `title` de secours. La
  // valeur du select (§6bis B4) se lit sans l'ouvrir.
  const renderDivisionRow = (division: ImportAnalysisDivision) => {
    const rowTitle = `${divisionLabel(division)} · ${division.rowCount} match${division.rowCount > 1 ? "s" : ""}`;
    const selected = choices[divisionKey(division)] ?? usableSuggestion(division) ?? "";
    return (
      <li key={divisionKey(division)} className="flex items-center justify-between gap-2">
        <span className="min-w-0 flex-1" title={rowTitle}>
          {division.name}
          {null !== division.fbiTeamLabel ? <span className="text-muted-foreground"> ({division.fbiTeamLabel})</span> : null}
          <span className="text-muted-foreground">
            {" "}
            · {division.rowCount} match{division.rowCount > 1 ? "s" : ""}
          </span>
        </span>
        {null !== division.teamId ? (
          <span className="shrink-0 text-xs text-muted-foreground">→ {teamName(division.teamId)}</span>
        ) : (
          <span className="flex shrink-0 flex-col items-end gap-0.5">
            <TeamSelect
              aria-label={`Équipe pour ${divisionLabel(division)}`}
              title={"" !== selected ? teamName(selected) : "Associer à…"}
              className="w-52 shrink-0"
              teams={teams}
              tiers={tiers}
              placeholder="Associer à…"
              value={selected}
              onValueChange={(v) => setChoices((prev) => ({ ...prev, [divisionKey(division)]: v }))}
            />
            {null !== usableSuggestion(division) && undefined === choices[divisionKey(division)] ? (
              <span className="rounded bg-muted px-1 text-xs uppercase tracking-wide text-muted-foreground">proposé par la FFBB</span>
            ) : null}
          </span>
        )}
      </li>
    );
  };

  return (
    <>
      <Modal
        label="Importer FBI"
        title="Importer un export FBI"
        onClose={onClose}
        size="xl"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>
            Fermer
          </Button>
          {null === report ? (
            <Button size="sm" disabled={!canImport} onClick={submit}>
              Importer
            </Button>
          ) : null}
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-xs text-muted-foreground">
          L’export FBI global du club (« Saisie des résultats », .xlsx). Chaque division se relie une seule fois à
          l’une de vos équipes ; les matchs connus sont mis à jour, jamais dupliqués.
        </p>

        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Fichier FBI (.xlsx)</span>
          <input
            aria-label="Fichier FBI"
            type="file"
            accept=".xlsx"
            className="text-sm"
            onChange={(e) => void onFileChange(e.target.files?.[0] ?? null)}
          />
        </label>

        {analyzeFbi.isPending ? <p className="text-xs text-muted-foreground">Analyse du fichier…</p> : null}

        {null !== analysis && null === report ? (
          <div className="flex flex-col gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
            <p className="text-xs text-muted-foreground">
              {analysis.totalRows} rencontre{analysis.totalRows > 1 ? "s" : ""} ·{" "}
              {analysis.divisions.length} division{analysis.divisions.length > 1 ? "s" : ""}
              {analysis.exempted > 0 ? ` · ${analysis.exempted} exempt${analysis.exempted > 1 ? "s" : ""}` : ""}
            </p>
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
                ariaLabel="Familles de divisions"
                idPrefix="fbi-family"
              />
            ) : null}
            {families.map((family) => (
              <TabPanel key={family} tabId={family} idPrefix="fbi-family" active={family === currentFamily} className="pt-1">
                <ul className="flex flex-col gap-1">{sortedByName(analysis.divisions.filter((d) => classifyDivision(d.name) === family)).map(renderDivisionRow)}</ul>
              </TabPanel>
            ))}
            {/* P1-4 PR F2 (6.1) — poule guard verdicts of the dry-run. */}
            {analysis.divisions.some((d) => null !== d.pouleError || d.pouleUnknownOpponents.length > 0) ? (
              <ul className="max-h-32 flex-col gap-0.5 overflow-y-auto text-xs">
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
                      {d.name} : hors poule — {d.pouleUnknownOpponents.join(", ")}
                    </li>
                  ))}
              </ul>
            ) : null}
            {analysis.errors.length > 0 ? (
              <ul className="max-h-32 list-inside list-disc overflow-y-auto text-xs text-destructive">
                {analysis.errors.map((error, i) => (
                  <li key={i}>{error}</li>
                ))}
              </ul>
            ) : null}
          </div>
        ) : null}

        {null !== report ? (
          <div className="flex flex-col gap-1 rounded-md border border-border bg-muted/40 px-3 py-2 text-sm">
            <p className="font-medium">
              {report.created} créé{report.created > 1 ? "s" : ""} · {report.updated} mis à jour ·{" "}
              {report.unchanged} inchangé{report.unchanged > 1 ? "s" : ""}
              {report.exempted > 0 ? ` · ${report.exempted} exempt${report.exempted > 1 ? "s" : ""}` : ""}
            </p>
            {report.warnings.length > 0 ? (
              <ul className="max-h-40 list-inside list-disc overflow-y-auto text-xs text-warning">
                {report.warnings.map((warning, i) => (
                  <li key={i}>{warning.message}</li>
                ))}
              </ul>
            ) : null}
            {report.completeness.length > 0 ? (
              <ul className="text-xs text-muted-foreground">
                {report.completeness.map((c) => (
                  <li key={c.competitionId}>
                    {c.name} : {c.imported}/{c.expected} journées — fichier partiel ou phase pas encore sortie.
                  </li>
                ))}
              </ul>
            ) : null}
            {report.unmappedDivisions.length > 0 ? (
              <p className="text-xs text-muted-foreground">
                Non associées :{" "}
                {report.unmappedDivisions
                  .map((d) => `${d.name}${null !== d.fbiTeamLabel ? ` (${d.fbiTeamLabel})` : ""} (${d.rowCount})`)
                  .join(", ")}
              </p>
            ) : null}
            {report.errors.length > 0 ? (
              <ul className="max-h-40 list-inside list-disc overflow-y-auto text-xs text-destructive">
                {report.errors.map((error, i) => (
                  <li key={i}>{error}</li>
                ))}
              </ul>
            ) : null}

            {/* PR-3b (D2) — les écarts domicile ≠ fichier sont CONSIGNÉS sur les
                rencontres (OUT_OF_SYNC) : on les compte et on ouvre la file. */}
            {report.unresolvedDeviations.length > 0 ? (
              <div className="mt-1 flex flex-col items-start gap-1 border-t border-border pt-2">
                <p className="text-xs text-muted-foreground">
                  {report.unresolvedDeviations.length} écart{report.unresolvedDeviations.length > 1 ? "s" : ""} consigné
                  {report.unresolvedDeviations.length > 1 ? "s" : ""} dans Importer — à traiter dans la file.
                </p>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    onClose();
                    void navigate("/matchs/importer");
                  }}
                >
                  <Inbox className="size-4" />
                  Ouvrir la file
                </Button>
              </div>
            ) : null}

            {/* L'enchaînement naturel : les matchs viennent d'arriver UNPLACED,
                on les place dans la foulée — un clic, jamais automatique. */}
            <div className="mt-1 flex flex-col items-end gap-1 border-t border-border pt-2">
              <Button
                size="sm"
                disabled={placeMatches.isPending || placeCreditsBlocked}
                onClick={() =>
                  placeMatches.mutate(undefined, {
                    onSuccess: (result) => {
                      toast.success(placementToastMessage(result));
                      onClose();
                    },
                  })
                }
              >
                <Wand2 className="size-4" />
                {placeMatches.isPending ? "Placement…" : `Placer les matchs importés${placeCreditSuffix}`}
              </Button>
            </div>
          </div>
        ) : null}

      </div>
      </Modal>

      {/* Bouton Importer TOUJOURS actif : des divisions sans équipe ne bloquent pas, elles
          se confirment. Leurs rencontres ne seront « ni créées ni erreurs » côté serveur
          (`FbiFixtureImporter`) — la confirmation le dit et les NOMME. `destructive={false}` :
          rien n'est détruit, on choisit juste de laisser des lignes de côté. */}
      <ConfirmDialog
        open={confirmOpen}
        title="Des divisions restent sans équipe"
        description={`Les rencontres de ${frenchEnum(unpairedDivisions.map(divisionLabel))} ne seront pas importées — elles resteront à associer au prochain dépôt. Importer quand même ?`}
        confirmLabel="Importer quand même"
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
