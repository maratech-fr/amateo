import { type FormEvent, useState } from "react";

import type { Coach, PriorityTier, Team, TeamCoach } from "@/features/wizard/api";
import { Button } from "@/shared/components/ui/button";
import { DayMultiPicker } from "@/shared/components/ui/day-multi-picker";
import { FIELD_CLASS } from "@/shared/components/ui/field";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { DAYS } from "@/shared/lib/days";
import { cn } from "@/shared/lib/utils";

import type { CoachWish, CoachWishPayload } from "./api";
import type { WeekWindow } from "@/features/cockpit/lib/date";

/** Les sept jours ISO (foyer `shared/lib/days`), pour calculer le complément « disponibles ». */
const ALL_DAYS: number[] = DAYS.map((d) => d.n);

/**
 * Saisie « au nom d'un coach » d'une doléance (feature #10, lot C1) — ajout ou édition.
 * Le gestionnaire recueille les souhaits par WhatsApp/téléphone et les consigne ici.
 *
 * P2-63 PR 2 (D + Q5, fondateur 2026-10-09) :
 *  - SEMAINE D'ABORD, puis équipe : « sélection de la semaine d'abord, ça filtre ensuite la
 *    liste des équipes qui n'en ont pas sur la semaine » ;
 *  - TOUTES les équipes sont offertes (plus seulement celles à coach principal) — le filtre
 *    « coach principal » de `WishesTab` ne s'applique qu'à la collecte par mail, pas à la
 *    saisie manuelle ;
 *  - une équipe DÉJÀ servie sur la semaine choisie est DÉSACTIVÉE avec motif (jamais masquée :
 *    « un état invisible est un état faux ») ;
 *  - le coach est FACULTATIF (« (aucun) ») : une équipe sans coach (Vétérans) peut avoir une
 *    doléance manuelle.
 *
 * La semaine est FIGÉE quand la modale est filtrée sur une semaine (vue wizard d'un plan
 * de semaine) : on ne saisit alors une doléance que pour cette semaine-là.
 */
export function CoachWishForm({
  calendarEntryId,
  weeks,
  lockedWeek,
  teams,
  tiers,
  coaches,
  teamCoaches,
  servedByWeek,
  editing,
  onSubmit,
  onCancel,
  pending,
}: {
  calendarEntryId: string;
  weeks: WeekWindow[];
  lockedWeek: string | null;
  teams: Team[];
  tiers: PriorityTier[];
  coaches: Coach[];
  teamCoaches: TeamCoach[];
  /** Pour chaque lundi, les équipes qui ONT déjà une doléance cette semaine-là (désactivées). */
  servedByWeek: Map<string, Set<string>>;
  editing: CoachWish | null;
  onSubmit: (payload: CoachWishPayload) => void;
  onCancel: () => void;
  pending: boolean;
}) {
  const mainCoachIds = (t: string): string[] => teamCoaches.filter((tc) => tc.teamId === t && "MAIN" === tc.role).map((tc) => tc.coachId);
  const mainCoachId = (t: string): string => mainCoachIds(t)[0] ?? "";

  const initialTeamId = editing?.teamId ?? teams[0]?.id ?? "";
  const [weekStart, setWeekStart] = useState(editing?.weekStart ?? lockedWeek ?? weeks[0]?.monday ?? "");
  const [teamId, setTeamId] = useState(initialTeamId);
  // À la création, le coach PRINCIPAL de l'équipe est le défaut ; l'édition garde ce qui est là
  // (y compris "" d'une doléance dé-attribuée). « (aucun) » est toujours un choix valide.
  const [coachId, setCoachId] = useState(editing?.coachId ?? (null === editing ? mainCoachId(initialTeamId) : ""));
  const [slotsWanted, setSlotsWanted] = useState(editing?.slotsWanted ?? 1);
  const [days, setDays] = useState<number[]>(editing?.unavailableDays ?? []);
  const [wishedDays, setWishedDays] = useState<number[]>(editing?.wishedDays ?? []);
  const [comment, setComment] = useState(editing?.comment ?? "");

  // Exclusion souhaité ∩ indisponible : cocher un jour d'un côté le retire de l'autre
  // (dernier geste gagne) — même règle que la garde serveur (422), ici silencieuse.
  const changeDays = (next: number[]): void => {
    setDays(next);
    setWishedDays((w) => w.filter((d) => !next.includes(d)));
  };
  const changeWishedDays = (next: number[]): void => {
    setWishedDays(next);
    setDays((d) => d.filter((x) => !next.includes(x)));
  };
  // P2-63 A — le picker montre les jours DISPONIBLES (complément des indisponibilités), tous
  // pressés par défaut. La donnée/le payload restent `unavailableDays` : dépresser un jour
  // disponible revient à l'inscrire en indisponible.
  const availableDays = ALL_DAYS.filter((d) => !days.includes(d));
  const changeAvailableDays = (nextAvailable: number[]): void => changeDays(ALL_DAYS.filter((d) => !nextAvailable.includes(d)));

  const isEdit = null !== editing;

  // Équipes déjà servies sur la semaine choisie : désactivées, jamais masquées. En édition on
  // ne touche pas la semaine/l'équipe (verrouillées), donc le motif ne s'applique qu'à l'ajout.
  const servedTeams = servedByWeek.get(weekStart) ?? new Set<string>();
  const isServed = (id: string): boolean => !isEdit && servedTeams.has(id);

  // Coach : liste les coachs PRINCIPAUX de l'équipe choisie, + la valeur courante si elle n'y
  // est plus (choisir n'est pas nommer, leçon #342). « (aucun) » est toujours offert.
  const offerableCoachIds = new Set(mainCoachIds(teamId));
  const offeredCoaches = coaches.filter((c) => offerableCoachIds.has(c.id) || c.id === coachId);

  const selectedTeamServed = isServed(teamId);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    // Coach FACULTATIF : seuls l'équipe et la semaine sont requis ; une équipe déjà servie
    // sur la semaine ne peut pas recevoir une deuxième doléance (le serveur rend un 422).
    if ("" === teamId || "" === weekStart || selectedTeamServed) {
      return;
    }
    onSubmit({
      calendarEntryId,
      weekStart,
      teamId,
      coachId: "" === coachId ? null : coachId,
      slotsWanted,
      unavailableDays: days,
      wishedDays,
      comment: "" === comment.trim() ? null : comment.trim(),
      done: editing?.done ?? false,
    });
  };

  return (
    <form onSubmit={submit} className="space-y-2 rounded-md border border-border bg-surface-muted p-2">
      <div className="flex flex-wrap items-end gap-2">
        {/* P2-63 D — la SEMAINE se choisit d'abord : c'est elle qui décide quelles équipes sont
            encore disponibles (celles déjà servies sont désactivées, jamais retirées). */}
        <label className="text-xs text-muted-foreground">
          Semaine
          <Select
            aria-label="Semaine"
            wrapperClassName="mt-0.5 w-40"
            value={weekStart}
            disabled={null !== lockedWeek || null !== editing}
            onChange={(e) => setWeekStart(e.target.value)}
          >
            {weeks.map((w) => (
              <option key={w.monday} value={w.monday}>
                Semaine du {w.startDate}
              </option>
            ))}
          </Select>
        </label>
        {/* P2-63 C — sélecteur d'équipe PARTAGÉ (TeamSelect : groupes par rang, pastille couleur,
            recherche au-delà de 8) : visible « Équipe » en caption, nom accessible via `aria-label`
            (TeamSelect est un listbox bouton, pas un `<select>` labellable). Une équipe déjà servie
            sur la semaine est désactivée AVEC MOTIF (option atteignable au clavier, inerte). */}
        <div className="text-xs text-muted-foreground">
          Équipe
          <TeamSelect
            aria-label="Équipe"
            wrapperClassName="mt-0.5 w-40"
            teams={teams}
            tiers={tiers}
            value={teamId}
            disabled={null !== editing}
            optionMeta={(t) => (isServed(t.id) ? { disabled: true, sub: "a déjà une doléance cette semaine" } : {})}
            onValueChange={(v) => {
              setTeamId(v);
              setCoachId(mainCoachId(v)); // recalcule le défaut coach sur la nouvelle équipe
            }}
          />
        </div>
        <label className="text-xs text-muted-foreground">
          Coach
          <Select aria-label="Coach" wrapperClassName="mt-0.5 w-40" value={coachId} onChange={(e) => setCoachId(e.target.value)}>
            <option value="">(aucun)</option>
            {offeredCoaches.map((c) => (
              <option key={c.id} value={c.id}>
                {c.firstName} {c.lastName}
                {offerableCoachIds.has(c.id) ? "" : " (n'encadre plus cette équipe)"}
              </option>
            ))}
          </Select>
        </label>
        <label className="text-xs text-muted-foreground">
          Créneaux souhaités
          <Select aria-label="Créneaux souhaités" wrapperClassName="mt-0.5 w-24" value={slotsWanted} onChange={(e) => setSlotsWanted(Number(e.target.value))}>
            {[0, 1, 2, 3, 4, 5, 6, 7].map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </Select>
        </label>
      </div>

      <DayMultiPicker legend="Jours souhaités" legendVisible tone="accent" value={wishedDays} onChange={changeWishedDays} />

      {/* Pressé = DISPONIBLE (positif) : `tone="accent"`, jamais `destructive` (« bloqué »). */}
      <DayMultiPicker legend="Jours disponibles" legendVisible tone="accent" value={availableDays} onChange={changeAvailableDays} />

      <textarea
        aria-label="Commentaire"
        placeholder="Commentaire (mutualisation, contexte…)"
        className={cn("min-h-16", FIELD_CLASS)}
        maxLength={1000}
        value={comment}
        onChange={(e) => setComment(e.target.value)}
      />

      <div className="flex justify-end gap-2">
        <Button type="button" size="sm" variant="ghost" onClick={onCancel}>
          Annuler
        </Button>
        <Button
          type="submit"
          size="sm"
          disabled={pending || "" === teamId || selectedTeamServed}
          disabledReason={selectedTeamServed ? "Cette équipe a déjà une doléance cette semaine" : undefined}
        >
          {null !== editing ? "Enregistrer" : "Ajouter la doléance"}
        </Button>
      </div>
    </form>
  );
}
