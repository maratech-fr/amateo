import { type ReactNode, useEffect, useRef } from "react";
import { createPortal } from "react-dom";
import { useLocation, useNavigation } from "react-router";

import { BrandSplash } from "@/shared/components/ui/brand-splash";
import { useMe } from "@/shared/session/queries";
import { useLoginSplashStore } from "@/shared/stores/loginSplashStore";

import { isConnectionReady } from "./loginReady";

// Filet ULTIME : « prêt » ne doit jamais tarder indéfiniment. Si rien n'aboutit en 30 s, le splash
// s'efface en douceur — un logo ne bloque JAMAIS l'écran à vie (revue sécu).
const SPLASH_MAX_WAIT_MS = 30_000;

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
 * « PRÊT » = `isConnectionReady` (`./loginReady`, sorti du module du composant pour FRT-39).
 */
export function LoginSplash({ children }: { children: ReactNode }) {
  const phase = useLoginSplashStore((s) => s.phase);
  const beginBreathing = useLoginSplashStore((s) => s.beginBreathing);
  const beginOutro = useLoginSplashStore((s) => s.beginOutro);
  const cancel = useLoginSplashStore((s) => s.cancel);
  const reset = useLoginSplashStore((s) => s.reset);

  const location = useLocation();
  const navIdle = "idle" === useNavigation().state;
  const me = useMe();
  const ready = isConnectionReady({ pathname: location.pathname, navIdle, meReady: me.isSuccess });

  const active = "idle" !== phase;
  // Bloque (inert) tant que la signature couvre l'écran ; PAS pendant l'annulation, où l'on rend
  // aussitôt la main au formulaire (le fondu de l'overlay n'est plus que visuel).
  const blocking = "intro" === phase || "breathing" === phase || "outro" === phase;

  // ANTI-BLOCAGE (revue sécu) : tant que le splash ATTEND « prêt » (intro/breathing), trois sorties
  // douces l'empêchent de laisser l'écran `inert` à vie. `cancel()` est sûr : il n'agit que depuis
  // intro/breathing (no-op en outro, où l'app est déjà prête).
  const waiting = "intro" === phase || "breathing" === phase;

  // (1) la query `me` échoue APRÈS le démarrage (5xx / réseau, ou 401 qui a vidé la session) : on
  // n'atteindra jamais « prêt ». Même effacement doux qu'un refus d'identifiants.
  useEffect(() => {
    if (waiting && me.isError) {
      cancel();
    }
  }, [waiting, me.isError, cancel]);

  // (2) retour sur /login (ou /logout) APRÈS l'avoir quitté : login abouti puis rebond (cookie
  // rejeté d'emblée, session expirée…). Un ref mémorise qu'on a bien quitté l'entrée — sinon
  // l'intro, qui DÉMARRE sur /login, s'annulerait aussitôt.
  const leftEntry = useRef(false);
  useEffect(() => {
    if (!waiting) {
      leftEntry.current = false;
      return;
    }
    const onEntry = "/login" === location.pathname || "/logout" === location.pathname;
    if (!onEntry) {
      leftEntry.current = true;
    } else if (leftEntry.current) {
      cancel();
    }
  }, [waiting, location.pathname, cancel]);

  // (3) filet ultime : « prêt » n'arrive pas en 30 s (constante `SPLASH_MAX_WAIT_MS`). Le timer part
  // au début de l'attente et court sans se réarmer sur intro→breathing (`waiting` reste vrai).
  useEffect(() => {
    if (!waiting) {
      return;
    }
    const t = setTimeout(cancel, SPLASH_MAX_WAIT_MS);
    return () => clearTimeout(t);
  }, [waiting, cancel]);

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
