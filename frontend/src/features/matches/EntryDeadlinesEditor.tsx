import { CalendarCheck, Eraser, Info, Users } from "lucide-react";
import { useMemo, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { errorMessage } from "@/shared/lib/errorMessage";
import type { TeamLike } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";

import type { Competition } from "./api";
import { frShortDate } from "./lib/deadlineLabel";
import { useSetEntryDeadlines } from "./queries";

/**
 * RMM-6 PR-2 — l'éditeur « Échéances de saisie » du SET-UP (`/matchs/configuration`),
 * frère de `MatchWindowsEditor`/`MatchSlotRotationsEditor`. La ligue/le comité fixe une
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
 */
export function EntryDeadlinesEditor<T extends TeamLike>({ competitions, teams }: { competitions: Competition[]; teams: T[] }) {
  const setDeadlines = useSetEntryDeadlines();
  const [checked, setChecked] = useState<Set<string>>(new Set());
  const [date, setDate] = useState("");
  const [formError, setFormError] = useState<string | null>(null);

  const teamName = useMemo(() => {
    const map = new Map(teams.map((t) => [t.id, t.name]));
    return (id: string): string => map.get(id) ?? "Équipe ?";
  }, [teams]);

  const rows = useMemo(
    () => [...competitions].sort((a, b) => teamName(a.teamId).localeCompare(teamName(b.teamId)) || a.name.localeCompare(b.name)),
    [competitions, teamName],
  );

  const allChecked = rows.length > 0 && rows.every((c) => checked.has(c.id));

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

  const toggleAll = (): void => setChecked(allChecked ? new Set() : new Set(rows.map((c) => c.id)));

  const apply = (deadline: string | null): void => {
    if (0 === checked.size || setDeadlines.isPending) {
      return;
    }
    setFormError(null);
    setDeadlines.mutate(
      { competitionIds: [...checked], deadline },
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
        <EmptyHint>Aucune compétition — importez le calendrier FBI ou appariez vos engagements FFBB pour saisir des échéances.</EmptyHint>
      </section>
    );
  }

  return (
    <section className="flex flex-col gap-3">
      <Header />

      {/* La barre de saisie groupée : une date, appliquée aux lignes cochées. */}
      <div className="flex flex-wrap items-end gap-2 rounded-md border border-dashed border-border px-3 py-2">
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Échéance
          <input
            type="date"
            aria-label="Échéance à appliquer"
            className="h-9 rounded-md border border-border bg-background px-2 text-sm"
            value={date}
            onChange={(e) => setDate(e.target.value)}
          />
        </label>
        <Button size="sm" disabled={0 === checked.size || "" === date || setDeadlines.isPending} onClick={() => apply(date)}>
          Appliquer{checked.size > 0 ? ` (${checked.size})` : ""}
        </Button>
        <Button variant="outline" size="sm" disabled={0 === checked.size || setDeadlines.isPending} onClick={() => apply(null)}>
          <Eraser className="size-4" />
          Effacer l'échéance
        </Button>
        <span className="ml-auto text-xs text-muted-foreground">
          {checked.size > 0 ? `${checked.size} cochée${checked.size > 1 ? "s" : ""}` : "Cochez les compétitions à régler"}
        </span>
      </div>

      <div className="flex items-center gap-2">
        <Button variant="ghost" size="sm" className="text-muted-foreground" onClick={toggleAll}>
          {allChecked ? "Tout décocher" : "Tout cocher"}
        </Button>
      </div>

      <ul className="flex flex-col gap-1.5">
        {rows.map((c) => (
          <li key={c.id} className="flex items-center gap-3 rounded-md border border-border px-3 py-2 text-sm">
            <label className="flex min-w-0 flex-1 items-center gap-3">
              <input
                type="checkbox"
                aria-label={`${c.name} — ${teamName(c.teamId)}`}
                className="size-4 shrink-0"
                checked={checked.has(c.id)}
                onChange={() => toggle(c.id)}
              />
              <span className="min-w-0">
                <span className="block truncate font-medium">{c.name}</span>
                <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                  <span>{teamName(c.teamId)}</span>
                  {null !== (c.ffbbCompetitionId ?? null) ? (
                    <span className="inline-flex items-center gap-1 rounded-full bg-accent/10 px-1.5 py-0.5 text-accent-foreground">
                      <Users className="size-3" aria-hidden />
                      partagée avec les autres clubs
                    </span>
                  ) : null}
                </span>
              </span>
            </label>
            <Provenance competition={c} />
          </li>
        ))}
      </ul>

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
      <h3 className="text-sm font-semibold">Échéances de saisie</h3>
      <p className="text-xs text-muted-foreground">
        La date limite pour saisir vos rencontres dans FBI, fixée par la ligue ou le comité — une par compétition. Cochez les
        compétitions concernées, posez la date, appliquez. Une date proposée vient d'un autre club : elle est modifiable.
      </p>
    </div>
  );
}

/** La provenance d'une échéance : valeur club (date pleine) · proposée · aucune. */
function Provenance({ competition }: { competition: Competition }) {
  const effective = competition.effectiveEntryDeadline ?? null;
  if (null === effective) {
    return <span className="shrink-0 text-xs italic text-muted-foreground">aucune échéance</span>;
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
      {community ? <span className="text-muted-foreground"> · proposée</span> : null}
    </span>
  );
}
