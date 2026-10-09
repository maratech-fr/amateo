import React from "react";
import { AbsoluteFill, Audio, Sequence } from "remotion";
import { COLORS } from "./brand";
import { CLICK_GAIN, CLICK_TRACK } from "./audio-levels";
import { mediaFile } from "./media";
import { Scene } from "./Scene";
import { Subtitles } from "./Subtitles";
import { PLANS } from "./script";
import { planFrames } from "./timing";

// Composition 1920x1080 a 60 i/s. Une Sequence par plan du storyboard (frontieres CALCULEES depuis
// les mesures), la couche de sous-titres incrustes par-dessus, et — brouillon (etape 3) — une PISTE
// DE CLICS au tempo (120 BPM, silence 0:36-0:38, generee par script) pour juger le rythme. Ni
// musique ni SFX licencies dans ce run (poses a l'etape 4, mapping en donnees : src/audio-levels.ts).
export const AmateoPromo: React.FC = () => (
  <AbsoluteFill style={{ backgroundColor: COLORS.paper }}>
    {PLANS.map((plan) => {
      const { from, durationInFrames } = planFrames(plan.startMeasure, plan.endMeasure);
      return (
        <Sequence key={plan.id} name={`${plan.id} (${plan.kind})`} from={from} durationInFrames={durationInFrames}>
          <Scene plan={plan} />
        </Sequence>
      );
    })}
    <Subtitles />
    {/* Piste de clics du brouillon — le silence 0:36-0:38 est deja grave dans le WAV (plan 8). */}
    <Audio src={mediaFile(CLICK_TRACK.file)} volume={CLICK_GAIN} />
  </AbsoluteFill>
);
