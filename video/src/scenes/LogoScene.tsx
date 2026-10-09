import React from "react";
import { AbsoluteFill, Img, interpolate, useCurrentFrame } from "remotion";
import { COLORS } from "../brand";
import { LOGO_ANIMATION, LOGO_INTRO_COUNT, LOGO_IS_NIGHT, LOGO_LAST_INTRO_INDEX } from "../logo";
import { logoIntroFrame } from "../media";

// Plans 3 (ouverture) et 12 (fermeture) : l'animation du logo « 01 · Orbite » (fond NUIT) exportee
// image par image (capture/capture-logo.cjs), rejouee en SEQUENCE D'IMAGES — le logo n'est jamais
// redessine (regle 4). On joue l'INTRO (0 -> 1,5 s = 90 images) puis on TIENT la derniere image
// (= logo complet). Decision fondateur 2026-10-09 : PAS de sortie qui vide l'ecran (ni implosion) ;
// le logo reste a l'ecran (plan 12 : l'appel final est pose dessous par la couche de sous-titres).

export const LogoScene: React.FC<{ fadeInFrames?: number }> = ({ fadeInFrames = 0 }) => {
  const frame = useCurrentFrame();
  // L'intro joue a 60 i/s (= FPS du film) : une image du film = une image d'intro, clampee pour tenir.
  const idx = Math.min(frame, LOGO_LAST_INTRO_INDEX);
  const src = logoIntroFrame(LOGO_ANIMATION, Math.max(0, Math.min(idx, LOGO_INTRO_COUNT - 1)));
  // Fondu d'ENTREE optionnel (depuis le fond papier du film) : plan 3 coupe NET (impact 0:10),
  // donc fadeInFrames = 0 par defaut (decision fondateur : fond nuit conserve, pas de fondu).
  const opacity = fadeInFrames > 0 ? interpolate(frame, [0, fadeInFrames], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" }) : 1;
  return (
    <AbsoluteFill style={{ backgroundColor: LOGO_IS_NIGHT ? "#08070a" : COLORS.paper }}>
      <Img src={src} style={{ width: 1920, height: 1080, display: "block", opacity }} />
    </AbsoluteFill>
  );
};
