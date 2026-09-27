import type { ReactNode } from "react";

import { FeedbackButton } from "@/shared/feedback/FeedbackButton";
import { cn } from "@/shared/lib/utils";

interface PageHeaderProps {
  /** Titre de l'écran — rendu dans un `h1` de niveau 1, à trait d'accent. */
  title: ReactNode;
  /** Libellé d'écran joint au signalement (ex. "/planning", "/matchs/conflits"). */
  screen: string;
  /** Planning affiché par l'écran, s'il en tient un (joint au signalement). */
  scheduleId?: string | null;
  /** Contenu AVANT le titre, dans le même bloc (ex. blason du club). */
  leading?: ReactNode;
  /** Contenu APRÈS le titre, dans le même bloc (ex. pastille « principal », renommer, supprimer). */
  beside?: ReactNode;
  /** Actions de page (boutons), rendues à droite, AVANT le bouton « Signaler ». */
  actions?: ReactNode;
  /** Sous-titre optionnel, sous le titre (aligné sous le trait). */
  subtitle?: ReactNode;
  /**
   * Masque la porte « Signaler ». Défaut : visible. À poser à `false` seulement là où le
   * signalement n'a pas de sens — page publique consultée DÉCONNECTÉE (le POST /feedback exige
   * une session).
   */
  showFeedback?: boolean;
  className?: string;
}

/**
 * **La brique d'en-tête de page** (retour fondateur 2026-09-27) : partout le même geste — un trait
 * d'accent + le titre à GAUCHE (taille du Planning, `text-2xl`), et, tout à DROITE, les actions de
 * page éventuelles puis le bouton « Signaler » TOUJOURS visible, pour qu'une erreur se remonte
 * depuis n'importe quel écran. Avant, chaque page réinventait son titre (tailles disparates) et le
 * « Signaler » ne vivait que sur le Planning et le Calendrier des matchs.
 *
 * `border-accent` est la couleur du CLUB (le fondateur dit « rouge » car son accent club l'est) —
 * on ne fige jamais une teinte, on garde le jeton.
 *
 * Vit dans `shared/` : un en-tête partagé ne peut pas remonter vers une feature (AUD-FRT-21), donc
 * le `FeedbackButton` est descendu avec lui dans `shared/feedback/`.
 */
export function PageHeader({ title, screen, scheduleId, leading, beside, actions, subtitle, showFeedback = true, className }: PageHeaderProps) {
  return (
    <div className={cn("flex flex-wrap items-center gap-x-3 gap-y-2", className)}>
      {leading}
      <div className="min-w-0">
        <h1 className="border-l-[3px] border-accent pl-3 text-2xl font-semibold">{title}</h1>
        {null != subtitle ? <p className="mt-1 pl-3 text-sm text-muted-foreground">{subtitle}</p> : null}
      </div>
      {beside}
      {/* Le groupe de droite est poussé au bout par `ml-auto` — les actions restent AVANT « Signaler ». */}
      <div className="ml-auto flex shrink-0 items-center gap-2">
        {actions}
        {showFeedback ? <FeedbackButton screen={screen} scheduleId={scheduleId} /> : null}
      </div>
    </div>
  );
}
