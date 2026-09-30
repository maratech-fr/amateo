import type { ReactNode } from "react";
import { createPortal } from "react-dom";
import { useLocation, useNavigation } from "react-router";

import { BrandSplash } from "@/shared/components/ui/brand-splash";
import { useMe } from "@/shared/session/queries";
import { useLoginSplashStore } from "@/shared/stores/loginSplashStore";

/**
 * ORCHESTRATEUR du splash de connexion (P4-252). Monté dans `RootShell` — donc PERSISTANT au
 * `navigate("/")` que le login déclenche : l'animation continue à travers le changement de route,
 * là où un splash rendu dans `LoginPage` aurait été démonté.
 *
 * Il enveloppe le contenu routé (patron `ActionVeil`) pour le rendre `inert` pendant que
 * l'overlay bloque, et pilote la machine à phases (`loginSplashStore`) au fil des signaux de
 * `BrandSplash` (fin d'intro / respiration posée / fin d'outro / fin de fondu d'annulation).
 *
 * L'overlay lui-même est un portal sur `document.body`, z-[70] — AU-DESSUS du voile d'action
 * (`ActionVeil`, z-[60]) : pendant la connexion, la signature couvre le voile générique.
 *
 * « PRÊT » (décision fondateur) = le login a abouti ET la query `me` est en succès ET la
 * navigation est posée (idle) ET on n'est plus sur /login — une adhésion en attente rendue sur
 * /waiting compte donc comme prête, elle aussi.
 */
export function isConnectionReady(p: { pathname: string; navIdle: boolean; meReady: boolean }): boolean {
  return p.meReady && p.navIdle && "/login" !== p.pathname;
}

export function LoginSplash({ children }: { children: ReactNode }) {
  const phase = useLoginSplashStore((s) => s.phase);
  const beginBreathing = useLoginSplashStore((s) => s.beginBreathing);
  const beginOutro = useLoginSplashStore((s) => s.beginOutro);
  const reset = useLoginSplashStore((s) => s.reset);

  const location = useLocation();
  const navIdle = "idle" === useNavigation().state;
  const me = useMe();
  const ready = isConnectionReady({ pathname: location.pathname, navIdle, meReady: me.isSuccess });

  const active = "idle" !== phase;
  // Bloque (inert) tant que la signature couvre l'écran ; PAS pendant l'annulation, où l'on rend
  // aussitôt la main au formulaire (le fondu de l'overlay n'est plus que visuel).
  const blocking = "intro" === phase || "breathing" === phase || "outro" === phase;

  return (
    <>
      <div style={{ display: "contents" }} inert={blocking || undefined}>
        {children}
      </div>
      {active
        ? createPortal(
            <BrandSplash
              phase={phase}
              ready={ready}
              onIntroComplete={() => (ready ? beginOutro() : beginBreathing())}
              onBreathingSettled={beginOutro}
              onOutroComplete={reset}
              onCancelComplete={reset}
            />,
            document.body,
          )
        : null}
    </>
  );
}
