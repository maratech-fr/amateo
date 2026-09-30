import { AlertTriangle, Pencil, Plus, Trash2 } from "lucide-react";
import { type ReactNode, useState } from "react";
import { Link, useSearchParams } from "react-router";

import { AccordionSection } from "@/shared/components/ui/accordion";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Select } from "@/shared/components/ui/select";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { DAYS, dayLabelLong } from "@/shared/lib/days";
import { readFailed } from "@/shared/lib/readState";
import { useMe } from "@/shared/session/queries";

import type { ClubLeagueWindow, ClubLeagueWindowInput, Coach, LeagueWindowLevel, MatchConstraint, MatchConstraintInput, MatchRuleType, Team, Venue } from "./api";
import { clockLabel, clubRuleLabel } from "./lib/clubRuleLabel";
import { LeagueSuggestions } from "./LeagueSuggestions";
import {
  useClubLeagueWindows,
  useCoaches,
  useCreateClubLeagueWindow,
  useCreateMatchConstraint,
  useDeleteClubLeagueWindow,
  useDeleteMatchConstraint,
  useMatchConstraintCoherence,
  useMatchConstraints,
  useTeams,
  useUpdateClubLeagueWindow,
  useUpdateMatchConstraint,
  useVenues,
} from "./queries";

/**
 * P4-272 — l'écran UNIQUE des contraintes de match, en accordéon (patron
 * `ConfigurationPage`, section ouverte ancrée `?section=`). Quatre sections, chacune
 * un CRUD gestionnaire : **Ligue** (copie club de l'enveloppe fédérale), **Club**
 * (règles de coup d'envoi), **Équipes** (interdictions de gymnase) et **Coachs**
 * (indisponibilités) — toutes lues par le placement, le radar et le calendrier.
 *
 * Le badge « modifié »/« ajouté » est calculé SERVEUR (`badge`) : le front
 * l'AFFICHE, il ne le redérive pas (.claude/rules/frontend.md).
 */
type ConstraintsSection = "ligue" | "club" | "equipes" | "coachs";

const SECTIONS: ConstraintsSection[] = ["ligue", "club", "equipes", "coachs"];

function isSection(value: string | null): value is ConstraintsSection {
  return null !== value && (SECTIONS as string[]).includes(value);
}

export function ConstraintsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const me = useMe();
  // P4-271 — vérité serveur : n'annoter la cohérence d'une semaine A/B que si le club alterne.
  const weekendAlternates = me.data?.club?.weekendAlternates ?? false;
  const openSection = isSection(searchParams.get("section")) ? (searchParams.get("section") as ConstraintsSection) : null;

  const setOpenSection = (section: ConstraintsSection | null): void => {
    const next = new URLSearchParams(searchParams);
    if (null === section) {
      next.delete("section");
    } else {
      next.set("section", section);
    }
    setSearchParams(next, { replace: true });
  };
  const sectionProps = (key: ConstraintsSection) => ({
    open: openSection === key,
    onToggle: (next: boolean): void => setOpenSection(next ? key : null),
  });

  return (
    <div className="flex flex-col gap-3">
      <AccordionSection {...sectionProps("ligue")} title="Ligue">
        <LeagueSection />
      </AccordionSection>

      <AccordionSection {...sectionProps("club")} title="Club">
        <ClubSection weekendAlternates={weekendAlternates} />
      </AccordionSection>

      <AccordionSection {...sectionProps("equipes")} title="Équipes">
        <TeamsSection />
      </AccordionSection>

      <AccordionSection {...sectionProps("coachs")} title="Coachs">
        <CoachsSection />
      </AccordionSection>
    </div>
  );
}

const LEVELS: { value: LeagueWindowLevel; label: string }[] = [
  { value: "DEPARTEMENTAL", label: "Départemental" },
  { value: "REGIONAL", label: "Régional" },
];

const GENDERS: { value: string; label: string }[] = [
  { value: "", label: "Tous" },
  { value: "M", label: "Masculin" },
  { value: "F", label: "Féminin" },
  { value: "MIXTE", label: "Mixte" },
];

/** Libellé court d'un jour ISO (Lun…Dim) — foyer unique `DAYS`, pour les résumés compacts. */
const dayShort = (n: number): string => DAYS.find((d) => d.n === n)?.label ?? "";
const daysShort = (days: number[]): string =>
  [...days]
    .sort((a, b) => a - b)
    .map(dayShort)
    .join(", ");

const LEVEL_LABEL = new Map(LEVELS.map((l) => [l.value, l.label]));
const GENDER_LABEL = new Map(GENDERS.map((g) => [g.value, g.label]));

/**
 * La section Ligue : le tableau éditable de la copie club. Bandeau si la copie est
 * VIDE (le placement n'applique alors plus de règle fédérale). Le badge vient du
 * serveur ; l'écran l'affiche seulement.
 */
function LeagueSection() {
  const windows = useClubLeagueWindows();

  if (readFailed(windows)) {
    return <LoadErrorHint onRetry={() => void windows.refetch()} />;
  }
  if (undefined === windows.data) {
    return <FullPageSpinner />;
  }

  const rows = windows.data;

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        Les fenêtres de coup d'envoi imposées par la ligue, copiées pour votre club — vous pouvez les corriger, en ajouter ou en retirer. Le
        placement des matchs et le radar suivent cette copie.
      </p>

      <LeagueSuggestions />

      {0 === rows.length ? (
        <p className="rounded-md border border-warning/50 bg-surface-warning px-3 py-2 text-sm text-foreground" role="status">
          Aucune fenêtre ligue — le placement n'applique plus de règle fédérale.
        </p>
      ) : (
        <div className="flex flex-col gap-2">
          {rows.map((window) => (
            <LeagueWindowRow key={window.id} window={window} />
          ))}
        </div>
      )}

      <AddLeagueWindowRow />
    </div>
  );
}

function badgePill(badge: ClubLeagueWindow["badge"]): ReactNode {
  if ("modified" === badge) {
    return <StatusPill variant="warning">Modifié</StatusPill>;
  }
  if ("added" === badge) {
    return <StatusPill variant="accent">Ajouté</StatusPill>;
  }
  return null;
}

const emptyDraft = (window: ClubLeagueWindow | null): ClubLeagueWindowInput => ({
  category: window?.category ?? "",
  level: window?.level ?? "DEPARTEMENTAL",
  gender: window?.gender ?? "",
  dayOfWeek: window?.dayOfWeek ?? 6,
  kickoffMin: window?.kickoffMin ?? "",
  kickoffMax: window?.kickoffMax ?? "",
});

const isComplete = (draft: ClubLeagueWindowInput): boolean =>
  "" !== draft.category.trim() && "" !== draft.kickoffMin && "" !== draft.kickoffMax;

function DraftFields({ draft, set }: { draft: ClubLeagueWindowInput; set: (patch: Partial<ClubLeagueWindowInput>) => void }) {
  return (
    <>
      <Input aria-label="Catégorie" placeholder="Catégorie" value={draft.category} onChange={(e) => set({ category: e.target.value })} className="min-w-32" />
      <Select aria-label="Niveau" value={draft.level} onChange={(e) => set({ level: e.target.value as LeagueWindowLevel })}>
        {LEVELS.map((l) => (
          <option key={l.value} value={l.value}>
            {l.label}
          </option>
        ))}
      </Select>
      <Select aria-label="Genre" value={draft.gender ?? ""} onChange={(e) => set({ gender: e.target.value })}>
        {GENDERS.map((g) => (
          <option key={g.value} value={g.value}>
            {g.label}
          </option>
        ))}
      </Select>
      <Select aria-label="Jour" value={String(draft.dayOfWeek)} onChange={(e) => set({ dayOfWeek: Number(e.target.value) })}>
        {DAYS.map((d) => (
          <option key={d.n} value={d.n}>
            {dayLabelLong(d.n)}
          </option>
        ))}
      </Select>
      <Input aria-label="De" type="time" value={draft.kickoffMin} onChange={(e) => set({ kickoffMin: e.target.value })} />
      <Input aria-label="À" type="time" value={draft.kickoffMax} onChange={(e) => set({ kickoffMax: e.target.value })} />
    </>
  );
}

/** Le résumé compact d'une fenêtre ligue : « U13F1 · Départemental · Samedi… · 13:00–21:00 ». */
function leagueWindowSummary(window: ClubLeagueWindow): string {
  const parts = [window.category, LEVEL_LABEL.get(window.level) ?? window.level];
  if (null !== window.gender && "" !== window.gender) {
    parts.push(GENDER_LABEL.get(window.gender) ?? window.gender);
  }
  parts.push(`${daysShort([window.dayOfWeek])} ${clockLabel(window.kickoffMin)}–${clockLabel(window.kickoffMax)}`);
  return parts.join(" · ");
}

/** Une ligne éditable de la copie : compacte au repos (résumé + ✎ + 🗑), dépliée en champs à l'édition. */
function LeagueWindowRow({ window }: { window: ClubLeagueWindow }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState<ClubLeagueWindowInput>(() => emptyDraft(window));
  const [confirmDelete, setConfirmDelete] = useState(false);
  const update = useUpdateClubLeagueWindow();
  const remove = useDeleteClubLeagueWindow();
  const set = (patch: Partial<ClubLeagueWindowInput>): void => setDraft((d) => ({ ...d, ...patch }));

  const openEdit = (): void => {
    setDraft(emptyDraft(window));
    setEditing(true);
  };

  const dirty =
    draft.category !== window.category ||
    draft.level !== window.level ||
    (draft.gender ?? "") !== (window.gender ?? "") ||
    draft.dayOfWeek !== window.dayOfWeek ||
    draft.kickoffMin !== window.kickoffMin ||
    draft.kickoffMax !== window.kickoffMax;

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card px-3 py-2">
      {editing ? (
        <>
          <DraftFields draft={draft} set={set} />
          <div className="ml-auto flex items-center gap-2">
            {badgePill(window.badge)}
            <Button
              size="sm"
              disabled={!dirty || !isComplete(draft) || update.isPending}
              onClick={() => update.mutate({ id: window.id, input: { ...draft, gender: "" === draft.gender ? null : draft.gender } }, { onSuccess: () => setEditing(false) })}
            >
              Enregistrer
            </Button>
            <Button variant="outline" size="sm" onClick={() => setEditing(false)}>
              Annuler
            </Button>
          </div>
        </>
      ) : (
        <>
          <span className="text-sm text-foreground">{leagueWindowSummary(window)}</span>
          <div className="ml-auto flex items-center gap-2">
            {badgePill(window.badge)}
            <Button variant="ghost" size="icon" className="size-8" aria-label="Modifier" title="Modifier" onClick={openEdit}>
              <Pencil className="size-3.5" />
            </Button>
            <Button variant="ghost" size="icon" className="size-8 text-destructive" aria-label="Supprimer" title="Supprimer" disabled={remove.isPending} onClick={() => setConfirmDelete(true)}>
              <Trash2 className="size-3.5" />
            </Button>
          </div>
        </>
      )}
      <ConfirmDialog
        open={confirmDelete}
        title="Supprimer cette fenêtre de ligue ?"
        description="Le placement et le radar cesseront d'appliquer cette fenêtre."
        confirmLabel="Supprimer"
        destructive
        onConfirm={() => {
          setConfirmDelete(false);
          remove.mutate(window.id);
        }}
        onCancel={() => setConfirmDelete(false)}
      />
    </div>
  );
}

/** La ligne d'ajout d'une nouvelle fenêtre (POST). */
function AddLeagueWindowRow() {
  const [draft, setDraft] = useState<ClubLeagueWindowInput>(() => emptyDraft(null));
  const create = useCreateClubLeagueWindow();
  const set = (patch: Partial<ClubLeagueWindowInput>): void => setDraft((d) => ({ ...d, ...patch }));

  const submit = (): void => {
    create.mutate(
      { ...draft, gender: "" === draft.gender ? null : draft.gender },
      { onSuccess: () => setDraft(emptyDraft(null)) },
    );
  };

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-dashed border-border px-3 py-2">
      <DraftFields draft={draft} set={set} />
      <Button size="sm" className="ml-auto" disabled={!isComplete(draft) || create.isPending} onClick={submit}>
        <Plus className="size-3.5" />
        Ajouter
      </Button>
    </div>
  );
}

// ── Section Club (P4-272 ③) ──────────────────────────────────────────────────

const RULE_TYPES: { value: MatchRuleType; label: string }[] = [
  { value: "HARD", label: "Obligatoire" },
  { value: "PREFERRED", label: "Préférée" },
];

const RULE_TYPE_LABEL = new Map(RULE_TYPES.map((t) => [t.value, t.label]));

interface ClubRuleDraft {
  ruleType: MatchRuleType;
  daysOfWeek: number[];
  /** "" = borne ouverte de ce côté. */
  kickoffMin: string;
  kickoffMax: string;
}

const emptyRuleDraft = (rule: MatchConstraint | null): ClubRuleDraft => ({
  ruleType: rule?.ruleType ?? "HARD",
  daysOfWeek: rule?.daysOfWeek ?? [6],
  kickoffMin: rule?.kickoffMin ?? "",
  kickoffMax: rule?.kickoffMax ?? "",
});

/** Au moins un jour ET au moins une borne (le serveur refuse sinon). min ≤ max reste au serveur. */
const isRuleComplete = (draft: ClubRuleDraft): boolean => draft.daysOfWeek.length > 0 && ("" !== draft.kickoffMin || "" !== draft.kickoffMax);

const toRuleInput = (draft: ClubRuleDraft): MatchConstraintInput => ({
  ruleType: draft.ruleType,
  daysOfWeek: draft.daysOfWeek,
  kickoffMin: "" !== draft.kickoffMin ? draft.kickoffMin : null,
  kickoffMax: "" !== draft.kickoffMax ? draft.kickoffMax : null,
});

const sameDays = (a: number[], b: number[]): boolean => a.length === b.length && a.every((d) => b.includes(d));

/**
 * La section Club : le CRUD des règles de match du club (« pas après 21:00 », …). Chaque
 * règle porte un ou plusieurs JOURS, une fourchette de coup d'envoi (chaque borne
 * facultative) et un type Obligatoire (HARD, honorée par le solveur) / Préférée
 * (PREFERRED, une préférence). Sous une règle, l'ALERTE DE COHÉRENCE — les créneaux
 * idéaux qu'elle heurte — est CALCULÉE côté serveur (`/coherence`), l'écran l'affiche.
 */
function ClubSection({ weekendAlternates }: { weekendAlternates: boolean }) {
  const rules = useMatchConstraints();
  const coherence = useMatchConstraintCoherence();

  if (readFailed(rules)) {
    return <LoadErrorHint onRetry={() => void rules.refetch()} />;
  }
  if (undefined === rules.data) {
    return <FullPageSpinner />;
  }

  // byRule → Map<ruleId, habits>. L'alerte est calculée serveur ; on la POSE sous la règle.
  const alertsByRule = new Map((coherence.data?.byRule ?? []).map((r) => [r.ruleId, r.habits]));
  // La section Club ne montre QUE les règles de club (les interdictions de gymnase TEAM
  // vivent dans la section Équipes). Simple tri d'affichage en deux listes — jamais un
  // verdict solveur (le backend et le radar décident, .claude/rules/frontend.md).
  const clubRules = rules.data.filter((rule) => "CLUB" === rule.scope);

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        Vos règles de match — par exemple « pas de match après 21h le samedi ». Une règle <strong>obligatoire</strong> est respectée par le
        placement ; une règle <strong>préférée</strong> est un souhait que le placement suit s'il le peut. Une pose manuelle hors d'une règle
        reste possible — le radar la signale.
      </p>

      {0 === clubRules.length ? (
        <p className="text-sm text-muted-foreground">Aucune règle de club — le placement ne s'impose que les fenêtres de la ligue.</p>
      ) : (
        <div className="flex flex-col gap-2">
          {clubRules.map((rule) => (
            <ClubRuleRow key={rule.id} rule={rule} alerts={alertsByRule.get(rule.id) ?? []} weekendAlternates={weekendAlternates} />
          ))}
        </div>
      )}

      <AddClubRuleRow />
    </div>
  );
}

/** Les 7 jours en bascule (aria-pressed) — le multi-jours d'une règle. */
function DayToggles({ value, onChange, label }: { value: number[]; onChange: (days: number[]) => void; label: string }) {
  const toggle = (n: number): void => onChange(value.includes(n) ? value.filter((d) => d !== n) : [...value, n].sort((a, b) => a - b));
  return (
    <div className="flex flex-wrap gap-1" role="group" aria-label={label}>
      {DAYS.map((d) => {
        const on = value.includes(d.n);
        return (
          <Button
            key={d.n}
            type="button"
            size="sm"
            variant={on ? "default" : "outline"}
            aria-pressed={on}
            aria-label={dayLabelLong(d.n)}
            onClick={() => toggle(d.n)}
          >
            {d.label}
          </Button>
        );
      })}
    </div>
  );
}

/** Les champs d'une règle (jours · type · de/à) — partagés par la ligne éditable et l'ajout. */
function RuleFields({ draft, set, idLabel }: { draft: ClubRuleDraft; set: (patch: Partial<ClubRuleDraft>) => void; idLabel: string }) {
  return (
    <>
      <DayToggles label={`Jours (${idLabel})`} value={draft.daysOfWeek} onChange={(daysOfWeek) => set({ daysOfWeek })} />
      <Select aria-label="Type" value={draft.ruleType} onChange={(e) => set({ ruleType: e.target.value as MatchRuleType })}>
        {RULE_TYPES.map((t) => (
          <option key={t.value} value={t.value}>
            {t.label}
          </option>
        ))}
      </Select>
      <label className="flex items-center gap-1 text-sm text-muted-foreground">
        Pas avant
        <Input aria-label="Pas avant (heure de début)" type="time" value={draft.kickoffMin} onChange={(e) => set({ kickoffMin: e.target.value })} />
      </label>
      <label className="flex items-center gap-1 text-sm text-muted-foreground">
        Pas après
        <Input aria-label="Pas après (heure de fin)" type="time" value={draft.kickoffMax} onChange={(e) => set({ kickoffMax: e.target.value })} />
      </label>
    </>
  );
}

/** L'alerte de cohérence sous une règle : les créneaux idéaux qu'elle heurte (calculée serveur). */
function ClubRuleAlerts({ alerts, weekendAlternates }: { alerts: { teamId: string; teamName: string; week: string; dayOfWeek: number; kickoff: string }[]; weekendAlternates: boolean }) {
  if (0 === alerts.length) {
    return null;
  }
  return (
    <div className="mt-1 flex w-full flex-col gap-1 rounded-md border border-warning/40 bg-surface-warning px-3 py-2 text-sm text-foreground" role="status">
      {alerts.map((h) => (
        <p key={`${h.teamId}-${h.dayOfWeek}-${h.kickoff}`} className="flex items-start gap-1.5">
          <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
          <span>
            Cette règle heurte le créneau idéal des {h.teamName}
            {weekendAlternates ? ` (semaine ${h.week})` : ""} : {dayLabelLong(h.dayOfWeek)} {clockLabel(h.kickoff)}.
          </span>
        </p>
      ))}
    </div>
  );
}

/** Le résumé compact d'une règle club : « Samedi · pas après 21:00 · Obligatoire ». */
function clubRuleSummary(rule: MatchConstraint): string {
  return `${daysShort(rule.daysOfWeek)} · ${clubRuleLabel(rule)} · ${RULE_TYPE_LABEL.get(rule.ruleType) ?? rule.ruleType}`;
}

/** Une règle éditable : compacte au repos (résumé + ✎ + 🗑), dépliée en champs à l'édition. */
function ClubRuleRow({ rule, alerts, weekendAlternates }: { rule: MatchConstraint; alerts: { teamId: string; teamName: string; week: string; dayOfWeek: number; kickoff: string }[]; weekendAlternates: boolean }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState<ClubRuleDraft>(() => emptyRuleDraft(rule));
  const [confirmDelete, setConfirmDelete] = useState(false);
  const update = useUpdateMatchConstraint();
  const remove = useDeleteMatchConstraint();
  const set = (patch: Partial<ClubRuleDraft>): void => setDraft((d) => ({ ...d, ...patch }));

  const openEdit = (): void => {
    setDraft(emptyRuleDraft(rule));
    setEditing(true);
  };

  const dirty =
    draft.ruleType !== rule.ruleType ||
    !sameDays(draft.daysOfWeek, rule.daysOfWeek) ||
    draft.kickoffMin !== (rule.kickoffMin ?? "") ||
    draft.kickoffMax !== (rule.kickoffMax ?? "");

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card px-3 py-2">
      {editing ? (
        <>
          <RuleFields draft={draft} set={set} idLabel="règle" />
          <div className="ml-auto flex items-center gap-2">
            <Button size="sm" disabled={!dirty || !isRuleComplete(draft) || update.isPending} onClick={() => update.mutate({ id: rule.id, input: toRuleInput(draft) }, { onSuccess: () => setEditing(false) })}>
              Enregistrer
            </Button>
            <Button variant="outline" size="sm" onClick={() => setEditing(false)}>
              Annuler
            </Button>
          </div>
        </>
      ) : (
        <>
          <span className="text-sm text-foreground">{clubRuleSummary(rule)}</span>
          <div className="ml-auto flex items-center gap-2">
            <Button variant="ghost" size="icon" className="size-8" aria-label="Modifier" title="Modifier" onClick={openEdit}>
              <Pencil className="size-3.5" />
            </Button>
            <Button variant="ghost" size="icon" className="size-8 text-destructive" aria-label="Supprimer" title="Supprimer" disabled={remove.isPending} onClick={() => setConfirmDelete(true)}>
              <Trash2 className="size-3.5" />
            </Button>
          </div>
        </>
      )}
      <ClubRuleAlerts alerts={alerts} weekendAlternates={weekendAlternates} />
      <ConfirmDialog
        open={confirmDelete}
        title="Supprimer cette règle de match ?"
        description="Le placement et le radar cesseront d'appliquer cette règle."
        confirmLabel="Supprimer"
        destructive
        onConfirm={() => {
          setConfirmDelete(false);
          remove.mutate(rule.id);
        }}
        onCancel={() => setConfirmDelete(false)}
      />
    </div>
  );
}

/** La ligne d'ajout d'une nouvelle règle (POST). */
function AddClubRuleRow() {
  const [draft, setDraft] = useState<ClubRuleDraft>(() => emptyRuleDraft(null));
  const create = useCreateMatchConstraint();
  const set = (patch: Partial<ClubRuleDraft>): void => setDraft((d) => ({ ...d, ...patch }));

  const submit = (): void => {
    create.mutate(toRuleInput(draft), { onSuccess: () => setDraft(emptyRuleDraft(null)) });
  };

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-dashed border-border px-3 py-2">
      <RuleFields draft={draft} set={set} idLabel="nouvelle règle" />
      <Button size="sm" className="ml-auto" disabled={!isRuleComplete(draft) || create.isPending} onClick={submit}>
        <Plus className="size-3.5" />
        Ajouter
      </Button>
    </div>
  );
}

// ── Section Équipes (P4-272 ④) : interdictions de gymnase par équipe ─────────────

/**
 * La section Équipes : le CRUD des INTERDICTIONS de gymnase (« l'équipe X ne joue
 * jamais au gymnase Y »). Chaque interdiction est une règle de match de scope TEAM
 * (ruleType HARD, sans jour ni horaire) — le placement retire le gymnase du domaine
 * de l'équipe, le radar signale une pose manuelle qui l'enfreint. La PRÉFÉRENCE de
 * gymnase (à l'inverse d'une interdiction) reste l'habitude de la semaine type.
 */
function TeamsSection() {
  const rules = useMatchConstraints();
  const teams = useTeams();
  const venues = useVenues();

  if (readFailed(rules) || readFailed(teams) || readFailed(venues)) {
    return (
      <LoadErrorHint
        onRetry={() => {
          void rules.refetch();
          void teams.refetch();
          void venues.refetch();
        }}
      />
    );
  }
  if (undefined === rules.data || undefined === teams.data || undefined === venues.data) {
    return <FullPageSpinner />;
  }

  // La section Équipes ne montre QUE les interdictions de gymnase (scope TEAM). Simple
  // tri d'affichage — jamais un verdict solveur (le backend décide, .claude/rules/frontend.md).
  const bans = rules.data.filter((rule) => "TEAM" === rule.scope);
  const teamsById = new Map(teams.data.map((t) => [t.id, t]));
  const venuesById = new Map(venues.data.map((v) => [v.id, v]));

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        Interdisez à une équipe un gymnase où elle ne doit jamais jouer ses matchs. Le placement l'évite ; une pose manuelle qui l'enfreint
        reste possible — le radar la signale. La <strong>préférence</strong> de gymnase, elle, se règle dans la{" "}
        <Link className="text-accent underline" to="/matchs/semaine-type">
          Semaine type
        </Link>
        .
      </p>

      {0 === bans.length ? (
        <p className="text-sm text-muted-foreground">Aucune interdiction — chaque équipe peut jouer dans n'importe quel gymnase du club.</p>
      ) : (
        <div className="flex flex-col gap-2">
          {bans.map((ban) => (
            <TeamVenueBanRow key={ban.id} ban={ban} teamName={teamsById.get(ban.scopeTargetId ?? "")?.name ?? "Équipe ?"} venueName={venuesById.get(ban.venueId ?? "")?.name ?? "Gymnase ?"} />
          ))}
        </div>
      )}

      <AddTeamVenueBanRow teams={teams.data} venues={venues.data} />
    </div>
  );
}

/** Une interdiction existante : équipe → gymnase interdit, avec suppression (DELETE). */
function TeamVenueBanRow({ ban, teamName, venueName }: { ban: MatchConstraint; teamName: string; venueName: string }) {
  const [confirmDelete, setConfirmDelete] = useState(false);
  const remove = useDeleteMatchConstraint();

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card px-3 py-2">
      <span className="text-sm text-foreground">
        <strong>{teamName}</strong> ne joue jamais à <strong>{venueName}</strong>
      </span>
      <Button variant="outline" size="sm" aria-label="Supprimer" className="ml-auto" disabled={remove.isPending} onClick={() => setConfirmDelete(true)}>
        <Trash2 className="size-3.5" />
      </Button>
      <ConfirmDialog
        open={confirmDelete}
        title="Lever cette interdiction ?"
        description="Le placement pourra de nouveau utiliser ce gymnase pour cette équipe."
        confirmLabel="Lever l'interdiction"
        destructive
        onConfirm={() => {
          setConfirmDelete(false);
          remove.mutate(ban.id);
        }}
        onCancel={() => setConfirmDelete(false)}
      />
    </div>
  );
}

/** La ligne d'ajout d'une interdiction (POST scope TEAM : équipe + gymnase, toujours HARD). */
function AddTeamVenueBanRow({ teams, venues }: { teams: Team[]; venues: Venue[] }) {
  const [teamId, setTeamId] = useState("");
  const [venueId, setVenueId] = useState("");
  const create = useCreateMatchConstraint();

  const submit = (): void => {
    create.mutate(
      { scope: "TEAM", scopeTargetId: teamId, venueId, ruleType: "HARD", daysOfWeek: [], kickoffMin: null, kickoffMax: null },
      {
        onSuccess: () => {
          setTeamId("");
          setVenueId("");
        },
      },
    );
  };

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-dashed border-border px-3 py-2">
      <Select aria-label="Équipe" value={teamId} onChange={(e) => setTeamId(e.target.value)} className="min-w-32">
        <option value="">Équipe…</option>
        {teams.map((t) => (
          <option key={t.id} value={t.id}>
            {t.name}
          </option>
        ))}
      </Select>
      <span className="text-sm text-muted-foreground">ne joue jamais à</span>
      <Select aria-label="Gymnase interdit" value={venueId} onChange={(e) => setVenueId(e.target.value)} className="min-w-32">
        <option value="">Gymnase…</option>
        {venues.map((v) => (
          <option key={v.id} value={v.id}>
            {v.name}
          </option>
        ))}
      </Select>
      <Button size="sm" className="ml-auto" disabled={"" === teamId || "" === venueId || create.isPending} onClick={submit}>
        <Plus className="size-3.5" />
        Interdire
      </Button>
    </div>
  );
}

// ── Section Coachs (P4-272 ⑤) : indisponibilités d'entraîneur ─────────────────────

interface CoachUnavailabilityDraft {
  coachId: string;
  daysOfWeek: number[];
  /** "" = borne ouverte de ce côté. */
  kickoffMin: string;
  kickoffMax: string;
}

const emptyCoachDraft = (rule: MatchConstraint | null): CoachUnavailabilityDraft => ({
  coachId: rule?.scopeTargetId ?? "",
  daysOfWeek: rule?.daysOfWeek ?? [6],
  kickoffMin: rule?.kickoffMin ?? "",
  kickoffMax: rule?.kickoffMax ?? "",
});

/** Un coach, au moins un jour ET au moins une borne (le serveur refuse sinon). min ≤ max reste au serveur. */
const isCoachDraftComplete = (draft: CoachUnavailabilityDraft): boolean =>
  "" !== draft.coachId && draft.daysOfWeek.length > 0 && ("" !== draft.kickoffMin || "" !== draft.kickoffMax);

/** Une indisponibilité de coach = une règle de match scope COACH, TOUJOURS PREFERRED (SOFT). */
const toCoachInput = (draft: CoachUnavailabilityDraft): MatchConstraintInput => ({
  scope: "COACH",
  scopeTargetId: draft.coachId,
  ruleType: "PREFERRED",
  daysOfWeek: draft.daysOfWeek,
  kickoffMin: "" !== draft.kickoffMin ? draft.kickoffMin : null,
  kickoffMax: "" !== draft.kickoffMax ? draft.kickoffMax : null,
});

const coachLabel = (coach: Coach | undefined): string => (undefined !== coach ? `${coach.firstName} ${coach.lastName}` : "Entraîneur ?");

/**
 * La section Coachs : le CRUD des INDISPONIBILITÉS d'entraîneur (« pas avant 14h le
 * samedi »). Chaque indisponibilité vise un entraîneur, un ou plusieurs JOURS et une
 * fourchette de coup d'envoi (chaque borne facultative) — plusieurs plages par
 * entraîneur, même jour compris, sont légitimes. C'est une PRÉFÉRENCE (SOFT) : le
 * placement l'évite quand il le peut, il ne rend jamais un match impossible.
 */
function CoachsSection() {
  const rules = useMatchConstraints();
  const coaches = useCoaches();

  if (readFailed(rules) || readFailed(coaches)) {
    return (
      <LoadErrorHint
        onRetry={() => {
          void rules.refetch();
          void coaches.refetch();
        }}
      />
    );
  }
  if (undefined === rules.data || undefined === coaches.data) {
    return <FullPageSpinner />;
  }

  // La section Coachs ne montre QUE les indisponibilités de coach (scope COACH). Simple tri
  // d'affichage — jamais un verdict solveur (le backend décide, .claude/rules/frontend.md).
  const unavailabilities = rules.data.filter((rule) => "COACH" === rule.scope);
  const coachesById = new Map(coaches.data.map((c) => [c.id, c]));

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        Indiquez quand un entraîneur n'est pas disponible pour un match — par exemple « pas de match avant 14h le samedi ». Le
        placement <strong>évite</strong> ces plages quand il le peut, sans jamais rendre un match impossible. Vous pouvez déclarer
        plusieurs plages pour un même entraîneur, y compris le même jour.
      </p>

      {0 === unavailabilities.length ? (
        <p className="text-sm text-muted-foreground">Aucune indisponibilité — chaque entraîneur est réputé disponible pour tous les matchs.</p>
      ) : (
        <div className="flex flex-col gap-2">
          {unavailabilities.map((rule) => (
            <CoachUnavailabilityRow key={rule.id} rule={rule} coaches={coaches.data} coachName={coachLabel(coachesById.get(rule.scopeTargetId ?? ""))} />
          ))}
        </div>
      )}

      <AddCoachUnavailabilityRow coaches={coaches.data} />
    </div>
  );
}

/** Le sélecteur d'entraîneur + les champs jours/de/à — partagés par la ligne éditable et l'ajout. */
function CoachFields({ draft, set, coaches, idLabel }: { draft: CoachUnavailabilityDraft; set: (patch: Partial<CoachUnavailabilityDraft>) => void; coaches: Coach[]; idLabel: string }) {
  return (
    <>
      <Select aria-label="Entraîneur" value={draft.coachId} onChange={(e) => set({ coachId: e.target.value })} className="min-w-32">
        <option value="">Entraîneur…</option>
        {coaches.map((c) => (
          <option key={c.id} value={c.id}>
            {coachLabel(c)}
          </option>
        ))}
      </Select>
      <DayToggles label={`Jours (${idLabel})`} value={draft.daysOfWeek} onChange={(daysOfWeek) => set({ daysOfWeek })} />
      <label className="flex items-center gap-1 text-sm text-muted-foreground">
        Pas avant
        <Input aria-label="Pas avant (heure de début)" type="time" value={draft.kickoffMin} onChange={(e) => set({ kickoffMin: e.target.value })} />
      </label>
      <label className="flex items-center gap-1 text-sm text-muted-foreground">
        Pas après
        <Input aria-label="Pas après (heure de fin)" type="time" value={draft.kickoffMax} onChange={(e) => set({ kickoffMax: e.target.value })} />
      </label>
    </>
  );
}

/** Le résumé compact d'une indisponibilité : « Mateo Durand · Sam · pas avant 14:00 ». */
function coachUnavailabilitySummary(rule: MatchConstraint, coachName: string): string {
  return `${coachName} · ${daysShort(rule.daysOfWeek)} · ${clubRuleLabel(rule)}`;
}

/** Une indisponibilité éditable : compacte au repos (résumé + ✎ + 🗑), dépliée en champs à l'édition. */
function CoachUnavailabilityRow({ rule, coaches, coachName }: { rule: MatchConstraint; coaches: Coach[]; coachName: string }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState<CoachUnavailabilityDraft>(() => emptyCoachDraft(rule));
  const [confirmDelete, setConfirmDelete] = useState(false);
  const update = useUpdateMatchConstraint();
  const remove = useDeleteMatchConstraint();
  const set = (patch: Partial<CoachUnavailabilityDraft>): void => setDraft((d) => ({ ...d, ...patch }));

  const openEdit = (): void => {
    setDraft(emptyCoachDraft(rule));
    setEditing(true);
  };

  const dirty =
    draft.coachId !== (rule.scopeTargetId ?? "") ||
    !sameDays(draft.daysOfWeek, rule.daysOfWeek) ||
    draft.kickoffMin !== (rule.kickoffMin ?? "") ||
    draft.kickoffMax !== (rule.kickoffMax ?? "");

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card px-3 py-2">
      {editing ? (
        <>
          <CoachFields draft={draft} set={set} coaches={coaches} idLabel={coachName} />
          <div className="ml-auto flex items-center gap-2">
            <Button size="sm" disabled={!dirty || !isCoachDraftComplete(draft) || update.isPending} onClick={() => update.mutate({ id: rule.id, input: toCoachInput(draft) }, { onSuccess: () => setEditing(false) })}>
              Enregistrer
            </Button>
            <Button variant="outline" size="sm" onClick={() => setEditing(false)}>
              Annuler
            </Button>
          </div>
        </>
      ) : (
        <>
          <span className="text-sm text-foreground">{coachUnavailabilitySummary(rule, coachName)}</span>
          <div className="ml-auto flex items-center gap-2">
            <Button variant="ghost" size="icon" className="size-8" aria-label="Modifier" title="Modifier" onClick={openEdit}>
              <Pencil className="size-3.5" />
            </Button>
            <Button variant="ghost" size="icon" className="size-8 text-destructive" aria-label="Supprimer" title="Supprimer" disabled={remove.isPending} onClick={() => setConfirmDelete(true)}>
              <Trash2 className="size-3.5" />
            </Button>
          </div>
        </>
      )}
      <ConfirmDialog
        open={confirmDelete}
        title="Supprimer cette indisponibilité ?"
        description="Le placement cessera d'éviter cette plage pour cet entraîneur."
        confirmLabel="Supprimer"
        destructive
        onConfirm={() => {
          setConfirmDelete(false);
          remove.mutate(rule.id);
        }}
        onCancel={() => setConfirmDelete(false)}
      />
    </div>
  );
}

/** La ligne d'ajout d'une indisponibilité (POST scope COACH, toujours PREFERRED). */
function AddCoachUnavailabilityRow({ coaches }: { coaches: Coach[] }) {
  const [draft, setDraft] = useState<CoachUnavailabilityDraft>(() => emptyCoachDraft(null));
  const create = useCreateMatchConstraint();
  const set = (patch: Partial<CoachUnavailabilityDraft>): void => setDraft((d) => ({ ...d, ...patch }));

  const submit = (): void => {
    create.mutate(toCoachInput(draft), { onSuccess: () => setDraft(emptyCoachDraft(null)) });
  };

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-dashed border-border px-3 py-2">
      <CoachFields draft={draft} set={set} coaches={coaches} idLabel="nouvelle indisponibilité" />
      <Button size="sm" className="ml-auto" disabled={!isCoachDraftComplete(draft) || create.isPending} onClick={submit}>
        <Plus className="size-3.5" />
        Ajouter
      </Button>
    </div>
  );
}
