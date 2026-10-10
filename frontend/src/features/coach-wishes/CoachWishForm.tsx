import { type FormEvent, useState } from "react";

import type { PriorityTier, Team, TeamCoach } from "@/features/wizard/api";
import { Button } from "@/shared/components/ui/button";
import { DayMultiPicker } from "@/shared/components/ui/day-multi-picker";
import { FIELD_CLASS } from "@/shared/components/ui/field";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { TeamSelect } from "@/shared/components/ui/team-select";
import { DAYS } from "@/shared/lib/days";
import { cn } from "@/shared/lib/utils";

import type { CoachWish, CoachWishPayload } from "./api";
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
 *  - PLUS de champ Coach : le coach est DÉDUIT — à la création, le coach PRINCIPAL de l'équipe
 *    s'il existe (sinon aucun), recalculé quand l'équipe change ; à l'édition, le `coachId`
 *    existant est gardé tel quel, jamais dé-attribué en silence. Côté serveur, rien ne change
 *    (coach facultatif, `coachId` nullable) ;
 *  - « Créneaux souhaités » est un champ NOMBRE (0-7, bornes serveur) ;
 *  - « Jours disponibles » AU-DESSUS de « Jours souhaités » ; un jour non disponible est
 *    DÉSACTIVÉ côté souhaités (un jour souhaité est au minimum un jour disponible).
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
  // Coach DÉDUIT, sans champ (retours fondateur 2026-10-10) : à la création le coach PRINCIPAL
  // de l'équipe (sinon aucun), recalculé quand l'équipe change ; à l'édition le `coachId`
  // existant est gardé TEL QUEL (y compris null), jamais dé-attribué en silence.
  const [coachId, setCoachId] = useState(editing?.coachId ?? (null === editing ? mainCoachId(initialTeamId) : ""));
  const [slotsWanted, setSlotsWanted] = useState(editing?.slotsWanted ?? 1);
  const [days, setDays] = useState<number[]>(editing?.unavailableDays ?? []);
  const [wishedDays, setWishedDays] = useState<number[]>(editing?.wishedDays ?? []);
  const [comment, setComment] = useState(editing?.comment ?? "");
  const [keepSeasonSlots, setKeepSeasonSlots] = useState(editing?.keepSeasonSlots ?? false);

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
          Semaine
          <Select
            aria-label="Semaine"
            wrapperClassName="mt-0.5 w-64"
            value={weekStart}
            disabled={null !== lockedWeek || null !== editing}
            onChange={(e) => setWeekStart(e.target.value)}
          >
            {weeks.map((w) => (
              <option key={w.monday} value={w.monday}>
                Semaine du {frDate(w.startDate)}
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
          Équipe
          <TeamSelect
            aria-label="Équipe"
            wrapperClassName="mt-0.5 w-40"
            panelMinWidth={320}
            teams={teams}
            tiers={tiers}
            value={teamId}
            disabled={null !== editing}
            optionMeta={(t) => (isServed(t.id) ? { disabled: true, sub: "a déjà une doléance cette semaine" } : {})}
            onValueChange={(v) => {
              setTeamId(v);
              setCoachId(mainCoachId(v)); // coach déduit : recalcule le coach principal de la nouvelle équipe
            }}
          />
        </div>
        {/* Créneaux souhaités = champ NOMBRE borné 0-7 (bornes serveur, retours fondateur 2026-10-10). */}
        <label className="text-xs text-muted-foreground">
          Créneaux souhaités
          <Input
            type="number"
            min={0}
            max={7}
            aria-label="Créneaux souhaités"
            className="mt-0.5 w-20"
            value={slotsWanted}
            onChange={(e) => setSlotsWanted(Math.max(0, Math.min(7, Number(e.target.value) || 0)))}
          />
        </label>
      </div>

      {/* « Jours disponibles » AU-DESSUS (tout pressé par défaut, on dépresse les creux) ;
          « Jours souhaités » dessous. Pressé = DISPONIBLE (positif) : `tone="accent"`. */}
      <DayMultiPicker legend="Jours disponibles" legendVisible tone="accent" value={availableDays} onChange={changeAvailableDays} />

      {/* Un jour souhaité est au minimum un jour DISPONIBLE : les jours indisponibles (`days`) sont
          DÉSACTIVÉS ici (visibles, grisés, avec motif), jamais seulement décochés. */}
      <DayMultiPicker
        legend="Jours souhaités"
        legendVisible
        tone="accent"
        value={wishedDays}
        onChange={changeWishedDays}
        disabledDays={days}
        disabledReason="Jour non disponible : rendez-le disponible pour pouvoir le souhaiter."
      />
      {/* Volet B — « garder les créneaux habituels » (ceux du planning de saison), par
          équipe × semaine. Souhait de plus ; le transfert vers le planning l'honore. */}
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" className="size-4 accent-[var(--accent)]" checked={keepSeasonSlots} onChange={() => setKeepSeasonSlots((v) => !v)} />
        Garder les créneaux habituels
      </label>

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
