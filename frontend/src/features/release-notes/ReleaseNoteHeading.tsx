import { frDateSlashed } from "@/shared/lib/date";
import type { SurfaceSkin } from "@/shared/lib/surfaceSkin";

import { RELEASE_NOTES_TEXTS } from "./texts";

const SKIN_CLASS: Record<SurfaceSkin, string> = {
  app: "text-xs text-muted-foreground",
  // `console-text-dim`, jamais `console-muted` : ce dernier passe sous 4,5:1 pour du texte.
  console: "text-xs text-console-text-dim",
};

/**
 * L'en-tête d'une note du journal : « 13/10/2026 - v1.3.5 », ou la date seule quand la note n'a
 * pas de version. Le même dans la modale, sur la page et dans la console (peau `console`).
 */
export function ReleaseNoteHeading({ date, version, variant = "app" }: { date: string; version: string | null; variant?: SurfaceSkin }) {
  return <p className={SKIN_CLASS[variant]}>{RELEASE_NOTES_TEXTS.heading(frDateSlashed(date), version)}</p>;
}
