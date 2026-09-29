import { AlertTriangle, Plus, Trash2 } from "lucide-react";
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

import type { ClubLeagueWindow, ClubLeagueWindowInput, LeagueWindowLevel, MatchConstraint, MatchConstraintInput, MatchRuleType } from "./api";
import { frClock } from "./lib/clubRuleLabel";
import { LeagueSuggestions } from "./LeagueSuggestions";
import {
  useClubLeagueWindows,
  useCreateClubLeagueWindow,
  useCreateMatchConstraint,
  useDeleteClubLeagueWindow,
  useDeleteMatchConstraint,
  useMatchConstraintCoherence,
  useMatchConstraints,
  useUpdateClubLeagueWindow,
  useUpdateMatchConstraint,
} from "./queries";

/**
 * P4-272 ① — l'écran UNIQUE des contraintes de match, en accordéon (patron
 * `ConfigurationPage`, section ouverte ancrée `?section=`). La section **Ligue**
 * est le CRUD gestionnaire de la copie club de l'enveloppe fédérale (le placement,
 * le radar et le calendrier lisent la même copie) ; les sections **Club**,
 * **Équipes** et **Coachs** arrivent dans les PR suivantes — en attendant, elles
 * pointent vers les écrans qui portent déjà ces réglages (liens croisés).
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
        <ClubSection />
      </AccordionSection>

      <AccordionSection {...sectionProps("equipes")} title="Équipes">
        <ComingSoon>
          Les contraintes d'équipe arriveront ici. En attendant, la préférence de gymnase et l'habitude de match se règlent dans la{" "}
          <Link className="text-accent underline" to="/matchs/semaine-type">
            Semaine type
          </Link>
          .
        </ComingSoon>
      </AccordionSection>

      <AccordionSection {...sectionProps("coachs")} title="Coachs">
        <ComingSoon>Les contraintes de coach arriveront ici.</ComingSoon>
      </AccordionSection>
    </div>
  );
}

function ComingSoon({ children }: { children: ReactNode }) {
  return (
    <div className="flex flex-col gap-2">
      <StatusPill>Bientôt</StatusPill>
      <p className="text-sm text-muted-foreground">{children}</p>
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

/** Une ligne éditable de la copie (PUT au « Enregistrer », DELETE au « Supprimer »). */
function LeagueWindowRow({ window }: { window: ClubLeagueWindow }) {
  const [draft, setDraft] = useState<ClubLeagueWindowInput>(() => emptyDraft(window));
  const [confirmDelete, setConfirmDelete] = useState(false);
  const update = useUpdateClubLeagueWindow();
  const remove = useDeleteClubLeagueWindow();
  const set = (patch: Partial<ClubLeagueWindowInput>): void => setDraft((d) => ({ ...d, ...patch }));

  const dirty =
    draft.category !== window.category ||
    draft.level !== window.level ||
    (draft.gender ?? "") !== (window.gender ?? "") ||
    draft.dayOfWeek !== window.dayOfWeek ||
    draft.kickoffMin !== window.kickoffMin ||
    draft.kickoffMax !== window.kickoffMax;

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card px-3 py-2">
      <DraftFields draft={draft} set={set} />
      <div className="ml-auto flex items-center gap-2">
        {badgePill(window.badge)}
        <Button
          size="sm"
          disabled={!dirty || !isComplete(draft) || update.isPending}
          onClick={() => update.mutate({ id: window.id, input: { ...draft, gender: "" === draft.gender ? null : draft.gender } })}
        >
          Enregistrer
        </Button>
        <Button variant="outline" size="sm" aria-label="Supprimer" disabled={remove.isPending} onClick={() => setConfirmDelete(true)}>
          <Trash2 className="size-3.5" />
        </Button>
      </div>
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
 * La section Club : le CRUD des règles de match du club (« pas après 21h », …). Chaque
 * règle porte un ou plusieurs JOURS, une fourchette de coup d'envoi (chaque borne
 * facultative) et un type Obligatoire (HARD, honorée par le solveur) / Préférée
 * (PREFERRED, une préférence). Sous une règle, l'ALERTE DE COHÉRENCE — les créneaux
 * idéaux qu'elle heurte — est CALCULÉE côté serveur (`/coherence`), l'écran l'affiche.
 */
function ClubSection() {
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

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        Vos règles de match — par exemple « pas de match après 21h le samedi ». Une règle <strong>obligatoire</strong> est respectée par le
        placement ; une règle <strong>préférée</strong> est un souhait que le placement suit s'il le peut. Une pose manuelle hors d'une règle
        reste possible — le radar la signale.
      </p>

      {0 === rules.data.length ? (
        <p className="text-sm text-muted-foreground">Aucune règle de club — le placement ne s'impose que les fenêtres de la ligue.</p>
      ) : (
        <div className="flex flex-col gap-2">
          {rules.data.map((rule) => (
            <ClubRuleRow key={rule.id} rule={rule} alerts={alertsByRule.get(rule.id) ?? []} />
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
function ClubRuleAlerts({ alerts }: { alerts: { teamId: string; teamName: string; week: string; dayOfWeek: number; kickoff: string }[] }) {
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
            {"ALL" !== h.week ? ` (semaine ${h.week})` : ""} : {dayLabelLong(h.dayOfWeek)} {frClock(h.kickoff)}.
          </span>
        </p>
      ))}
    </div>
  );
}

/** Une règle éditable (PUT au « Enregistrer », DELETE au « Supprimer »). */
function ClubRuleRow({ rule, alerts }: { rule: MatchConstraint; alerts: { teamId: string; teamName: string; week: string; dayOfWeek: number; kickoff: string }[] }) {
  const [draft, setDraft] = useState<ClubRuleDraft>(() => emptyRuleDraft(rule));
  const [confirmDelete, setConfirmDelete] = useState(false);
  const update = useUpdateMatchConstraint();
  const remove = useDeleteMatchConstraint();
  const set = (patch: Partial<ClubRuleDraft>): void => setDraft((d) => ({ ...d, ...patch }));

  const dirty =
    draft.ruleType !== rule.ruleType ||
    !sameDays(draft.daysOfWeek, rule.daysOfWeek) ||
    draft.kickoffMin !== (rule.kickoffMin ?? "") ||
    draft.kickoffMax !== (rule.kickoffMax ?? "");

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card px-3 py-2">
      <RuleFields draft={draft} set={set} idLabel="règle" />
      <div className="ml-auto flex items-center gap-2">
        <Button size="sm" disabled={!dirty || !isRuleComplete(draft) || update.isPending} onClick={() => update.mutate({ id: rule.id, input: toRuleInput(draft) })}>
          Enregistrer
        </Button>
        <Button variant="outline" size="sm" aria-label="Supprimer" disabled={remove.isPending} onClick={() => setConfirmDelete(true)}>
          <Trash2 className="size-3.5" />
        </Button>
      </div>
      <ClubRuleAlerts alerts={alerts} />
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
