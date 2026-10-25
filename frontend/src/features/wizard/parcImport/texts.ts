/**
 * Chantier G PR3 — textes centralisés de la modale d'import du parc (`ParcImportModal`).
 * Une clé par intention ; interpolation par fonction typée. JAMAIS comme valeur : un libellé
 * servi par le serveur (`libelle`/`raison` sont affichés verbatim), un jour (→ shared/lib/days),
 * une heure/durée (→ time/duration), la marque (→ product).
 */
export const PARC_IMPORT_TEXTS = {
  // Coquille
  modalTitle: "Importer votre parc",
  close: "Fermer",

  // Temps 1 — le prompt à copier
  promptHeading: "1. Copiez le prompt dans votre assistant",
  promptHelp:
    "Collez ce texte dans un assistant conversationnel (ChatGPT, Claude, Gemini, Copilot…). Il décrira votre planning au bon format. Puis recopiez sa réponse ci-dessous.",
  copyPrompt: "Copier le prompt",
  promptCopied: "Prompt copié.",

  // Temps 2 — le collage + l'analyse
  pasteHeading: "2. Collez la réponse de l'assistant",
  pasteLabel: "Réponse de l'assistant (JSON)",
  pastePlaceholder: "Collez ici le texte rendu par l'assistant…",
  analyze: "Analyser",
  analyzing: "Analyse en cours…",

  // Temps 3 — l'aperçu cochable
  previewHeading: "3. Vérifiez puis cochez ce qui sera importé",
  previewHelp: "Rien n'est enregistré tant que vous n'avez pas cliqué sur Importer. Décochez ce que vous ne voulez pas.",
  venuesCreatedWithoutAddress:
    "Les gymnases à créer naîtront sans adresse — vous la renseignerez ensuite dans l'étape « Gymnases ».",
  /** Bandeau « résultat peu fiable » (D10) — informatif, jamais bloquant : l'import reste possible. */
  unreliableResult:
    "La lecture de votre planning semble incertaine : vérifiez chaque ligne de près avant d'importer, et corrigez ce qui a été mal lu.",
  /** En-tête du bloc des alertes transverses (D9) — les messages, eux, sont des données serveur. */
  alertsHeading: "Points à vérifier",
  /** Mise à jour de séances PROPOSÉE sur une équipe appariée (cochée, décochable). */
  sessionsUpdate: (de: number, a: number) => `Séances : ${de} → ${a} (mise à jour proposée)`,

  // Temps 4 — l'import
  /** Le bouton d'import chiffre ce qui sera posé (lignes cochées). */
  importAction: (n: number) => `Importer ${n} élément${n > 1 ? "s" : ""}`,
  nothingToImport: "Cochez au moins une ligne pour pouvoir importer.",
  importDone: "Import terminé.",

  // Ré-appariement (D5) — pour une ligne équipe/gymnase/coach, choisir l'entité existante à viser.
  rematchCreate: "Créer",
  rematchLabel: (libelle: string) => `Associer « ${libelle} » à un élément existant, ou le créer`,

  // Correction sur place d'un créneau (champs simples amorcés depuis le parc collé)
  editDay: (libelle: string) => `Jour — ${libelle}`,
  editStart: (libelle: string) => `Heure de début — ${libelle}`,
  editDuration: (libelle: string) => `Durée en minutes — ${libelle}`,
  /** D12 — sélecteur de gymnase d'une case (créneau) : corriger un gymnase mal lu avant l'import. */
  venueForSlot: (libelle: string) => `Gymnase du créneau — ${libelle}`,

  // Requalification d'une mutualisation (D8) — nommer/corriger le libellé du groupe mutualisé.
  groupLabel: (libelle: string) => `Nom du groupe mutualisé — ${libelle}`,
  groupLabelPlaceholder: "Nom du groupe (ex. « CEC »)",

  /** Le libellé HUMAIN d'un statut de ligne (enum serveur → libellé). Table inline. */
  statusLabel: (statut: string): string => {
    const labels: Record<string, string> = {
      apparie: "Apparié",
      suggere: "Suggéré",
      aCreer: "À créer",
      decoche: "Ignoré",
      alerte: "À vérifier",
    };
    return labels[statut] ?? statut;
  },

  /** Le titre HUMAIN d'une section (clé serveur → libellé). Table inline, pas un module à part. */
  sectionTitle: (section: string): string => {
    const titles: Record<string, string> = {
      equipes: "Équipes",
      gymnases: "Gymnases",
      creneaux: "Créneaux",
      mutualisations: "Mutualisations",
      reservations: "Réservations",
    };
    return titles[section] ?? section;
  },

  /** Le récap d'une section importée (contrat PR2, provisoire). */
  sectionRecap: (crees: number, apparies: number, misAJour: number, ignores: number): string =>
    `${crees} créé(s) · ${apparies} rattaché(s) · ${misAJour} mis à jour · ${ignores} ignoré(s)`,
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
