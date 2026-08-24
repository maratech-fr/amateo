import { ArrowLeft, FileWarning, Info } from "lucide-react";
import { useMemo, useState } from "react";
import { useNavigate } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent } from "@/shared/components/ui/card";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyState } from "@/shared/components/ui/empty-hint";

import type { DeviationDecision, ImportFbiResult, Team } from "./api";
import { ReconciliationPanel } from "./ReconciliationPanel";
import { useImportFbiFixtures, useTeams } from "./queries";
import { useMatchesStore } from "./store";

/**
 * RMM-4 — la VUE DÉDIÉE de réconciliation FBI (`/matchs/reconciliation`, décision
 * fondateur 2026-08-24 : la passe de design a jugé l'écran de choix impraticable
 * dans la modale d'import). Enfant de `MatchesLayout` : la garde socle est héritée.
 *
 * ZÉRO état serveur : la page vit du payload d'analyse porté EN MÉMOIRE par le
 * dialogue (store `reconciliation`, `File` = référence JS vivante). Arriver ici sans
 * payload (accès direct/refresh) est un « renvoi propre » vers la boucle — rien
 * n'est écrit. Quitter = abandonner (rien d'écrasé) ; re-déposer le fichier
 * re-présente les écarts. L'« Appliquer l'import » final envoie fichier + mappings
 * + les décisions EXACTEMENT posées.
 */
export function ReconciliationView() {
  const navigate = useNavigate();
  const payload = useMatchesStore((s) => s.reconciliation);
  const setReconciliation = useMatchesStore((s) => s.setReconciliation);
  const teams = useTeams();
  const importFbi = useImportFbiFixtures();

  const [decisions, setDecisions] = useState<DeviationDecision[]>([]);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [report, setReport] = useState<ImportFbiResult | null>(null);

  const teamName = useMemo(() => {
    const byId = new Map<string, Team>((teams.data ?? []).map((t) => [t.id, t]));
    return (id: string): string => byId.get(id)?.name ?? "Équipe ?";
  }, [teams.data]);

  const backToLoop = (): void => {
    setReconciliation(null);
    void navigate("/matchs");
  };

  // Rapport final : rien d'écrasé sur les écarts laissés sans choix.
  if (null !== report) {
    return (
      <div className="flex flex-col gap-3">
        <Card>
          <CardContent className="flex flex-col gap-2 py-4 text-sm">
            <p className="font-medium">
              {report.created} créé{report.created > 1 ? "s" : ""} · {report.updated} mis à jour · {report.unchanged} inchangé{report.unchanged > 1 ? "s" : ""}
            </p>
            {report.unresolvedDeviations.length > 0 ? (
              <p className="rounded-md border border-border bg-muted/40 px-3 py-2 text-muted-foreground">
                <Info className="mr-1.5 inline size-4 shrink-0 align-text-bottom" aria-hidden="true" />
                {report.unresolvedDeviations.length} écart{report.unresolvedDeviations.length > 1 ? "s" : ""} non tranché
                {report.unresolvedDeviations.length > 1 ? "s" : ""} — rien n'a été écrasé. Re-déposez le fichier pour les revoir.
              </p>
            ) : null}
          </CardContent>
        </Card>
        <Button variant="outline" size="sm" className="w-fit" onClick={backToLoop}>
          <ArrowLeft className="size-4" />
          Retour à la semaine
        </Button>
      </div>
    );
  }

  // Renvoi propre : pas de payload (accès direct / refresh / après abandon).
  if (null === payload) {
    return (
      <div className="flex flex-col gap-3">
        <EmptyState
          icon={FileWarning}
          title="Aucun écart à examiner"
          description="Re-déposez le fichier FBI depuis la boucle pour examiner les écarts entre l'app et le fichier."
        />
        <Button variant="outline" size="sm" className="w-fit" onClick={() => void navigate("/matchs")}>
          <ArrowLeft className="size-4" />
          Retour à la semaine
        </Button>
      </div>
    );
  }

  const total = payload.deviations.reduce((n, d) => n + Object.keys(d.fields).length, 0);
  const taken = decisions.filter((d) => "take_file" === d.choice).length;
  const kept = decisions.filter((d) => "keep_app" === d.choice).length;
  const untranched = total - decisions.length;

  const apply = (): void => {
    setConfirmOpen(false);
    importFbi.mutate(
      { file: payload.file, mappings: payload.mappings, decisions },
      {
        onSuccess: (result) => {
          setReport(result);
          setReconciliation(null);
        },
      },
    );
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h2 className="text-base font-semibold">Réconciliation FBI</h2>
        <p className="text-sm text-muted-foreground">
          {payload.deviations.length} écart{payload.deviations.length > 1 ? "s" : ""} entre l'app et le fichier, sur des matchs à domicile déjà placés. Tranchez chaque écart —
          quitter n'écrase rien, re-déposer le fichier les re-présente.
        </p>
      </div>

      <ReconciliationPanel deviations={payload.deviations} decisions={decisions} onDecisionsChange={setDecisions} teamName={teamName} />

      {/* Barre sticky : l'ACTION d'écriture (avec résumé de confirmation) + l'abandon. */}
      <div className="sticky bottom-0 flex flex-wrap items-center justify-end gap-2 border-t border-border bg-card/95 py-3 backdrop-blur">
        <Button variant="ghost" size="sm" onClick={backToLoop}>
          Abandonner
        </Button>
        <Button size="sm" disabled={importFbi.isPending} onClick={() => setConfirmOpen(true)}>
          {importFbi.isPending ? "Import…" : "Appliquer l'import"}
        </Button>
      </div>

      <ConfirmDialog
        open={confirmOpen}
        destructive={false}
        title="Appliquer l'import ?"
        confirmLabel="Appliquer"
        cancelLabel="Revenir"
        description={
          <span>
            {taken} écart{taken > 1 ? "s" : ""} pris du fichier · {kept} gardé{kept > 1 ? "s" : ""} · {untranched} non tranché{untranched > 1 ? "s" : ""}.
            {untranched > 0 ? " Les écarts non tranchés ne sont pas écrasés — ils vous seront re-présentés au prochain dépôt." : ""}
          </span>
        }
        onConfirm={apply}
        onCancel={() => setConfirmOpen(false)}
      />
    </div>
  );
}
