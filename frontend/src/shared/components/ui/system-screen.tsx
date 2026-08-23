import { CalendarCheck2 } from "lucide-react";
import { type ReactNode, useEffect, useId, useRef } from "react";

import { DevIncidentDetails } from "@/shared/components/ui/dev-incident-details";
import { PRODUCT_NAME } from "@/shared/lib/product";
import { SystemScene } from "@/shared/components/ui/system-scene";

/**
 * P5-14 — la primitive PRÉSENTATIONNELLE des écrans système (404, 403, 500,
 * hors-ligne pleine page). Elle porte la FORME seulement : en-tête/pied avec le
 * nom produit, un titre, un corps, UN geste principal + un secondaire, une ligne
 * « Code incident » discrète si fournie.
 *
 * ⚠ Ce qui la définit par la NÉGATIVE : aucune prop `variant`, aucun `switch` sur
 * un type d'écran. Chaque écran est un CONSOMMATEUR qui apporte sa copie et ses
 * gestes — c'est ce qui tient « un seul composant d'état » sans fabriquer un
 * fourre-tout.
 *
 * ⚠ Contrainte dure : elle rend SANS aucun provider — elle sert sous l'ErrorBoundary
 * React, monté HORS providers. Donc pas de `useQuery`, pas de router, pas de
 * `FeedbackDialog` à l'intérieur. Le nom produit vient de la VARIABLE `PRODUCT_NAME`
 * (jamais un littéral — garde `product.guard.test.ts`).
 *
 * A11y (passe de design P5-14) : un écran système est une NAVIGATION qui remplace la
 * page, pas un toast — on déplace donc le focus sur le titre (`h1` focusable), sans
 * région live (qui sur-annoncerait). Le titre est l'unique `h1` ; le nom produit
 * n'est pas un titre. Ordre des gestes : principal d'abord (DOM = tabulation = lecture).
 */
export interface SystemScreenAction {
  label: string;
  onClick: () => void;
}

export interface SystemScreenProps {
  title: string;
  children: ReactNode;
  primaryAction: SystemScreenAction;
  secondaryAction?: SystemScreenAction;
  incidentId?: string | null;
}

const PRIMARY_CLASSES =
  "inline-flex min-h-11 items-center justify-center rounded-md border border-border bg-accent px-4 py-2.5 text-sm font-medium text-accent-foreground hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background";
const SECONDARY_CLASSES =
  "inline-flex min-h-11 items-center justify-center rounded-md border border-border bg-transparent px-4 py-2.5 text-sm font-medium text-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background";

export function SystemScreen({ title, children, primaryAction, secondaryAction, incidentId }: SystemScreenProps): ReactNode {
  const titleId = useId();
  const headingRef = useRef<HTMLHeadingElement>(null);

  // Focus le titre au montage : titre → corps → boutons dans l'ordre naturel,
  // JAMAIS un bouton (une frappe Entrée déclencherait « Réessayer »/« Retour »).
  // Effet DOM pur — légal sous la contrainte « aucun provider ».
  useEffect(() => {
    headingRef.current?.focus();
  }, []);

  return (
    <section aria-labelledby={titleId} className="flex min-h-[70vh] flex-col items-center justify-center gap-6 bg-background px-4 py-10 text-center text-foreground">
      <span className="flex items-center gap-2 text-sm font-semibold text-muted-foreground">
        <CalendarCheck2 className="size-5 text-accent" aria-hidden />
        {PRODUCT_NAME}
      </span>

      {/* Scène commune à TOUS les écrans système (P5-22) : un demi-terrain où des cartes
          flottent en apesanteur. Décor (aria-hidden), rendu par DÉFAUT — c'est de la forme
          partagée, pas de la copie par écran (aucune prop, aucun switch, cf. SystemScene). */}
      <SystemScene />

      <div className="flex max-w-md flex-col gap-3">
        <h1 id={titleId} ref={headingRef} tabIndex={-1} className="text-xl font-semibold outline-none">
          {title}
        </h1>
        {/* Corps en `text-foreground` (pas `muted`) : c'est la réassurance, le cœur de
            l'écran — le muted-sur-fond est le raté de contraste classique. */}
        <div className="text-sm text-foreground">{children}</div>
      </div>

      <div className="flex w-full max-w-md flex-col gap-2 sm:w-auto sm:flex-row sm:justify-center">
        <button type="button" onClick={primaryAction.onClick} className={PRIMARY_CLASSES}>
          {primaryAction.label}
        </button>
        {secondaryAction ? (
          <button type="button" onClick={secondaryAction.onClick} className={SECONDARY_CLASSES}>
            {secondaryAction.label}
          </button>
        ) : null}
      </div>

      <footer className="mt-2 flex flex-col items-center gap-1">
        {incidentId ? (
          // Code de corrélation (X-Request-Id), pas un détail interne : ni stack, ni
          // exception, ni SQL. Discret, jamais un titre, jamais annoncé d'office.
          // `select-all` pour copier le code d'un geste ; texte visible suffisant
          // (pas d'aria-label sur un <p>, qu'axe refuse — aria-prohibited-attr).
          <p className="select-all text-xs tabular-nums text-muted-foreground">Code incident : {incidentId}</p>
        ) : null}
        {/* P4-129 — détails techniques repliables, DEV uniquement. Dans le pied, donc hors
            du focus posé sur le h1 au montage ; le composant ne vole jamais le focus. */}
        <DevIncidentDetails />
        <span className="text-xs text-muted-foreground">{PRODUCT_NAME}</span>
      </footer>
    </section>
  );
}
