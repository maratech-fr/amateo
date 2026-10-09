// Chargement de la police d'affichage depuis public/fonts/ (fichier LOCAL, aucun reseau au rendu).
// On bloque le rendu avec delayRender jusqu'a ce que la police soit prete : ni `remotion still` ni
// le rendu final ne doivent capturer un fallback.
import { loadFont } from "@remotion/fonts";
import { continueRender, delayRender, staticFile } from "remotion";
import { FONTS } from "./brand";

const handle = delayRender("Chargement de la police d'affichage");

void loadFont({
  family: FONTS.display,
  url: staticFile("fonts/bricolage-latin.woff2"),
  weight: "700 800",
})
  .then(() => continueRender(handle))
  .catch((err: unknown) => {
    // Une police manquante ne doit pas faire echouer le rendu : on continue sur le fallback body.
    // eslint-disable-next-line no-console
    console.error("Police d'affichage non chargee :", err);
    continueRender(handle);
  });
