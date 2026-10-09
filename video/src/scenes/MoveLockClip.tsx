import React from "react";
import { AbsoluteFill, OffthreadVideo } from "remotion";
import { COLORS } from "../brand";
import { captureFile } from "../media";
import { secToFrames } from "../timing";

// Plan 7 (0:32-0:36, 4 s) — « Déplacez, verrouillez, régénérez. » VRAI CLIP du geste (regle : le
// mouvement reel montre la puissance mieux qu'un fondu). Capture Playwright P07-drag-lock.webm :
// on selectionne une seance, « Déplacer » arme le mode cible, on clique « Placer ici » sur une case
// vide, puis on verrouille. Faux curseur injecte dans la capture.
//
// startFromSeconds / playbackRate CADRENT la fenetre pertinente du clip (navigation/attente ecartees).
// A CALER apres recapture (ffprobe sur le webm) — valeurs par defaut raisonnables pour le brouillon.
export const MoveLockClip: React.FC<{ startFromSeconds?: number; playbackRate?: number }> = ({ startFromSeconds = 0, playbackRate = 1 }) => (
  <AbsoluteFill style={{ backgroundColor: COLORS.paper, overflow: "hidden" }}>
    <OffthreadVideo
      src={captureFile("P07-drag-lock.webm")}
      startFrom={secToFrames(startFromSeconds)}
      playbackRate={playbackRate}
      muted
      style={{ width: 1920, height: 1080, objectFit: "cover" }}
    />
  </AbsoluteFill>
);
