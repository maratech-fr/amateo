import React from "react";
import { AbsoluteFill, Sequence } from "remotion";
import { COLORS } from "./brand";
import { Scene } from "./Scene";
import { Subtitles } from "./Subtitles";
import { PLANS } from "./script";
import { planFrames } from "./timing";

// Composition 1920x1080 a 60 i/s. Une Sequence par plan du storyboard (frontieres CALCULEES depuis
// les mesures, src/timing.ts), plus la couche de sous-titres incrustes par-dessus (image absolue).
export const AmateoPromo: React.FC = () => (
  <AbsoluteFill style={{ backgroundColor: COLORS.paper }}>
    {PLANS.map((plan) => {
      const { from, durationInFrames } = planFrames(plan.startMeasure, plan.endMeasure);
      return (
        <Sequence
          key={plan.id}
          name={`${plan.id} (${plan.kind})`}
          from={from}
          durationInFrames={durationInFrames}
        >
          <Scene plan={plan} />
        </Sequence>
      );
    })}
    <Subtitles />
  </AbsoluteFill>
);
