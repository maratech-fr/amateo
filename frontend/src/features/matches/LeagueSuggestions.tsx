import { Button } from "@/shared/components/ui/button";
import { dayLabelLong } from "@/shared/lib/days";

import type { LeagueWindowSuggestionCombination, LeagueWindowSuggestionItem } from "./api";
import { useApplyLeagueWindowSuggestions, useLeagueWindowSuggestions } from "./queries";

/**
 * P4-272 ② — le bloc « Plages suggérées (estimation) » de la section Ligue : la
 * tendance dominante des plages de match de l'instance fédérale du club (ce que font
 * les autres clubs de son comité / sa ligue) plus un repli sur le catalogue fédéral.
 *
 * Le backend est la SEULE maison du calcul (seuil, majorité, masquage des lignes déjà
 * identiques à la copie) — l'écran AFFICHE ce qu'il sert, il ne redérive rien
 * (🔴 .claude/rules/frontend.md). N est affiché, jamais QUELS clubs. « Appliquer »
 * (par ligne) et « Tout appliquer » RECALCULENT côté serveur ; on n'envoie jamais de
 * plages. Rien ne s'affiche si l'instance est illisible ou sans item.
 */
const SCOPE_LABEL: Record<LeagueWindowSuggestionItem["scope"], string> = {
  comite: "comité",
  ligue: "ligue",
  federation: "fédération",
};

const GENDER_LABEL: Record<string, string> = { M: "Masculin", F: "Féminin", MIXTE: "Mixte" };

function toCombination(item: LeagueWindowSuggestionItem): LeagueWindowSuggestionCombination {
  return { category: item.category, level: item.level, gender: item.gender, dayOfWeek: item.dayOfWeek };
}

function genderLabel(gender: string | null): string {
  return null === gender ? "Tous" : (GENDER_LABEL[gender] ?? gender);
}

function windowsLabel(windows: LeagueWindowSuggestionItem["windows"]): string {
  return windows.map((w) => `${w.kickoffMin}–${w.kickoffMax}`).join(", ");
}

/** La note de provenance : un compte de clubs (jamais un « qui »), ou l'étiquette fédérale. */
function sourceNote(item: LeagueWindowSuggestionItem): string {
  if ("federation" === item.source) {
    return "données fédérales";
  }
  return `Estimation à partir de ${item.clubCount} club${1 === item.clubCount ? "" : "s"} de votre ${SCOPE_LABEL[item.scope]} — à vérifier auprès de votre ligue et de votre comité`;
}

export function LeagueSuggestions() {
  const suggestions = useLeagueWindowSuggestions();
  const apply = useApplyLeagueWindowSuggestions();

  const data = suggestions.data;
  // Aide facultative : silencieuse tant qu'il n'y a rien à proposer (instance illisible,
  // aucun item, ou lecture en cours/échouée — la copie éditable reste, elle, servie).
  if (undefined === data || null === data.instance || 0 === data.items.length) {
    return null;
  }

  return (
    <section className="flex flex-col gap-2 rounded-md border border-accent/40 bg-surface-accent px-3 py-3" aria-label="Plages suggérées">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-medium text-foreground">Plages suggérées (estimation)</h3>
        <Button
          size="sm"
          variant="outline"
          disabled={apply.isPending}
          onClick={() => apply.mutate(data.items.map(toCombination))}
        >
          Tout appliquer
        </Button>
      </div>

      <div className="flex flex-col gap-2">
        {data.items.map((item) => (
          <div key={`${item.category}|${item.level}|${item.gender ?? ""}|${item.dayOfWeek}`} className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-card px-3 py-2">
            <div className="flex min-w-48 flex-col gap-0.5">
              <span className="text-sm text-foreground">
                {item.category} · {genderLabel(item.gender)} · {dayLabelLong(item.dayOfWeek)} {windowsLabel(item.windows)}
              </span>
              <span className="text-xs text-muted-foreground">{sourceNote(item)}</span>
            </div>
            <div className="ml-auto flex items-center gap-2">
              <Button size="sm" variant="outline" disabled={apply.isPending} onClick={() => apply.mutate([toCombination(item)])}>
                Appliquer
              </Button>
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}
