import { LEVEL_LABEL, type Gender, type TeamLevel } from "@/shared/lib/teamIdentity";

/**
 * Textes centralisés de l'éditeur d'équipes (`TeamsStep`) et de la modale Liens
 * (`TeamLinksModal`) — chantier D (CTC) D4. Maison unique des libellés FR de ces deux écrans
 * (migration texts, règle `.claude/rules/frontend.md`) : une clé par intention, interpolation par
 * fonctions typées. Scindé du `texts.ts` de la feature (au-delà de ~300 lignes attendues côté
 * équipes), consommé par `TeamsStep.tsx` et `TeamLinksModal.tsx`.
 *
 * Les libellés de NIVEAU délèguent à la maison partagée `LEVEL_LABEL` (`shared/lib/teamIdentity`,
 * alignée sur `App\Enum\TeamLevel::label`) — on ne réinvente pas le vocabulaire métier ; seul le
 * placeholder « — » (hors niveau) vit ici. Les libellés de GENRE n'ont pas de maison partagée :
 * table inline typée.
 */
export const TEAMS_TEXTS = {
  // ── Entente (CTC) — D4 : la saisie vit dans la modale Liens, le repère sur la ligne ──
  /** Onglet (modale Liens) et libellé court du repère. */
  ctcTabLabel: "Entente (CTC)",
  /** Case de l'onglet Entente. */
  ctcCheckboxLabel: "Cette équipe joue en entente (CTC)",
  /** Phrase d'aide AVANT la case (reliée par aria-describedby). */
  ctcHelp:
    "Une entente (CTC) est un repère pour le club : elle s'affiche devant le nom de l'équipe, sans aucun effet sur le planning. Elle vaut pour toute la saison.",
  /** Libellé visible du champ partenaire (sert aussi de nom accessible via le <label>). */
  ctcPartnerLabel: "Partenaire(s)",
  ctcPartnerPlaceholder: "Avec qui ? (facultatif)",
  /** Segment de sous-ligne (ligne d'équipe) sans partenaire. */
  ctcSubline: "Entente (CTC)",
  /** Segment de sous-ligne avec partenaire. */
  ctcSublineWith: (partner: string) => `Entente (CTC) avec ${partner}`,

  // ── Modale Liens ──
  linksModalTitle: (name: string) => `Liens de ${name}`,
  linkTypeAria: "Type de lien",
  passerellesTab: "Passerelles",
  mutualisationTab: "Mutualisation",

  // ── Libellés d'enum (Genre : table inline ; Niveau : maison partagée + placeholder) ──
  genderLabel: (value: Gender | ""): string => {
    const table: Record<Gender | "", string> = { "": "—", M: "Homme", F: "Femme", MIXTE: "Mixte" };
    return table[value];
  },
  levelLabel: (value: TeamLevel | ""): string => ("" === value ? "—" : LEVEL_LABEL[value]),

  // ── Colonnes / champs (noms accessibles de la ligne et du formulaire) ──
  colNameAria: "Nom",
  colName: "Nom de l'équipe",
  colCategory: "Catégorie",
  colGender: "Genre",
  colLevel: "Niveau de jeu",
  colSessionsAria: "Séances/sem",
  colSessions: "Séances",
  colRank: "Rang",
  colDelete: "Suppr.",
  categoryPlaceholderOption: "— catégorie —",

  // ── Ligne d'équipe ──
  moveUpTeam: (name: string) => `Monter ${name}`,
  moveDownTeam: (name: string) => `Descendre ${name}`,
  linksOfTeam: (name: string) => `Liens de ${name}`,
  deleteTeam: (name: string) => `Supprimer l'équipe ${name}`,
  engagedRowMarker: "Engagée en compétition — niveau et suppression verrouillés.",
  bonusWarning: "Équipe en compétition classée Bonus (D) — elle passera en dernier ; vérifiez le rang.",

  // ── Mode « Trier » (glisser-déposer) ──
  dragHandleAria: "Déplacer l'équipe",
  moveUp: "Monter",
  moveDown: "Descendre",
  reverseRankOrder: (label: string) => `Inverser l'ordre du rang ${label}`,
  reverseOrder: "Inverser l'ordre",
  dropHere: "Glissez une équipe ici",
  sortTrier: "Trier",
  sortFinish: "Terminer le tri",
  sortModeIntroLead: "Glissez une équipe par sa poignée pour la réordonner ",
  sortModeIntroStrong: "ou la déplacer dans un autre niveau",
  sortModeIntroRest: ". Les flèches restent disponibles.",

  // ── En-tête de tri (bouton de colonne) ──
  sortByAria: (label: string, state: string) => `Trier par ${label.toLocaleLowerCase("fr")}${state}`,
  sortedAsc: ", trié croissant",
  sortedDesc: ", trié décroissant",

  // ── Intro de l'étape ──
  introLead: "Listez vos équipes et donnez à chacune un ",
  introRankWord: "rang",
  introRankRest: " : il tranche quand les créneaux manquent — les mieux classées passent d'abord.",
  introLevelLead: "Le ",
  introLevelWord: "niveau de jeu",
  introLevelRest: " (division FFBB) est indépendant : il décrit la compétition, pas la priorité de placement.",

  // ── Imports (onboarding) ──
  importFromFbi: "Importer depuis FBI",
  addTeam: "Ajouter l'équipe",

  // ── Erreurs / états ──
  nameRequired: "Le nom de l'équipe est obligatoire.",
  categoryRequired: "Choisissez la catégorie de l'équipe.",
  noTeamsYet: "Aucune équipe pour le moment.",

  // ── Bandeaux (liste) ──
  engagedReason: "Cette équipe joue en compétition : ses matchs sont engagés auprès de la fédération.",
  engagedListRest:
    " Leur niveau de jeu et leur suppression sont verrouillés ; le reste (nom, rang, créneaux, gymnase) se modifie librement. Pour (re)poser le niveau d'une équipe jeune depuis sa division, passez par Matchs › Importer › « Engagements FFBB ».",
  sessionsLockHint: "Rouvrez le planning de saison pour modifier le nombre de séances.",
  sessionsLockedNoticeLead:
    "Le nombre de séances par semaine fait partie du planning de saison en vigueur : il reste visible mais verrouillé. ",

  // ── Modale d'annonce de l'import automatique FFBB ──
  importNoticeTitle: "Équipes importées depuis la FFBB",
  importNoticeDescription:
    "Les équipes ont été importées automatiquement depuis la FFBB. Des erreurs ont pu se glisser — merci de corriger et de compléter cet écran (vos équipes loisir et école de basket ne sont pas connues de la fédération).",
  importNoticeConfirm: "Compris",
  importNoticeCancel: "Fermer",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
