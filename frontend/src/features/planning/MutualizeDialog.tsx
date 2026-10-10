import { Lock, Users, X } from "lucide-react";
import { useMemo, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Select } from "@/shared/components/ui/select";
import { Spinner } from "@/shared/components/ui/spinner";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { dayLabelLong } from "@/shared/lib/days";
import { type TierLike } from "@/shared/lib/teamTiers";
import { formatMinutes, parseTime } from "@/shared/lib/time";

import type { MutualizeBody, Slot, Team } from "./api";

const LABEL_MAX = 40;

interface MutualizeDialogProps {
  /** La séance SOURCE (la case d'ancrage) : son équipe est pré-cochée et verrouillée dans le bloc. */
  anchor: Slot;
  teams: Team[];
  tiers: (TierLike & { color?: string | null })[];
  /** Les créneaux de la version affichée : servent à lister les séances de chaque équipe rejoignante
   *  (choix de la séance remplacée) et à repérer une équipe à 0 séance (activation annoncée). */
  slots: Slot[];
  teamName: (teamId: string) => string;
  venueName: (venueId: string) => string;
  busy: boolean;
  /** Message serveur d'un refus 422/409/404 (déjà humain), affiché TEL QUEL ; `null` = aucun. */
  error: string | null;
  onClose: () => void;
  onConfirm: (body: MutualizeBody) => void;
}

/** « lundi 18:00 · Gymnase A » — l'identité lisible d'une séance (jour · heure pendule · gymnase). */
function slotWhen(slot: Slot, venueName: (venueId: string) => string): string {
  const day = dayLabelLong(slot.dayOfWeek);
  const time = formatMinutes(parseTime(slot.startTime) ?? 0);
  return `${"" === day ? "?" : day} ${time} · ${venueName(slot.venueId)}`;
}

/**
 * Lot 9 — MUTUALISER DEPUIS LA GÉNÉRATION EN GARDANT LE CRÉNEAU (F3).
 *
 * Depuis la fiche d'une séance (plan de période), déclarer un bloc de mutualisation ancré à SA case :
 * la source reste sur place (sa séance devient la séance commune), et chaque équipe rattachée rejoint
 * la case. Trois cas par équipe rattachée, tous ANNONCÉS avant le geste (décisions fondateur) :
 *  - plusieurs séances → le gestionnaire CHOISIT celle que la séance commune remplace ;
 *  - une seule séance → elle est prise par défaut (annoncée) ;
 *  - aucune séance → l'équipe est ACTIVÉE dans le plan avec la séance du groupe (rien à retirer).
 *
 * Le serveur reste seul juge : la modale AFFICHE un refus 422 tel quel (capacité, Σ, inactive,
 * ensemble déjà déclaré…) sans rien re-dériver. Réutilise `Modal`/`Button`/`TeamSelect`/`StatusPill`.
 */
export function MutualizeDialog({ anchor, teams, tiers, slots, teamName, venueName, busy, error, onClose, onConfirm }: MutualizeDialogProps) {
  // Les équipes rattachées, dans l'ordre d'ajout (la source n'y figure jamais — elle est verrouillée).
  const [attached, setAttached] = useState<string[]>([]);
  // Par équipe rattachée AYANT plusieurs séances : l'id de la séance remplacée (défaut = la première).
  const [replacedByTeam, setReplacedByTeam] = useState<Record<string, string>>({});
  const [label, setLabel] = useState("");

  const sourceTeamId = anchor.teamId;

  // teamId → ses séances dans cette version (hors source : la source garde la sienne telle quelle).
  const slotsByTeam = useMemo(() => {
    const map = new Map<string, Slot[]>();
    for (const s of slots) {
      map.set(s.teamId, [...(map.get(s.teamId) ?? []), s]);
    }
    return map;
  }, [slots]);

  const attachedSet = new Set(attached);
  // Options du sélecteur d'ajout : toutes les équipes sauf la source et celles déjà rattachées.
  const addableTeams = teams.filter((t) => t.id !== sourceTeamId && !attachedSet.has(t.id));

  const addTeam = (teamId: string) => {
    if ("" === teamId || teamId === sourceTeamId || attachedSet.has(teamId)) {
      return;
    }
    setAttached((prev) => [...prev, teamId]);
    const teamSlots = slotsByTeam.get(teamId) ?? [];
    // Pré-sélection déterministe de la séance remplacée dès l'ajout (première séance) : pour une
    // équipe à séance unique c'est le « pris par défaut » annoncé ; pour plusieurs, le défaut du Select.
    if (teamSlots.length >= 1) {
      setReplacedByTeam((prev) => ({ ...prev, [teamId]: teamSlots[0].id }));
    }
  };

  const removeTeam = (teamId: string) => {
    setAttached((prev) => prev.filter((id) => id !== teamId));
    setReplacedByTeam((prev) => {
      const next = { ...prev };
      delete next[teamId];
      return next;
    });
  };

  const canConfirm = attached.length >= 1 && !busy;

  const submit = () => {
    if (!canConfirm) {
      return;
    }
    // replacedSlotIds : une entrée par équipe rattachée AYANT au moins une séance (la choisie).
    // Une équipe à 0 séance n'en porte aucune (le serveur l'active). La source n'y figure pas.
    const replacedSlotIds = attached
      .map((teamId) => replacedByTeam[teamId])
      .filter((id): id is string => undefined !== id);
    const trimmed = label.trim();
    onConfirm({ teamIds: attached, label: "" === trimmed ? undefined : trimmed, replacedSlotIds });
  };

  return (
    <Modal
      label="Mutualiser des équipes sur ce créneau"
      title={
        <span className="flex items-center gap-2">
          <Users className="size-5 text-accent" aria-hidden="true" />
          Mutualiser sur ce créneau
        </span>
      }
      size="md"
      onClose={onClose}
      footer={
        <div className="flex items-center justify-end gap-2">
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            Annuler
          </Button>
          <Button
            onClick={submit}
            disabled={!canConfirm}
            disabledReason={attached.length < 1 ? "Ajoutez au moins une équipe à mutualiser avec l'équipe de référence." : undefined}
          >
            {busy ? (
              <>
                <Spinner className="size-4" />
                Mutualisation…
              </>
            ) : (
              "Mutualiser"
            )}
          </Button>
        </div>
      }
    >
      <p className="text-sm text-muted-foreground">
        Les équipes choisies s'entraîneront ENSEMBLE sur ce créneau ({slotWhen(anchor, venueName)}), en une séance commune — sans quitter la case.
      </p>

      {/* La source : pré-cochée et VERROUILLÉE, avec le motif. Sa séance reste sur place. */}
      <div className="mt-4 flex items-start gap-2 rounded-md border border-border bg-muted px-3 py-2">
        <Lock className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        <div className="min-w-0 text-sm">
          <span className="font-medium text-foreground">{teamName(sourceTeamId)}</span>
          <p className="text-xs text-muted-foreground">Équipe de référence : sa séance reste sur place et devient la séance commune du groupe.</p>
        </div>
      </div>

      <div className="mt-4">
        <p className="mb-1 text-sm font-medium text-foreground">Équipes à rattacher</p>
        <TeamSelect
          teams={addableTeams}
          tiers={tiers}
          value=""
          onValueChange={addTeam}
          placeholder="Ajouter une équipe…"
          aria-label="Ajouter une équipe à mutualiser"
          disabled={0 === addableTeams.length || busy}
        />
        {attached.length > 0 ? (
          <ul className="mt-3 flex flex-col gap-2">
            {attached.map((teamId) => {
              const teamSlots = slotsByTeam.get(teamId) ?? [];
              return (
                <li key={teamId} className="rounded-md border border-border bg-card px-3 py-2">
                  <div className="flex items-center gap-2">
                    <span className="min-w-0 flex-1 truncate text-sm font-medium text-foreground">{teamName(teamId)}</span>
                    {0 === teamSlots.length ? <StatusPill variant="accent">via mutualisation</StatusPill> : null}
                    <button
                      type="button"
                      onClick={() => removeTeam(teamId)}
                      aria-label={`Retirer ${teamName(teamId)}`}
                      className="rounded p-1 text-muted-foreground hover:text-destructive"
                    >
                      <X className="size-4" aria-hidden="true" />
                    </button>
                  </div>
                  {0 === teamSlots.length ? (
                    <p className="mt-1 text-xs text-muted-foreground">Cette équipe n'a aucune séance : elle sera activée avec la séance du groupe.</p>
                  ) : 1 === teamSlots.length ? (
                    <p className="mt-1 text-xs text-muted-foreground">Sa séance du {slotWhen(teamSlots[0], venueName)} sera remplacée par la séance commune.</p>
                  ) : (
                    <label className="mt-2 block text-xs text-muted-foreground">
                      Séance remplacée
                      <Select
                        aria-label={`Séance remplacée pour ${teamName(teamId)}`}
                        className="mt-0.5"
                        value={replacedByTeam[teamId] ?? teamSlots[0].id}
                        onChange={(e) => setReplacedByTeam((prev) => ({ ...prev, [teamId]: e.target.value }))}
                      >
                        {teamSlots.map((s) => (
                          <option key={s.id} value={s.id}>
                            {slotWhen(s, venueName)}
                          </option>
                        ))}
                      </Select>
                    </label>
                  )}
                </li>
              );
            })}
          </ul>
        ) : (
          <p className="mt-2 text-xs text-muted-foreground">Ajoutez au moins une équipe pour former le groupe.</p>
        )}
      </div>

      <div className="mt-4">
        <label className="block text-sm font-medium text-foreground" htmlFor="mutualize-label">
          Nom du groupe <span className="font-normal text-muted-foreground">(optionnel)</span>
        </label>
        <Input
          id="mutualize-label"
          className="mt-1"
          maxLength={LABEL_MAX}
          placeholder="Ex. U11"
          value={label}
          onChange={(e) => setLabel(e.target.value)}
        />
        <p className="mt-1 text-xs text-muted-foreground">Affiché sur la grille, la fiche et les exports. {LABEL_MAX} caractères maximum.</p>
      </div>

      {null !== error ? (
        <NoticeBanner tone="destructive" role="alert" className="mt-4" message={error} />
      ) : null}
    </Modal>
  );
}
