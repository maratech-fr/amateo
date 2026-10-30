import { Check, Pencil, Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { CtcMark } from "@/shared/components/ui/ctc-mark";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { dayLabelLong } from "@/shared/lib/days";
import { errorMessage } from "@/shared/lib/errorMessage";
import { groupTeamsByTier } from "@/shared/lib/teamTiers";
import { formatMinutes, parseTime } from "@/shared/lib/time";
import { cn } from "@/shared/lib/utils";

import type { PriorityTier, SharedTrainingBlock, Team } from "../api";
import { filterCandidates, sharedGroupLabel } from "../lib/sharedTraining";
import { blockCommonSessionOptions, blockMembershipCount } from "../lib/sharedTrainingBlock";
import { useCreateSharedTrainingBlock, useDeleteSharedTrainingBlock, useSharedTrainingBlocks, useTeamPeriodOverrides, useUpdateSharedTrainingBlock } from "../queries";
import { WIZARD_TEXTS as T } from "../texts";

/**
 * La section « Mutualisation » de la modale « Liens » (P2-51 PR-4, geste DÉCLARER) : déclarer un
 * « groupe à mutualiser » — un ensemble d'équipes qui se comporte comme UNE équipe, avec SON propre
 * nombre de séances communes (liste déroulante bornée). Même ancrage de portée (`schedulePlanId`
 * null = socle, un UUID = période).
 *
 * ⚠ Vocabulaire écran (correction fondateur D13) : mutualisation et « bloc » sont LA MÊME notion —
 * l'écran ne dit jamais « bloc », il dit « groupe » ; « bloc » n'est qu'une image interne (nom des
 * fichiers/composants/types/entité backend `SharedTrainingBlock`). C'est la SEULE notion de
 * mutualisation depuis le retrait du modèle groupe {équipes, K}.
 *
 * E12 (essai fondateur 30/10) — un « Nom du groupe » FACULTATIF (`label`, ≤ 40) part à la création
 * et à la modification ; la liste affiche ce nom quand il existe, sinon l'affichage par les équipes.
 * Écran MIGRÉ : tous ses libellés viennent de `WIZARD_TEXTS` (garde `hardcodedTextGuard`).
 *
 * Le serveur (`SharedTrainingBlockStateProcessor`) est seul juge ; tout ici (borne de la liste
 * déroulante, repère « déjà dans N groupes ») est FAIL-SAFE — un 422 reste affiché via `errorMessage`
 * (lit `detail` + `violations[].message`, la forme d'un 422 API Platform). La multi-appartenance est
 * PERMISE (aucun verrou « déjà dans un autre groupe », juste un repère informatif), et les séances
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
  const [groupName, setGroupName] = useState("");
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

  // E12 — nom AFFICHÉ d'un groupe dans la liste : son `label` s'il en a un, sinon l'affichage par les équipes.
  const displayNameOf = (b: SharedTrainingBlock): string => {
    const label = (b.label ?? "").trim();
    return "" !== label ? label : sharedGroupLabel(b.teamIds, b.commonSessions, nameOf);
  };
  const labelPayload = (): string | null => ("" === groupName.trim() ? null : groupName.trim());

  const resetForm = () => {
    setEditingId(null);
    setChecked(new Set());
    setCommonSessions(1);
    setGroupName("");
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
    setGroupName(b.label ?? "");
    setError(null);
  };

  const submit = async () => {
    setError(null);
    const teamIds = [...checked];
    try {
      if (null !== editingId) {
        await update.mutateAsync({ id: editingId, body: { teamIds, commonSessions: effectiveK, label: labelPayload() } });
      } else {
        await create.mutateAsync({ schedulePlanId, teamIds, commonSessions: effectiveK, label: labelPayload() });
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
  // Heure de pendule : foyer unique `formatMinutes` (jamais un « 18h » fait main). La séance vient
  // du serveur sous forme d'instant ISO ; `parseTime` en tire les minutes.
  const sessionClock = (iso: string): string => formatMinutes(parseTime(iso) ?? 0);

  const candidateRow = (t: Team) => {
    // Multi-appartenance PERMISE : jamais de verrou. Un simple repère informatif dit combien de
    // blocs contiennent déjà l'équipe — jamais un warning (ce n'est pas un problème).
    const otherBlocks = blockMembershipCount(scopeBlocks, t.id, editingId);
    // L'ancre (la fiche ouverte) reste dans SON bloc : on ne la décoche pas sans le voir. Verrou sur
    // le GESTE de décocher (cochée), pas un forçage permanent.
    const anchorLocked = t.id === initialTeamId && checked.has(t.id);

    return (
      <div key={t.id} className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
        <label className={cn("flex items-center gap-1.5 text-sm", anchorLocked && "text-muted-foreground")}>
          <input type="checkbox" aria-label={t.name} checked={checked.has(t.id)} disabled={anchorLocked} onChange={() => toggle(t.id)} />
          {true === t.isCtc ? <CtcMark partner={t.ctcPartner} /> : null}
          {t.name}
        </label>
        {anchorLocked ? <span className="text-xs text-muted-foreground">{T.blockAnchorTeam}</span> : otherBlocks > 0 ? <span className="text-xs text-muted-foreground">{T.blockAlreadyInGroups(otherBlocks)}</span> : null}
      </div>
    );
  };

  return (
    <div>
      <p className="mb-3 text-sm text-muted-foreground">{T.blockIntro}</p>

      <div className="mb-4 rounded-lg border border-border bg-card p-3">
        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{null !== editingId ? T.blockEditTitle : T.blockNewTitle}</p>

        {0 === candidates.length ? (
          <EmptyHint>{T.blockNoTeamsYet}</EmptyHint>
        ) : (
          <>
            <Input type="search" aria-label={T.blockSearchTeam} placeholder={T.blockSearchTeam} className="mb-2" value={query} onChange={(e) => setQuery(e.target.value)} />

            {searching ? (
              <>
                <p aria-live="polite" className="mb-1 text-xs text-muted-foreground">
                  {T.blockTeamsFound(filtered.length)}
                </p>
                {0 === filtered.length ? <EmptyHint>{T.blockNoMatch(query.trim())}</EmptyHint> : <div className="flex flex-col gap-1">{filtered.map(candidateRow)}</div>}
              </>
            ) : (
              <div className="flex flex-col gap-1">{candidates.map(candidateRow)}</div>
            )}

            {/* Au-delà de 3 équipes cochées : inhabituel, jamais bloquant (le serveur va jusqu'à 10). */}
            {tooMany ? (
              <p role="alert" className="mt-3 text-xs text-warning">
                {T.blockTooMany(checked.size)}
              </p>
            ) : null}

            {/* E12 — « Nom du groupe » facultatif, borné à 40 (colonne serveur). */}
            <label className="mt-3 block text-xs text-muted-foreground">
              {T.blockNameLabel}
              <Input aria-label={T.blockNameLabel} placeholder={T.blockNamePlaceholder} className="mt-0.5" maxLength={40} value={groupName} onChange={(e) => setGroupName(e.target.value)} />
            </label>

            <div className="mt-3 flex flex-wrap items-end gap-2">
              <label className="text-xs text-muted-foreground">
                {T.blockCommonSessions}
                <Select
                  aria-label={T.blockCommonSessions}
                  aria-describedby="block-sessions-help"
                  className="mt-0.5"
                  wrapperClassName="w-40"
                  disabled={!sessionsReady}
                  value={String(effectiveK)}
                  onChange={(e) => setCommonSessions(Number(e.target.value) || 1)}
                >
                  {sessionsReady ? (
                    sessionOptions.map((n) => (
                      <option key={n} value={n}>
                        {T.blockCommonSessionsOption(n)}
                      </option>
                    ))
                  ) : (
                    <option value="1">{T.blockPickTwoTeamsFirst}</option>
                  )}
                </Select>
              </label>
              <span id="block-sessions-help" className="text-xs text-muted-foreground">
                {sessionsReady ? T.blockSessionsCap(cap) : T.blockSessionsCapHint}
              </span>
              {null !== editingId ? (
                <Button size="sm" variant="ghost" className="ml-auto" onClick={resetForm}>
                  {T.blockCancel}
                </Button>
              ) : null}
              <Button size="sm" className={cn("gap-1", null === editingId && "ml-auto")} disabled={!enoughTeams || !sessionsReady || busy} onClick={() => void submit()}>
                {null !== editingId ? <Check className="size-4" /> : <Plus className="size-4" />}
                {null !== editingId ? T.blockSave : T.blockCreate}
              </Button>
            </div>
            {!enoughTeams ? <p className="mt-1 text-xs text-muted-foreground">{T.blockPickTwoTeams}</p> : null}
            {null !== error ? (
              <p role="alert" className="mt-2 text-sm text-destructive">
                {error}
              </p>
            ) : null}
          </>
        )}
      </div>

      {0 === scopeBlocks.length ? (
        <EmptyHint>{T.blockNone}</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-1">
          {scopeBlocks.map((b) => (
            <li key={b.id} className={cn("flex items-center gap-2 rounded-md border bg-card px-3 py-1.5 text-sm", editingId === b.id ? "border-accent ring-1 ring-accent" : "border-border")}>
              <span className="flex-1">{displayNameOf(b)}</span>
              <button type="button" aria-label={T.blockEditAria(blockNames(b.teamIds))} className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={() => editBlock(b)}>
                <Pencil className="size-4" />
              </button>
              <button type="button" aria-label={T.blockDeleteAria(blockNames(b.teamIds))} className="rounded p-1 text-muted-foreground hover:text-destructive" onClick={() => setPendingDelete(b)}>
                <Trash2 className="size-4" />
              </button>
            </li>
          ))}
        </ul>
      )}

      <ConfirmDialog
        open={pendingDelete !== null}
        title={T.blockDeleteTitle}
        // Lot 9 — NOMMER ce que la suppression emporte : les séances communes DÉJÀ PLACÉES du groupe
        // disparaissent du planning (cascade `shared_training_block_id`). Quand le serveur les connaît
        // (bloc de période avec séances placées), on les NOMME une par une (équipe · jour · heure ·
        // gymnase) ; sinon (bloc socle, aucune séance) on retombe sur le texte par équipes.
        description={
          pendingDelete ? (
            pendingDelete.sessions.length > 0 ? (
              <>
                {T.blockDeleteWithSessions(displayNameOf(pendingDelete))}
                <ul className="mt-2 list-disc space-y-0.5 pl-5">
                  {pendingDelete.sessions.map((s, i) => (
                    <li key={`${s.teamId}-${i}`}>
                      {s.teamName} — {dayLabelLong(s.dayOfWeek)} {sessionClock(s.startTime)}-{sessionClock(s.endTime)}, {s.venueName}
                    </li>
                  ))}
                </ul>
              </>
            ) : (
              T.blockDeleteNoSessions(displayNameOf(pendingDelete), pendingDelete.teamIds.map(nameOf).join(", "))
            )
          ) : null
        }
        confirmLabel={T.blockDeleteConfirm}
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
