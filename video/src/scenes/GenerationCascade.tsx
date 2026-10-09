import React, { useEffect, useState } from "react";
import { AbsoluteFill, cancelRender, continueRender, delayRender, Img, interpolate, useCurrentFrame } from "remotion";
import { COLORS } from "../brand";
import { captureFile, mediaFile } from "../media";
import { focalTransform, type Rect } from "./Still";

// Plan 6 (0:26-0:32, 6 s) — « Un planning optimisé en quelques minutes. » Base = la grille generee
// reelle (P06-grille-finale.png) ; par-dessus, une CASCADE ACCELEREE des seances, calee sur les
// boites englobantes reelles (P06-boxes.json, regle 9) : chaque seance « se pose » (flash teal
// bref), balayage gauche->droite/haut->bas, puis ZOOM lent sur la plage horaire DENSE (le soir).
// Le mouvement rend le message « le planning se fait tout seul, vite » — le rythme sert le message.

interface Box { x: number; y: number; w: number; h: number }

// Zone dense : les creneaux du soir, serres en bas de grille (cf. la capture). Zoom modere dessus.
const DENSE: Rect = { x: 820, y: 820, w: 720, h: 300 };

export const GenerationCascade: React.FC<{ durationInFrames: number }> = ({ durationInFrames }) => {
  const frame = useCurrentFrame();
  const [boxes, setBoxes] = useState<Box[] | null>(null);
  const [handle] = useState(() => delayRender("Chargement des boites P06"));

  useEffect(() => {
    fetch(mediaFile("captures/P06-boxes.json"))
      .then((r) => r.json())
      .then((d: { boxes: Box[] }) => {
        // On garde les seances VISIBLES dans le cadre (la grille deborde a droite, x > 1920).
        const visible = (d.boxes ?? []).filter((b) => b.x >= 0 && b.x + b.w <= 1920 && b.y >= 440 && b.y + b.h <= 1080);
        // Ordre de balayage : gauche->droite puis haut->bas (cascade lisible).
        visible.sort((a, b) => a.x - b.x || a.y - b.y);
        setBoxes(visible);
        continueRender(handle);
      })
      .catch((e) => cancelRender(e));
  }, [handle]);

  const n = boxes?.length ?? 0;
  const cascadeEnd = durationInFrames * 0.52;
  // Zoom sur la zone dense sur la 2e moitie.
  const zoomP = interpolate(frame, [durationInFrames * 0.5, durationInFrames], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  const transform = focalTransform(DENSE, 1.6, zoomP < 0 ? 0 : 1 - Math.pow(1 - zoomP, 3));

  return (
    <AbsoluteFill style={{ backgroundColor: COLORS.paper, overflow: "hidden" }}>
      <div style={{ position: "absolute", width: 1920, height: 1080, transform, transformOrigin: "0 0" }}>
        <Img src={captureFile("P06-grille-finale.png")} style={{ width: 1920, height: 1080, display: "block" }} />
        {boxes?.map((b, i) => {
          const appear = (i / Math.max(1, n)) * cascadeEnd;
          const p = interpolate(frame, [appear, appear + 10, appear + 26], [0, 1, 0], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
          if (p <= 0) return null;
          return (
            <div
              key={i}
              style={{
                position: "absolute",
                left: b.x - 3,
                top: b.y - 3,
                width: b.w + 6,
                height: b.h + 6,
                borderRadius: 6,
                border: `3px solid ${COLORS.teal}`,
                background: `${COLORS.teal}33`,
                opacity: p,
                transform: `scale(${1.18 - 0.18 * Math.min(1, p * 2)})`,
                transformOrigin: "center",
                boxShadow: `0 0 18px ${COLORS.teal}aa`,
              }}
            />
          );
        })}
      </div>
    </AbsoluteFill>
  );
};
