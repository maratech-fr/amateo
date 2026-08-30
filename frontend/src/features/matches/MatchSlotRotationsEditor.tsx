import { ChevronDown, ChevronUp, Plus, Trash2, X } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { errorMessage } from "@/shared/lib/errorMessage";
import type { TeamLike, TierLike } from "@/shared/lib/teamTiers";

import type { MatchSlotRotation, Venue } from "./api";
import { useCreateMatchSlotRotation, useDeleteMatchSlotRotation, useMatchSlotRotations, useUpdateMatchSlotRotation } from "./queries";

const DAY_LABELS = ["", "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi", "Dimanche"];

/** A/B/C… — l'étiquette d'alternance d'une position (fictive : elle ne pilote aucun calendrier). */
const positionLabel = (index: number): string => String.fromCharCode(65 + index);

/** Déplace l'élément `from` vers `to` (bornes gardées) — pur, testé via les clics. */
function reordered<T>(list: T[], from: number, to: number): T[] {
  if (to < 0 || to >= list.length) {
    return list;
  }
  const copy = [...list];
  const [moved] = copy.splice(from, 1);
  copy.splice(to, 0, moved);
  return copy;
}

/**
 * RMM-5 PR-4 — l'éditeur « Créneaux partagés (alternance) » du SET-UP : déclarer un
 * créneau physique (gymnase + jour + heure) et les N équipes qui l'occupent en
 * alternance, DANS L'ORDRE (A/B/C…). Frère de `MatchWindowsEditor` et
 * `TeamLinksSection` — liste bordée inline, réordonnancement par flèches (jamais du
 * drag : sans clavier ni cible tactile fiable pour un gestionnaire de 50 ans).
 *
 * ⚠ L'ordre est FICTIF : il dessine l'alternance à l'écran, il ne commande AUCUN
 * calendrier (décision fondateur n°4, spec §8) — dit à l'écran, jamais tu.
 *
 * Générique sur `TeamLike` (comme `TeamLinksSection`) : l'éditeur n'a besoin que de
 * l'id + le nom des équipes.
 */
export function MatchSlotRotationsEditor<T extends TeamLike>({ teams, tiers, venues }: { teams: T[]; tiers: TierLike[]; venues: Venue[] }) {
  const rotationsQuery = useMatchSlotRotations();
  const create = useCreateMatchSlotRotation();
  const update = useUpdateMatchSlotRotation();
  const remove = useDeleteMatchSlotRotation();

  const rotations = rotationsQuery.data ?? [];
  const venueName = (id: string): string => venues.find((v) => v.id === id)?.name ?? "Gymnase ?";
  const teamName = (id: string): string => teams.find((t) => t.id === id)?.name ?? "Équipe ?";

  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-semibold">Créneaux partagés (alternance)</h3>
        <p className="text-xs text-muted-foreground">
          Un créneau de match rare (gymnase, jour, heure) partagé par plusieurs équipes qui s'y relaient — une semaine chacune. L'ordre dessine seulement
          l'alternance A/B/C ; il ne commande aucun calendrier.
        </p>
      </div>

      {rotationsQuery.isError ? <p className="text-sm text-destructive">Les créneaux partagés n'ont pas pu être chargés.</p> : null}

      {0 === rotations.length && !rotationsQuery.isLoading ? (
        <EmptyHint className="text-xs">Aucun créneau partagé déclaré — ajoutez-en un ci-dessous si deux équipes se relaient sur un même créneau.</EmptyHint>
      ) : null}

      <ul className="flex flex-col gap-2">
        {rotations.map((rotation) => (
          <RotationRow
            key={rotation.id}
            rotation={rotation}
            teams={teams}
            tiers={tiers}
            venueName={venueName}
            teamName={teamName}
            busy={update.isPending || remove.isPending}
            onUpdate={(teamIds) => update.mutate({ id: rotation.id, input: { venueId: rotation.venueId, dayOfWeek: rotation.dayOfWeek, kickoffTime: rotation.kickoffTime, teamIds } })}
            onDelete={() => remove.mutate(rotation.id)}
          />
        ))}
      </ul>

      <NewRotationForm teams={teams} tiers={tiers} venues={venues} teamName={teamName} creating={create.isPending} onCreate={create} />
    </section>
  );
}

/** Une rotation existante : le créneau lisible + ses membres ordonnés, édités en place (PUT). */
function RotationRow<T extends TeamLike>({
  rotation,
  teams,
  tiers,
  venueName,
  teamName,
  busy,
  onUpdate,
  onDelete,
}: {
  rotation: MatchSlotRotation;
  teams: T[];
  tiers: TierLike[];
  venueName: (id: string) => string;
  teamName: (id: string) => string;
  busy: boolean;
  onUpdate: (teamIds: string[]) => void;
  onDelete: () => void;
}) {
  const [toAdd, setToAdd] = useState("");
  const available = teams.filter((t) => !rotation.teamIds.includes(t.id));
  const slotLabel = `${DAY_LABELS[rotation.dayOfWeek] ?? "?"} ${rotation.kickoffTime} · ${venueName(rotation.venueId)}`;

  const addTeam = (): void => {
    if ("" === toAdd) {
      return;
    }
    onUpdate([...rotation.teamIds, toAdd]);
    setToAdd("");
  };

  return (
    <li className="flex flex-col gap-2 rounded-md border border-border px-3 py-2">
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium">{slotLabel}</span>
        <Button variant="ghost" size="icon" className="size-7" aria-label={`Supprimer le créneau ${slotLabel}`} disabled={busy} onClick={onDelete}>
          <Trash2 className="size-4" />
        </Button>
      </div>

      <MemberList
        teamIds={rotation.teamIds}
        teamName={teamName}
        busy={busy}
        onReorder={(from, to) => onUpdate(reordered(rotation.teamIds, from, to))}
        onRemove={(index) => onUpdate(rotation.teamIds.filter((_, i) => i !== index))}
      />

      {available.length > 0 ? (
        <div className="flex flex-wrap items-end gap-2">
          <TeamSelect aria-label={`Ajouter une équipe au créneau ${slotLabel}`} className="w-40" teams={available} tiers={tiers} placeholder="Ajouter une équipe…" value={toAdd} onChange={(e) => setToAdd(e.target.value)} />
          <Button size="icon" className="size-9" aria-label={`Ajouter l'équipe au créneau ${slotLabel}`} title="Ajouter l'équipe" disabled={"" === toAdd || busy} onClick={addTeam}>
            <Plus className="size-4" />
          </Button>
        </div>
      ) : null}
    </li>
  );
}

/** La liste ordonnée des membres (A/B/C…) avec flèches monter/descendre + retrait. */
function MemberList({
  teamIds,
  teamName,
  busy,
  onReorder,
  onRemove,
}: {
  teamIds: string[];
  teamName: (id: string) => string;
  busy: boolean;
  onReorder: (from: number, to: number) => void;
  onRemove: (index: number) => void;
}) {
  return (
    <ol className="flex flex-col gap-1">
      {teamIds.map((teamId, index) => (
        <li key={teamId} className="flex items-center gap-2 rounded-md bg-muted/50 px-2 py-1 text-sm">
          <span className="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-accent/15 text-xs font-semibold text-accent-foreground" aria-hidden>
            {positionLabel(index)}
          </span>
          <span className="flex-1 truncate">
            <span className="sr-only">Position {positionLabel(index)} — </span>
            {teamName(teamId)}
          </span>
          <Button variant="ghost" size="icon" className="size-7" aria-label={`Monter ${teamName(teamId)}`} disabled={busy || 0 === index} onClick={() => onReorder(index, index - 1)}>
            <ChevronUp className="size-4" />
          </Button>
          <Button variant="ghost" size="icon" className="size-7" aria-label={`Descendre ${teamName(teamId)}`} disabled={busy || index === teamIds.length - 1} onClick={() => onReorder(index, index + 1)}>
            <ChevronDown className="size-4" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            className="size-7"
            aria-label={`Retirer ${teamName(teamId)} du créneau`}
            disabled={busy || teamIds.length <= 2}
            title={teamIds.length <= 2 ? "Un créneau partagé compte au moins deux équipes." : undefined}
            onClick={() => onRemove(index)}
          >
            <X className="size-4" />
          </Button>
        </li>
      ))}
    </ol>
  );
}

/** Le formulaire de création : le créneau + une liste ordonnée d'équipes en brouillon local. */
function NewRotationForm<T extends TeamLike>({
  teams,
  tiers,
  venues,
  teamName,
  creating,
  onCreate,
}: {
  teams: T[];
  tiers: TierLike[];
  venues: Venue[];
  teamName: (id: string) => string;
  creating: boolean;
  onCreate: ReturnType<typeof useCreateMatchSlotRotation>;
}) {
  const [venueId, setVenueId] = useState("");
  const [dayOfWeek, setDayOfWeek] = useState(6);
  const [kickoffTime, setKickoffTime] = useState("20:30");
  const [draftTeamIds, setDraftTeamIds] = useState<string[]>([]);
  const [toAdd, setToAdd] = useState("");
  const [formError, setFormError] = useState<string | null>(null);

  const available = teams.filter((t) => !draftTeamIds.includes(t.id));
  const canCreate = "" !== venueId && "" !== kickoffTime && draftTeamIds.length >= 2 && !creating;

  const addDraft = (): void => {
    if ("" === toAdd) {
      return;
    }
    setDraftTeamIds((ids) => [...ids, toAdd]);
    setToAdd("");
  };

  const submit = (): void => {
    if (!canCreate) {
      return;
    }
    setFormError(null);
    onCreate.mutate(
      { venueId, dayOfWeek, kickoffTime, teamIds: draftTeamIds },
      {
        onSuccess: () => {
          setDraftTeamIds([]);
          setToAdd("");
          setVenueId("");
        },
        // 422 lisible SUR le formulaire (en plus du toast global) — le geste échoué
        // reste sous les yeux, avec sa raison (créneau déjà pris, équipe étrangère…).
        onError: (error) => void errorMessage(error).then(setFormError),
      },
    );
  };

  return (
    <div className="flex flex-col gap-2 rounded-md border border-dashed border-border px-3 py-2">
      <p className="text-xs font-medium text-muted-foreground">Nouveau créneau partagé</p>

      <div className="flex flex-wrap items-end gap-2">
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Gymnase
          <Select aria-label="Gymnase du créneau partagé" className="w-40" value={venueId} onChange={(e) => setVenueId(e.target.value)}>
            <option value="">Choisir…</option>
            {venues.map((v) => (
              <option key={v.id} value={v.id}>
                {v.name}
              </option>
            ))}
          </Select>
        </label>
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Jour
          <Select aria-label="Jour du créneau partagé" className="w-32" value={dayOfWeek} onChange={(e) => setDayOfWeek(Number(e.target.value))}>
            {[1, 2, 3, 4, 5, 6, 7].map((day) => (
              <option key={day} value={day}>
                {DAY_LABELS[day]}
              </option>
            ))}
          </Select>
        </label>
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Heure
          <input aria-label="Heure du créneau partagé" type="time" className="h-9 rounded-md border border-border bg-background px-2 text-sm" value={kickoffTime} onChange={(e) => setKickoffTime(e.target.value)} />
        </label>
      </div>

      {draftTeamIds.length > 0 ? (
        <MemberList
          teamIds={draftTeamIds}
          teamName={teamName}
          busy={creating}
          onReorder={(from, to) => setDraftTeamIds((ids) => reordered(ids, from, to))}
          onRemove={(index) => setDraftTeamIds((ids) => ids.filter((_, i) => i !== index))}
        />
      ) : (
        <p className="text-xs text-muted-foreground">Ajoutez les équipes une à une, dans l'ordre d'alternance (au moins deux).</p>
      )}

      <div className="flex flex-wrap items-end gap-2">
        <TeamSelect aria-label="Ajouter une équipe au nouveau créneau" className="w-40" teams={available} tiers={tiers} placeholder="Ajouter une équipe…" value={toAdd} onChange={(e) => setToAdd(e.target.value)} />
        <Button variant="outline" size="sm" aria-label="Ajouter l'équipe au nouveau créneau" disabled={"" === toAdd} onClick={addDraft}>
          <Plus className="size-4" />
          Ajouter
        </Button>
        <Button size="sm" disabled={!canCreate} onClick={submit}>
          Créer le créneau
        </Button>
      </div>

      {formError !== null ? (
        <p role="alert" className="text-xs text-destructive">
          {formError}
        </p>
      ) : null}
    </div>
  );
}
