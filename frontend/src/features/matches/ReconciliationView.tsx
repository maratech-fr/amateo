import { ArrowLeft, CalendarPlus, FileText, FileWarning, Info, Radar } from "lucide-react";
import { useMemo, useState } from "react";
import { useNavigate } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { Card, CardContent } from "@/shared/components/ui/card";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyState } from "@/shared/components/ui/empty-hint";
import { TeamSelect } from "@/shared/components/ui/team-select";

import type { Deviation, DeviationDecision, PriorityTier, RencontreCreatable, RencontreCreation, Team } from "./api";
import { ReconciliationPanel } from "./ReconciliationPanel";
import { usePriorityTiers, useApplyFfbbRencontres, useImportFbiFixtures, useTeams } from "./queries";
import { useMatchesStore } from "./store";

/** The report shown after « Appliquer », normalised across the two channels. */
interface ReconReport {
  created: number;
  updated: number;
  unchanged?: number;
  unresolvedDeviations: Deviation[];
}

/**
 * RMM-4 — la VUE DÉDIÉE de réconciliation FBI (`/matchs/reconciliation`, décision
 * fondateur 2026-08-24). Enfant de `MatchesLayout` : la garde socle est héritée.
 *
 * DEUX canaux, une seule vue (le `ReconciliationPanel` est agnostique) :
 *  - `xlsx` — le dépôt du fichier FBI (le File voyage en mémoire par le store) ;
 *  - `api`  — le canal API FFBB (PR-3) : les rencontres publiées croisées avec
 *    l'app. FBI reste la vérité, l'API est un CONFORT qui ajoute les amicaux.
 *    La vue distingue le canal pour : le rappel de provenance, le bandeau
 *    d'honnêteté (couverture jamais garantie) et la section « Présents à la FFBB,
 *    absents de l'app » (un `TeamSelect` par ligne — non sélectionné = non créé).
 *
 * ZÉRO état serveur : la page vit du payload porté EN MÉMOIRE. Arriver ici sans
 * payload (accès direct/refresh) est un « renvoi propre » vers la boucle — rien
 * n'est écrit. Quitter = abandonner ; re-lancer la vérification re-présente tout.
 */
export function ReconciliationView() {
  const navigate = useNavigate();
  const payload = useMatchesStore((s) => s.reconciliation);
  const setReconciliation = useMatchesStore((s) => s.setReconciliation);
  const teams = useTeams();
  const tiers = usePriorityTiers();
  const importFbi = useImportFbiFixtures();
  const applyFfbb = useApplyFfbbRencontres();

  const [decisions, setDecisions] = useState<DeviationDecision[]>([]);
  // rencontreId → teamId chosen for creation (missing/"" = not created).
  const [creationTeam, setCreationTeam] = useState<Record<string, string>>({});
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [report, setReport] = useState<ReconReport | null>(null);

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
              {report.created} créé{report.created > 1 ? "s" : ""} · {report.updated} mis à jour
              {undefined !== report.unchanged ? ` · ${report.unchanged} inchangé${report.unchanged > 1 ? "s" : ""}` : ""}
            </p>
            {report.unresolvedDeviations.length > 0 ? (
              <p className="rounded-md border border-border bg-muted/40 px-3 py-2 text-muted-foreground">
                <Info className="mr-1.5 inline size-4 shrink-0 align-text-bottom" aria-hidden="true" />
                {report.unresolvedDeviations.length} écart{report.unresolvedDeviations.length > 1 ? "s" : ""} non tranché
                {report.unresolvedDeviations.length > 1 ? "s" : ""} — rien n'a été écrasé.
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
          title="Rien à examiner"
          description="Depuis la boucle, re-déposez le fichier FBI ou relancez « Vérifier via l'API FFBB » pour examiner les écarts."
        />
        <Button variant="outline" size="sm" className="w-fit" onClick={() => void navigate("/matchs")}>
          <ArrowLeft className="size-4" />
          Retour à la semaine
        </Button>
      </div>
    );
  }

  const isApi = "api" === payload.channel;
  const deviations = payload.deviations;
  const creatable = isApi ? payload.creatable : [];

  // WYSIWYG: a line is created for exactly the team the select DISPLAYS — the
  // manager's pick, else the FFBB suggestion pre-fill, else « Ne pas créer » («»).
  const chosenTeam = (c: RencontreCreatable): string => creationTeam[c.rencontreId] ?? c.suggestedTeamId ?? "";
  const creations: RencontreCreation[] = creatable
    .map((c): RencontreCreation | null => {
      const teamId = chosenTeam(c);
      return "" === teamId ? null : { rencontreId: c.rencontreId, teamId };
    })
    .filter((c): c is RencontreCreation => null !== c);

  const totalFields = deviations.reduce((n, d) => n + Object.keys(d.fields).length, 0);
  const taken = decisions.filter((d) => "take_file" === d.choice).length;
  const kept = decisions.filter((d) => "keep_app" === d.choice).length;
  const untranched = totalFields - decisions.length;
  const pending = isApi ? applyFfbb.isPending : importFbi.isPending;
  const nothingToApply = isApi && 0 === creations.length && 0 === decisions.length;

  const apply = (): void => {
    setConfirmOpen(false);
    if (isApi) {
      applyFfbb.mutate(
        { decisions, creations },
        {
          onSuccess: (r) => {
            setReport({ created: r.created, updated: r.updated, unresolvedDeviations: r.unresolvedDeviations });
            setReconciliation(null);
          },
        },
      );
      return;
    }
    importFbi.mutate(
      { file: payload.file, mappings: payload.mappings, decisions },
      {
        onSuccess: (r) => {
          setReport({ created: r.created, updated: r.updated, unchanged: r.unchanged, unresolvedDeviations: r.unresolvedDeviations });
          setReconciliation(null);
        },
      },
    );
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <h2 className="text-base font-semibold">Réconciliation FBI</h2>
          {/* Provenance — le gestionnaire sait toujours d'où vient ce qu'il regarde. */}
          {isApi ? (
            <span className="inline-flex items-center gap-1 rounded-full bg-accent px-2 py-0.5 text-xs font-medium text-accent-foreground">
              <Radar className="size-3" aria-hidden="true" />
              Source : API FFBB
            </span>
          ) : (
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
              <FileText className="size-3" aria-hidden="true" />
              Source : dépôt FBI (fichier)
            </span>
          )}
        </div>
        {!isApi ? (
          <p className="text-sm text-muted-foreground">
            {deviations.length} écart{deviations.length > 1 ? "s" : ""} entre l'app et le fichier, sur des matchs à domicile déjà placés.
            Tranchez chaque écart — quitter n'écrase rien, re-déposer le fichier les re-présente.
          </p>
        ) : null}
      </div>

      {/* Bandeau d'honnêteté — INFO, pas alarme (role=status, ton accent). */}
      {isApi ? (
        <div role="status" className="flex items-start gap-2 rounded-md border border-accent bg-accent/40 px-3 py-2 text-sm text-accent-foreground">
          <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          <span>
            Ce que la FFBB publie à cet instant — {formatFetchedAt(payload.fetchedAt)}. Une équipe absente ici peut avoir des matchs :
            la couverture fédérale n'est pas garantie. L'import FBI reste la référence.
          </span>
        </div>
      ) : null}

      {deviations.length > 0 ? (
        <ReconciliationPanel deviations={deviations} decisions={decisions} onDecisionsChange={setDecisions} teamName={teamName} />
      ) : null}

      {/* Présents à la FFBB, absents de l'app — proposés à la création (jamais imposés). */}
      {isApi ? (
        <CreatableSection
          creatable={creatable}
          teams={teams.data ?? []}
          tiers={tiers.data ?? []}
          creationTeam={creationTeam}
          onPick={(rencontreId, teamId) => setCreationTeam((prev) => ({ ...prev, [rencontreId]: teamId }))}
          hasDeviations={deviations.length > 0}
        />
      ) : null}

      {/* Barre sticky : l'ACTION d'écriture (avec résumé de confirmation) + l'abandon. */}
      <div className="sticky bottom-0 flex flex-wrap items-center justify-end gap-2 border-t border-border bg-card/95 py-3 backdrop-blur">
        <Button variant="ghost" size="sm" onClick={backToLoop}>
          Abandonner
        </Button>
        <Button size="sm" disabled={pending || nothingToApply} onClick={() => setConfirmOpen(true)}>
          {pending ? "Application…" : isApi ? "Appliquer" : "Appliquer l'import"}
        </Button>
      </div>

      <ConfirmDialog
        open={confirmOpen}
        destructive={false}
        title={isApi ? "Appliquer les changements ?" : "Appliquer l'import ?"}
        confirmLabel="Appliquer"
        cancelLabel="Revenir"
        description={
          <span>
            {isApi ? (
              <>
                {creations.length} match{creations.length > 1 ? "s" : ""} créé{creations.length > 1 ? "s" : ""} depuis la FFBB ·{" "}
              </>
            ) : null}
            {taken} écart{taken > 1 ? "s" : ""} pris du fichier · {kept} gardé{kept > 1 ? "s" : ""} · {untranched} non tranché
            {untranched > 1 ? "s" : ""}.
            {untranched > 0 ? " Les écarts non tranchés ne sont pas écrasés — ils vous seront re-présentés." : ""}
          </span>
        }
        onConfirm={apply}
        onCancel={() => setConfirmOpen(false)}
      />
    </div>
  );
}

/** Presents the FFBB rencontres absent of the app, one row + TeamSelect each. */
function CreatableSection({
  creatable,
  teams,
  tiers,
  creationTeam,
  onPick,
  hasDeviations,
}: {
  creatable: RencontreCreatable[];
  teams: Team[];
  tiers: PriorityTier[];
  creationTeam: Record<string, string>;
  onPick: (rencontreId: string, teamId: string) => void;
  hasDeviations: boolean;
}) {
  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-center gap-2">
        <CalendarPlus className="size-4 text-muted-foreground" aria-hidden="true" />
        <h3 className="text-sm font-medium">Présents à la FFBB, absents de l'app</h3>
      </div>
      {creatable.length > 0 ? (
        <p className="text-xs text-muted-foreground">
          Souvent des amicaux — choisissez l'équipe pour les créer, laissez « Ne pas créer » pour ignorer.
        </p>
      ) : null}
      {0 === creatable.length ? (
        <EmptyState
          icon={CalendarPlus}
          title={hasDeviations ? "Rien de plus à ajouter" : "Rien à ajouter"}
          description="La FFBB ne publie rien que vous n'ayez déjà. Ce n'est pas une erreur — sa couverture n'est simplement pas garantie."
        />
      ) : (
        <ul className="flex flex-col gap-2">
          {creatable.map((c) => {
            const chosen = creationTeam[c.rencontreId] ?? c.suggestedTeamId ?? "";
            return (
              <li key={c.rencontreId} className="flex flex-col gap-2 rounded-md border border-border bg-card p-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-col gap-0.5 text-sm">
                  <span className="font-medium">
                    {"HOME" === c.homeAway ? "Domicile" : "Extérieur"} vs {c.opponentLabel}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {formatDate(c.date)}
                    {null !== c.kickoff ? ` · ${c.kickoff}` : ""}
                    {"" !== c.competitionNom ? ` · ${c.competitionNom}` : ""}
                    {null !== c.venueLabel ? ` · ${c.venueLabel}` : ""}
                  </span>
                </div>
                <div className="w-full sm:w-56">
                  <TeamSelect
                    aria-label={`Créer le match vs ${c.opponentLabel} pour l'équipe`}
                    teams={teams}
                    tiers={tiers}
                    placeholder="Ne pas créer"
                    value={chosen}
                    onChange={(e) => onPick(c.rencontreId, e.target.value)}
                  />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

/** « 27 août 2026 » from an ISO date. */
function formatDate(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" });
}

/** « 27 août 2026 à 14:05 » from an ISO datetime — the honesty banner instant. */
function formatFetchedAt(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString("fr-FR", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" });
}
