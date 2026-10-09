import React from "react";
import { AbsoluteFill, interpolate, useCurrentFrame } from "remotion";
import { COLORS, FONTS } from "./brand";
import { SUBTITLE_CUES } from "./script";

// Sous-titres INCRUSTES (regle 6). Une seule source : SUBTITLE_CUES (src/script.ts). Pose en
// overlay au niveau du film (image absolue), bande ancree en bas dans la zone de securite 96 px.
// Contraste encre sur papier (>= 15:1). Le plan 8 (0:36-0:38) n'a aucun sous-titre : rien ne
// s'affiche sur cet intervalle.
const FADE = 8; // images (~0,13 s) — fluide, jamais epileptique (regle 8)

export const Subtitles: React.FC = () => {
  const frame = useCurrentFrame();
  const cue = SUBTITLE_CUES.find((c) => frame >= c.fromFrame && frame < c.toFrame);
  if (!cue) {
    return null;
  }
  const appear = interpolate(frame, [cue.fromFrame, cue.fromFrame + FADE], [0, 1], {
    extrapolateLeft: "clamp",
    extrapolateRight: "clamp",
  });
  const disappear = interpolate(frame, [cue.toFrame - FADE, cue.toFrame], [1, 0], {
    extrapolateLeft: "clamp",
    extrapolateRight: "clamp",
  });
  const opacity = Math.min(appear, disappear);
  const lift = interpolate(frame, [cue.fromFrame, cue.fromFrame + FADE], [12, 0], {
    extrapolateLeft: "clamp",
    extrapolateRight: "clamp",
  });

  return (
    <AbsoluteFill style={{ alignItems: "center", justifyContent: "flex-end", paddingBottom: 112 }}>
      <div
        style={{
          transform: `translateY(${lift}px)`,
          opacity,
          maxWidth: 1250,
          margin: "0 96px",
          padding: "22px 44px",
          borderRadius: 18,
          background: "rgba(250, 249, 247, 0.94)",
          border: `2px solid ${COLORS.teal}`,
          boxShadow: "0 18px 50px -20px rgba(25, 23, 20, 0.45)",
          fontFamily: `"${FONTS.display}", ${FONTS.body}`,
          fontWeight: 800,
          fontSize: 56,
          lineHeight: 1.16,
          letterSpacing: "-0.01em",
          color: COLORS.ink,
          textAlign: "center",
          textWrap: "balance",
        }}
      >
        {cue.text}
      </div>
    </AbsoluteFill>
  );
};
