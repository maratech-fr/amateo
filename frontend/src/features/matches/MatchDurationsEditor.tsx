import { Pencil, RotateCcw } from "lucide-react";
import { useId, useMemo, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Input } from "@/shared/components/ui/input";

import type { SportCategoryDuration } from "./api";
import { useUpdateSportCategoryDuration } from "./queries";

/**
 * P2-54 RMM-9 — l'éditeur « Durée des matchs » du SET-UP (`/matchs/configuration`),
 * frère d'« Échéances de saisie ». Le temps qu'un match occupe (échauffement compris)
 * devient un réglage PAR catégorie : il sert au radar à repérer quand un coach est pris
 * par un match.
 *
 * 🔴 Le front n'invente RIEN : le défaut de FAMILLE (placeholder, en-tête de groupe) est
 * SERVI par le backend (`defaultMatchMinutes`/`defaultWarmupMinutes`, résolus par
 * `MatchDurationResolver`). On groupe les catégories par ce défaut servi — jamais on ne
 * recalcule ni ne code en dur les 75/90/105 (`.claude/rules/frontend.md`).
 *
 * Une catégorie sans valeur propre HÉRITE (champ vide, placeholder = défaut, « défaut ») ;
 * une valeur saisie est AJUSTÉE (valeur pleine, badge crayon, « Ajusté »). Sauvegarde par
 * blur/Entrée (champ non contrôlé re-semé par `key`). « Revenir au défaut » renvoie NULL
 * sur les deux durées.
 */

const MATCH_MIN = 30;
const MATCH_MAX = 240;
const WARMUP_MIN = 0;
const WARMUP_MAX = 120;

type FieldKind = "match" | "warmup";

/** Un champ de durée : non contrôlé, re-semé par `key`, borné, erreur inline sans restauration. */
function DurationField({
  value,
  placeholder,
  min,
  max,
  ariaLabel,
  errorText,
  onCommit,
}: {
  value: number | null;
  placeholder: number;
  min: number;
  max: number;
  ariaLabel: string;
  errorText: string;
  onCommit: (minutes: number) => void;
}) {
  const [error, setError] = useState(false);
  const errorId = useId();
  // Valeur servie : re-sème le champ (via `key`) à chaque refetch, sans setState dans un effet.
  const served = null !== value ? String(value) : "";

  const commit = (input: HTMLInputElement): void => {
    const trimmed = input.value.trim();
    if ("" === trimmed) {
      // Champ vidé = restaure la valeur servie (le seul geste de reset est le bouton).
      input.value = served;
      setError(false);
      return;
    }
    const n = Number(trimmed);
    if (!Number.isInteger(n) || n < min || n > max) {
      // Hors bornes : on GARDE la saisie et on signale — jamais de restauration muette.
      setError(true);
      return;
    }
    setError(false);
    if (n === value) {
      return;
    }
    onCommit(n);
  };

  return (
    <div className="flex flex-col gap-0.5">
      <div className="flex items-center gap-1">
        <Input
          key={served}
          aria-label={ariaLabel}
          aria-invalid={error}
          aria-describedby={error ? errorId : undefined}
          inputMode="numeric"
          className="h-9 w-16 tabular-nums"
          placeholder={String(placeholder)}
          defaultValue={served}
          onBlur={(e) => commit(e.currentTarget)}
          onKeyDown={(e) => {
            if ("Enter" === e.key) {
              e.currentTarget.blur();
            }
          }}
        />
        <span className="text-xs text-muted-foreground">min</span>
        {null !== value ? (
          <span className="inline-flex items-center gap-1 text-xs font-medium text-accent">
            <Pencil className="size-3.5" aria-hidden="true" />
            Ajusté
          </span>
        ) : (
          <span className="text-xs italic text-muted-foreground">défaut</span>
        )}
      </div>
      {error ? (
        <p id={errorId} role="alert" className="text-xs text-destructive">
          {errorText}
        </p>
      ) : null}
    </div>
  );
}

function CategoryRow({ category }: { category: SportCategoryDuration }) {
  const update = useUpdateSportCategoryDuration();
  const atDefault = null === category.matchMinutes && null === category.warmupMinutes;

  const commit = (kind: FieldKind, minutes: number): void =>
    update.mutate({
      category,
      input: {
        matchMinutes: "match" === kind ? minutes : category.matchMinutes,
        warmupMinutes: "warmup" === kind ? minutes : category.warmupMinutes,
      },
    });

  const reset = (): void => update.mutate({ category, input: { matchMinutes: null, warmupMinutes: null } });

  return (
    <li className="flex flex-wrap items-start gap-x-4 gap-y-2 rounded-md border border-border px-3 py-2 text-sm">
      <span className="min-w-24 flex-1 self-center truncate font-medium">{category.name}</span>
      {/* Légende visuelle de colonne : le champ porte déjà son propre aria-label,
          d'où un <span> plutôt qu'un <label> (pas de double étiquetage). */}
      <div className="flex flex-col gap-0.5 text-xs text-muted-foreground">
        <span>Match (min)</span>
        <DurationField
          value={category.matchMinutes}
          placeholder={category.defaultMatchMinutes}
          min={MATCH_MIN}
          max={MATCH_MAX}
          ariaLabel={`Durée du match — ${category.name}`}
          errorText={`Entrez une durée de match entre ${MATCH_MIN} et ${MATCH_MAX} minutes.`}
          onCommit={(m) => commit("match", m)}
        />
      </div>
      <div className="flex flex-col gap-0.5 text-xs text-muted-foreground">
        <span>Échauffement (min)</span>
        <DurationField
          value={category.warmupMinutes}
          placeholder={category.defaultWarmupMinutes}
          min={WARMUP_MIN}
          max={WARMUP_MAX}
          ariaLabel={`Échauffement — ${category.name}`}
          errorText={`Entrez un échauffement entre ${WARMUP_MIN} et ${WARMUP_MAX} minutes.`}
          onCommit={(m) => commit("warmup", m)}
        />
      </div>
      <Button
        variant="ghost"
        size="sm"
        className="self-center text-muted-foreground"
        aria-label={`Revenir au défaut — ${category.name}`}
        disabled={atDefault || update.isPending}
        onClick={reset}
      >
        <RotateCcw className="size-4" aria-hidden="true" />
        Revenir au défaut
      </Button>
    </li>
  );
}

export function MatchDurationsEditor({ categories }: { categories: SportCategoryDuration[] }) {
  // Groupes par défaut de famille SERVI (jamais recalculé) : les catégories qui
  // partagent un même défaut forment un groupe, ordonné du plus court au plus long.
  const groups = useMemo(() => {
    const byDefault = new Map<string, { match: number; warmup: number; rows: SportCategoryDuration[] }>();
    for (const category of categories) {
      const key = `${category.defaultMatchMinutes}|${category.defaultWarmupMinutes}`;
      const group = byDefault.get(key) ?? { match: category.defaultMatchMinutes, warmup: category.defaultWarmupMinutes, rows: [] };
      group.rows.push(category);
      byDefault.set(key, group);
    }
    return [...byDefault.values()].sort((a, b) => a.match - b.match || a.warmup - b.warmup);
  }, [categories]);

  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-col gap-1">
        <h3 className="text-sm font-semibold">Durée des matchs</h3>
        <p className="text-xs text-muted-foreground">
          Le temps qu'un match occupe, échauffement compris — il sert à repérer quand un coach est pris par un match, trajet inclus.
        </p>
      </div>

      {0 === categories.length ? (
        <EmptyHint>Aucune catégorie — elles se définissent à l'étape Équipes du wizard.</EmptyHint>
      ) : (
        <div className="flex flex-col gap-4">
          {groups.map((group) => (
            <div key={`${group.match}|${group.warmup}`} className="flex flex-col gap-1.5">
              <p className="text-xs font-medium text-muted-foreground">
                Défaut : {group.match} min de match + {group.warmup} min d'échauffement
              </p>
              <ul className="flex flex-col gap-1.5">
                {group.rows.map((category) => (
                  <CategoryRow key={category.id} category={category} />
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
