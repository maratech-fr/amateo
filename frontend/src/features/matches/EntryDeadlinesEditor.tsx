import { CalendarCheck, Eraser, Info, Users } from "lucide-react";
import { useMemo, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";
import { SegmentedControl } from "@/shared/components/ui/segmented-control";
import { errorMessage } from "@/shared/lib/errorMessage";
import type { TeamLike } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";

import type { Competition } from "./api";
import { frShortDate } from "./lib/deadlineLabel";
import { useSetEntryDeadlines } from "./queries";
import { MATCHES_ENTRY_DEADLINES_TEXTS as TEXTS } from "./texts/entryDeadlines";

/**
 * RMM-6 PR-2 — l'éditeur « Échéances de saisie » du SET-UP (`/matchs/configuration`),
 * frère de `MatchWindowsEditor`/`IdealSlotsEditor`. La ligue/le comité fixe une
 * date limite de saisie PAR compétition (région le 2 sept, département le 10…) : le
 * gestionnaire COCHE un lot de compétitions et pose (ou efface) UNE échéance en un geste.
 *
 * 🔴 Le front n'invente RIEN : la provenance (`deadlineSource`) et la valeur effective
 * (`effectiveEntryDeadline`, règle « club gagne, sinon défaut communautaire ») sont
 * SERVIES par le backend — on les affiche, on ne les recalcule pas.
 *
 * Trois provenances, distinctes d'un coup d'œil et jamais par la couleur seule
 * (icône + texte) : valeur CLUB (date pleine, `CalendarCheck`), PROPOSÉE (défaut
 * communautaire pré-rempli, `Info` + « proposée » — une info, pas une alarme), AUCUNE.
 * Une compétition APPARIÉE porte le badge « partagée avec les autres clubs » (poser sa
 * date la partage comme défaut) ; une non appariée ne le porte pas.
 *
 * Le filtre « Sans échéance | Tous » lit la valeur servie : une date proposée compte comme datée.
 * Compte et envoi portent sur les lignes VISIBLES cochées : rien de masqué ne part.
 */
export function EntryDeadlinesEditor<T extends TeamLike>({ competitions, teams }: { competitions: Competition[]; teams: T[] }) {
  const setDeadlines = useSetEntryDeadlines();
  const [checked, setChecked] = useState<Set<string>>(new Set());
  const [filter, setFilter] = useState<"missing" | "all">("all");
  const [date, setDate] = useState("");
  const [formError, setFormError] = useState<string | null>(null);

  const teamName = useMemo(() => {
    const map = new Map(teams.map((t) => [t.id, t.name]));
    return (id: string): string => map.get(id) ?? TEXTS.teamFallback;
  }, [teams]);

  const rows = useMemo(
    () => [...competitions].sort((a, b) => teamName(a.teamId).localeCompare(teamName(b.teamId)) || a.name.localeCompare(b.name)),
    [competitions, teamName],
  );

  const missingRows = rows.filter((c) => null === (c.effectiveEntryDeadline ?? null));
  const visibleRows = "missing" === filter ? missingRows : rows;
  const visibleIds = new Set(visibleRows.map((c) => c.id));
  const checkedIds = [...checked].filter((id) => visibleIds.has(id));
  const allChecked = visibleRows.length > 0 && checkedIds.length === visibleRows.length;

  const toggle = (id: string): void =>
    setChecked((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });

  const toggleAll = (): void => setChecked(allChecked ? new Set() : new Set(visibleRows.map((c) => c.id)));

  const changeFilter = (next: "missing" | "all"): void => {
    setFilter(next);
    setChecked(new Set());
  };

  const apply = (deadline: string | null): void => {
    if (0 === checkedIds.length || setDeadlines.isPending) {
      return;
    }
    setFormError(null);
    setDeadlines.mutate(
      { competitionIds: checkedIds, deadline },
      {
        onSuccess: () => setChecked(new Set()),
        // 422/409 lisible SUR le formulaire (en plus du toast global du hook).
        onError: (error) => void errorMessage(error).then(setFormError),
      },
    );
  };

  if (0 === rows.length) {
    return (
      <section className="flex flex-col gap-3">
        <Header />
        <EmptyHint>{TEXTS.noCompetition}</EmptyHint>
      </section>
    );
  }

  return (
    <section className="flex flex-col gap-3">
      <Header />

      <SegmentedControl
        mode="single"
        label={TEXTS.filterLabel}
        value={filter}
        onValueChange={changeFilter}
        options={[
          { key: "missing", label: TEXTS.filterMissing, count: missingRows.length, ariaLabel: TEXTS.filterMissingAria(missingRows.length) },
          { key: "all", label: TEXTS.filterAll, count: rows.length, ariaLabel: TEXTS.filterAllAria(rows.length) },
        ]}
      />

      {0 === visibleRows.length ? <EmptyHint>{TEXTS.allDated}</EmptyHint> : null}

      {visibleRows.length > 0 ? (
        <>
          {/* La barre de saisie groupée : une date, appliquée aux lignes cochées. */}
          <div className="flex flex-wrap items-end gap-2 rounded-md border border-dashed border-border px-3 py-2">
            <label className="flex flex-col gap-1 text-xs text-muted-foreground">
              {TEXTS.deadlineLabel}
              <Input type="date" aria-label={TEXTS.deadlineAria} value={date} onChange={(e) => setDate(e.target.value)} />
            </label>
            <Button size="sm" disabled={0 === checkedIds.length || "" === date || setDeadlines.isPending} onClick={() => apply(date)}>
              {TEXTS.apply(checkedIds.length)}
            </Button>
            <Button variant="outline" size="sm" disabled={0 === checkedIds.length || setDeadlines.isPending} onClick={() => apply(null)}>
              <Eraser className="size-4" />
              {TEXTS.clear}
            </Button>
            <span className="ml-auto text-xs text-muted-foreground">
              {checkedIds.length > 0 ? TEXTS.checkedCount(checkedIds.length) : TEXTS.checkHint}
            </span>
          </div>

          <div className="flex items-center gap-2">
            <Button variant="ghost" size="sm" className="text-muted-foreground" onClick={toggleAll}>
              {allChecked ? TEXTS.uncheckAll : TEXTS.checkAll}
            </Button>
          </div>

          <ul className="flex flex-col gap-1.5">
            {visibleRows.map((c) => (
              <li key={c.id} className="flex items-center gap-3 rounded-md border border-border bg-card px-3 py-2 text-sm">
                <label className="flex min-w-0 flex-1 items-center gap-3">
                  <input
                    type="checkbox"
                    aria-label={TEXTS.rowAria(c.name, teamName(c.teamId))}
                    className="size-4 shrink-0"
                    checked={checked.has(c.id)}
                    onChange={() => toggle(c.id)}
                  />
                  <span className="min-w-0">
                    <span className="block truncate font-medium">{c.name}</span>
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                      <span>{teamName(c.teamId)}</span>
                      {null !== (c.ffbbCompetitionId ?? null) ? (
                        <StatusPill variant="accent" icon={<Users className="size-3 text-accent" aria-hidden />}>
                          {TEXTS.sharedPill}
                        </StatusPill>
                      ) : null}
                    </span>
                  </span>
                </label>
                <Provenance competition={c} />
              </li>
            ))}
          </ul>
        </>
      ) : null}

      {null !== formError ? (
        <p role="alert" className="text-xs text-destructive">
          {formError}
        </p>
      ) : null}
    </section>
  );
}

function Header() {
  return (
    <div className="flex flex-col gap-1">
      <h3 className="text-sm font-semibold">{TEXTS.title}</h3>
      <p className="text-xs text-muted-foreground">{TEXTS.intro}</p>
    </div>
  );
}

/** La provenance d'une échéance : valeur club (date pleine) · proposée · aucune. */
function Provenance({ competition }: { competition: Competition }) {
  const effective = competition.effectiveEntryDeadline ?? null;
  if (null === effective) {
    return <span className="shrink-0 text-xs italic text-muted-foreground">{TEXTS.noDeadline}</span>;
  }
  const community = "community" === competition.deadlineSource;
  return (
    <span
      className={cn(
        "inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs",
        community ? "bg-muted text-muted-foreground" : "bg-muted font-medium text-foreground",
      )}
    >
      {community ? <Info className="size-3.5" aria-hidden /> : <CalendarCheck className="size-3.5" aria-hidden />}
      {frShortDate(effective)}
      {community ? <span className="text-muted-foreground">{TEXTS.proposedSuffix}</span> : null}
    </span>
  );
}
