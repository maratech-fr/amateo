import type { ReactNode } from "react";

import { cn } from "@/shared/lib/utils";

type NoticeTone = "warning" | "accent" | "destructive" | "muted";
type NoticeRole = "status" | "alert" | "region" | "note";

/**
 * NoticeBanner — le BANDEAU d'information PARTAGÉ : « voici un fait qui décrit ou limite ce que tu
 * peux faire ici ». Maison unique du motif `rounded-md border … + fond`, recopié à l'identique dans
 * une douzaine de fichiers indépendants (P4-265) — remplace `WarningPanel` (P4-127 (b)), dont il
 * reprend la structure et étend la palette. La série « uniformité des écrans » (PR 4/7, 2026-10-01)
 * y a ramené les ~28 derniers bandeaux faits main : UNE seule boîte (bordure/fond/rayon/padding) pour
 * tous les encarts d'information de l'app — gardé par `src/test/bannerPrimitiveGuard.test.ts`.
 *
 * Fond OPAQUE dédié par ton (P4-265) : `bg-surface-<ton>` (jetons `--surface-*` d'`index.css`), et
 * NON une teinte semi-transparente `bg-<ton>/NN` — elle laisse traverser le fond à motifs du body,
 * et deux background-color (`bg-card` + une teinte) ne se composent pas (Tailwind n'empile pas).
 * Le TEXTE de la racine est TOUJOURS `text-foreground` : `--surface-accent` suit la couleur du club, un
 * `text-accent`/`text-warning` dessus tomberait sous AA (contrastes gardés par `a11y-contrast.spec.ts`).
 *
 * Ce qui VARIE reste à l'appelant : l'icône (décorative, `aria-hidden` — le texte porte toujours le
 * sens, contrainte a11y « jamais la couleur ni l'icône seules ») et le contenu.
 *
 * ⚑ `message` et `children` sont SÉPARÉS pour une raison de correction, pas de goût : l'icône ne peut
 * décorer que la ligne de TEXTE, et mettre une action (un `<div>`) dans le même `<p>` produirait un
 * `<div>` dans un `<p>` — HTML invalide que le navigateur « répare » en cassant la mise en page.
 * `message` est OPTIONNEL : un bandeau dont le contenu est une LISTE, plusieurs lignes à icône
 * propre, ou une composition riche (boutons, sous-listes) passe tout par `children` — la primitive
 * ne fournit alors que la BOÎTE, et c'est `children` qui porte sa mise en page (p.ex. une rangée
 * `flex`). Sans `message`, aucun `<p>` n'est rendu.
 *
 * `role` est OPTIONNEL. Par défaut le bandeau décrit un état STABLE de l'écran (pas de région live) ;
 * un appelant dont le bandeau apparaît de façon asynchrone passe `role="status"` (poli) ou
 * `role="alert"` (assertif) pour l'annoncer. `role="region"`/`"note"` (+ `ariaLabel`) servent un
 * bandeau-repère permanent (ex. « Séances à replacer ») dont on veut conserver le landmark d'origine.
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
  ariaLabel,
  className,
}: {
  tone?: NoticeTone;
  icon?: ReactNode;
  message?: ReactNode;
  children?: ReactNode;
  role?: NoticeRole;
  ariaLabel?: string;
  className?: string;
}) {
  return (
    <div role={role} aria-label={ariaLabel} className={cn("space-y-2 rounded-md border px-3 py-2 text-sm text-foreground", TONE[tone], className)}>
      {undefined === message ? null : (
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
      )}
      {children}
    </div>
  );
}
