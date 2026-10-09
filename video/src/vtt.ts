// Generateur WebVTT — memes textes et memes horaires que les sous-titres incrustes (regle 6).
// Une seule source : SUBTITLE_CUES (src/script.ts).
import { SUBTITLE_CUES, type FrameCue } from "./script";

const pad = (n: number, len: number): string => String(n).padStart(len, "0");

/** Secondes -> horodatage WebVTT HH:MM:SS.mmm */
export const vttTime = (sec: number): string => {
  const ms = Math.round(sec * 1000);
  const h = Math.floor(ms / 3_600_000);
  const m = Math.floor((ms % 3_600_000) / 60_000);
  const s = Math.floor((ms % 60_000) / 1000);
  const rest = ms % 1000;
  return `${pad(h, 2)}:${pad(m, 2)}:${pad(s, 2)}.${pad(rest, 3)}`;
};

export const toWebVtt = (cues: readonly FrameCue[] = SUBTITLE_CUES): string => {
  const blocks = cues.map(
    (c, i) => `${i + 1}\n${vttTime(c.fromSec)} --> ${vttTime(c.toSec)}\n${c.text}`,
  );
  return `WEBVTT\n\n${blocks.join("\n\n")}\n`;
};
