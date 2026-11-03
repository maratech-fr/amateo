// Textes centralisés de la feature profil (chantier Membre, PR 1 — zone « Supprimer mon compte »).
// Écran NON migré (ProfilePage hors MIGRATED_FILES) : seuls les textes touchés par ce chantier
// vivent ici. Convention : clé camelCase par intention ; interpolation par fonctions typées.
// JAMAIS en valeur : marque, identifiant interne, ni une DONNÉE servie par le serveur.
export const PROFILE_TEXTS = {
  deleteWarningLead: "Action ",
  deleteWarningEmphasis: "irréversible",
  deleteWarningRest:
    " : vos données personnelles sont anonymisées immédiatement. Si vous êtes le dernier gestionnaire actif du club, son espace sera supprimé après un délai de 30 jours, sauf reprise par un gestionnaire (seule la fiche publique FFBB est conservée). Les autres membres en sont prévenus.",
  deletedClubClosing: (days: number) =>
    `Compte supprimé. Vous étiez le dernier gestionnaire : l'espace du club sera supprimé dans ${days} jours, sauf reprise.`,
  deleted: "Compte supprimé.",
  // Chantier Membre (PR 3) — prévention : le SEUL gestionnaire nomme un successeur avant de partir.
  soleManagerWarning: (club: string) =>
    `Vous êtes le seul gestionnaire de ${club}. Nommez un successeur avant de supprimer votre compte, sinon l'espace du club sera fermé.`,
  nameSuccessor: "Nommer gestionnaire",
  nameSuccessorOf: (name: string) => `Nommer ${name} gestionnaire`,
  successorNamed: (name: string) => `${name} est gestionnaire.`,
  noOtherActiveMember: "Aucun autre membre actif : invitez un gestionnaire depuis l'écran Club avant de partir.",
  openClubScreen: "Ouvrir l'écran Club",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
