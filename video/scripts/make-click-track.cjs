// Genere une PISTE DE CLICS au tempo, par CALCUL (aucun telechargement) — son du BROUILLON (etape 3).
// 120 BPM = 1 clic toutes les 0,5 s ; le premier temps de chaque mesure (4 temps) est accentue.
// SILENCE NET de 0:36,0 a 0:38,0 (respiration du plan 8, regle 7bis) : aucun clic sur cet intervalle.
// Sortie : WAV PCM 16 bits mono 44,1 kHz dans out/audio/click-120.wav (pointe par public/media).
//
// Le vrai mixage (musique + SFX licencies) vient a l'etape 4 ; ici on ne fournit QUE le guide de tempo.
const fs = require("node:fs");
const path = require("node:path");

const SR = 44100;
const DURATION_S = 60;
const BPM = 120;
const BEAT_S = 60 / BPM; // 0,5 s
const BEATS_PER_MEASURE = 4;
const SILENCE_FROM_S = 36.0; // fin mesure 18
const SILENCE_TO_S = 38.0; // debut mesure 20

function writeClickTrack(outPath) {
  const n = Math.round(DURATION_S * SR);
  const buf = new Float32Array(n);
  const totalBeats = Math.floor(DURATION_S / BEAT_S);
  for (let b = 0; b < totalBeats; b++) {
    const t0 = b * BEAT_S;
    if (t0 >= SILENCE_FROM_S && t0 < SILENCE_TO_S) continue; // respiration plan 8
    const downbeat = b % BEATS_PER_MEASURE === 0;
    const freq = downbeat ? 2100 : 1500; // Hz
    const amp = downbeat ? 0.9 : 0.55;
    const decay = downbeat ? 55 : 40; // 1/s (exp), tick court et sec
    const len = Math.round(0.06 * SR);
    const start = Math.round(t0 * SR);
    for (let i = 0; i < len && start + i < n; i++) {
      const t = i / SR;
      const env = Math.exp(-decay * t);
      buf[start + i] += amp * env * Math.sin(2 * Math.PI * freq * t);
    }
  }
  // Float -> PCM16 (clamp).
  const data = Buffer.alloc(n * 2);
  for (let i = 0; i < n; i++) {
    const s = Math.max(-1, Math.min(1, buf[i]));
    data.writeInt16LE((s * 32767) | 0, i * 2);
  }
  // En-tete WAV.
  const header = Buffer.alloc(44);
  header.write("RIFF", 0);
  header.writeUInt32LE(36 + data.length, 4);
  header.write("WAVE", 8);
  header.write("fmt ", 12);
  header.writeUInt32LE(16, 16);
  header.writeUInt16LE(1, 20); // PCM
  header.writeUInt16LE(1, 22); // mono
  header.writeUInt32LE(SR, 24);
  header.writeUInt32LE(SR * 2, 28); // byte rate
  header.writeUInt16LE(2, 32); // block align
  header.writeUInt16LE(16, 34); // bits
  header.write("data", 36);
  header.writeUInt32LE(data.length, 40);
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, Buffer.concat([header, data]));
  return { outPath, seconds: DURATION_S, clicks: totalBeats, silence: [SILENCE_FROM_S, SILENCE_TO_S] };
}

module.exports = { writeClickTrack };

if (require.main === module) {
  const out = path.resolve(__dirname, "..", "out", "audio", "click-120.wav");
  const r = writeClickTrack(out);
  // eslint-disable-next-line no-console
  console.log("Piste de clics ecrite :", r.outPath, `(${r.seconds}s, silence ${r.silence[0]}-${r.silence[1]}s)`);
}
