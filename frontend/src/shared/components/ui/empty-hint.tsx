import { CalendarX2, type LucideIcon } from "lucide-react";
import type { ReactNode } from "react";

import { Card, CardDescription, CardHeader, CardTitle } from "@/shared/components/ui/card";
import type { SurfaceSkin } from "@/shared/lib/surfaceSkin";
import { cn } from "@/shared/lib/utils";

/**
 * Deux PEAUX pour le vide, comme les onglets (`ui/tabs.tsx`, `TAB_SKINS`). `app` suit les
 * jetons du thème club ; `console` parle les jetons `--console-*` de la surface superadmin
 * (P4-151), sinon rallier ces écrans les décolorerait. La forme des couleurs (jamais la
 * structure) diffère par peau ; le squelette partagé reste dans la classe de base.
 */
const EMPTY_HINT_SKINS: Record<SurfaceSkin, string> = {
  app: "text-muted-foreground",
  console: "text-console-muted",
};

const EMPTY_BLOCK_SKINS: Record<SurfaceSkin, string> = {
  app: "rounded-lg border-border bg-card p-8 text-muted-foreground",
  console: "rounded-xl border-white/15 px-6 py-12 text-console-muted",
};

/**
 * Inline empty-list message ("Aucun…") — the small muted paragraph re-invented
 * across ~14 screens. One home so the empty state reads the same everywhere.
 * `variant="console"` for the superadmin console; `app` (theme) by default.
 */
export function EmptyHint({ children, className, variant = "app", role }: { children: ReactNode; className?: string; variant?: SurfaceSkin; role?: string }) {
  return (
    <p role={role} className={cn("text-sm", EMPTY_HINT_SKINS[variant], className)}>
      {children}
    </p>
  );
}

/**
 * Dashed-card empty block for a grid/panel with nothing to show yet (the timetable
 * grids re-implemented the exact same markup inline). Sits between the inline
 * `EmptyHint` and PlanningPage's full-view `EmptyState` Card.
 * `variant="console"` for the superadmin console; `app` (theme) by default.
 */
export function EmptyBlock({ children, className, variant = "app" }: { children: ReactNode; className?: string; variant?: SurfaceSkin }) {
  return <div className={cn("border border-dashed text-center text-sm", EMPTY_BLOCK_SKINS[variant], className)}>{children}</div>;
}

/**
 * The big Card-style empty for a WHOLE view ("Aucun planning", "Génération en
 * échec") — third tier above `EmptyHint` (inline) and `EmptyBlock` (grid/panel).
 *
 * UXC-17 (P4-117) : il vivait en local dans `PlanningPage` — la note qui disait
 * « il y reste, intent différent » confondait l'intent (vrai : une vue entière
 * vide ≠ une liste vide) et la MAISON : un intent différent mérite sa primitive,
 * pas un composant privé qu'un futur écran vide ré-inventera. `icon` par défaut
 * `CalendarX2` parce que tous les consommateurs du jour sont des vues calendaires ;
 * un écran d'une autre nature passe la sienne.
 */
export function EmptyState({ icon: Icon = CalendarX2, title, description }: { icon?: LucideIcon; title: string; description: string }) {
  return (
    <Card className="border-dashed">
      <CardHeader>
        <div className="flex items-center gap-2">
          <Icon className="size-5 text-muted-foreground" />
          <CardTitle>{title}</CardTitle>
        </div>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
    </Card>
  );
}
