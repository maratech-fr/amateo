/**
 * Textes du canal de signalement partagé (`shared/feedback`). Maison des libellés de `FeedbackDialog`
 * et de la capture d'écran jointe (chantier F PR3). Migré vers `texts.ts` par la PR B « Signaler depuis
 * une modale » (D14-D17) — `FeedbackDialog.tsx` est inscrit à `MIGRATED_FILES`. La migration ne recule
 * jamais.
 */
export const FEEDBACK_TEXTS = {
  attachScreenshot: "Joindre une capture",
  attachScreenshotHint: "La capture de l'écran courant est jointe pour nous aider à voir le problème (la fenêtre de signalement n'y figure pas).",
  // Drapeau / modale
  reportLabel: "Signaler",
  titleContextual: "Signaler un problème sur cet écran",
  titleFree: "Signaler un bug ou une idée",
  // Champs
  topicFieldLabel: "Type de signalement",
  topicBug: "Bug",
  topicMissingConstraint: "Contrainte manquante",
  topicIdea: "Idée",
  messageLabelContextual: "Décrivez le problème",
  messageLabelFree: "Votre message",
  // Pied
  cancel: "Annuler",
  send: "Envoyer",
  sending: "Envoi…",
  // Aides & retours
  contextualHint: "L'écran courant et quelques informations techniques seront joints automatiquement pour nous aider à reproduire.",
  messageRequired: "Le message est obligatoire.",
  tooManyReports: "Trop de signalements, réessayez plus tard.",
  sendRefused: "Le signalement n'a pas pu être envoyé. Réessayez plus tard.",
  sentToast: "Signalement envoyé — vous recevrez un accusé par email.",
} as const;
