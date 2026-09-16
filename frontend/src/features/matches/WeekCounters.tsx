import { AlertTriangle, ArrowRight } from "lucide-react";
import { Link } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import type { WeekCounts } from "./lib/loopSteps";

interface WeekCountersProps extends WeekCounts {
  /** « à placer » : ramène la liste « À placer » dans le champ et lui donne le focus. */
  onScrollToPlace: () => void;
  /** « à saisir dans FBI » : ouvre la modale « À recopier dans FBI ». */
  onOpenFbi: () => void;
}

/**
 * PR 3b — la barre « Semaine affichée » : trois compteurs de la semaine (à placer ·
 * conflits · à saisir dans FBI), remplaçant le rail supprimé. Un `role="group"` nommé,
 * trois boutons `ghost` ; le chiffre en `tabular-nums`, la teinte en sourdine quand
 * c'est zéro (le bouton reste actif). Un seul porte une flèche : « conflits », qui
 * CHANGE d'onglet (`<Link to="/matchs/conflits">`) — cohérent avec le badge de nav.
 * « à placer » défile jusqu'à la liste, « FBI » ouvre une modale (`aria-haspopup`).
 * PRÉSENTATION pure : les comptes viennent de `deriveWeekCounters` (mêmes formules
 * que le rail), aucun verdict recalculé ici.
 */
export function WeekCounters({ unplaced, conflicts, fbiToEnter, onScrollToPlace, onOpenFbi }: WeekCountersProps) {
  const numberClass = (value: number): string => cn("tabular-nums font-semibold", 0 === value ? "text-muted-foreground" : "text-foreground");
  const labelClass = (value: number): string | undefined => (0 === value ? "text-muted-foreground" : undefined);
  return (
    <div role="group" aria-label="Semaine affichée" className="flex flex-wrap items-center gap-1 border-b border-border pb-2">
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
      <Button type="button" variant="ghost" size="sm" aria-haspopup="dialog" onClick={onOpenFbi}>
        <span className={numberClass(fbiToEnter)}>{fbiToEnter}</span>{" "}
        <span className={labelClass(fbiToEnter)}>à saisir dans FBI</span>
        <span className="sr-only">, ouvrir la liste</span>
      </Button>
    </div>
  );
}
