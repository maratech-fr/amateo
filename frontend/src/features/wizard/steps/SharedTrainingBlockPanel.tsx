import { Check, Pencil, Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { errorMessage } from "@/shared/lib/errorMessage";
import { groupTeamsByTier } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";

import type { PriorityTier, SharedTrainingBlock, Team } from "../api";
import { filterCandidates, sharedGroupLabel } from "../lib/sharedTraining";
import { blockCommonSessionOptions, blockMembershipCount } from "../lib/sharedTrainingBlock";
import { useCreateSharedTrainingBlock, useDeleteSharedTrainingBlock, useSharedTrainingBlocks, useTeamPeriodOverrides, useUpdateSharedTrainingBlock } from "../queries";

/**
 * « Bloc d'équipes » (P2-51 PR-4, geste DÉCLARER) : déclarer un ensemble d'équipes qui se comporte
 * comme UNE équipe, avec SON propre nombre de séances communes (liste déroulante bornée). Notion
 * DISTINCTE du groupe {équipes, K} de `MutualisationPanel` — les deux coexistent (D9). Même ancrage
 * de portée (`schedulePlanId` null = socle, un UUID = période).
 *
 * Le serveur (`SharedTrainingBlockStateProcessor`) est seul juge ; tout ici (borne de la liste
 * déroulante, repère « déjà dans N blocs ») est FAIL-SAFE — un 422 reste affiché via `errorMessage`
 * (lit `detail` + `violations[].message`, la forme d'un 422 API Platform).
 *
 * ⚠ Différences ASSUMÉES avec le groupe K (elles ENSEIGNENT la distinction) : la multi-appartenance
 * est PERMISE (aucun verrou « déjà dans un autre bloc », juste un repère informatif), et les séances
 * communes se choisissent en LISTE DÉROULANTE (verbatim fondateur), pas en saisie libre.
 */
export function SharedTrainingBlockPanel({
  teams,
  tiers,
  schedulePlanId,
  pausedTeamIds,
  initialTeamId,
}: {
  teams: Team[];
  tiers: PriorityTier[];
  /** null = socle (saison) ; un UUID = plan de période (rendu derrière PeriodAnchorGate). */
  schedulePlanId: string | null;
  /** Équipes en pause pour la période : jamais proposées (les mutualiser serait un no-op). */
  pausedTeamIds?: ReadonlySet<string>;
  /** P2-45 — ouvert depuis une équipe : pré-coche cette équipe dans le nouveau bloc. */
  initialTeamId?: string;
}) {
  const { data: allBlocks = [] } = useSharedTrainingBlocks(schedulePlanId);
  // En portée socle le provider renvoie socle ET périodes : on ne garde que le socle.
  const scopeBlocks = null === schedulePlanId ? allBlocks.filter((b) => null === b.schedulePlanId) : allBlocks;
  const { data: overrides = [] } = useTeamPeriodOverrides(schedulePlanId);
  const create = useCreateSharedTrainingBlock();
  const update = useUpdateSharedTrainingBlock();
  const del = useDeleteSharedTrainingBlock();

  const [checked, setChecked] = useState<Set<string>>(new Set(undefined === initialTeamId ? [] : [initialTeamId]));
  const [commonSessions, setCommonSessions] = useState(1);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [query, setQuery] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pendingDelete, setPendingDelete] = useState<SharedTrainingBlock | null>(null);

  const overrideByTeamId = new Map(overrides.map((o) => [o.teamId, o]));
  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const nameOf = (id: string): string => teamName.get(id) ?? "?";

  // Candidats = équipes non en pause, dans l'ordre par rang (même ordre que partout).
  const candidates = groupTeamsByTier(
    teams.filter((t) => !pausedTeamIds?.has(t.id)),
    tiers,
  ).flatMap((g) => g.teams);

  const checkedTeams = candidates.filter((t) => checked.has(t.id));
  // La borne de la LISTE DÉROULANTE : 1..min(séances effectives). Plafond de CONFORT, le serveur
  // (garde Σ) tranche plus finement — voir `sharedTrainingBlock.ts`.
  const sessionOptions = blockCommonSessionOptions(checkedTeams, overrideByTeamId);
  const cap = sessionOptions.length; // = max des options (1..cap)
  const effectiveK = cap > 0 ? Math.min(Math.max(commonSessions, 1), cap) : 1;
  const enoughTeams = checked.size >= 2;
  // La liste déroulante n'a de choix RÉEL qu'une fois la sélection bornable (≥ 2 équipes, cap ≥ 1) —
  // divulgation progressive : sinon elle est désactivée avec le motif dit.
  const sessionsReady = enoughTeams && cap > 0;

  const searching = "" !== query.trim();
  const filtered = filterCandidates(candidates, query, checked);
  const countMessage = 0 === filtered.length ? "Aucune équipe trouvée" : `${filtered.length} équipe${filtered.length > 1 ? "s" : ""} trouvée${filtered.length > 1 ? "s" : ""}`;

  const resetForm = () => {
    setEditingId(null);
    setChecked(new Set());
    setCommonSessions(1);
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

  const editBlock = (b: SharedTrainingBlock) => {
    setEditingId(b.id);
    setChecked(new Set(b.teamIds));
    setCommonSessions(b.commonSessions);
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
      // ky 2.x : le corps du 422 vit dans error.data. `errorMessage` lit `detail` PUIS
      // `violations[].message` (forme API Platform). Le serveur reste seul juge : on AFFICHE son
      // motif (garde Σ notamment), sans vider la sélection — le gestionnaire corrige et retente.
      setError(await errorMessage(e));
    }
  };

  const busy = create.isPending || update.isPending;
  const tooMany = checked.size > 3;
  const blockNames = (teamIds: string[]): string => teamIds.map(nameOf).join(" + ");

  const candidateRow = (t: Team) => {
    // Multi-appartenance PERMISE : jamais de verrou. Un simple repère informatif dit combien de
    // blocs contiennent déjà l'équipe — jamais un warning (ce n'est pas un problème).
    const otherBlocks = blockMembershipCount(scopeBlocks, t.id, editingId);
    // L'ancre (la fiche ouverte) reste dans SON bloc : on ne la décoche pas sans le voir. Verrou sur
    // le GESTE de décocher (cochée), pas un forçage permanent — patron `MutualisationPanel`.
    const anchorLocked = t.id === initialTeamId && checked.has(t.id);

    return (
      <div key={t.id} className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
        <label className={cn("flex items-center gap-1.5 text-sm", anchorLocked && "text-muted-foreground")}>
          <input type="checkbox" aria-label={t.name} checked={checked.has(t.id)} disabled={anchorLocked} onChange={() => toggle(t.id)} />
          {t.name}
        </label>
        {anchorLocked ? (
          <span className="text-xs text-muted-foreground">équipe de référence</span>
        ) : otherBlocks > 0 ? (
          <span className="text-xs text-muted-foreground">
            déjà dans {otherBlocks} bloc{otherBlocks > 1 ? "s" : ""}
          </span>
        ) : null}
      </div>
    );
  };

  return (
    <div>
      <p className="mb-3 text-sm text-muted-foreground">
        Un ensemble d'équipes qui se comporte comme <strong>une seule</strong> : cochez-les, puis choisissez son nombre de <strong>séances communes</strong>. Une équipe
        peut appartenir à plusieurs blocs.
      </p>

      <div className="mb-4 rounded-lg border border-border bg-card p-3">
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{null !== editingId ? "Modifier le bloc" : "Nouveau bloc"}</p>

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
              <>
                <p aria-live="polite" className="mb-1 text-xs text-muted-foreground">
                  {countMessage}
                </p>
                {0 === filtered.length ? (
                  <EmptyHint>Aucune équipe ne correspond à « {query.trim()} ».</EmptyHint>
                ) : (
                  <div className="flex flex-col gap-1">{filtered.map(candidateRow)}</div>
                )}
              </>
            ) : (
              <div className="flex flex-col gap-1">{candidates.map(candidateRow)}</div>
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
                <Select
                  aria-label="Séances communes"
                  aria-describedby="block-sessions-help"
                  className="mt-0.5 h-8 w-40"
                  disabled={!sessionsReady}
                  value={String(effectiveK)}
                  onChange={(e) => setCommonSessions(Number(e.target.value) || 1)}
                >
                  {sessionsReady ? (
                    sessionOptions.map((n) => (
                      <option key={n} value={n}>
                        {n} séance{n > 1 ? "s" : ""} commune{n > 1 ? "s" : ""}
                      </option>
                    ))
                  ) : (
                    <option value="1">Choisissez au moins 2 équipes</option>
                  )}
                </Select>
              </label>
              <span id="block-sessions-help" className="text-xs text-muted-foreground">
                {sessionsReady ? `Jusqu'à ${cap} avec les équipes choisies.` : "Sélectionnez les équipes pour connaître le maximum."}
              </span>
              {null !== editingId ? (
                <Button size="sm" variant="ghost" className="ml-auto h-8" onClick={resetForm}>
                  Annuler
                </Button>
              ) : null}
              <Button
                size="sm"
                className={cn("h-8 gap-1", null === editingId && "ml-auto")}
                disabled={!enoughTeams || !sessionsReady || busy}
                onClick={() => void submit()}
              >
                {null !== editingId ? <Check className="size-4" /> : <Plus className="size-4" />}
                {null !== editingId ? "Enregistrer le bloc" : "Créer le bloc"}
              </Button>
            </div>
            {!enoughTeams ? <p className="mt-1 text-xs text-muted-foreground">Cochez au moins deux équipes.</p> : null}
            {null !== error ? (
              <p role="alert" className="mt-2 text-sm text-destructive">
                {error}
              </p>
            ) : null}
          </>
        )}
      </div>

      {0 === scopeBlocks.length ? (
        <EmptyHint>Aucun bloc déclaré.</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-1">
          {scopeBlocks.map((b) => (
            <li key={b.id} className={cn("flex items-center gap-2 rounded-md border bg-card px-3 py-1.5 text-sm", editingId === b.id ? "border-accent ring-1 ring-accent" : "border-border")}>
              <span className="flex-1">{sharedGroupLabel(b.teamIds, b.commonSessions, nameOf)}</span>
              <button type="button" aria-label={`Modifier le bloc ${blockNames(b.teamIds)}`} className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={() => editBlock(b)}>
                <Pencil className="size-4" />
              </button>
              <button type="button" aria-label={`Supprimer le bloc ${blockNames(b.teamIds)}`} className="rounded p-1 text-muted-foreground hover:text-destructive" onClick={() => setPendingDelete(b)}>
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        title="Supprimer ce bloc ?"
        description={pendingDelete ? <>« {sharedGroupLabel(pendingDelete.teamIds, pendingDelete.commonSessions, nameOf)} » sera supprimé. Les équipes ne seront plus placées ensemble.</> : null}
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
