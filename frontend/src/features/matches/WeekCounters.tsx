import { AlertTriangle, ArrowRight } from "lucide-react";
import { Link } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import type { WeekCounts } from "./lib/loopSteps";

interface WeekCountersProps extends WeekCounts {
  /** « FBI à faire » GLOBAL (toutes semaines) : domiciles PLACED à saisir + entrées à corriger. */
  fbiTodo: number;
  /** « à placer » : ramène la liste « À placer » dans le champ et lui donne le focus. */
  onScrollToPlace: () => void;
  /** « FBI à faire » : ouvre la liste « FBI — à faire ». */
  onOpenFbi: () => void;
}

/**
 * PR 3b — la barre au-dessus de la grille. Deux compteurs BORNÉS à la semaine affichée
 * (à placer · conflits) vivent dans un `role="group"` nommé « Semaine affichée » ; le
 * troisième — « FBI à faire » — est GLOBAL (toutes semaines) et vit DEHORS du groupe,
 * en frère `ml-auto`, pour ne pas laisser croire qu'il compte la seule semaine. Le
 * chiffre en `tabular-nums`, la teinte en sourdine à zéro (le bouton reste actif).
 * PRÉSENTATION pure : `à placer`/`conflits` viennent de `deriveWeekCounters`, le FBI
 * du `fbiTodo` servi par le backend — aucun verdict recalculé ici.
 */
export function WeekCounters({ unplaced, conflicts, fbiTodo, onScrollToPlace, onOpenFbi }: WeekCountersProps) {
  const numberClass = (value: number): string => cn("tabular-nums font-semibold", 0 === value ? "text-muted-foreground" : "text-foreground");
  const labelClass = (value: number): string | undefined => (0 === value ? "text-muted-foreground" : undefined);
  return (
    <div className="flex flex-wrap items-center gap-1 border-b border-border pb-2">
      <div role="group" aria-label="Semaine affichée" className="flex flex-wrap items-center gap-1">
        <Button type="button" variant="ghost" size="sm" onClick={onScrollToPlace}>
          <span className={numberClass(unplaced)}>{unplaced}</span>{" "}
          <span className={labelClass(unplaced)}>à placer</span>
          <span className="sr-only">, voir la liste</span>
        </Button>
        <Button variant="ghost" size="sm" asChild>
          <Link to="/matchs/conflits">
            {conflicts > 0 ? <AlertTriangle className="size-3.5 text-warning" aria-hidden="true" /> : null}
            <span className={numberClass(conflicts)}>{conflicts}</span>{" "}
            <span className={labelClass(conflicts)}>conflits</span>
            <span className="sr-only">, ouvrir l'onglet Conflits</span>
            <ArrowRight className="size-3.5" aria-hidden="true" />
          </Link>
        </Button>
      </div>
      <Button type="button" variant="ghost" size="sm" className="ml-auto" aria-haspopup="dialog" onClick={onOpenFbi}>
        <span className={numberClass(fbiTodo)}>{fbiTodo}</span>{" "}
        <span className={labelClass(fbiTodo)}>FBI à faire</span>
        <span className="sr-only">, toutes semaines, ouvrir la liste</span>
      </Button>
    </div>
  );
}
