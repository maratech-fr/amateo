/**
 * Textes centralisés de l'authentification — maison unique des libellés FR de la feature
 * `auth` (fondation textes centralisés, `.claude/rules/frontend.md`). Clé camelCase par
 * INTENTION ; valeur = copie byte-identique du libellé rendu. JAMAIS en valeur : un jour
 * (→ shared/lib/days), une heure/durée (→ time/duration), la marque (→ product), un
 * identifiant interne, ni une DONNÉE servie par le serveur (nom/ville du club résolus).
 */
export const AUTH_TEXTS = {
  // Étape « sport » (basket présélectionné).
  sportTitle: "Votre sport",
  sportDescription: "Choisissez le sport de votre club. D'autres arrivent bientôt.",
  soon: "bientôt",
  continue: "Continuer",
  previous: "Précédent",
  // Pied partagé des deux étapes.
  alreadyHaveAccount: "Déjà un compte ?",
  signIn: "Se connecter",
  // Étape « détails » — en-tête.
  createAccountTitle: "Créer un compte",
  detailsDescription:
    "Le code club FFBB identifie votre club. S'il existe déjà, votre demande sera soumise à l'approbation du gestionnaire.",
  // Champs.
  firstName: "Prénom",
  lastName: "Nom",
  email: "Email",
  araLabel: "Code club FFBB",
  araPlaceholder: "Ex. ARA0069013",
  createAccount: "Créer le compte",
  // Nom du club + résolution FFBB (améliorations légères #1).
  clubNameLabel: "Nom du club",
  clubNameHint: "(si nouveau club)",
  clubNameAutoHint: "Récupéré automatiquement depuis la FFBB si le code est reconnu.",
  clubNotRecognized: "Code non reconnu à la FFBB — vérifiez la saisie.",
  youAreClub: "Vous êtes le club",
  notMyClub: "Ce n'est pas mon club",
  // Amendement fondateur 2026-10-23 — après « Ce n'est pas mon club » : une SEULE phrase qui
  // invite à revérifier le code en NOMMANT le club reconnu (le champ nom ne réapparaît pas).
  checkYourCode: (name: string) => `Vérifiez votre code : il correspond au club ${name}.`,
  // Écran « vérifiez votre e-mail ».
  sentDescription: "Vérifiez votre boîte mail pour activer votre compte.",
  // Écrans du raccourci démo.
  demoTitle: "Démonstration",
  demoExplainBody:
    "Dans la vraie vie, vous confirmez votre e-mail et la boîte officielle de votre club valide votre inscription. Ce clic fait les deux.",
  demoReadyTitle: "Votre club est prêt",
  demoReadyBody: "Connectez-vous normalement.",
  // Messages d'erreur / validation (bannière de formulaire).
  emailRequired: "Renseignez une adresse email valide.",
  emailInvalid: "Adresse email invalide.",
  passwordInvalid: "Le mot de passe ne respecte pas les critères.",
  consentRequired: "Vous devez accepter les conditions d'utilisation et la politique de confidentialité.",
  demoWrongPassword: "Mot de passe incorrect pour le compte de démonstration.",
  demoTeardownRefused: "Impossible de remplacer votre club de démonstration précédent. Réessayez, ou contactez le support.",
  demoAraTaken: "Ce code FFBB est déjà utilisé par un autre club : nous ne le remplaçons pas.",

  // Page PUBLIQUE d'approbation (`ClubApprovalPage`) — ouverte par l'adresse officielle du club.
  // Chantier Membre (PR 3) : création, reprise (club sans gestionnaire) ou promotion
  // (gestionnaires sans réponse), selon le `kind` servi par le serveur.
  clubApprovalCreateTitle: "Demande de création d'espace",
  clubApprovalCreateDescription: (product: string) => `Un gestionnaire demande à créer l'espace ${product} de votre club.`,
  clubApprovalCreateBody: "demande à créer et gérer l'espace de",
  clubApprovalCreateHelp:
    "Si cette personne est bien un gestionnaire de votre club, approuvez : l'espace sera créé et elle en deviendra gestionnaire. Sinon, refusez — elle en sera informée.",
  clubApprovalRepriseTitle: "Demande de reprise d'espace",
  clubApprovalRepriseDescription: (product: string) => `Un membre demande à reprendre l'espace ${product} de votre club.`,
  clubApprovalRepriseBody: "demande à reprendre la gestion de l'espace de",
  clubApprovalRepriseBodyEnd: ", qui n'a plus de gestionnaire.",
  clubApprovalRepriseHelp:
    "Si cette personne est bien un gestionnaire de votre club, approuvez : elle deviendra gestionnaire et la suppression de l'espace sera annulée. Sinon, refusez — elle gardera un accès en consultation.",
  clubApprovalPromotionTitle: "Demande de gestionnaire",
  clubApprovalPromotionDescription: (product: string) => `Un membre demande à devenir gestionnaire de l'espace ${product} de votre club.`,
  clubApprovalPromotionBody: "demande à devenir gestionnaire de l'espace de",
  clubApprovalPromotionBodyEnd: ". Les gestionnaires actuels n'ont pas répondu depuis 14 jours.",
  clubApprovalPromotionHelp:
    "Si vous approuvez, cette personne devient gestionnaire avec les gestionnaires actuels. Sinon, refusez — elle gardera un accès en consultation.",
  clubApprovalExpiresOn: (date: string) => `Sans réponse, la demande expire le ${date}.`,
  clubApprovalLoading: "Chargement…",
  clubApprovalExpired: (product: string) => `Cette demande a expiré (7 jours sans réponse). Le demandeur peut se rapprocher du support ${product}.`,
  clubApprovalInvalid: "Ce lien est invalide — ou la demande a déjà été traitée.",
  clubApprovalDecisionError: "La décision n'a pas pu être enregistrée — réessayez.",
  clubApprovalApprove: "Approuver",
  clubApprovalRefuse: "Refuser",
  clubApprovalClosePage: "Vous pouvez fermer cette page.",
  clubApprovalCreatedTitle: "Espace créé",
  clubApprovalCreatedBody: "Le demandeur est prévenu par email — l'espace de votre club est prêt.",
  clubApprovalRepriseApprovedTitle: "Reprise approuvée",
  clubApprovalRepriseApprovedBody: "Le demandeur est prévenu par email — il est gestionnaire de l'espace.",
  clubApprovalPromotionApprovedTitle: "Gestionnaire nommé",
  clubApprovalPromotionApprovedBody: "Le demandeur est prévenu par email.",
  clubApprovalSupersededTitle: "Demande dépassée",
  clubApprovalSupersededTakenOver: "Un gestionnaire a déjà repris cet espace ; le demandeur est prévenu.",
  clubApprovalSupersededCreate: "Le club existe déjà : la demande est transmise à ses gestionnaires.",
  clubApprovalRefusedTitle: "Demande refusée",
  clubApprovalRefusedBody: "Le demandeur est prévenu par email.",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
