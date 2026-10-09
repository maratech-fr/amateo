// Prepare les MEDIAS pour Remotion avant un rendu (still/render) :
//   1. (re)cree le pont public/media -> ../out (lien symbolique), pour que staticFile serve les
//      medias regenerables de out/ (captures, images du logo, audio) ;
//   2. genere la piste de clics du brouillon si elle manque (out/audio/click-120.wav).
// Idempotent, sans reseau. Appele par `npm run stage-media` et en prefixe de `still`/`render`/`draft`.
const fs = require("node:fs");
const path = require("node:path");
const { writeClickTrack } = require("./make-click-track.cjs");

const ROOT = path.resolve(__dirname, "..");
const link = path.join(ROOT, "public", "media");

// 1. Pont public/media -> ../out.
try {
  const stat = fs.lstatSync(link, { throwIfNoEntry: false });
  if (stat && !stat.isSymbolicLink()) {
    console.warn(`public/media existe et n'est PAS un lien — laisse tel quel : ${link}`);
  } else {
    if (stat) fs.unlinkSync(link);
    fs.mkdirSync(path.dirname(link), { recursive: true });
    fs.symlinkSync("../out", link);
    console.log("pont public/media -> ../out (ok)");
  }
} catch (e) {
  console.warn("pont public/media impossible :", e.message);
}

// 2. Piste de clics (brouillon) si absente.
const click = path.join(ROOT, "out", "audio", "click-120.wav");
if (!fs.existsSync(click)) {
  const r = writeClickTrack(click);
  console.log("piste de clics generee :", r.outPath);
} else {
  console.log("piste de clics deja presente :", click);
}
