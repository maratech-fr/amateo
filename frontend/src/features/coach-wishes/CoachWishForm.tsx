import { type FormEvent, useState } from "react";

import type { Coach, PriorityTier, Team, TeamCoach } from "@/features/wizard/api";
import { Button } from "@/shared/components/ui/button";
import { DayMultiPicker } from "@/shared/components/ui/day-multi-picker";
import { FIELD_CLASS } from "@/shared/components/ui/field";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { DAYS } from "@/shared/lib/days";
import { cn } from "@/shared/lib/utils";

import type { CoachWish, CoachWishPayload } from "./api";
import { COACH_WISHES_MANAGER_TEXTS as TEXTS } from "./texts/manager";
import { frDate } from "./wishSections";
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
 *    « un état invisible est un état faux »).
 *
 * P2-63 lot 5 (retours fondateur 2026-10-10) :
 *  - PLUS de champ Coach : le coach est DÉDUIT — à la création, le coach PRINCIPAL destinataire
 *    de l'équipe, sinon le premier principal (sinon aucun), recalculé quand l'équipe change ; à l'édition, le `coachId`
 *    existant est gardé tel quel, jamais dé-attribué en silence. Côté serveur, rien ne change
 *    (coach facultatif, `coachId` nullable) ;
 *  - « Créneaux souhaités » est un champ NOMBRE (0-7, bornes serveur) ;
 *  - les jours de l'équipe AU-DESSUS de « Jours souhaités » ; un jour fermé est DÉSACTIVÉ côté
 *    souhaités (un jour souhaité est au minimum un jour où l'équipe peut s'entraîner).
 *
 * Les absences par personne se saisissent sur le staff COURANT de l'équipe : une ligne d'une
 * personne partie est abandonnée à l'enregistrement.
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
  teamCoaches,
  coaches,
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
  teamCoaches: TeamCoach[];
  coaches: Coach[];
  /** Pour chaque lundi, les équipes qui ONT déjà une doléance cette semaine-là (désactivées). */
  servedByWeek: Map<string, Set<string>>;
  editing: CoachWish | null;
  onSubmit: (payload: CoachWishPayload) => void;
  onCancel: () => void;
  pending: boolean;
}) {
  const mainCoachId = (t: string): string => {
    const mains = teamCoaches.filter((tc) => tc.teamId === t && "MAIN" === tc.role);
    return (mains.find((tc) => tc.wishRecipient) ?? mains[0])?.coachId ?? "";
  };

  const initialTeamId = editing?.teamId ?? teams[0]?.id ?? "";
  const [weekStart, setWeekStart] = useState(editing?.weekStart ?? lockedWeek ?? weeks[0]?.monday ?? "");
  const [teamId, setTeamId] = useState(initialTeamId);
  // Coach DÉDUIT, sans champ (retours fondateur 2026-10-10) : à la création le coach PRINCIPAL
  // de l'équipe (sinon aucun), recalculé quand l'équipe change ; à l'édition le `coachId`
  // existant est gardé TEL QUEL (y compris null), jamais dé-attribué en silence.
  const [coachId, setCoachId] = useState(editing?.coachId ?? (null === editing ? mainCoachId(initialTeamId) : ""));
  const [slotsWanted, setSlotsWanted] = useState(editing?.slotsWanted ?? 1);
  const [days, setDays] = useState<number[]>(editing?.unavailableDays ?? []);
  // Jours d'absence par personne. À l'enregistrement seul le staff courant de l'équipe est relu.
  const [staff, setStaff] = useState<Record<string, number[]>>(() => Object.fromEntries((editing?.staffUnavailability ?? []).map((line) => [line.coachId, line.days])));
  const [wishedDays, setWishedDays] = useState<number[]>(editing?.wishedDays ?? []);
  const [comment, setComment] = useState(editing?.comment ?? "");
  const [keepSeasonSlots, setKeepSeasonSlots] = useState(editing?.keepSeasonSlots ?? false);
  // Marquage manuel « traité / non traité » (retour fondateur 2026-10-11) : la coche vit dans le
  // formulaire (le crayon), plus dans la liste. Le serveur ACCEPTE déjà `done` au PUT (CoachWishInput,
  // groupe `write`) — rien à changer côté API.
  const [done, setDone] = useState(editing?.done ?? false);

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

  // Le staff COURANT de l'équipe choisie : principaux puis adjoints, recalculé au changement d'équipe.
  const coachById = new Map(coaches.map((c) => [c.id, c]));
  const links = teamCoaches.filter((tc) => tc.teamId === teamId && coachById.has(tc.coachId));
  const staffLinks = [...links.filter((tc) => "MAIN" === tc.role), ...links.filter((tc) => "MAIN" !== tc.role)];

  // Équipes déjà servies sur la semaine choisie : désactivées, jamais masquées. En édition on
  // ne touche pas la semaine/l'équipe (verrouillées), donc le motif ne s'applique qu'à l'ajout.
  const servedTeams = servedByWeek.get(weekStart) ?? new Set<string>();
  const isServed = (id: string): boolean => !isEdit && servedTeams.has(id);

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
      staffUnavailability: staffLinks
        .filter((tc) => (staff[tc.coachId] ?? []).length > 0)
        .map((tc) => ({ coachId: tc.coachId, days: [...staff[tc.coachId]].sort((a, b) => a - b) })),
      wishedDays,
      comment: "" === comment.trim() ? null : comment.trim(),
      done,
      keepSeasonSlots,
    });
  };

  return (
    <form onSubmit={submit} className="space-y-2 rounded-md border border-border bg-surface-muted p-2">
      <div className="flex flex-wrap items-end gap-2">
        {/* P2-63 D — la SEMAINE se choisit d'abord : c'est elle qui décide quelles équipes sont
            encore disponibles (celles déjà servies sont désactivées, jamais retirées). */}
        {/* Semaine assez large pour « Semaine du 19/10/2026 » en entier (jamais tronqué, retours
            fondateur 2026-10-10). */}
        <label className="text-xs text-muted-foreground">
          {TEXTS.formWeek}
          <Select
            aria-label={TEXTS.formWeek}
            wrapperClassName="mt-0.5 w-64"
            value={weekStart}
            disabled={null !== lockedWeek || null !== editing}
            onChange={(e) => setWeekStart(e.target.value)}
          >
            {weeks.map((w) => (
              <option key={w.monday} value={w.monday}>
                {TEXTS.weekTitle(frDate(w.startDate))}
              </option>
            ))}
          </Select>
        </label>
        {/* P2-63 C — sélecteur d'équipe PARTAGÉ (TeamSelect : groupes par rang, pastille couleur,
            recherche au-delà de 8) : visible « Équipe » en caption, nom accessible via `aria-label`
            (TeamSelect est un listbox bouton, pas un `<select>` labellable). Une équipe déjà servie
            sur la semaine est désactivée AVEC MOTIF (option atteignable au clavier, inerte). Le
            PANNEAU est élargi (`panelMinWidth`) pour que le motif et la recherche respirent, plutôt
            que de gonfler le champ (retours fondateur 2026-10-10). */}
        <div className="text-xs text-muted-foreground">
          {TEXTS.formTeam}
          <TeamSelect
            aria-label={TEXTS.formTeam}
            wrapperClassName="mt-0.5 w-40"
            panelMinWidth={320}
            teams={teams}
            tiers={tiers}
            value={teamId}
            disabled={null !== editing}
            optionMeta={(t) => (isServed(t.id) ? { disabled: true, sub: TEXTS.formAlreadyServed } : {})}
            onValueChange={(v) => {
              setTeamId(v);
              setCoachId(mainCoachId(v)); // coach déduit : recalcule le coach principal de la nouvelle équipe
              setStaff({}); // une autre équipe, un autre staff
            }}
          />
        </div>
        {/* Créneaux souhaités = champ NOMBRE borné 0-7 (bornes serveur, retours fondateur 2026-10-10). */}
        <label className="text-xs text-muted-foreground">
          {TEXTS.formSlots}
          <Input
            type="number"
            min={0}
            max={7}
            aria-label={TEXTS.formSlots}
            className="mt-0.5 w-20"
            value={slotsWanted}
            onChange={(e) => setSlotsWanted(Math.max(0, Math.min(7, Number(e.target.value) || 0)))}
          />
        </label>
      </div>

      {/* Les jours de l'équipe AU-DESSUS (tout pressé par défaut, on retire les jours fermés) ;
          « Jours souhaités » dessous. Pressé = DISPONIBLE (positif) : `tone="accent"`. */}
      <DayMultiPicker legend={TEXTS.formTeamDaysLegend} legendVisible tone="accent" value={availableDays} onChange={changeAvailableDays} />

      {/* Un jour souhaité est au minimum un jour DISPONIBLE : les jours indisponibles (`days`) sont
          DÉSACTIVÉS ici (visibles, grisés, avec motif), jamais seulement décochés. */}
      <DayMultiPicker
        legend={TEXTS.formWishedDaysLegend}
        legendVisible
        tone="accent"
        value={wishedDays}
        onChange={changeWishedDays}
        disabledDays={days}
        disabledReason={TEXTS.formWishedDayDisabledReason}
      />

      {/* Une ligne par personne, masquée pour une équipe sans lien. Pressé = disponible. */}
      {staffLinks.length > 0 ? (
        <div className="space-y-2 border-t border-border pt-2">
          <p className="text-sm font-medium">{TEXTS.formStaffTitle}</p>
          {staffLinks.map((tc) => {
            const coach = coachById.get(tc.coachId) as Coach;
            const absent = staff[tc.coachId] ?? [];
            return (
              <DayMultiPicker
                key={tc.coachId}
                legend={TEXTS.formStaffMemberLegend(coach.firstName, coach.lastName, tc.role)}
                legendVisible
                tone="accent"
                value={ALL_DAYS.filter((d) => !absent.includes(d))}
                onChange={(next) => setStaff((prev) => ({ ...prev, [tc.coachId]: ALL_DAYS.filter((d) => !next.includes(d)) }))}
              />
            );
          })}
        </div>
      ) : null}

      {/* Volet B — « garder les créneaux habituels » (ceux du planning de saison), par
          équipe × semaine. Souhait de plus ; le transfert vers le planning l'honore. */}
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="size-4 accent-[var(--accent)]" checked={keepSeasonSlots} onChange={() => setKeepSeasonSlots((v) => !v)} />
        {TEXTS.formKeepSlots}
      </label>

      {/* Marquage manuel « traité » (retour fondateur 2026-10-11) : la pastille verte « Traité » de la
          liste suit ce drapeau. Une doléance transférée est déjà traitée ; ici on peut la (dé)marquer. */}
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="size-4 accent-[var(--accent)]" checked={done} onChange={() => setDone((v) => !v)} />
        {TEXTS.formDone}
      </label>

      <textarea
        aria-label={TEXTS.formComment}
        placeholder={TEXTS.formCommentPlaceholder}
        className={cn("min-h-16", FIELD_CLASS)}
        maxLength={1000}
        value={comment}
        onChange={(e) => setComment(e.target.value)}
      />

      <div className="flex justify-end gap-2">
        <Button type="button" size="sm" variant="ghost" onClick={onCancel}>
          {TEXTS.formCancel}
        </Button>
        <Button
          type="submit"
          size="sm"
          disabled={pending || "" === teamId || selectedTeamServed}
          disabledReason={selectedTeamServed ? TEXTS.formAlreadyServedReason : undefined}
        >
          {null !== editing ? TEXTS.formSave : TEXTS.formCreate}
        </Button>
      </div>
    </form>
  );
}
