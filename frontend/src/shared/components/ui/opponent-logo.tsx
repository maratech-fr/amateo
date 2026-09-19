import { useState } from "react";

import { cn } from "@/shared/lib/utils";

/**
 * C7 — le logo FÉDÉRAL d'un adversaire (re-hébergé, servi par `GET /api/opponents/{code}/logo`),
 * avec repli sur des initiales. Primitive partagée, GÉNÉRIQUE : le caller passe les `initials`
 * déjà calculées (le calcul métier vit dans `features/matches/lib/opponentInitials.ts`, hors du
 * partagé). Deux tailles : `sm` 16 px (dans une ligne dense), `md` 24 px.
 *
 * Repli sur les initiales UNIQUEMENT en `md` (une pastille lisible) ; en `sm`, sans image, on ne
 * rend RIEN (pas de mini-pastille illisible). `alt=""` + `aria-hidden` : le nom de l'adversaire
 * est déjà écrit à côté, le logo est décoratif. `onError` (404 : logo devenu introuvable) bascule
 * sur le repli.
 */
export function OpponentLogo({ code, hasLogo, initials, size = "sm", className }: { code: string | null; hasLogo: boolean; initials: string; size?: "sm" | "md"; className?: string }) {
  const [failed, setFailed] = useState(false);
  const px = "sm" === size ? 16 : 24;
  const showImage = hasLogo && null !== code && !failed;

  if (showImage) {
    return (
      <img
        src={`/api/opponents/${code}/logo`}
        width={px}
        height={px}
        loading="lazy"
        alt=""
        aria-hidden="true"
        className={cn("shrink-0 rounded-full border border-border object-cover", className)}
        onError={() => setFailed(true)}
      />
    );
  }

  // Repli initiales : md seulement. En sm sans image, rien (une pastille de 16 px serait illisible).
  if ("md" !== size) {
    return null;
  }

  return (
    <span
      aria-hidden="true"
      style={{ width: px, height: px }}
      className={cn("inline-flex shrink-0 items-center justify-center rounded-full border border-border bg-muted text-[10px] font-semibold text-muted-foreground", className)}
    >
      {initials}
    </span>
  );
}
