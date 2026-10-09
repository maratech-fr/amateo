import React from "react";
import { AbsoluteFill, Sequence } from "remotion";
import { captureFile } from "../media";
import { Still } from "./Still";

// Plan 5 (0:18-0:26, 8 s) — « Chaque coach donne ses dates, propose une mutualisation — sans créer
// de compte. » puis « Tout remonte dans votre todo. » TROIS cadrages enchaines (regle montage) :
//   a. l'e-mail recu par le coach (CTA « Donner mes disponibilités »),
//   b. la page coach (bloc « Mutualisation », sans compte),
//   c. la fenetre « Doléances des coachs » cote gestionnaire (tout remonte).
// Captures reelles de la demo (P05a/b/c), zoom amorti sur l'element parlant de chaque cadrage.

const SUB = 160; // 8 s / 3 ~ 2,67 s par cadrage (480 images / 3)

export const Plan05Wishes: React.FC<{ durationInFrames: number }> = ({ durationInFrames }) => (
  <AbsoluteFill>
    <Sequence from={0} durationInFrames={SUB} name="P05a e-mail">
      <Still
        src={captureFile("P05a-email.png")}
        focal={{ x: 650, y: 25, w: 615, h: 425 }}
        targetScale={1.5}
        annotations={[{ x: 693, y: 268, w: 226, h: 44, appearAt: 0.45, radius: 10 }]}
        durationInFrames={SUB}
      />
    </Sequence>
    <Sequence from={SUB} durationInFrames={SUB} name="P05b page coach">
      <Still
        src={captureFile("P05b-page-coach.png")}
        focal={{ x: 650, y: 245, w: 630, h: 320 }}
        targetScale={1.55}
        annotations={[{ x: 672, y: 258, w: 648, h: 300, appearAt: 0.5, radius: 14 }]}
        durationInFrames={SUB}
      />
    </Sequence>
    <Sequence from={SUB * 2} durationInFrames={durationInFrames - SUB * 2} name="P05c doléances hub">
      <Still
        src={captureFile("P05c-doleances-hub.png")}
        focal={{ x: 576, y: 330, w: 770, h: 430 }}
        targetScale={1.5}
        annotations={[{ x: 600, y: 494, w: 740, h: 150, appearAt: 0.5, radius: 12 }]}
        durationInFrames={durationInFrames - SUB * 2}
      />
    </Sequence>
  </AbsoluteFill>
);
