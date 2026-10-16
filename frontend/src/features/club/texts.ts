// Textes centralisés de la feature club (lot 5 PR E — fondation pour ClubPage).
// Valeurs = copie BYTE-IDENTIQUE des littéraux rendus par l'écran. Convention : clé camelCase par
// intention ; interpolation par fonctions typées. Pas de lib i18n. JAMAIS en valeur : jour
// (→ shared/lib/days), heure/durée (→ time/duration), marque (→ product), identifiant interne,
// ni une DONNÉE servie par le serveur.
export const CLUB_TEXTS = {
  // IdentitySection (Visuel)
  brandingIntro: "Logo et couleur d'accent du club. La couleur personnalise les boutons, liens et éléments actifs de toute l'interface.",
  chooseLogo: "Choisir un logo",
  recrop: "Recadrer",
  recropLogoTitle: "Recadrer le logo",
  removeLogoLabel: "Retirer",
  logoFormatHint: "PNG / JPEG / WebP, ≤ 500 Ko.",
  logoColorsLabel: "Couleurs du logo :",
  accentHint: "Couleur d'accent — une pour le thème clair, une pour le sombre. Elle habille boutons, liens et éléments actifs de toute l'interface.",
  accentLight: "Thème clair",
  accentDark: "Thème sombre",
  resetColors: "Réinitialiser les couleurs",

  // DangerSection
  dangerIntro: "Supprime définitivement toutes les données saisies (équipes, gymnases, coachs, contraintes, plannings) pour repartir de zéro. Le club et les comptes membres sont conservés.",
  resetClubButton: "Réinitialiser le club",
  resetClubConfirmTitle: "Réinitialiser le club ?",
  resetClubConfirmDescription: "Toutes les équipes, gymnases, coachs, contraintes et plannings seront définitivement supprimés. Cette action est irréversible.",
  deleteAllConfirmLabel: "Tout supprimer",

  // ClubShortNameField
  shortNameLabel: "Nom court",
  shortNamePlaceholder: "Ex. BC Vallée",
  save: "Enregistrer",
  cancel: "Annuler",
  shortNameHint: "Utilisé dans les e-mails envoyés aux coachs. Vide = le nom complet.",

  // ClubSiegeSubsection
  siegeTitle: "Siège du club",
  siegeHint: "L'adresse du siège sert à estimer les trajets de vos coachs vers les adversaires.",
  siegeAddressPlaceholder: "Adresse du siège",
  siegeAddressLabel: "Adresse du siège",
  siegeLocated: "Siège localisé",
  siegeUnlocated: "Siège non localisé — les trajets vers les adversaires ne sont pas estimés.",

  // ClubInfoSection (Identité + Contact)
  identityTitle: "Identité",
  refreshFromFfbb: "Actualiser depuis la FFBB",
  officialNameLabel: "Nom officiel (FFBB)",
  ffbbCodeLabel: "Code FFBB",
  leagueLabel: "Ligue",
  schoolZoneLabel: "Zone de vacances",
  committeeLabel: "Comité",
  contactTitle: "Contact du club",
  ffbbUnavailableWithHint: "Données FFBB non disponibles — essayez « Actualiser depuis la FFBB ».",
  ffbbContactsIntro: "Coordonnées récupérées automatiquement depuis la FFBB (lecture seule).",

  // ContactBlock (Contacts FFBB)
  ffbbUnavailable: "Données FFBB non disponibles.",

  // UsageTable (Statistiques des gymnases)
  usageByVenue: "Par gymnase",
  usageByLevel: "Par niveau",
  usageVenueCol: "Gymnase",
  usageLevelCol: "Niveau",
  usageReal: "Réalisé",
  usageProjected: "À venir",
  usageTotal: "Total",

  // VenueStatsSection
  statsNoPlan: "Les statistiques se calculent sur le planning en vigueur — validez d'abord le planning de saison.",
  statsLoading: "Chargement des statistiques…",
  statsLoadError: "Les statistiques n'ont pas pu être chargées.",
  statsEmptyRange: "Le planning en vigueur ne place aucune séance sur cette plage.",

  // OfferSection
  yourOffer: "Votre offre",
  offersLoadError: "Les offres n'ont pas pu être chargées.",
  offersLoading: "Chargement des offres…",

  // ExportClubSection
  exportIntro: "Téléchargez une copie complète des données du club (saisons, équipes, coachs, gymnases, contraintes, plannings, matchs…) au format JSON — conformité RGPD (portabilité).",
  exportClubData: "Exporter les données du club",

  // ClubHub (en-tête + titres d'accordéon + intros de section)
  clubManagementTitle: "Gestion du club",
  sectionOffer: "Offre",
  offerSectionHint: "Votre offre actuelle et les paliers disponibles.",
  sectionMembershipRequests: "Demandes d'adhésion",
  membershipRequestsHint: "Approuvez ou refusez les personnes qui souhaitent rejoindre votre club.",
  sectionInvitations: "Invitations",
  invitationsHint: "Invitez une adresse e-mail à rejoindre votre club. L'invité crée son compte (ou se connecte) et devient membre, sans passer par la file d'approbation.",
  sectionMembers: "Membres",
  membersHint: "Gérez les rôles de vos membres, désactivez ou réactivez un accès. Un club garde toujours au moins un gestionnaire.",
  sectionVisual: "Visuel",
  sectionClubInfo: "Informations du club",
  sectionFfbbContacts: "Contacts FFBB",
  sectionVenueStats: "Statistiques des gymnases",
  sectionExport: "Exporter les données",
  sectionReset: "Réinitialiser le club",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
