// Options du CLI Remotion (studio / render / still). N'affecte pas les API SSR.
// Doc : https://www.remotion.dev/docs/config
import { Config } from "@remotion/cli/config";

Config.setVideoImageFormat("jpeg");
Config.setPixelFormat("yuv420p");
Config.setCodec("h264");
// Rendu net ; le montage final (hors de ce run) reglera le CRF/bitrate pour viser <= 10 Mo.
Config.setConcurrency(null);
// Les polices et le HTML du logo sont servis depuis public/ (staticFile).
Config.setPublicDir("public");
