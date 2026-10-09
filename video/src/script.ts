// LE fichier unique des textes a l'ecran (regle 6 du prompt maitre). On ne modifie QUE ce fichier
// pour changer un mot. Chaque texte est recopie MOT POUR MOT du storyboard v2
// (business/video_promo/03-prompts.md §4bis) ; le nom du produit et l'adresse du site sont injectes
// depuis src/brand.ts (regle 3 : marque en variables, aucun litteral de marque ailleurs).
//
// Les FRONTIERES DE PLAN sont decrites par mesures (1-indexees, inclusives) et calculees en images
// par src/timing.ts — jamais un numero d'image en dur. Les sous-titres sont cales sur des SECONDES
// absolues reprises telles quelles du tableau §4bis.
import { BRAND, brandHost } from "./brand";
import { secToFrames } from "./timing";

export type PlanKind = "motion" | "logo-intro" | "logo-outro" | "capture";

export interface SubtitleCue {
  /** debut, en secondes absolues dans le film (storyboard §4bis) */
  readonly fromSec: number;
  /** fin, en secondes absolues dans le film */
  readonly toSec: number;
  readonly text: string;
}

export interface Plan {
  /** identifiant stable « P01 »..« P12 » (sert aussi a nommer les captures) */
  readonly id: string;
  /** numero 1..12 */
  readonly n: number;
  /** mesure de debut, 1-indexee et INCLUSIVE (storyboard §4bis) */
  readonly startMeasure: number;
  /** mesure de fin, 1-indexee et INCLUSIVE */
  readonly endMeasure: number;
  readonly kind: PlanKind;
  /** ce qu'on voit, en langage club — ZERO jargon technique (regle 5ter) */
  readonly shot: string;
  readonly cues: readonly SubtitleCue[];
  /** fichiers attendus dans out/ (emplacement reserve jusqu'au montage) */
  readonly captures?: readonly string[];
}

export const PLANS: readonly Plan[] = [
  {
    id: "P01",
    n: 1,
    startMeasure: 1,
    endMeasure: 3,
    kind: "motion",
    shot:
      "Motion design, fond papier : des post-it de contraintes apparaissent un par un puis s'accumulent autour d'une grille de planning vide dessinee.",
    cues: [
      { fromSec: 0, toSec: 4, text: "Aujourd'hui, il doit penser à tout sans rien oublier." },
      { fromSec: 4, toSec: 6, text: "C'est la charge mentale." },
    ],
  },
  {
    id: "P02",
    n: 2,
    startMeasure: 4,
    endMeasure: 5,
    kind: "motion",
    shot: "Un post-it tombe, la grille se rature en cascade.",
    cues: [{ fromSec: 6, toSec: 10, text: "Une contrainte oubliée = tout recommencer." }],
  },
  {
    id: "P03",
    n: 3,
    startMeasure: 6,
    endMeasure: 7,
    kind: "logo-intro",
    shot:
      "Animation du logo (version Creneaux) : les cases de la grille deviennent des arcs, le mot apparait.",
    cues: [
      { fromSec: 10, toSec: 14, text: `${BRAND.name} capture toutes les contraintes du club.` },
    ],
    captures: ["logo/intro (images 0 a 1,5 s)"],
  },
  {
    id: "P04",
    n: 4,
    startMeasure: 8,
    endMeasure: 9,
    kind: "capture",
    shot: "Ecran des contraintes : on saisit une regle, elle s'ajoute a la liste.",
    cues: [
      {
        fromSec: 14,
        toSec: 18,
        text: "Chaque règle est capturée, écrite, et ne se perd plus jamais.",
      },
    ],
    captures: ["P04-contraintes.png"],
  },
  {
    id: "P05",
    n: 5,
    startMeasure: 10,
    endMeasure: 13,
    kind: "capture",
    shot:
      "La demande aux coachs : l'e-mail recu par un coach, la page ou il donne ses dates (sans compte) et propose une mutualisation, puis la fenetre « Doleances des coachs » cote gestionnaire ou tout remonte.",
    cues: [
      {
        fromSec: 18,
        toSec: 22,
        text: "Chaque coach donne ses dates, propose une mutualisation — sans créer de compte.",
      },
      { fromSec: 22, toSec: 26, text: "Tout remonte dans votre todo." },
    ],
    captures: ["P05a-email.png", "P05b-page-coach.png", "P05c-doleances-hub.png"],
  },
  {
    id: "P06",
    n: 6,
    startMeasure: 14,
    endMeasure: 16,
    kind: "capture",
    shot: "Clic « Generer » : la grille de planning se remplit, chaque equipe prend sa place.",
    cues: [{ fromSec: 26, toSec: 32, text: "Un planning optimisé en quelques minutes." }],
    captures: ["P06-generation.webm", "P06-grille-finale.png", "P06-boxes.json"],
  },
  {
    id: "P07",
    n: 7,
    startMeasure: 17,
    endMeasure: 18,
    kind: "capture",
    shot: "Une seance deplacee a la main puis verrouillee sur son creneau.",
    cues: [
      // Décision fondateur 2026-10-09 : « Glissez » → « Déplacez » (la grille n'a pas de
      // glisser-déposer — on sélectionne une séance puis « Placer ici »). Second segment inchangé.
      { fromSec: 32, toSec: 34, text: "Déplacez, verrouillez, régénérez." },
      { fromSec: 34, toSec: 36, text: "L'outil optimise, vous décidez." },
    ],
    captures: ["P07-drag-lock.webm"],
  },
  {
    id: "P08",
    n: 8,
    startMeasure: 19,
    endMeasure: 19,
    kind: "capture",
    // Regle 5bis/11 : AUCUN texte, aucun sous-titre. Respiration, bascule clair->sombre en un clic.
    shot: "Bascule du theme clair vers sombre en un clic (respiration, sans texte ni sous-titre).",
    cues: [],
    captures: ["P08-theme.webm"],
  },
  {
    id: "P09",
    n: 9,
    startMeasure: 20,
    endMeasure: 23,
    kind: "capture",
    shot:
      "Les matchs : le calendrier des rencontres, puis l'onglet Conflits avec un coach sur deux rencontres.",
    cues: [
      { fromSec: 38, toSec: 42, text: "Les matchs, un dossier de moins à garder en tête." },
      { fromSec: 42, toSec: 46, text: "Les conflits de coachs vus avant le week-end." },
    ],
    captures: ["P09a-calendrier.png", "P09b-conflits.png"],
  },
  {
    id: "P10",
    n: 10,
    startMeasure: 24,
    endMeasure: 25,
    kind: "capture",
    shot: "La vue d'un membre du bureau en lecture seule.",
    cues: [{ fromSec: 46, toSec: 50, text: "Votre bureau voit tout, en lecture." }],
    captures: ["P10-lecture.png"],
  },
  {
    id: "P11",
    n: 11,
    startMeasure: 26,
    endMeasure: 27,
    kind: "motion",
    shot:
      "Plan calme (motion design) : fond papier, confiance sur les donnees — clubs amateurs francais, donnees en France.",
    cues: [
      { fromSec: 50, toSec: 52, text: "Application pour les clubs amateurs français." },
      { fromSec: 52, toSec: 54, text: "Données hébergées en France." },
    ],
  },
  {
    id: "P12",
    n: 12,
    startMeasure: 28,
    endMeasure: 30,
    kind: "logo-outro",
    shot: "Sortie de l'animation du logo, puis logo fixe et l'adresse du site.",
    cues: [
      {
        fromSec: 54,
        toSec: 58,
        text: "La saison n'a pas encore débuté que le planning est déjà prêt.",
      },
      // CTA : seul appel a l'action retenu (decision fondateur 2026-10-09). Hote injecte depuis brand.
      { fromSec: 58, toSec: 60, text: `Demander une démo — ${brandHost}` },
    ],
    captures: ["logo/outro (images 7,5 a 9,0 s)"],
  },
] as const;

/** Un sous-titre, exprime en IMAGES (calcule depuis les secondes du storyboard). */
export interface FrameCue {
  readonly fromFrame: number;
  readonly toFrame: number;
  readonly fromSec: number;
  readonly toSec: number;
  readonly text: string;
}

/** Liste a plat de tous les sous-titres du film (plan 8 n'en a aucun), pour l'incrustation
 *  (src/Subtitles.tsx) ET le generateur WebVTT (scripts/make-vtt.ts) — une seule source. */
export const SUBTITLE_CUES: readonly FrameCue[] = PLANS.flatMap((p) =>
  p.cues.map((c) => ({
    fromFrame: secToFrames(c.fromSec),
    toFrame: secToFrames(c.toSec),
    fromSec: c.fromSec,
    toSec: c.toSec,
    text: c.text,
  })),
);
