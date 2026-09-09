import { Clock, Link2, Radar, Upload } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { todayISO } from "@/shared/lib/clock";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import { FfbbEngagementsDialog } from "./FfbbEngagementsDialog";
import { ImportFbiDialog } from "./ImportFbiDialog";
import { STALE_DAYS, depositDaysAgo, relativeDepositLabel } from "./lib/fbiFreshness";
import { useApplyFfbbRencontres, useFfbbRencontres, useFixtures, useLatestFbiIngestion, usePriorityTiers, useTeams, useVenues } from "./queries";
import { ReviewQueue } from "./ReviewQueue";
import { useMatchesStore } from "./store";

/**
 * PR-3b — l'espace « Importer » : d'un côté les ENTRÉES de données de match
 * (dépôt FBI, canal API FFBB, engagements) avec leur fraîcheur ; de l'autre la
 * FILE de traitement, rencontre par rencontre, par équipe. C'est la maison de tout
 * ce qui touche les données FBI/FFBB, sorti de la Configuration (P4-186).
 */
export function ImportPage() {
  const navigate = useNavigate();
  const teams = useTeams();
  const tiers = usePriorityTiers();
  const fixtures = useFixtures();
  const venues = useVenues();
  const freshness = useLatestFbiIngestion();
  const rencontres = useFfbbRencontres(false);
  const applyFfbb = useApplyFfbbRencontres();
  const setReconciliation = useMatchesStore((s) => s.setReconciliation);

  const [importDialogOpen, setImportDialogOpen] = useState(false);
  const [ffbbDialogOpen, setFfbbDialogOpen] = useState(false);

  // La fraîcheur : le dernier dépôt FBI, en relatif ; escalade en warning quand
  // aucun dépôt cette saison ou que le dernier date de plus de 30 jours.
  const latest = freshness.data?.latest ?? null;
  const freshDays = null !== latest ? depositDaysAgo(latest.depositedAt, todayISO()) : null;
  const staleFreshness = null === latest || (null !== freshDays && freshDays > STALE_DAYS);

  // « Vérifier via l'API FFBB » : fetch à la demande, puis trois issues (D3).
  const checkViaApi = async (): Promise<void> => {
    const res = await rencontres.refetch();
    if (undefined === res.data) {
      toast.error("La FFBB est indisponible pour le moment — réessayez plus tard.");
      return;
    }
    const { deviations, creatable, fetchedAt } = res.data;
    if (creatable.length > 0) {
      // (a) des rencontres à créer → la vue d'intégration (elle applique + revient ici).
      setReconciliation({ channel: "api", creatable, fetchedAt });
      void navigate("/matchs/reconciliation");
      return;
    }
    if (deviations.length > 0) {
      // (b) que des écarts → on les CONSIGNE dans la file (apply sans création).
      applyFfbb.mutate(
        { decisions: [], creations: [] },
        {
          onSuccess: () => toast.info(`${deviations.length} écart${deviations.length > 1 ? "s" : ""} consigné${deviations.length > 1 ? "s" : ""} dans la file.`),
        },
      );
      return;
    }
    // (c) ni l'un ni l'autre → rien à faire, aucun dépôt daté pour rien.
    toast.success("Tout est en phase avec ce que la FFBB publie.");
  };

  if (readLoading(fixtures) || readLoading(teams) || readLoading(venues)) {
    return <FullPageSpinner />;
  }
  if (readFailed(fixtures) || readFailed(teams) || readFailed(venues)) {
    return (
      <LoadErrorHint
        onRetry={() => {
          void fixtures.refetch();
          void teams.refetch();
          void venues.refetch();
        }}
      />
    );
  }

  return (
    <div className="flex flex-col gap-6">
      {/* 1. Les entrées de données de match. */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Données de match</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="mb-3 text-sm text-muted-foreground">
            L'export FBI complet du club (début de saison ou de phase), le canal API FFBB (souvent
            des amicaux) et les engagements. L'import FBI fait foi.
          </p>
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => setImportDialogOpen(true)}>
              <Upload className="size-4" />
              Importer FBI
            </Button>
            <Button variant="ghost" size="sm" disabled={rencontres.isFetching} onClick={() => void checkViaApi()}>
              <Radar className="size-4" />
              {rencontres.isFetching ? "Vérification…" : "Vérifier via l'API FFBB"}
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setFfbbDialogOpen(true)}>
              <Link2 className="size-4" />
              Engagements FFBB
            </Button>
          </div>
          <p className={cn("mt-3 flex items-center gap-1.5 text-sm", staleFreshness ? "text-warning" : "text-muted-foreground")}>
            <Clock className="size-4 shrink-0" aria-hidden="true" />
            {null === latest || null === freshDays ? "Aucun dépôt FBI cette saison." : `Dernier dépôt FBI : ${relativeDepositLabel(freshDays)}.`}
          </p>
        </CardContent>
      </Card>

      {/* 2. La file de traitement, par équipe. */}
      <ReviewQueue fixtures={fixtures.data ?? []} teams={teams.data ?? []} venues={venues.data ?? []} />

      {importDialogOpen ? <ImportFbiDialog teams={teams.data ?? []} tiers={tiers.data ?? []} onClose={() => setImportDialogOpen(false)} /> : null}
      {ffbbDialogOpen ? <FfbbEngagementsDialog teams={teams.data ?? []} tiers={tiers.data ?? []} onClose={() => setFfbbDialogOpen(false)} /> : null}
    </div>
  );
}
