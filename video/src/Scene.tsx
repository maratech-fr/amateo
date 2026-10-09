import React from "react";
import { AbsoluteFill } from "remotion";
import { COLORS } from "./brand";
import type { Plan } from "./script";
import { planFrames } from "./timing";
import { captureFile } from "./media";
import { Still } from "./scenes/Still";
import { LogoScene } from "./scenes/LogoScene";
import { MotionCharge } from "./scenes/MotionCharge";
import { FranceMap } from "./scenes/FranceMap";
import { ThemeSwitch } from "./scenes/ThemeSwitch";
import { GenerationCascade } from "./scenes/GenerationCascade";
import { MoveLockClip } from "./scenes/MoveLockClip";
import { Plan05Wishes } from "./scenes/Plan05Wishes";
import { Plan09Matches } from "./scenes/Plan09Matches";

// Aiguillage par plan du storyboard v2 (§4bis). Chaque plan recoit sa duree EN IMAGES (calculee
// depuis ses mesures). Les coupures entre plans tombent sur une mesure (coupes franches calees sur
// le tempo, jamais epileptiques) ; le plan 3 (impact 0:10) et le plan 12 (impact final 0:54)
// coupent NET sur le fond nuit du logo (decision fondateur 2026-10-09).

export const Scene: React.FC<{ plan: Plan }> = ({ plan }) => {
  const { durationInFrames } = planFrames(plan.startMeasure, plan.endMeasure);
  switch (plan.n) {
    case 1:
      return <MotionCharge variant="accumulate" durationInFrames={durationInFrames} />;
    case 2:
      return <MotionCharge variant="collapse" durationInFrames={durationInFrames} />;
    case 3:
      return <LogoScene />; // Orbite, fond NUIT, coupure nette (pas de fondu vers le papier)
    case 4:
      // Contraintes : la regle saisie (« Toutes les équipes · pas après 20:30 · Préféré ») s'ajoute
      // a la liste (groupe « TOUTES LES ÉQUIPES »). Zoom + encadre teal sur la regle ajoutee.
      return (
        <Still
          src={captureFile("P04-contraintes.png")}
          focal={{ x: 232, y: 555, w: 1030, h: 320 }}
          targetScale={1.45}
          annotations={[{ x: 236, y: 668, w: 1014, h: 74, appearAt: 0.45, radius: 12 }]}
          durationInFrames={durationInFrames}
        />
      );
    case 5:
      return <Plan05Wishes durationInFrames={durationInFrames} />;
    case 6:
      return <GenerationCascade durationInFrames={durationInFrames} />;
    case 7:
      // Fenetre du VRAI deplacement accepte + verrou dans le clip (webm 32 s) : ~23,5 -> 31,5 s
      // (le moteur verifie « chaque règle », « Créneau déplacé », puis verrou). 2x pour tenir en 4 s.
      return <MoveLockClip startFromSeconds={23.5} playbackRate={2} />;
    case 8:
      return <ThemeSwitch durationInFrames={durationInFrames} />;
    case 9:
      return <Plan09Matches durationInFrames={durationInFrames} />;
    case 10:
      // Bureau en LECTURE : l'app n'a PAS de badge « Lecture » (verifie) — on guide l'oeil par une
      // annotation VIDEO (encadre teal + icone d'oeil), distincte de l'UI, sans ajouter de mot.
      return (
        <Still
          src={captureFile("P10-lecture.png")}
          focal={{ x: 28, y: 300, w: 1810, h: 760 }}
          targetScale={1.08}
          annotations={[{ x: 28, y: 300, w: 1806, h: 760, appearAt: 0.25, radius: 18, eye: true }]}
          durationInFrames={durationInFrames}
        />
      );
    case 11:
      return <FranceMap durationInFrames={durationInFrames} />;
    case 12:
      return <LogoScene />; // Orbite, fond NUIT, se pose puis TIENT (l'appel final est pose dessous)
    default:
      return <AbsoluteFill style={{ backgroundColor: COLORS.paper }} />;
  }
};
