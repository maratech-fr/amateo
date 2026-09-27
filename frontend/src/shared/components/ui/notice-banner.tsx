import type { ReactNode } from "react";

import { cn } from "@/shared/lib/utils";

type NoticeTone = "warning" | "accent" | "destructive" | "muted";

/**
 * NoticeBanner — le BANDEAU d'information PARTAGÉ : « voici un fait qui décrit ou limite ce que tu
 * peux faire ici ». Maison unique du motif `rounded-md border … + fond`, recopié à l'identique dans
 * une douzaine de fichiers indépendants (P4-265) — remplace `WarningPanel` (P4-127 (b)), dont il
 * reprend la structure et étend la palette.
 *
 * Fond OPAQUE dédié par ton (P4-265) : `bg-surface-<ton>` (jetons `--surface-*` d'`index.css`), et
 * NON une teinte semi-transparente `bg-<ton>/NN` — elle laisse traverser le fond à motifs du body,
 * et deux background-color (`bg-card` + une teinte) ne se composent pas (Tailwind n'empile pas).
 * Le TEXTE est TOUJOURS `text-foreground` : `--surface-accent` suit la couleur du club, un
 * `text-accent`/`text-warning` dessus tomberait sous AA (contrastes gardés par `a11y-contrast.spec.ts`).
 *
 * Ce qui VARIE reste à l'appelant : l'icône (décorative, `aria-hidden` — le texte porte toujours le
 * sens, contrainte a11y « jamais la couleur ni l'icône seules ») et le contenu.
 *
 * ⚑ `message` et `children` sont SÉPARÉS pour une raison de correction, pas de goût : l'icône ne peut
 * décorer que la ligne de TEXTE, et mettre une action (un `<div>`) dans le même `<p>` produirait un
 * `<div>` dans un `<p>` — HTML invalide que le navigateur « répare » en cassant la mise en page.
 *
 * `role` est OPTIONNEL. Par défaut le bandeau décrit un état STABLE de l'écran (pas de région live) ;
 * un appelant dont le bandeau apparaît de façon asynchrone passe `role="status"` (poli) ou
 * `role="alert"` (assertif) pour l'annoncer — ou porte la région live sur son propre conteneur.
 */
const TONE: Record<NoticeTone, string> = {
  warning: "border-warning/60 bg-surface-warning",
  accent: "border-accent/40 bg-surface-accent",
  destructive: "border-destructive/50 bg-surface-destructive",
  muted: "border-border bg-surface-muted",
};

export function NoticeBanner({
  tone = "warning",
  icon,
  message,
  children,
  role,
  className,
}: {
  tone?: NoticeTone;
  icon?: ReactNode;
  message: ReactNode;
  children?: ReactNode;
  role?: "status" | "alert";
  className?: string;
}) {
  return (
    <div role={role} className={cn("space-y-2 rounded-md border px-3 py-2 text-sm text-foreground", TONE[tone], className)}>
      <p className={undefined === icon ? undefined : "flex items-start gap-2"}>
        {undefined === icon ? (
          message
        ) : (
          <>
            <span aria-hidden className="mt-0.5 shrink-0 leading-none">
              {icon}
            </span>
            <span>{message}</span>
          </>
        )}
      </p>
      {children}
    </div>
  );
}
