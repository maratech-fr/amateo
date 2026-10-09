import React from "react";
import { AbsoluteFill, Sequence } from "remotion";
import { captureFile } from "../media";
import { Still } from "./Still";

// Plan 9 (0:38-0:46, 8 s) — « Les matchs, un dossier de moins à garder en tête. » puis « Les
// conflits de coachs vus avant le week-end. » Glisse CALENDRIER -> CONFLITS (4 s chacun) ; sur
// l'onglet Conflits, encadres teal sur la puce « Personne en double » et la fiche « Alex Martin »
// (coach sur deux rencontres a domicile le meme soir). Captures reelles de la demo (P09a/b).

export const Plan09Matches: React.FC<{ durationInFrames: number }> = ({ durationInFrames }) => {
  const half = Math.round(durationInFrames / 2);
  return (
    <AbsoluteFill>
      <Sequence from={0} durationInFrames={half} name="P09a calendrier">
        <Still src={captureFile("P09a-calendrier.png")} focal={{ x: 320, y: 260, w: 1280, h: 620 }} targetScale={1.25} durationInFrames={half} />
      </Sequence>
      <Sequence from={half} durationInFrames={durationInFrames - half} name="P09b conflits">
        <Still
          src={captureFile("P09b-conflits.png")}
          focal={{ x: 30, y: 300, w: 1860, h: 300 }}
          targetScale={1.35}
          annotations={[
            { x: 142, y: 248, w: 170, h: 38, appearAt: 0.25, radius: 999 },
            { x: 36, y: 300, w: 1850, h: 300, appearAt: 0.45, radius: 14 },
          ]}
          durationInFrames={durationInFrames - half}
        />
      </Sequence>
    </AbsoluteFill>
  );
};
