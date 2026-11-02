const plural = (n: number): string => (n > 1 ? "s" : "");

/** Textes de la modale d'import FBI des équipes (`TeamsImportModal`). */
export const TEAMS_IMPORT_TEXTS = {
  genderToChoose: "À choisir",
  genderMale: "Homme",
  genderFemale: "Femme",
  genderMixed: "Mixte",
  fileUnreadable: "Le fichier n'a pas pu être lu — est-il ouvert dans un autre logiciel ?",
  missingGender: (n: number) => `${n} équipe${plural(n)} sans genre — choisissez-le pour importer`,
  title: "Importer vos équipes (export FBI)",
  close: "Fermer",
  importButton: (n: number) => `Importer ${n} équipe${plural(n)}`,
  dropHint: "Déposez l'export FBI de la liste de vos équipes (.xlsx, colonnes Nom, Catégorie, Numéro, Organisme). Un autre fichier Excel ne sera pas reconnu.",
  fileLabel: "Fichier FBI (.xlsx)",
  analyzing: "Analyse du fichier…",
  selectHint: "Cochez les équipes à importer. Celles déjà présentes sont décochées pour éviter les doublons.",
  uncheckAll: "Tout décocher",
  checkAll: "Tout cocher",
  alreadyPresentSuffix: " — déjà présente",
  tierAria: (team: string) => `Rang de ${team}`,
  genderAria: (team: string) => `Genre de ${team}`,
  ctcAria: (team: string) => `Entente (CTC) pour ${team}`,
  ctcShort: "CTC",
  report: (created: number, skipped: number) => `${created} équipe${plural(created)} créée${plural(created)} · ${skipped} ignorée${plural(skipped)} (déjà présentes sous le même nom)`,
  completeHint: "Complétez maintenant chaque équipe : genre, niveau de jeu, rang et séances.",
  federationHint: "La fédération ne déclare pas toutes vos équipes (loisirs, école de basket…) : celles qui manquent s'ajoutent à la main, ce n'est pas un oubli de l'import.",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
