import { AlertTriangle, Check, Undo2 } from "lucide-react";
import { useMemo, useState } from "react";

import { frDateWeekdayNoYear } from "@/shared/lib/date";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Select } from "@/shared/components/ui/select";
import { todayISO } from "@/shared/lib/clock";
import { cn } from "@/shared/lib/utils";

import type { Competition, Fixture, FixtureStatus, Team, Venue } from "./api";
import { deadlineDisplay } from "./lib/deadlineLabel";

interface FbiEntryListProps {
  /** Fixtures of the active week (already bucketed by the page). */
  fixtures: Fixture[];
  teams: Map<string, Team>;
  venues: Map<string, Venue>;
  /** RMM-6 — competition of each fixture, to read its served entry deadline. */
  competitions?: Map<string, Competition>;
  /** App today (`todayISO`, demo anchor included) — for the deadline countdown. */
  today?: string;
  busy: boolean;
  /** L4 — cocher une ligne PLACÉE : elle passe SUBMITTED (« saisi dans FBI »). */
  onSubmit: (fixture: Fixture) => void;
  /** L4 — corriger une ligne SAISIE : elle redevient PLACED (chemin de réparation). */
  onReopen: (fixture: Fixture) => void;
}

/** Domiciles recopiables dans FBI : un UNPLACED n'a rien à saisir (fait #4 §6quater). */
const RECOPIABLE: readonly FixtureStatus[] = ["PLACED", "SUBMITTED", "VALIDATED"];
const isRecopiable = (f: Fixture): boolean => "HOME" === f.homeAway && RECOPIABLE.includes(f.status);
const isDone = (f: Fixture): boolean => "SUBMITTED" === f.status || "VALIDATED" === f.status;


/** Active (à saisir) EN TÊTE, puis les rangées déjà saisies ; à statut égal, par date+heure. */
function orderInTeam(a: Fixture, b: Fixture): number {
  if (isDone(a) !== isDone(b)) {
    return isDone(a) ? 1 : -1;
  }
  return `${a.matchDate}${a.kickoffTime ?? ""}`.localeCompare(`${b.matchDate}${b.kickoffTime ?? ""}`);
}

/**
 * RMM-1 PR4 (L9) — **la vraie vue de saisie FBI** : la liste à recopier dans le
 * logiciel de la fédération, pensée pour que la saisie soit BÊTE (coller à l'écran
 * FBI, préconisation fondateur). Organisée par équipe, filtrable équipe · date
 * (la semaine = la navigation existante, pas un filtre de plus). Cocher une ligne
 * = le geste L4 (`onSubmit`) ; une ligne saisie recède (état coché rangé) et garde
 * un « Corriger » discret (`onReopen`). « Tout marquer saisi » ne coche QUE les
 * lignes affichées (le filtre borne le lot — décision fondateur, plus sûr), sous
 * confirmation. **Zéro backend : une PROJECTION des fixtures existants.**
 */
export function FbiEntryList({ fixtures, teams, venues, competitions, today = todayISO(), busy, onSubmit, onReopen }: FbiEntryListProps) {
  const [teamFilter, setTeamFilter] = useState("");
  const [dateFilter, setDateFilter] = useState("");
  const [confirmBatch, setConfirmBatch] = useState(false);

  const rows = useMemo(() => fixtures.filter(isRecopiable), [fixtures]);

  const teamOptions = useMemo(
    () =>
      [...new Set(rows.map((r) => r.teamId))]
        .map((id) => ({ id, name: teams.get(id)?.name ?? "Équipe ?" }))
        .sort((a, b) => a.name.localeCompare(b.name)),
    [rows, teams],
  );
  const dateOptions = useMemo(() => [...new Set(rows.map((r) => r.matchDate))].sort(), [rows]);

  const shown = useMemo(
    () => rows.filter((r) => ("" === teamFilter || r.teamId === teamFilter) && ("" === dateFilter || r.matchDate === dateFilter)),
    [rows, teamFilter, dateFilter],
  );

  // Le lot de masse = UNIQUEMENT les lignes affichées ET encore à saisir (PLACED).
  const shownToSubmit = useMemo(() => shown.filter((f) => "PLACED" === f.status), [shown]);

  // Groupes équipe (dans l'ordre alpha), chacun avec ses lignes ordonnées.
  const groups = useMemo(
    () =>
      teamOptions
        .map((t) => ({ team: t, lines: shown.filter((f) => f.teamId === t.id).sort(orderInTeam) }))
        .filter((g) => g.lines.length > 0),
    [teamOptions, shown],
  );

  if (0 === rows.length) {
    return <EmptyHint>Aucun domicile à recopier dans FBI cette semaine.</EmptyHint>;
  }

  function runBatch(): void {
    for (const f of shownToSubmit) {
      onSubmit(f);
    }
    setConfirmBatch(false);
  }

  return (
    <div className="flex flex-col gap-4">
      {/* Barre : filtres (équipe · date) + le geste de masse, borné au filtre. */}
      <div className="flex flex-wrap items-end gap-3">
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
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Date
          <Select aria-label="Filtrer par date" className="h-9 w-44" value={dateFilter} onChange={(e) => setDateFilter(e.target.value)}>
            <option value="">Toutes les dates</option>
            {dateOptions.map((d) => (
              <option key={d} value={d}>
                {frDateWeekdayNoYear(d)}
              </option>
            ))}
          </Select>
        </label>
        <div className="ml-auto flex items-center gap-3">
          <span className="text-xs text-muted-foreground">
            {shownToSubmit.length} à saisir
            {shownToSubmit.length !== shown.length ? ` · ${shown.length - shownToSubmit.length} fait${shown.length - shownToSubmit.length > 1 ? "s" : ""}` : ""}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={busy || 0 === shownToSubmit.length}
            className="border-success/40 text-success hover:bg-success/10"
            onClick={() => setConfirmBatch(true)}
          >
            <Check className="size-3.5" />
            Tout marquer saisi
          </Button>
        </div>
      </div>

      {0 === shown.length ? (
        <EmptyHint>Aucune ligne ne correspond au filtre.</EmptyHint>
      ) : (
        <div className="flex flex-col gap-4">
          {groups.map((group) => (
            <section key={group.team.id} className="flex flex-col gap-1.5">
              <h3 className="text-sm font-semibold">{group.team.name}</h3>
              <ul className="flex flex-col gap-1.5">
                {group.lines.map((f) => {
                  const done = isDone(f);
                  const teamName = group.team.name;
                  const venueName = null === f.venueId ? "Gymnase ?" : (venues.get(f.venueId)?.name ?? "Gymnase ?");
                  // RMM-6 — l'échéance de saisie SERVIE de la compétition du match (amical =
                  // pas de compétition → rien). Signal, jamais un blocage : la case reste cochable.
                  const competition = null !== f.competitionId ? competitions?.get(f.competitionId) : undefined;
                  const effective = competition?.effectiveEntryDeadline ?? null;
                  const deadline = null !== effective ? deadlineDisplay(effective, today) : null;
                  const proposed = "community" === competition?.deadlineSource;
                  return (
                    <li
                      key={f.id}
                      className={cn(
                        "flex items-center gap-3 rounded-md border px-3 py-2 text-sm",
                        done ? "border-success/40 bg-success/10" : "border-border",
                      )}
                    >
                      {done ? (
                        // Ligne rangée : la case COCHÉE (verte, statique). La checklist
                        // « se vide » — le travail restant flotte en tête de groupe.
                        <span aria-hidden className="grid size-5 shrink-0 place-items-center rounded bg-success/20 text-success">
                          <Check className="size-3.5" />
                        </span>
                      ) : (
                        // Case à cocher : cocher = le geste « saisi ».
                        <button
                          type="button"
                          aria-label={`Marquer saisi : ${teamName} contre ${f.opponentLabel}`}
                          disabled={busy}
                          onClick={() => onSubmit(f)}
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
                        {null !== deadline ? (
                          <span className={cn("flex items-center gap-1 text-xs", deadline.overdue ? "text-warning" : "text-muted-foreground")}>
                            {deadline.overdue ? <AlertTriangle className="size-3 shrink-0" aria-hidden /> : null}
                            {deadline.label} ({deadline.countdown})
                            {proposed ? " · proposée" : ""}
                          </span>
                        ) : null}
                      </span>

                      {"SUBMITTED" === f.status ? (
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={busy}
                          aria-label={`Corriger : ${teamName} contre ${f.opponentLabel}`}
                          className="shrink-0 text-muted-foreground"
                          onClick={() => onReopen(f)}
                        >
                          <Undo2 className="size-3.5" />
                          Corriger
                        </Button>
                      ) : "VALIDATED" === f.status ? (
                        <span className="shrink-0 text-xs text-muted-foreground">Validé ligue</span>
                      ) : null}
                    </li>
                  );
                })}
              </ul>
            </section>
          ))}
        </div>
      )}

      <ConfirmDialog
        open={confirmBatch}
        destructive={false}
        title={`Marquer saisi ${shownToSubmit.length} match${shownToSubmit.length > 1 ? "s" : ""} ?`}
        description="Seules les lignes actuellement affichées (le filtre en cours) sont marquées saisies dans FBI. Chaque ligne reste corrigeable ensuite."
        confirmLabel="Confirmer"
        onConfirm={runBatch}
        onCancel={() => setConfirmBatch(false)}
      />
    </div>
  );
}
