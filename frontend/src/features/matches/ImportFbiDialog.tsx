import { Wand2 } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { useCredits } from "@/shared/credits/useCredits";
import { toast } from "@/shared/stores/toastStore";

import type { FbiMapping, ImportFbiAnalysis, ImportFbiResult, PriorityTier, Team } from "./api";
import { placementToastMessage } from "./lib/placementToast";
import { useAnalyzeFbiFixtures, useImportFbiFixtures, usePlaceMatches } from "./queries";
import { useMatchesStore } from "./store";

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
  const setReconciliation = useMatchesStore((s) => s.setReconciliation);
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

  const teamName = (id: string | null): string => teams.find((t) => t.id === id)?.name ?? "?";

  // A suggestion is usable ONLY when its team is offerable by the select —
  // otherwise the select would render blank while the submit sent an invisible
  // value (« what the select displays is what gets imported »).
  const usableSuggestion = (d: { suggestedTeamId: string | null }): string | null =>
    null !== d.suggestedTeamId && teams.some((t) => t.id === d.suggestedTeamId) ? d.suggestedTeamId : null;

  const onFileChange = (next: File | null): void => {
    setFile(next);
    setAnalysis(null);
    setChoices({});
    setReport(null);
    if (null !== next) {
      analyzeFbi.mutate(next, { onSuccess: setAnalysis });
    }
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

  const submit = (): void => {
    if (null === file || null === analysis) {
      return;
    }
    importFbi.mutate({ file, mappings: buildMappings(analysis) }, { onSuccess: setReport });
  };

  // RMM-4 — des écarts domicile ≠ fichier : on NE PAS importe en silence. Le
  // fichier + les mappings complétés + les deviations voyagent EN MÉMOIRE vers la
  // vue dédiée (`/matchs/reconciliation`) où le gestionnaire tranche chaque écart
  // puis lance l'« Appliquer l'import ». Le File est une référence vivante — pas
  // de sérialisation, pas de re-upload.
  const examine = (): void => {
    if (null === file || null === analysis) {
      return;
    }
    setReconciliation({ channel: "xlsx", file, mappings: buildMappings(analysis), deviations: analysis.deviations });
    onClose();
    void navigate("/matchs/reconciliation");
  };

  const deviationCount = analysis?.deviations.length ?? 0;
  const hasDeviations = deviationCount > 0;
  const canImport = null !== file && null !== analysis && !importFbi.isPending && !analyzeFbi.isPending;

  return (
    <Modal
      label="Importer FBI"
      title="Importer un export FBI"
      onClose={onClose}
      size="lg"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>
            Fermer
          </Button>
          {hasDeviations && null === report ? (
            <Button size="sm" disabled={!canImport} onClick={examine}>
              {1 === deviationCount ? "Examiner l'écart" : `Examiner les ${deviationCount} écarts`}
            </Button>
          ) : (
            <Button size="sm" disabled={!canImport} onClick={submit}>
              Importer
            </Button>
          )}
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
            onChange={(e) => onFileChange(e.target.files?.[0] ?? null)}
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
            <ul className="flex max-h-64 flex-col gap-1 overflow-y-auto">
              {analysis.divisions.map((division) => {
                // Mapper à l'aveugle crée des matchs sur la MAUVAISE équipe (§6bis B3) : le nom
                // de division + son `fbiTeamLabel` (présent SEULEMENT quand deux équipes du club
                // partagent la division) ne sont plus tronqués — ils s'enroulent, avec un `title`
                // de secours. La valeur du select (§6bis B4) se lit sans l'ouvrir.
                const divisionLabel = `${division.name}${null !== division.fbiTeamLabel ? ` (${division.fbiTeamLabel})` : ""} · ${division.rowCount} match${division.rowCount > 1 ? "s" : ""}`;
                const selected = choices[divisionKey(division)] ?? usableSuggestion(division) ?? "";
                return (
                  <li key={divisionKey(division)} className="flex items-center justify-between gap-2">
                    <span className="min-w-0 flex-1" title={divisionLabel}>
                      {division.name}
                      {null !== division.fbiTeamLabel ? (
                        <span className="text-muted-foreground"> ({division.fbiTeamLabel})</span>
                      ) : null}
                      <span className="text-muted-foreground"> · {division.rowCount} match{division.rowCount > 1 ? "s" : ""}</span>
                    </span>
                    {null !== division.teamId ? (
                      <span className="shrink-0 text-xs text-muted-foreground">→ {teamName(division.teamId)}</span>
                    ) : (
                      <span className="flex shrink-0 flex-col items-end gap-0.5">
                        <TeamSelect
                          aria-label={`Équipe pour ${division.name}${null !== division.fbiTeamLabel ? ` (${division.fbiTeamLabel})` : ""}`}
                          title={"" !== selected ? teamName(selected) : "Associer à…"}
                          className="w-52 shrink-0"
                          teams={teams}
                          tiers={tiers}
                          placeholder="Associer à…"
                          value={selected}
                          onChange={(e) => setChoices((prev) => ({ ...prev, [divisionKey(division)]: e.target.value }))}
                        />
                        {null !== usableSuggestion(division) && undefined === choices[divisionKey(division)] ? (
                          <span className="rounded bg-muted px-1 text-xs uppercase tracking-wide text-muted-foreground">proposé par la FFBB</span>
                        ) : null}
                      </span>
                    )}
                  </li>
                );
              })}
            </ul>
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
  );
}
