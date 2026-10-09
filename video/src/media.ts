// Acces aux MEDIAS regenerables (captures de l'app, images du logo exportees, piste audio) depuis
// Remotion. Tout vit dans out/ (gitignore, reproductible par `npm run capture`/`logo`) ; un lien
// symbolique public/media -> ../out (cree par scripts/stage-media.cjs) les expose a staticFile
// (Remotion sert public/). On ne tape donc JAMAIS un chemin out/ en dur : on passe par mediaFile().
import { staticFile } from "remotion";

/** Un media regenerable de out/, servi via le pont public/media. Ex. mediaFile("captures/P06-grille-finale.png"). */
export const mediaFile = (rel: string): string => staticFile(`media/${rel.replace(/^\/+/, "")}`);

/** Une capture de l'app (out/captures/<name>). */
export const captureFile = (name: string): string => mediaFile(`captures/${name}`);

/** Une image d'intro du logo (out/logo/<anim>/intro/intro-XXXX.png), index 0-base. */
export const logoIntroFrame = (anim: string, index: number): string =>
  mediaFile(`logo/${anim}/intro/intro-${String(index).padStart(4, "0")}.png`);

/** Le poster du logo = fin d'intro, logo complet tenu (out/logo/<anim>/poster.png). */
export const logoPoster = (anim: string): string => mediaFile(`logo/${anim}/poster.png`);
