import React from "react";
import { AbsoluteFill, Img, interpolate, useCurrentFrame } from "remotion";
import { COLORS } from "../brand";

// Boite-outils des plans de CAPTURE : une capture fixe (PNG 3840x2160 = 2x le viewport 1920x1080)
// posee plein cadre, avec un seul mouvement de camera amorti (zoom/pan « Ken Burns », regle 8) et
// des encadres teal d'annotation (regle 9 : caler sur les boites englobantes des elements).
//
// Reperes : la capture represente un viewport 1920x1080, donc une coordonnee CSS (x,y,w,h) de
// l'element se lit DIRECTEMENT en coordonnees de composition (1920x1080). On rend l'image a 1920x1080
// et on pose les annotations dans le MEME groupe transforme, pour qu'elles suivent le zoom.

export interface Rect {
  readonly x: number;
  readonly y: number;
  readonly w: number;
  readonly h: number;
}

/** easeInOutCubic — mouvement doux, jamais de rebond sur l'interface (regle 8). */
const easeInOut = (t: number): number => (t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2);

const COMP_W = 1920;
const COMP_H = 1080;

/** Transformation qui amene le centre d'un rect focal au centre de l'image, a l'echelle voulue. */
export function focalTransform(focal: Rect | null, targetScale: number, p: number): string {
  if (!focal) return "none";
  const s = 1 + (targetScale - 1) * p;
  const fcx = focal.x + focal.w / 2;
  const fcy = focal.y + focal.h / 2;
  const cx = COMP_W / 2 + (fcx - COMP_W / 2) * p;
  const cy = COMP_H / 2 + (fcy - COMP_H / 2) * p;
  const tx = COMP_W / 2 - cx * s;
  const ty = COMP_H / 2 - cy * s;
  return `translate(${tx}px, ${ty}px) scale(${s})`;
}

/** Echelle pour qu'un rect focal remplisse ~`fill` de la hauteur/largeur du cadre. */
export function scaleToFit(focal: Rect, fill = 0.82): number {
  return Math.min((COMP_W * fill) / focal.w, (COMP_H * fill) / focal.h, 2.4);
}

export interface Annotation extends Rect {
  /** fraction du plan (0..1) ou l'encadre apparait */
  readonly appearAt?: number;
  /** icone d'oeil dans un coin (annotation VIDEO, distincte de l'UI — plan 10) */
  readonly eye?: boolean;
  /** rayon des coins */
  readonly radius?: number;
}

const EyeBadge: React.FC = () => (
  <div
    style={{
      position: "absolute",
      top: -34,
      left: -4,
      width: 52,
      height: 52,
      borderRadius: 999,
      background: COLORS.tealInk,
      display: "flex",
      alignItems: "center",
      justifyContent: "center",
      boxShadow: "0 8px 20px -8px rgba(25,23,20,0.55)",
    }}
  >
    <svg width={30} height={30} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path d="M1.5 12S5 5 12 5s10.5 7 10.5 7-3.5 7-10.5 7S1.5 12 1.5 12Z" stroke="#fff" strokeWidth={2} strokeLinejoin="round" />
      <circle cx={12} cy={12} r={3.2} fill="#fff" />
    </svg>
  </div>
);

const AnnotationBox: React.FC<{ a: Annotation; localFrame: number; durationInFrames: number }> = ({ a, localFrame, durationInFrames }) => {
  const appear = (a.appearAt ?? 0.15) * durationInFrames;
  const p = interpolate(localFrame, [appear, appear + 10], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  const pulse = 0.5 + 0.5 * Math.sin((localFrame / 30) * Math.PI); // respiration lente du liseré
  return (
    <div
      style={{
        position: "absolute",
        left: a.x,
        top: a.y,
        width: a.w,
        height: a.h,
        borderRadius: a.radius ?? 16,
        border: `4px solid ${COLORS.teal}`,
        boxShadow: `0 0 0 4px ${COLORS.teal}22, 0 10px 30px -10px ${COLORS.tealInk}aa`,
        opacity: p,
        transform: `scale(${0.96 + 0.04 * p})`,
        transformOrigin: "center",
        background: `${COLORS.teal}${Math.round(10 + 8 * pulse).toString(16).padStart(2, "0")}`,
      }}
    >
      {a.eye ? <EyeBadge /> : null}
    </div>
  );
};

export interface StillProps {
  readonly src: string;
  /** rect focal du zoom (coords composition) ; absent = pas de zoom */
  readonly focal?: Rect | null;
  /** echelle finale du zoom (defaut derivee du focal) */
  readonly targetScale?: number;
  /** encadres d'annotation (coords composition) */
  readonly annotations?: readonly Annotation[];
  /** debut du zoom en fraction du plan (defaut 0) et fin (defaut 1) */
  readonly zoomFrom?: number;
  readonly zoomTo?: number;
}

/** Une capture fixe plein cadre, un zoom amorti, des encadres teal. `durationInFrames` = duree du plan. */
export const Still: React.FC<StillProps & { durationInFrames: number }> = ({ src, focal = null, targetScale, annotations = [], zoomFrom = 0, zoomTo = 1, durationInFrames }) => {
  const frame = useCurrentFrame();
  const scale = targetScale ?? (focal ? scaleToFit(focal) : 1.08);
  const rawP = interpolate(frame, [zoomFrom * durationInFrames, zoomTo * durationInFrames], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  const p = easeInOut(rawP);
  const transform = focal ? focalTransform(focal, scale, p) : `scale(${1 + (scale - 1) * p})`;
  return (
    <AbsoluteFill style={{ backgroundColor: COLORS.paper, overflow: "hidden" }}>
      <div style={{ position: "absolute", width: COMP_W, height: COMP_H, transform, transformOrigin: "0 0" }}>
        <Img src={src} style={{ width: COMP_W, height: COMP_H, display: "block" }} />
        {annotations.map((a, i) => (
          <AnnotationBox key={i} a={a} localFrame={frame} durationInFrames={durationInFrames} />
        ))}
      </div>
    </AbsoluteFill>
  );
};
