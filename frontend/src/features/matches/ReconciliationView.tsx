import { ArrowLeft, CalendarPlus, FileWarning, Info, Radar } from "lucide-react";
import { useState } from "react";
import { useNavigate } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { EmptyState } from "@/shared/components/ui/empty-hint";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { toast } from "@/shared/stores/toastStore";

import type { PriorityTier, RencontreCreatable, RencontreCreation, Team } from "./api";
import { usePriorityTiers, useApplyFfbbRencontres, useTeams } from "./queries";
import { useMatchesStore } from "./store";

/**
 * PR-3b (D3) — la vue d'INTÉGRATION des rencontres FFBB à créer. RMM-4 mêlait ici
 * l'arbitrage des écarts ; ces écarts vivent désormais PERSISTÉS sur les
 * rencontres (`Fixture.pendingDeviations`) et se traitent dans la file de l'onglet
 * Importer. Il ne reste donc ici qu'UN rôle : proposer à la création les
 * rencontres que la FFBB publie et que l'app n'a pas (souvent des amicaux), puis
 * « Intégrer » et renvoyer vers la file.
 *
 * Enfant de `MatchesLayout` (garde socle héritée). ZÉRO état serveur : la page vit
 * du payload porté EN MÉMOIRE (`store`). Arriver ici sans payload (accès
 * direct/refresh) est un « renvoi propre » vers la boucle.
 */
export function ReconciliationView() {
  const navigate = useNavigate();
  const payload = useMatchesStore((s) => s.reconciliation);
  const setReconciliation = useMatchesStore((s) => s.setReconciliation);
  const teams = useTeams();
  const tiers = usePriorityTiers();
  const applyFfbb = useApplyFfbbRencontres();

  // rencontreId → teamId chosen for creation (missing/"" = not created).
  const [creationTeam, setCreationTeam] = useState<Record<string, string>>({});

  const backToLoop = (): void => {
    setReconciliation(null);
    void navigate("/matchs");
  };

  // Renvoi propre : pas de payload (accès direct / refresh / après abandon).
  if (null === payload) {
    return (
      <div className="flex flex-col gap-3">
        <EmptyState
          icon={FileWarning}
          title="Rien à examiner"
          description="Depuis Importer, relancez « Vérifier via l'API FFBB » pour proposer les rencontres publiées absentes de l'app."
        />
        <Button variant="outline" size="sm" className="w-fit" onClick={() => void navigate("/matchs/importer")}>
          <ArrowLeft className="size-4" />
          Retour à Importer
        </Button>
      </div>
    );
  }

  const creatable = payload.creatable;

  // WYSIWYG: a line is created for exactly the team the select DISPLAYS — the
  // manager's pick, else the FFBB suggestion pre-fill, else « Ne pas créer » («»).
  const chosenTeam = (c: RencontreCreatable): string => creationTeam[c.rencontreId] ?? c.suggestedTeamId ?? "";
  const creations: RencontreCreation[] = creatable
    .map((c): RencontreCreation | null => {
      const teamId = chosenTeam(c);
      return "" === teamId ? null : { rencontreId: c.rencontreId, teamId };
    })
    .filter((c): c is RencontreCreation => null !== c);

  const nothingToApply = 0 === creations.length;

  const integrate = (): void => {
    applyFfbb.mutate(
      { decisions: [], creations },
      {
        onSuccess: (r) => {
          setReconciliation(null);
          toast.success(`${r.created} match${r.created > 1 ? "s" : ""} créé${r.created > 1 ? "s" : ""} depuis la FFBB.`);
          void navigate("/matchs/importer");
        },
      },
    );
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <h2 className="text-base font-semibold">Rencontres publiées par la FFBB</h2>
        {/* Provenance — le gestionnaire sait toujours d'où vient ce qu'il regarde. */}
        <span className="inline-flex items-center gap-1 rounded-full bg-accent px-2 py-0.5 text-xs font-medium text-accent-foreground">
          <Radar className="size-3" aria-hidden="true" />
          Source : API FFBB
        </span>
      </div>

      {/* Bandeau d'honnêteté — INFO, pas alarme (role=status, ton accent). */}
      <div role="status" className="flex items-start gap-2 rounded-md border border-accent bg-accent/40 px-3 py-2 text-sm text-accent-foreground">
        <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <span>
          Ce que la FFBB publie à cet instant — {formatFetchedAt(payload.fetchedAt)}. Une équipe absente ici peut avoir des matchs :
          la couverture fédérale n'est pas garantie. L'import FBI reste la référence.
        </span>
      </div>

      {/* Présents à la FFBB, absents de l'app — proposés à la création (jamais imposés). */}
      <CreatableSection
        creatable={creatable}
        teams={teams.data ?? []}
        tiers={tiers.data ?? []}
        creationTeam={creationTeam}
        onPick={(rencontreId, teamId) => setCreationTeam((prev) => ({ ...prev, [rencontreId]: teamId }))}
      />

      {/* Barre sticky : l'ACTION d'écriture + l'abandon. */}
      <div className="sticky bottom-0 flex flex-wrap items-center justify-end gap-2 border-t border-border bg-card/95 py-3 backdrop-blur">
        <Button variant="ghost" size="sm" onClick={backToLoop}>
          Abandonner
        </Button>
        <Button size="sm" disabled={applyFfbb.isPending || nothingToApply} onClick={integrate}>
          {applyFfbb.isPending ? "Intégration…" : "Intégrer"}
        </Button>
      </div>
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
}: {
  creatable: RencontreCreatable[];
  teams: Team[];
  tiers: PriorityTier[];
  creationTeam: Record<string, string>;
  onPick: (rencontreId: string, teamId: string) => void;
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
          title="Rien à ajouter"
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
                    onValueChange={(v) => onPick(c.rencontreId, v)}
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
