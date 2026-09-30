import { useEffect, useRef, useState } from "react";

import { PRODUCT_NAME } from "@/shared/lib/product";

import type { LoginSplashPhase } from "@/shared/stores/loginSplashStore";

import {
  breatheOpacity,
  breathingSettleTime,
  clamp,
  computeFit,
  easeOutCubic,
  FULL_LAYOUT,
  ICON_BOX,
  introFrame,
  LOGO,
  LOGO_ARCS,
  outroFrame,
  prefersReducedMotion,
  type RenderFrame,
  SCENE_H,
  SCENE_W,
  splashWord,
  TEAL,
  TIMINGS,
} from "./brand-splash.math";

/**
 * Le logo « Signature » ANIMÉ du splash de connexion (P4-252) — icône (3 arcs) + le mot produit,
 * dessinés en SVG/HTML et animés en `requestAnimationFrame`. Les FORMULES vivent dans
 * `brand-splash.math.ts` (recopiées du handoff marque, `business/` gitignoré) ; on NE reprend PAS
 * le moteur de timeline du prototype.
 *
 * Le mot vient de la VARIABLE produit (`PRODUCT_NAME`), jamais d'un littéral ; « eo » = ses deux
 * dernières lettres, en teal, comme chez le graphiste. Couleurs des arcs et du « eo » EN DUR
 * (`#…`) — c'est le MARK de marque, exception documentée à « jamais un #hex »
 * (`.claude/rules/frontend.md`), au même titre que `BrandIcon`.
 */

// Défauts STABLES (référence constante entre rendus) — sinon la boucle rAF, dont l'effet dépend de
// ces fns, se relancerait à chaque rendu (remettant l'horloge à zéro à chaque frame).
const REAL_NOW = () => performance.now();
const REAL_RAF = (cb: (t: number) => void) => requestAnimationFrame(cb);
const REAL_CAF = (h: number) => cancelAnimationFrame(h);

export interface BrandSplashProps {
  /** Phase active (jamais `idle` : le composant n'est monté que lorsqu'il y a quelque chose à jouer). */
  phase: Exclude<LoginSplashPhase, "idle">;
  /** L'app est-elle prête (login résolu + `me` OK + navigation posée hors /login) ? */
  ready: boolean;
  onIntroComplete: () => void;
  onBreathingSettled: () => void;
  onOutroComplete: () => void;
  onCancelComplete: () => void;
  /** Injection pour tests déterministes (défaut : horloge et rAF réels). */
  now?: () => number;
  raf?: (cb: (t: number) => void) => number;
  caf?: (handle: number) => void;
}

export function BrandSplash({
  phase,
  ready,
  onIntroComplete,
  onBreathingSettled,
  onOutroComplete,
  onCancelComplete,
  now = REAL_NOW,
  raf = REAL_RAF,
  caf = REAL_CAF,
}: BrandSplashProps) {
  const [reduced] = useState(prefersReducedMotion);
  const [fit, setFit] = useState(computeFit);
  const [frame, setFrame] = useState<RenderFrame>(() =>
    "cancelling" === phase ? { layout: FULL_LAYOUT, logoOpacity: 1, overlayOpacity: 1 } : introFrame(0, reduced),
  );

  // Les valeurs qui NE doivent PAS relancer la boucle (sinon la respiration se remettrait à 0 à
  // chaque changement de `ready`) vivent en refs, rafraîchies APRÈS chaque rendu (jamais pendant).
  const readyRef = useRef(ready);
  const cbRef = useRef({ onIntroComplete, onBreathingSettled, onOutroComplete, onCancelComplete });
  useEffect(() => {
    readyRef.current = ready;
    cbRef.current = { onIntroComplete, onBreathingSettled, onOutroComplete, onCancelComplete };
  });

  useEffect(() => {
    const onResize = () => setFit(computeFit());
    window.addEventListener("resize", onResize);
    return () => window.removeEventListener("resize", onResize);
  }, []);

  // La boucle rAF : une par PHASE. Ne dépend QUE de la phase (et des fns injectées, stables) — pas
  // de `ready`, capturé en ref, pour ne pas réarmer l'horloge en cours de respiration.
  useEffect(() => {
    const startAt = now();
    let handle = 0;
    let fired = false;
    let readyElapsed: number | null = null;
    // Overlay figé au moment de l'annulation (le geste peut couper en pleine intro) : on part de
    // l'opacité 1 sur la géométrie courante et on la fond.
    const cancelLayout = frame.layout;
    const cancelLogoOpacity = frame.logoOpacity;

    const step = () => {
      const elapsed = now() - startAt;

      if ("intro" === phase) {
        const f = introFrame(elapsed, reduced);
        setFrame(f);
        if (f.done && !fired) {
          fired = true;
          cbRef.current.onIntroComplete();
          return; // l'orchestrateur bascule la phase → nouvelle boucle
        }
      } else if ("breathing" === phase) {
        const logoOpacity = reduced ? 1 : breatheOpacity(elapsed);
        setFrame({ layout: FULL_LAYOUT, logoOpacity, overlayOpacity: 1 });
        if (readyRef.current && null === readyElapsed) {
          readyElapsed = elapsed;
        }
        // Réduit : « disparaît dès prêt », pas de cycle à finir. Sinon : on attend la borne du cycle.
        const settleAt = reduced ? readyElapsed : null === readyElapsed ? null : breathingSettleTime(readyElapsed);
        if (null !== settleAt && elapsed >= settleAt && !fired) {
          fired = true;
          cbRef.current.onBreathingSettled();
          return;
        }
      } else if ("outro" === phase) {
        const f = outroFrame(elapsed, reduced);
        setFrame(f);
        if (f.done && !fired) {
          fired = true;
          cbRef.current.onOutroComplete();
          return;
        }
      } else {
        // cancelling — fondu de l'overlay depuis la géométrie figée.
        const t = clamp(elapsed / TIMINGS.cancelFadeMs, 0, 1);
        setFrame({ layout: cancelLayout, logoOpacity: cancelLogoOpacity, overlayOpacity: 1 - easeOutCubic(t) });
        if (elapsed >= TIMINGS.cancelFadeMs && !fired) {
          fired = true;
          cbRef.current.onCancelComplete();
          return;
        }
      }
      handle = raf(step);
    };
    handle = raf(step);
    return () => caf(handle);
    // `frame` volontairement HORS deps : lu une seule fois au démarrage de la phase (figeage cancel).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [phase, reduced, now, raf, caf]);

  const { layout, logoOpacity, overlayOpacity } = frame;

  return (
    <div
      data-testid="login-splash"
      role="status"
      aria-live="polite"
      aria-busy={"cancelling" !== phase}
      className="fixed inset-0 z-[70] flex items-center justify-center bg-background"
      style={{ opacity: overlayOpacity, pointerEvents: "cancelling" === phase ? "none" : "auto" }}
    >
      <span className="sr-only">Connexion en cours…</span>
      <div
        aria-hidden="true"
        style={{
          position: "relative",
          width: SCENE_W,
          height: SCENE_H,
          transform: `scale(${fit})`,
          transformOrigin: "center center",
          opacity: logoOpacity,
        }}
      >
        {/* Le mot SOUS l'icône (ordre de dessin du handoff) : il sort de derrière le trait teal. */}
        <div
          data-testid="splash-word"
          className="text-foreground"
          style={{
            position: "absolute",
            left: LOGO.wordLeft,
            top: LOGO.top,
            display: "flex",
            fontFamily: '"Poppins Signature", system-ui, sans-serif',
            fontWeight: 500,
            fontSize: LOGO.fontSize,
            lineHeight: 1,
            letterSpacing: "-0.01em",
            whiteSpace: "nowrap",
            transform: `translateX(${layout.wordDx}px)`,
            clipPath: `inset(-40px -40px -40px ${layout.clipLeft}px)`,
          }}
        >
          {splashWord(PRODUCT_NAME).map((letter, i) => (
            <span key={i} style={letter.teal ? { color: TEAL } : undefined}>
              {letter.ch}
            </span>
          ))}
        </div>
        {/* L'icône : 3 arcs pleins (aucune orbite), translatée + mise à l'échelle. */}
        <svg
          viewBox="0 0 1000 1000"
          width={ICON_BOX}
          height={ICON_BOX}
          style={{
            position: "absolute",
            left: layout.iconX - ICON_BOX / 2,
            top: SCENE_H / 2 - ICON_BOX / 2,
            overflow: "visible",
            transform: `scale(${layout.iconScale})`,
            transformOrigin: "50% 50%",
          }}
        >
          {LOGO_ARCS.map((a) => (
            <circle
              key={a.color}
              cx={500}
              cy={500}
              r={a.r}
              fill="none"
              stroke={a.color}
              strokeWidth={a.w}
              strokeLinecap="round"
              pathLength={360}
              strokeDasharray={`${a.len} 400`}
              transform={`rotate(${a.start} 500 500)`}
            />
          ))}
        </svg>
      </div>
    </div>
  );
}
