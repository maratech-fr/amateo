import { AlertTriangle, Check, Undo2 } from "lucide-react";
import { useId, useMemo, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Select } from "@/shared/components/ui/select";
import { frDateWeekdayNoYear } from "@/shared/lib/date";
import { todayISO } from "@/shared/lib/clock";
import { cn } from "@/shared/lib/utils";

import type { Competition, FbiCorrection, Fixture, Team, Venue } from "./api";
import { deadlineDisplay } from "./lib/deadlineLabel";
import { FIELD_LABEL, seenInFbiLabel } from "./lib/fbiCorrectionLabel";

interface FbiEntryListProps {
  /** TOUTES les fixtures de la saison — le « FBI à faire » est global, pas hebdomadaire. */
  fixtures: Fixture[];
  /** Le registre « à corriger dans FBI » (entrées OUVERTES servies par le backend). */
  corrections: FbiCorrection[];
  teams: Map<string, Team>;
  venues: Map<string, Venue>;
  /** Échéance de saisie servie, pour la mention discrète des lignes « à saisir » (amical = rien). */
  competitions?: Map<string, Competition>;
  /** App today (`todayISO`, ancrage démo inclus) — « vu dans FBI » relatif + compte à rebours. */
  today?: string;
  busy: boolean;
  /** Cocher une ligne « à saisir » PLACÉE → elle passe SUBMITTED (« saisi dans FBI »). */
  onSubmit: (fixture: Fixture) => void;
  /** « Corrigé dans FBI » → ferme (manuel) TOUTES les entrées du match. */
  onCorrected: (corrections: FbiCorrection[]) => void;
  /** « Annuler » → rouvre les entrées du match. */
  onUndoCorrected: (corrections: FbiCorrection[]) => void;
}

interface HasWhen {
  matchDate: string;
  kickoffTime: string | null;
}

const byDateKickoff = (a: HasWhen, b: HasWhen): number => `${a.matchDate}${a.kickoffTime ?? ""}`.localeCompare(`${b.matchDate}${b.kickoffTime ?? ""}`);

interface CorrectionGroup {
  fixture: Fixture;
  corrections: FbiCorrection[];
  done: boolean;
}

/**
 * La maison unique **« FBI — à faire »** : tout ce qui reste à reporter dans le
 * portail fédéral, toutes semaines confondues, en DEUX sections empilées —
 * **« À corriger dans FBI » EN PREMIER** (l'appli fait foi, FBI est en retard), puis
 * **« À saisir »** (les domiciles placés pas encore saisis). Une famille vide est
 * masquée ; les deux vides → « Rien à faire dans FBI. ». Filtre Équipe conservé
 * (le filtre Date a disparu — la liste est globale, triée par date de match).
 *
 * Cocher « Corrigé dans FBI » (sans confirmation) grise la ligne et propose « Annuler »
 * jusqu'à la fermeture de la modale (`Set` local, remis à zéro à l'unmount) ; idem pour
 * « saisi ». PRÉSENTATION pure : gras = la valeur Amateo (à taper), la valeur FBI reste
 * en sourdine, jamais barrée. Le front n'invente aucune règle.
 */
export function FbiEntryList({ fixtures, corrections, teams, venues, competitions, today = todayISO(), busy, onSubmit, onCorrected, onUndoCorrected }: FbiEntryListProps) {
  const [teamFilter, setTeamFilter] = useState("");
  const [confirmBatch, setConfirmBatch] = useState(false);
  const [submittedSession, setSubmittedSession] = useState<ReadonlySet<string>>(() => new Set());
  const [correctedSession, setCorrectedSession] = useState<ReadonlyMap<string, FbiCorrection>>(() => new Map());
  const correctHeadingId = useId();
  const enterHeadingId = useId();

  const fixtureById = useMemo(() => new Map(fixtures.map((f) => [f.id, f])), [fixtures]);

  // « À corriger » — groupé par MATCH : union des entrées OUVERTES et des entrées
  // cochées cette session (snapshot gardé grisé jusqu'à la fermeture de la modale).
  const correctionGroups = useMemo<CorrectionGroup[]>(() => {
    const byId = new Map<string, FbiCorrection>();
    for (const c of corrections) {
      byId.set(c.id, c);
    }
    for (const c of correctedSession.values()) {
      byId.set(c.id, c);
    }
    const byFixture = new Map<string, FbiCorrection[]>();
    for (const c of byId.values()) {
      const list = byFixture.get(c.fixtureId) ?? [];
      list.push(c);
      byFixture.set(c.fixtureId, list);
    }
    return [...byFixture.entries()]
      .map(([fixtureId, list]) => ({ fixture: fixtureById.get(fixtureId), corrections: list, done: list.every((c) => correctedSession.has(c.id)) }))
      .filter((g): g is CorrectionGroup => undefined !== g.fixture)
      .filter((g) => "" === teamFilter || g.fixture.teamId === teamFilter)
      .sort((a, b) => byDateKickoff(a.fixture, b.fixture));
  }, [corrections, correctedSession, fixtureById, teamFilter]);

  // « À saisir » — domiciles PLACED + lignes cochées cette session (grisées) ; les
  // SUBMITTED anciennes (jamais cochées ici) ne sont PAS listées.
  const submitRows = useMemo(
    () =>
      fixtures
        .filter((f) => "HOME" === f.homeAway && ("PLACED" === f.status || submittedSession.has(f.id)))
        .filter((f) => "" === teamFilter || f.teamId === teamFilter)
        .sort(byDateKickoff),
    [fixtures, submittedSession, teamFilter],
  );
  const shownToSubmit = useMemo(() => submitRows.filter((f) => "PLACED" === f.status && !submittedSession.has(f.id)), [submitRows, submittedSession]);

  const teamOptions = useMemo(() => {
    const ids = new Set<string>();
    for (const c of [...corrections, ...correctedSession.values()]) {
      const f = fixtureById.get(c.fixtureId);
      if (undefined !== f) {
        ids.add(f.teamId);
      }
    }
    for (const f of fixtures) {
      if ("HOME" === f.homeAway && ("PLACED" === f.status || submittedSession.has(f.id))) {
        ids.add(f.teamId);
      }
    }
    return [...ids].map((id) => ({ id, name: teams.get(id)?.name ?? "Équipe ?" })).sort((a, b) => a.name.localeCompare(b.name));
  }, [corrections, correctedSession, fixtures, submittedSession, fixtureById, teams]);

  const correctToDo = correctionGroups.filter((g) => !g.done).length;
  const submitToDo = shownToSubmit.length;

  if (0 === correctionGroups.length && 0 === submitRows.length) {
    return <EmptyHint>Rien à faire dans FBI.</EmptyHint>;
  }

  function markCorrected(group: CorrectionGroup): void {
    onCorrected(group.corrections);
    setCorrectedSession((prev) => {
      const next = new Map(prev);
      for (const c of group.corrections) {
        next.set(c.id, c);
      }
      return next;
    });
  }

  function undoCorrected(group: CorrectionGroup): void {
    onUndoCorrected(group.corrections);
    setCorrectedSession((prev) => {
      const next = new Map(prev);
      for (const c of group.corrections) {
        next.delete(c.id);
      }
      return next;
    });
  }

  function markSubmitted(fixture: Fixture): void {
    onSubmit(fixture);
    setSubmittedSession((prev) => new Set(prev).add(fixture.id));
  }

  function runBatch(): void {
    for (const f of shownToSubmit) {
      markSubmitted(f);
    }
    setConfirmBatch(false);
  }

  return (
    <div className="flex flex-col gap-4">
      {teamOptions.length > 1 ? (
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Équipe
          <Select aria-label="Filtrer par équipe" className="h-9 w-48" value={teamFilter} onChange={(e) => setTeamFilter(e.target.value)}>
            <option value="">Toutes les équipes</option>
            {teamOptions.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </Select>
        </label>
      ) : null}

      {correctionGroups.length > 0 ? (
        <section aria-labelledby={correctHeadingId} className="flex flex-col gap-1.5">
          <h3 id={correctHeadingId} className="text-sm font-semibold">
            À corriger dans FBI · {correctToDo}
          </h3>
          <ul className="flex flex-col gap-1.5">
            {correctionGroups.map((group) => {
              const teamName = teams.get(group.fixture.teamId)?.name ?? "Équipe ?";
              const seen = group.done ? null : seenInFbiLabel(group.corrections[0]?.lastSeenInFbiAt ?? null, today);
              return (
                <li
                  key={group.fixture.id}
                  className={cn("flex items-start gap-3 rounded-md border px-3 py-2 text-sm", group.done ? "border-success/40 bg-success/10" : "border-border")}
                >
                  {group.done ? (
                    <span aria-hidden className="mt-0.5 grid size-5 shrink-0 place-items-center rounded bg-success/20 text-success">
                      <Check className="size-3.5" />
                    </span>
                  ) : (
                    <span aria-hidden className="mt-0.5 grid size-5 shrink-0 place-items-center rounded bg-warning/15 text-warning">
                      <AlertTriangle className="size-3.5" />
                    </span>
                  )}

                  <div className={cn("min-w-0 flex-1", group.done ? "text-muted-foreground" : "")}>
                    <div className="flex flex-wrap items-baseline gap-x-2">
                      <span className="font-medium tabular-nums">{frDateWeekdayNoYear(group.fixture.matchDate)}</span>
                      <span className="font-medium">{teamName}</span>
                      <span className="text-muted-foreground">vs {group.fixture.opponentLabel}</span>
                      {null !== group.fixture.externalRef ? <span className="tabular-nums text-muted-foreground">n° {group.fixture.externalRef}</span> : null}
                      {null !== seen ? <span className="w-full text-xs text-muted-foreground">{seen}</span> : null}
                    </div>
                    <ul className="mt-1 flex flex-col gap-0.5">{group.corrections.map((c) => <CorrectionField key={c.id} correction={c} />)}</ul>
                  </div>

                  {group.done ? (
                    <Button variant="ghost" size="sm" className="ml-auto shrink-0" disabled={busy} onClick={() => undoCorrected(group)}>
                      <Undo2 className="size-3.5" />
                      Annuler
                    </Button>
                  ) : (
                    <Button
                      variant="outline"
                      size="sm"
                      className="ml-auto shrink-0"
                      disabled={busy}
                      aria-label={`Corrigé dans FBI : ${teamName} contre ${group.fixture.opponentLabel}`}
                      onClick={() => markCorrected(group)}
                    >
                      <Check className="size-3.5" />
                      Corrigé dans FBI
                    </Button>
                  )}
                </li>
              );
            })}
          </ul>
        </section>
      ) : null}

      {submitRows.length > 0 ? (
        <section aria-labelledby={enterHeadingId} className="flex flex-col gap-1.5">
          <div className="flex items-center gap-3">
            <h3 id={enterHeadingId} className="text-sm font-semibold">
              À saisir · {submitToDo}
            </h3>
            <Button
              variant="outline"
              size="sm"
              className="ml-auto border-success/40 text-success hover:bg-success/10"
              disabled={busy || 0 === shownToSubmit.length}
              onClick={() => setConfirmBatch(true)}
            >
              <Check className="size-3.5" />
              Tout marquer saisi
            </Button>
          </div>
          <ul className="flex flex-col gap-1.5">
            {submitRows.map((f) => {
              const done = submittedSession.has(f.id);
              const teamName = teams.get(f.teamId)?.name ?? "Équipe ?";
              const venueName = null === f.venueId ? "Gymnase ?" : (venues.get(f.venueId)?.name ?? "Gymnase ?");
              const competition = null !== f.competitionId ? competitions?.get(f.competitionId) : undefined;
              const effective = competition?.effectiveEntryDeadline ?? null;
              const deadline = null !== effective ? deadlineDisplay(effective, today) : null;
              return (
                <li
                  key={f.id}
                  className={cn("flex items-center gap-3 rounded-md border px-3 py-2 text-sm", done ? "border-success/40 bg-success/10" : "border-border")}
                >
                  {done ? (
                    <span aria-hidden className="grid size-5 shrink-0 place-items-center rounded bg-success/20 text-success">
                      <Check className="size-3.5" />
                    </span>
                  ) : (
                    <button
                      type="button"
                      aria-label={`Marquer saisi : ${teamName} contre ${f.opponentLabel}`}
                      disabled={busy}
                      onClick={() => markSubmitted(f)}
                      className="group grid size-5 shrink-0 place-items-center rounded border border-input text-success transition-colors hover:border-success/60 hover:bg-success/10 disabled:opacity-50"
                    >
                      <Check className="size-3.5 opacity-0 transition-opacity group-hover:opacity-100" />
                    </button>
                  )}

                  <span className={cn("min-w-0 flex-1", done ? "text-muted-foreground" : "")}>
                    <span className="block font-medium tabular-nums">
                      {frDateWeekdayNoYear(f.matchDate)} · {f.kickoffTime ?? "—"}
                    </span>
                    <span className="block truncate text-xs text-muted-foreground">
                      vs {f.opponentLabel} · {venueName}
                      {null !== f.externalRef ? <span className="tabular-nums"> · n° {f.externalRef}</span> : null}
                    </span>
                    <span className="flex flex-wrap items-center gap-1.5">
                      {f.fbiEcho ? (
                        <StatusPill variant="warning" icon={<AlertTriangle className="size-3 shrink-0" aria-hidden />}>
                          FBI affiche {f.fbiEcho.value}
                        </StatusPill>
                      ) : null}
                      {null !== deadline ? (
                        <span className={cn("flex items-center gap-1 text-xs", deadline.overdue ? "text-warning" : "text-muted-foreground")}>
                          {deadline.overdue ? <AlertTriangle className="size-3 shrink-0" aria-hidden /> : null}
                          {deadline.label} ({deadline.countdown})
                          {"community" === competition?.deadlineSource ? " · proposée" : ""}
                        </span>
                      ) : null}
                    </span>
                  </span>
                </li>
              );
            })}
          </ul>
        </section>
      ) : null}

      <ConfirmDialog
        open={confirmBatch}
        destructive={false}
        title={`Marquer saisi ${shownToSubmit.length} match${shownToSubmit.length > 1 ? "s" : ""} ?`}
        description="Seules les lignes « à saisir » actuellement affichées sont marquées saisies dans FBI. Les corrections ne sont jamais touchées. Chaque ligne reste corrigeable ensuite."
        confirmLabel="Confirmer"
        onConfirm={runBatch}
        onCancel={() => setConfirmBatch(false)}
      />
    </div>
  );
}

/** Un item de champ d'une correction : gras = valeur Amateo (à taper), FBI en sourdine (jamais barré). */
function CorrectionField({ correction }: { correction: FbiCorrection }) {
  const label = FIELD_LABEL[correction.field] ?? correction.field;
  if ("venue" === correction.field) {
    // « à vérifier » plutôt qu'un « ? » nu quand ni le libellé FBI ni la valeur cible ne sont
    // connus (cas d'une erreur FBI : l'appli a importé l'erreur, elle ne l'invente pas) — un
    // « ? » se lisait comme un bug (décision fondateur, lot N).
    const bold = correction.venueFbiLabel ?? correction.appValue ?? "à vérifier";
    const showAmateo = null !== correction.venueFbiLabel && correction.venueFbiLabel !== correction.appValue && null !== correction.appValue;
    return (
      <li>
        {label} : <span className="font-medium">{bold}</span>
        {showAmateo ? <span className="text-muted-foreground"> ({correction.appValue})</span> : null}
        {null !== correction.fbiValue ? <span className="text-muted-foreground"> · FBI affiche {correction.fbiValue}</span> : null}
      </li>
    );
  }
  // Même absence de valeur cible que la salle (une erreur FBI n'apporte aucune valeur à
  // taper : l'appli a importé l'erreur, elle ne l'invente pas) → même lecture « à vérifier »
  // que la branche salle, jamais un tiret muet qui se lisait différemment (lot N).
  return (
    <li>
      {label} : <span className="font-medium">{correction.appValue ?? "à vérifier"}</span>
      {null !== correction.fbiValue ? <span className="text-muted-foreground"> · FBI affiche {correction.fbiValue}</span> : null}
    </li>
  );
}
