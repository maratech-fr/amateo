// Ecrit out/amateo-promo.fr.vtt depuis les sous-titres (src/script.ts via src/vtt.ts).
// Lance par `npm run vtt` (tsx), depuis le dossier video/.
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { toWebVtt } from "../src/vtt";

const out = resolve(process.cwd(), "out", "amateo-promo.fr.vtt");
mkdirSync(dirname(out), { recursive: true });
writeFileSync(out, toWebVtt(), "utf8");
// eslint-disable-next-line no-console
console.log("VTT ecrit :", out);
