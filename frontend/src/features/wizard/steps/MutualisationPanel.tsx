import { Check, Pencil, Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { errorMessage } from "@/shared/lib/errorMessage";
import { HabitsLinksButton } from "@/features/matches/HabitsLinksButton";
import { useTeamLinks } from "@/features/matches/queries";
import { groupTeamsByTier } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";

import type { PriorityTier, SharedTrainingGroup, Team } from "../api";
import { alreadyGroupedTeamIds, filterCandidates, groupContainingTeam, maxCommonSessions, sharedGroupLabel, splitByLinks, teamsLinkedTo } from "../lib/sharedTraining";
import { useCreateSharedTrainingGroup, useDeleteSharedTrainingGroup, useSharedTrainingGroups, useTeamPeriodOverrides, useUpdateSharedTrainingGroup } from "../queries";

/**
 * « Mutualisation » tab (P2-27 PR B) : declare that N teams train TOGETHER with EXACTLY K common
 * sessions. Same anchoring as the "Réserver" tab — base plan (schedulePlanId null) or a period
 * overlay behind `PeriodAnchorGate`. The server (`SharedTrainingGroupStateProcessor`) is the sole
 * judge; everything below (K cap, already-grouped lock, candidate ranking) is FAIL-SAFE guidance,
 * never a permission — a 422 stays handled and shown via `errorMessage` (reads detail + violations).
 */
export function MutualisationPanel({
  teams,
  tiers,
  schedulePlanId,
  pausedTeamIds,
  initialTeamId,
  hideLinksManager = false,
}: {
  teams: Team[];
  tiers: PriorityTier[];
  /** null = base plan (season socle) ; a UUID = a period plan (rendered behind PeriodAnchorGate). */
  schedulePlanId: string | null;
  /** Teams paused for the period : never offered as candidates (grouping them would be a no-op). */
  pausedTeamIds?: ReadonlySet<string>;
  /** P2-45 — ouvert depuis une équipe : pré-coche cette équipe dans le nouveau groupe. */
  initialTeamId?: string;
  /** P2-45 — masque « Gérer les passerelles » quand la section passerelles est déjà à l'écran
   *  (la modale Liens) — jamais un dialog dans le dialog. */
  hideLinksManager?: boolean;
}) {
  const { data: allGroups = [] } = useSharedTrainingGroups(schedulePlanId);
  // En portée socle le provider renvoie socle ET périodes : on ne garde que le socle.
  const scopeGroups = null === schedulePlanId ? allGroups.filter((g) => null === g.schedulePlanId) : allGroups;
  const { data: overrides = [] } = useTeamPeriodOverrides(schedulePlanId);
  // Passerelles du club+saison (P2-34) — SERVIES par le backend, jamais redérivées : elles
  // décident l'ORDRE d'affichage (équipes liées d'abord), une présentation, pas une permission.
  const { data: teamLinks = [] } = useTeamLinks();
  const create = useCreateSharedTrainingGroup();
  const update = useUpdateSharedTrainingGroup();
  const del = useDeleteSharedTrainingGroup();

  const [checked, setChecked] = useState<Set<string>>(new Set(undefined === initialTeamId ? [] : [initialTeamId]));
  const [commonSessions, setCommonSessions] = useState(1);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [showAll, setShowAll] = useState(false);
  const [query, setQuery] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pendingDelete, setPendingDelete] = useState<SharedTrainingGroup | null>(null);

  const overrideByTeamId = new Map(overrides.map((o) => [o.teamId, o]));
  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const nameOf = (id: string): string => teamName.get(id) ?? "?";

  // Candidats = équipes non en pause, dans l'ordre par rang (même ordre que partout).
  const candidates = groupTeamsByTier(
    teams.filter((t) => !pausedTeamIds?.has(t.id)),
    tiers,
  ).flatMap((g) => g.teams);

  const lockedIds = alreadyGroupedTeamIds(scopeGroups, editingId);
  const checkedTeams = candidates.filter((t) => checked.has(t.id));
  const cap = maxCommonSessions(checkedTeams, overrideByTeamId);
  const effectiveK = cap > 0 ? Math.min(Math.max(commonSessions, 1), cap) : Math.max(commonSessions, 1);

  // Ancre = première équipe cochée dans l'ordre d'affichage. Le split n'apparaît qu'à partir d'elle.
  const anchor = candidates.find((t) => checked.has(t.id)) ?? null;
  const linkedIds = null === anchor ? new Set<string>() : teamsLinkedTo(teamLinks, anchor.id);
  const { near, far } = splitByLinks(candidates, anchor, checked, linkedIds);
  // Le split « liées / reste » n'a de sens que s'il y a des équipes PASSERELÉES à remonter : sans
  // passerelle pour l'ancre, on garde la liste plate (toutes visibles) et le hint guide vers la
  // déclaration — le bloc « liées » ne masque jamais les autres équipes pour rien.
  const showSplit = candidates.some((t) => linkedIds.has(t.id));

  // Recherche (P2-45 suite) : une requête active COURT-CIRCUITE le split liées/reste en liste plate
  // (sinon un résultat resterait caché derrière « Afficher toutes les équipes »). Une équipe cochée
  // ou verrouillée reste trouvable (`filterCandidates` : le coché est exempté, le verrouillé a un nom).
  const searching = "" !== query.trim();
  const filtered = filterCandidates(candidates, query, checked);
  const countMessage = 0 === filtered.length ? "Aucune équipe trouvée" : `${filtered.length} équipe${filtered.length > 1 ? "s" : ""} trouvée${filtered.length > 1 ? "s" : ""}`;

  const resetForm = () => {
    setEditingId(null);
    setChecked(new Set());
    setCommonSessions(1);
    setShowAll(false);
    setError(null);
  };

  const toggle = (teamId: string) =>
    setChecked((prev) => {
      const next = new Set(prev);
      if (next.has(teamId)) {
        next.delete(teamId);
      } else {
        next.add(teamId);
      }
      return next;
    });

  const editGroup = (group: SharedTrainingGroup) => {
    setEditingId(group.id);
    setChecked(new Set(group.teamIds));
    setCommonSessions(group.commonSessions);
    setShowAll(false);
    setError(null);
  };

  const submit = async () => {
    setError(null);
    const teamIds = [...checked];
    try {
      if (null !== editingId) {
        await update.mutateAsync({ id: editingId, body: { teamIds, commonSessions: effectiveK } });
      } else {
        await create.mutateAsync({ schedulePlanId, teamIds, commonSessions: effectiveK });
      }
      resetForm();
    } catch (e) {
      // ky 2.x : le corps du 422 vit dans error.data (jamais error.response). `errorMessage` lit
      // `detail` PUIS `violations[].message` — la forme d'un 422 d'API Platform, que `apiErrorMessage`
      // (error/message seuls) laissait muet à l'écran (P4-126). Le serveur reste seul juge : on
      // affiche son motif plutôt que de le pré-supposer.
      setError(await errorMessage(e));
    }
  };

  const busy = create.isPending || update.isPending;
  const tooMany = checked.size > 3;

  const candidateRow = (t: Team) => {
    const otherGroup = lockedIds.has(t.id) ? groupContainingTeam(scopeGroups, t.id, editingId) : undefined;
    const locked = undefined !== otherGroup;
    const withNames = otherGroup ? otherGroup.teamIds.filter((id) => id !== t.id).map(nameOf).join(", ") : "";
    // L'ancre = l'équipe de CETTE fiche (`initialTeamId`) : on ne la décoche pas de son propre
    // groupe sans le voir. Le verrou porte sur le GESTE de décocher (disabled si cochée), pas sur un
    // forçage permanent : éditer un groupe qui ne la contient pas la décoche légitimement, re-cocher
    // reste permis. Fondateur : pas de confirmation — on supprime le groupe et on recommence.
    const anchorLocked = t.id === initialTeamId && checked.has(t.id);

    return (
      <div key={t.id} className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
        <label className={cn("flex items-center gap-1.5 text-sm", (locked || anchorLocked) && "text-muted-foreground")}>
          <input type="checkbox" aria-label={t.name} checked={checked.has(t.id)} disabled={locked || anchorLocked} onChange={() => toggle(t.id)} />
          {t.name}
        </label>
        {/* La raison EN TEXTE (pas un `title` de survol) : au clavier comme au lecteur d'écran, une
            case grisée sans motif lisible laisse le gestionnaire sans recours. Le verrou « déjà
            mutualisée » prime celui de l'ancre. */}
        {locked ? (
          <span className="text-xs text-muted-foreground">déjà mutualisée{withNames ? ` avec ${withNames}` : ""}</span>
        ) : anchorLocked ? (
          <span className="text-xs text-muted-foreground">Cette fiche — retirez-la en supprimant le groupe.</span>
        ) : null}
      </div>
    );
  };

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          Déclarez que plusieurs équipes s'entraînent <strong>ensemble</strong> : cochez-les, puis indiquez combien de <strong>séances communes</strong> elles
          partagent chaque semaine. Le système les placera au même créneau pour ce nombre de séances.
        </p>
        {/* L'écran unique des passerelles (module matchs) : déclarer un lien y remonte l'équipe liée
            en tête de la sélection ci-dessous. Le dialog reste dans features/matches, ce bouton l'ouvre.
            Masqué (P2-45) quand la modale Liens montre déjà la section passerelles au-dessus. */}
        {hideLinksManager ? null : <HabitsLinksButton className="shrink-0" />}
      </div>

      {0 === teamLinks.length && candidates.length > 0 ? (
        <div className="mb-4">
          <EmptyHint>Aucune passerelle déclarée — déclarez-les pour retrouver ici vos équipes liées.</EmptyHint>
        </div>
      ) : null}

      <div className="mb-4 rounded-lg border border-border bg-card p-3">
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{null !== editingId ? "Modifier le groupe" : "Nouveau groupe"}</p>

        {0 === candidates.length ? (
          <EmptyHint>Ajoutez d'abord des équipes pour pouvoir les mutualiser.</EmptyHint>
        ) : (
          <>
            <Input
              type="search"
              aria-label="Rechercher une équipe"
              placeholder="Rechercher une équipe"
              className="mb-2 h-8"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
            />

            {searching ? (
              // Recherche active : liste PLATE (le split est court-circuité) + compte annoncé.
              <>
                <p aria-live="polite" className="mb-1 text-xs text-muted-foreground">
                  {countMessage}
                </p>
                {0 === filtered.length ? (
                  // Témoin, jamais une liste vide muette : la requête est NOMMÉE.
                  <p className="text-sm text-muted-foreground">Aucune équipe ne correspond à « {query.trim()} ».</p>
                ) : (
                  <div className="flex flex-col gap-1">{filtered.map(candidateRow)}</div>
                )}
              </>
            ) : !showSplit ? (
              // Rien de coché, ou aucune passerelle pour l'ancre : liste plate (toutes les équipes
              // visibles). Le split « liées » n'apparaît qu'à partir d'une équipe passerelée.
              <div className="flex flex-col gap-1">{candidates.map(candidateRow)}</div>
            ) : (
              <>
                <fieldset className="min-w-0 border-0 p-0">
                  <legend className="mb-1 text-xs font-semibold text-muted-foreground">Équipes liées</legend>
                  <div className="flex flex-col gap-1">{near.map(candidateRow)}</div>
                </fieldset>
                {far.length > 0 ? (
                  <div className="mt-2">
                    <button
                      type="button"
                      aria-expanded={showAll}
                      className="text-xs text-muted-foreground underline decoration-dotted underline-offset-2 hover:text-foreground"
                      onClick={() => setShowAll((v) => !v)}
                    >
                      Afficher toutes les équipes
                    </button>
                    {showAll ? <div className="mt-1 flex flex-col gap-1">{far.map(candidateRow)}</div> : null}
                  </div>
                ) : null}
              </>
            )}

            {/* Au-delà de 3 équipes cochées : inhabituel, jamais bloquant (le serveur va jusqu'à 10). */}
            {tooMany ? (
              <p role="alert" className="mt-3 text-xs text-warning">
                {checked.size} équipes cochées — c'est inhabituel : vérifiez que ces équipes s'entraînent vraiment ensemble.
              </p>
            ) : null}

            <div className="mt-3 flex flex-wrap items-end gap-2">
              <label className="text-xs text-muted-foreground">
                Séances communes
                <Input
                  aria-label="Séances communes"
                  type="number"
                  min={1}
                  max={cap > 0 ? cap : undefined}
                  className="mt-0.5 h-8 w-20"
                  value={effectiveK}
                  onChange={(e) => setCommonSessions(Math.max(1, Number(e.target.value) || 1))}
                />
              </label>
              {cap > 0 ? <span className="text-xs text-muted-foreground">au plus {cap} séance{cap > 1 ? "s" : ""} commune{cap > 1 ? "s" : ""}</span> : null}
              {null !== editingId ? (
                <Button size="sm" variant="ghost" className="ml-auto h-8" onClick={resetForm}>
                  Annuler
                </Button>
              ) : null}
              <Button
                size="sm"
                className={cn("h-8 gap-1", null === editingId && "ml-auto")}
                disabled={checked.size < 2 || busy}
                onClick={() => void submit()}
              >
                {null !== editingId ? <Check className="size-4" /> : <Plus className="size-4" />}
                {null !== editingId ? "Enregistrer le groupe" : "Créer le groupe"}
              </Button>
            </div>
            {checked.size < 2 ? <p className="mt-1 text-xs text-muted-foreground">Cochez au moins deux équipes.</p> : null}
            {null !== error ? (
              <p role="alert" className="mt-2 text-sm text-destructive">
                {error}
              </p>
            ) : null}
          </>
        )}
      </div>

      {0 === scopeGroups.length ? (
        <EmptyHint>Aucune mutualisation déclarée.</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-1">
          {scopeGroups.map((group) => (
            <li key={group.id} className={cn("flex items-center gap-2 rounded-md border bg-card px-3 py-1.5 text-sm", editingId === group.id ? "border-accent ring-1 ring-accent" : "border-border")}>
              <span className="flex-1">{sharedGroupLabel(group.teamIds, group.commonSessions, nameOf)}</span>
              <button type="button" aria-label="Modifier" className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={() => editGroup(group)}>
                <Pencil className="size-4" />
              </button>
              <button type="button" aria-label="Supprimer" className="rounded p-1 text-muted-foreground hover:text-destructive" onClick={() => setPendingDelete(group)}>
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        title="Supprimer cette mutualisation ?"
        description={pendingDelete ? <>« {sharedGroupLabel(pendingDelete.teamIds, pendingDelete.commonSessions, nameOf)} » sera supprimée. Les équipes ne seront plus placées ensemble.</> : null}
        confirmLabel="Supprimer"
        onCancel={() => setPendingDelete(null)}
        onConfirm={() => {
          if (pendingDelete) {
            del.mutate(pendingDelete.id);
            if (editingId === pendingDelete.id) {
              resetForm();
            }
          }
          setPendingDelete(null);
        }}
      />
    </div>
  );
}
