import React from "react";
import { Composition } from "remotion";
import "./fonts"; // effet de bord : charge la police d'affichage locale (delayRender)
import { AmateoPromo } from "./AmateoPromo";
import { FPS, TOTAL_FRAMES } from "./timing";

export const RemotionRoot: React.FC = () => (
  <Composition
    id="AmateoPromo"
    component={AmateoPromo}
    durationInFrames={TOTAL_FRAMES}
    fps={FPS}
    width={1920}
    height={1080}
  />
);
