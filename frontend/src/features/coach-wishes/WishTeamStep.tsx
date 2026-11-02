import { StatusPill } from "@/shared/components/ui/badge";
import { DayMultiPicker } from "@/shared/components/ui/day-multi-picker";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { FIELD_CLASS } from "@/shared/components/ui/field";
import { Input } from "@/shared/components/ui/input";
import { DAYS, dayLabelLong } from "@/shared/lib/days";
import { cn } from "@/shared/lib/utils";

import type { PublicStaffMember, PublicTeamLink, PublicWishContext } from "./publicApi";
import { COACH_WISHES_PUBLIC_TEXTS as TEXTS } from "./texts/public";
import { partnerOptionsFor, type MutualizationState } from "./wishMutualizations";
import { frDate, sectionKey, type SectionState } from "./wishSections";

interface WishTeamStepProps {
  team: { id: string; name: string; staff: PublicStaffMember[] };
  weeks: string[];
  sections: Map<string, SectionState>;
  /** Équipes de la campagne proposables en partenaires + passerelles (pour le bloc mutualisation). */
  partnerTeams: { id: string; name: string }[];
  teamLinks: PublicTeamLink[];
  mutualization: MutualizationState | undefined;
  /** Jours déclarés par d'autres équipes pour une personne du staff : un bandeau, jamais un jour pré-pressé. */
  absencesElsewhere: PublicWishContext["absencesElsewhere"];
  onPatch: (key: string, next: Partial<SectionState>) => void;
  onToggleDay: (key: string, day: number) => void;
  onToggleWishedDay: (key: string, day: number) => void;
  onToggleStaffDay: (key: string, coachId: string, day: number) => void;
  onTogglePartner: (teamId: string, partnerId: string) => void;
  onSharedSlots: (teamId: string, slots: number) => void;
  /** Aperçu gestionnaire : lecture seule (inputs désactivés). */
  readOnly?: boolean;
}

/**
 * Rejoue, jour par jour, l'écart entre l'ancienne sélection d'un `DayMultiPicker` et la
 * nouvelle — le parent gère un `Set` par bascule d'UN jour (l'exclusion souhaité/indispo
 * vit côté parent). Robuste même si plus d'un jour changeait.
 */
function replayToggles(before: Set<number>, next: number[], toggle: (day: number) => void): void {
  const after = new Set(next);
  for (const day of new Set([...before, ...after])) {
    if (before.has(day) !== after.has(day)) {
      toggle(day);
    }
  }
}

/** Les sept jours ISO (foyer `shared/lib/days`), pour calculer le complément « disponibles ». */
const ALL_DAYS: number[] = DAYS.map((d) => d.n);

/**
 * Une étape du parcours = UNE équipe et ses semaines (lot E, P2-24). Depuis D2, un bloc
 * « Mutualisation (facultatif) » UNE fois par équipe, AU-DESSUS des semaines : le coach
 * choisit des équipes partenaires (passerelles en tête) et un nombre de séances à partager.
 * C'est une DEMANDE informative, jamais une contrainte : le club arbitre.
 */
export function WishTeamStep({ team, weeks, sections, partnerTeams, teamLinks, mutualization, absencesElsewhere, onPatch, onToggleDay, onToggleWishedDay, onToggleStaffDay, onTogglePartner, onSharedSlots, readOnly = false }: WishTeamStepProps) {
  const partners = partnerOptionsFor(team.id, partnerTeams, teamLinks);
  const selectedPartners = mutualization?.partnerTeamIds ?? new Set<string>();
  const sharedSlots = mutualization?.sharedSlots ?? 1;

  return (
    <div className="space-y-4">
      {partners.length > 0 ? (
        <fieldset disabled={readOnly} className="rounded-lg border border-border bg-card p-3">
          <legend className="px-1 text-sm font-medium">{TEXTS.mutualizationLegend}</legend>
          <p className="mt-1 text-xs text-muted-foreground">{TEXTS.mutualizationHelp(team.name)}</p>
          <div className="mt-2 flex flex-wrap gap-2">
            {partners.map((p) => (
              <label key={p.id} className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-2 py-1 text-sm">
                <input
                  type="checkbox"
                  className="size-4 accent-[var(--accent)]"
                  checked={selectedPartners.has(p.id)}
                  onChange={() => onTogglePartner(team.id, p.id)}
                  aria-label={TEXTS.mutualizeWith(team.name, p.name, p.isBridge)}
                />
                {p.name}
                {p.isBridge ? (
                  <StatusPill variant="accent" className="ml-0.5">
                    {TEXTS.bridgePill}
                  </StatusPill>
                ) : null}
              </label>
            ))}
          </div>
          {selectedPartners.size > 0 ? (
            <label className="mt-2 flex items-center gap-2 text-sm">
              {TEXTS.sharedSlotsLabel}
              <Input
                type="number"
                min={1}
                max={7}
                aria-label={TEXTS.sharedSlotsAria(team.name)}
                className="w-16"
                value={sharedSlots}
                onChange={(e) => onSharedSlots(team.id, Math.max(1, Math.min(7, Number(e.target.value) || 1)))}
              />
            </label>
          ) : null}
        </fieldset>
      ) : null}

      {weeks.map((week) => {
        const key = sectionKey(team.id, week);
        const s = sections.get(key) as SectionState;
        // P2-63 A — inversion de PRÉSENTATION : on affiche les jours où l'équipe PEUT s'entraîner
        // (le complément de `s.days`), tous pressés par défaut, on retire les jours fermés. La
        // DONNÉE reste `s.days` : retirer un jour == le fermer, `onToggleDay` fait le travail.
        const availableDays = ALL_DAYS.filter((d) => !s.days.has(d));
        return (
          <fieldset key={key} disabled={readOnly} className="rounded-lg border border-border bg-card p-3">
            <legend className="px-1 text-sm font-medium">{TEXTS.weekLegend(team.name, frDate(week))}</legend>
            {/* Option A — un autre coach de cette équipe a déjà répondu pour cette semaine : on
                prévient que l'envoi remplacera sa version. JAMAIS son prénom (« un autre coach ») ;
                sa saisie est déjà pré-remplie dans les champs ci-dessous. */}
            {s.byAnotherCoach ? (
              <NoticeBanner
                tone="warning"
                className="mt-2"
                message={TEXTS.byAnotherCoach}
              />
            ) : null}
            {absencesElsewhere
              .filter((a) => a.teamId === team.id && a.weekStart === week && a.days.length > 0)
              .map((a) => {
                const member = team.staff.find((m) => m.coachId === a.coachId);
                return undefined === member ? null : <NoticeBanner key={a.coachId} tone="warning" className="mt-2" message={TEXTS.absentElsewhere(member.firstName, a.days.map(dayLabelLong))} />;
              })}
            <label className="mt-2 flex items-center gap-2 text-sm">
              {TEXTS.slotsLabel}
              <Input
                type="number"
                min={0}
                max={7}
                aria-label={TEXTS.slotsAria(team.name, frDate(week))}
                className="w-16"
                value={s.slotsWanted}
                onChange={(e) => onPatch(key, { slotsWanted: Math.max(0, Math.min(7, Number(e.target.value) || 0)) })}
              />
            </label>
            <div className="mt-2">
              {/* Les jours de l'équipe AU-DESSUS de « Jours souhaités » (retours fondateur 2026-10-10).
                  Pressé = DISPONIBLE (état positif/neutre) : `tone="accent"`, jamais `destructive`
                  (qui signale « bloqué ») — polarité documentée du DayMultiPicker + arbitrage
                  fondateur 2026-10-01 (les deux saisies de vœux sont en accent). */}
              <DayMultiPicker
                legend={TEXTS.teamDaysLegend}
                legendVisible
                tone="accent"
                value={availableDays}
                onChange={(next) => replayToggles(new Set(availableDays), next, (day) => onToggleDay(key, day))}
              />
              <p className="mt-1 text-xs text-muted-foreground">{TEXTS.teamDaysHelp}</p>
            </div>
            <div className="mt-2">
              {/* Un jour souhaité est au minimum un jour DISPONIBLE : les jours indisponibles
                  (`s.days`) sont DÉSACTIVÉS ici (visibles, grisés, avec motif), jamais seulement
                  décochés. Les deux saisies de vœux (page publique ET modale gestionnaire) sont en
                  couleur du club (accent), jamais destructive (arbitrage fondateur 2026-10-01). */}
              <DayMultiPicker
                legend={TEXTS.wishedDaysLegend}
                legendVisible
                tone="accent"
                value={[...s.wishedDays].sort((a, b) => a - b)}
                onChange={(next) => replayToggles(s.wishedDays, next, (day) => onToggleWishedDay(key, day))}
                disabledDays={[...s.days].sort((a, b) => a - b)}
                disabledReason={TEXTS.wishedDayDisabledReason}
              />
            </div>
            {/* Une ligne par personne, principaux puis adjoints. Pressé = disponible, comme au-dessus ;
                la donnée reste les jours d'ABSENCE. Masqué si l'équipe n'a aucun lien. */}
            {team.staff.length > 0 ? (
              <div className="mt-3 space-y-2 border-t border-border pt-2">
                <p className="text-sm font-medium">{TEXTS.staffTitle}</p>
                <p className="text-xs text-muted-foreground">{TEXTS.staffHelp}</p>
                {team.staff.map((member) => {
                  const absent = s.staff.get(member.coachId) ?? new Set<number>();
                  const present = ALL_DAYS.filter((d) => !absent.has(d));
                  return (
                    <DayMultiPicker
                      key={member.coachId}
                      legend={TEXTS.staffMemberLegend(member.firstName, member.lastName, member.role)}
                      legendVisible
                      tone="accent"
                      value={present}
                      onChange={(next) => replayToggles(new Set(present), next, (day) => onToggleStaffDay(key, member.coachId, day))}
                    />
                  );
                })}
              </div>
            ) : null}
            {/* Volet B — « garder mes créneaux habituels » (ceux du planning de saison) : un
                souhait de plus, par équipe × semaine. La page n'expose AUCUN horaire de saison,
                juste cette case ; le club arbitre au transfert vers le planning. */}
            <label className="mt-2 flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                className="size-4 accent-[var(--accent)]"
                checked={s.keepSeasonSlots}
                onChange={() => onPatch(key, { keepSeasonSlots: !s.keepSeasonSlots })}
              />
              {TEXTS.keepSeasonSlots}
            </label>
            <label className="mt-2 block text-sm">
              <span className="text-muted-foreground">{TEXTS.commentLabel}</span>
              <textarea
                className={cn("mt-1", FIELD_CLASS)}
                rows={2}
                aria-label={TEXTS.commentAria(team.name, frDate(week))}
                value={s.comment}
                onChange={(e) => onPatch(key, { comment: e.target.value })}
              />
            </label>
          </fieldset>
        );
      })}
    </div>
  );
}
