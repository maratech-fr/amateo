/** Textes de la page publique de collecte (lien personnel du destinataire, sans compte). */

/** « Lundi, mardi et jeudi » : la liste lue dans une phrase. */
const inSentence = (labels: string[]): string => (labels.length < 2 ? (labels[0] ?? "") : `${labels.slice(0, -1).join(", ")} et ${labels[labels.length - 1]}`);

const absentList = (people: { firstName: string; dayLabels: string[] }[]): string => people.map((p) => `${p.firstName} (${p.dayLabels.join(", ")})`).join(", ");

export const COACH_WISHES_PUBLIC_TEXTS = {
  // États de la page
  loadingTitle: "Vos disponibilités",
  loadingDescription: "Chargement…",
  loadingHint: "Un instant…",
  expiredTitle: "Lien expiré",
  expiredDescription: "La collecte est close.",
  expiredBody: "La période de collecte est terminée. Rapprochez-vous de votre club si vous souhaitez encore transmettre vos disponibilités.",
  invalidTitle: "Lien invalide",
  invalidDescription: "Ce lien n'est pas reconnu.",
  invalidBody: "Ce lien est invalide. Vérifiez qu'il est complet, ou demandez-en un nouveau à votre club.",
  noTeamTitle: "Aucune équipe concernée",
  noTeamBody: "Aucune de vos équipes n'est concernée par cette collecte pour le moment. Rapprochez-vous de votre club.",
  thanksTitle: "Merci !",
  thanksOneTeam: "Les disponibilités de votre équipe sont enregistrées.",
  thanksManyTeams: "Les disponibilités de vos équipes sont enregistrées.",
  thanksBody: (deadline: string) => `C'est transmis à votre club. Vous pouvez revenir modifier ce formulaire jusqu'au ${deadline}.`,
  poweredBy: "Propulsé par",
  poweredByLink: "découvrir",

  // Parcours
  greeting: (firstName: string) => `Bonjour ${firstName}`,
  fallbackTeamTitle: "Votre équipe",
  recapTitle: "Récapitulatif",
  periodPill: (start: string, end: string) => `Vacances du ${start} au ${end}`,
  deadlinePill: (deadline: string) => `Répondre avant le ${deadline}`,
  previewNotice: (firstName: string) => `Aperçu — ce que voit ${firstName}. L'envoi est désactivé.`,
  splashAnnouncement: "Préparation de votre formulaire…",
  alreadyAnswered: (respondedOn: string, deadline: string) => `Vous avez déjà répondu le ${respondedOn}. Révisable jusqu'au ${deadline}.`,
  introResponsibility: "Vous répondez pour toute l'équipe : ses séances, les jours où elle peut s'entraîner, et les disponibilités de chaque membre du staff.",
  introPeriod: (period: string) => `Votre club prépare le planning de ${period}.`,
  introBulletPerTeam: "Pour chaque équipe : nombre de séances, jours souhaités, jours où l'équipe peut s'entraîner et disponibilités de chaque membre du staff, semaine par semaine.",
  introBulletDuration: "Comptez 5 minutes : vos réponses partent en une seule fois, à la fin.",
  introBulletRevise: "Vous pouvez revenir modifier jusqu'à la date limite.",
  introWish: "C'est un souhait, pas un engagement : le club arbitre selon les gymnases disponibles.",
  reviseButton: "Réviser mes réponses",
  startButton: "Commencer",
  previous: "Précédent",
  next: "Suivant",
  nothingToReport: "Rien à signaler",
  backToRecap: "Revenir au récapitulatif",
  previewSendDisabled: "Aperçu : l'envoi est désactivé",
  confirmUnchanged: "Confirmer sans modification",
  validateAndSend: (count: number) => `Valider et envoyer${count > 1 ? ` (${count})` : ""}`,
  progressLabel: "Progression",
  progressStart: "Début",
  progressRecap: "Récap",
  progressTeam: "Équipe",

  // Refus à l'envoi : le brouillon est gardé, on dit quoi faire
  sendFailed: "Envoi impossible pour le moment. Réessayez dans un instant.",
  refusedPerimeter: "Une de vos équipes n'est plus dans votre périmètre. Rechargez la page : vos saisies sont conservées.",
  refusedStaff: "Le staff d'une équipe a changé. Rechargez la page : vos saisies sont conservées.",
  refusedWeeks: "Les semaines de la collecte ont changé. Rechargez la page : vos saisies sont conservées.",
  refusedOther: (message: string) => `${message} Rechargez la page : vos saisies sont conservées.`,

  // Étape d'une équipe
  mutualizationLegend: "Mutualisation (facultatif)",
  mutualizationHelp: (team: string) => `Partagez une ou plusieurs séances de ${team} avec une autre équipe. Le club arbitre ensuite.`,
  mutualizeWith: (team: string, partner: string, isBridge: boolean) => `Mutualiser ${team} avec ${partner}${isBridge ? " (passerelle)" : ""}`,
  bridgePill: "passerelle",
  sharedSlotsLabel: "Séances à mutualiser",
  sharedSlotsAria: (team: string) => `Séances à mutualiser — ${team}`,
  weekLegend: (team: string, week: string) => `${team} · semaine du ${week}`,
  byAnotherCoach: "Un autre coach a déjà répondu pour cette équipe cette semaine (sa saisie est pré-remplie ci-dessous). Votre envoi remplacera sa version — précisez les particularités dans le commentaire.",
  slotsLabel: "Séances souhaitées",
  slotsAria: (team: string, week: string) => `Séances souhaitées — ${team}, semaine du ${week}`,
  teamDaysLegend: "Jours où l'équipe peut s'entraîner",
  teamDaysHelp: "Retirez un jour pour que l'équipe ne s'entraîne pas ce jour-là.",
  wishedDaysLegend: "Jours souhaités",
  wishedDayDisabledReason: "Jour non disponible : rendez-le disponible pour pouvoir le souhaiter.",
  staffTitle: "Disponibilités du staff",
  staffHelp: "Tout le monde est disponible par défaut. Retirez les jours d'absence de chacun. Une absence vaut pour la personne, pour toutes ses équipes.",
  staffMemberLegend: (firstName: string, lastName: string, role: "MAIN" | "ASSISTANT") => `${`${firstName} ${lastName}`.trim()} — ${"MAIN" === role ? "principal" : "adjoint"}`,
  absentElsewhere: (firstName: string, dayLabels: string[]) =>
    `${firstName} est déjà noté absent ${dayLabels.length > 1 ? "les" : "le"} ${inSentence(dayLabels)} cette semaine, pour une autre équipe. Cette absence vaut pour toutes ses équipes. Si c'est une erreur, prévenez le club.`,
  keepSeasonSlots: "Garder mes créneaux habituels",
  commentLabel: "Commentaire",
  commentAria: (team: string, week: string) => `Commentaire — ${team}, semaine du ${week}`,

  // Récapitulatif
  recapNothing: "aucune modification",
  recapMutualization: (slots: number, partners: string[]) => `mutualise ${slots} séance${slots > 1 ? "s" : ""} avec : ${partners.join(", ")}`,
  recapWeek: (week: string, summary: string) => `semaine du ${week} : ${summary}`,
  recapEdit: "Modifier",
  recapPartnerFallback: "équipe",
  recapSlots: (slots: number) => `${slots} séance${slots > 1 ? "s" : ""}`,
  recapWished: (dayLabels: string[]) => `souhaité ${dayLabels.join(", ")}`,
  recapTeamClosed: (dayLabels: string[]) => `équipe fermée ${dayLabels.join(", ")}`,
  recapAbsent: (people: { firstName: string; dayLabels: string[] }[]) => `${people.length > 1 ? "absents" : "absent"} : ${absentList(people)}`,
  recapKeepsSlots: "garde ses créneaux habituels",
} as const;
