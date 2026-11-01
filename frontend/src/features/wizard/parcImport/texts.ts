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
  /** Libellé VISIBLE du champ « Séances » éditable d'une ligne équipe. */
  sessionsLabel: "Séances",
  /** Nom accessible CONTEXTUALISÉ du champ « Séances » d'une équipe (A11Y-28). */
  sessionsFieldLabel: (libelle: string): string => `Séances de ${libelle}`,
  /** Indication « actuellement N » à côté du champ d'une équipe APPARIÉE (ce qu'elle a aujourd'hui). */
  sessionsCurrent: (n: number): string => `actuellement ${n}`,

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
  /** Finitions 2d — placeholder COURT du champ de nom de groupe (non tronqué dans le champ). */
  groupNamePlaceholder: "Nom du groupe",
  /** P4-363 — sélecteur de gymnase de la réservation de groupe d'une mutualisation (aria-label). */
  venueForGroup: (libelle: string) => `Gymnase de la réservation de groupe — ${libelle}`,
  /** Finitions 2a — libellé VISIBLE du sélecteur de gymnase d'une mutualisation (pas qu'un aria-label). */
  venueForGroupVisible: "Gymnase",

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
      mutualisations: "Séances communes",
      reservations: "Réservations",
    };
    return titles[section] ?? section;
  },

  /** Le récap d'une section importée (contrat PR2, provisoire). */
  sectionRecap: (crees: number, apparies: number, misAJour: number, ignores: number): string =>
    `${crees} créé(s) · ${apparies} rattaché(s) · ${misAJour} mis à jour · ${ignores} ignoré(s)`,

  // ─────────── Refonte PR1 — assistant en 5 étapes (passe ui-ux-pro-max) ───────────
  /** Libellés des 2 premières étapes du rail (les 3 suivantes réutilisent `sectionTitle`). */
  stepPrompt: "Prompt",
  stepPaste: "Coller",
  /** Phrase sous l'étape « Coller » : les étapes de contenu attendent l'analyse. */
  lockedUntilAnalysis: "Les étapes suivantes s'ouvrent après l'analyse.",
  next: "Suivant",
  previous: "Précédent",
  /** En-tête d'étape focalisé au changement d'étape (`<h3 tabIndex={-1}>`). */
  stepHeading: (n: number, total: number, title: string): string => `Étape ${n}/${total} · ${title}`,
  /** Désactivation d'« Analyser » tant que rien n'est collé (A11Y-30). */
  analyzeDisabledHint: "Collez d'abord la réponse de l'assistant.",

  // Décision d'une ligne équipe / gymnase (sélecteur `Listbox` brut, groupes ordonnés)
  decisionFor: (libelle: string): string => `Décision pour « ${libelle} »`,
  /** Placeholder d'une ligne AMBIGUË — sans présélection, à trancher à la main. */
  toDecide: "À trancher…",
  matchTo: (nom: string): string => `Apparier à « ${nom} »`,
  matchToSuggested: (nom: string): string => `${nom} (suggéré)`,
  createOption: (libelle: string): string => `Créer « ${libelle} »`,
  ignoreLine: "Ignorer cette ligne",
  suggestionGroup: "Suggestion de l'analyse",
  candidatesGroup: "Candidats possibles",
  otherChoiceGroup: "Autre choix",
  allTeamsGroup: "Toutes les équipes du club",
  allVenuesGroup: "Tous les gymnases du club",

  // Import bloqué / erreur / revalidation
  /**
   * Raison de désactivation d'« Importer » : nombre de lignes à trancher (+ sans genre, PR2 ; + séances
   * communes à revoir, PR A / D13). `nAlerte` est optionnel : un appel à deux arguments reste identique.
   */
  importBlocked: (nAmbigu: number, nGenre: number, nAlerte = 0): string => {
    const parts: string[] = [];
    if (nAmbigu > 0) {
      parts.push(`${nAmbigu} ligne${nAmbigu > 1 ? "s" : ""} à trancher`);
    }
    if (nGenre > 0) {
      parts.push(`${nGenre} équipe${nGenre > 1 ? "s" : ""} sans genre`);
    }
    if (nAlerte > 0) {
      parts.push(`${nAlerte} séance${nAlerte > 1 ? "s" : ""} commune${nAlerte > 1 ? "s" : ""} à revoir`);
    }
    return 0 === parts.length ? "Cochez au moins une ligne pour pouvoir importer." : `Impossible d'importer : ${parts.join(" · ")}.`;
  },
  /** Raison visible d'« Importer » inactif : des étapes pas encore vues, ou vues avec un point en attente. */
  importStepsToVisit: (n: number): string => `${n} étape${n > 1 ? "s" : ""} à vérifier avant d'importer.`,
  verifyUpToDate: "Vérifié",
  verifyAction: "Revérifier",
  verifying: "Vérification…",
  importStaleReason: "Revérifiez vos modifications avant d'importer.",
  importVerifyingReason: "Vérification en cours.",
  verifyStatusUpToDate: "Vérification à jour.",
  verifyStatusStale: "Des modifications sont à revérifier avant d'importer.",
  verifyStatusRunning: "Vérification en cours.",
  verifyStatusFailed: "La vérification a échoué — réessayez.",
  /** Finitions 4 — raison concise de désactivation d'« Importer » (le DÉTAIL compté est, lui, VISIBLE au
   *  pied via `importBlocked`) : évite de doubler à l'identique l'annonce lecteur d'écran (A11Y-30). */
  importDisabledHint: "Importer n'est pas encore possible — voir la raison affichée.",
  /** Finitions 4 — bouton qui mène à la 1ʳᵉ ligne à trancher (change d'étape + focus son sélecteur). */
  seeBlockingLine: "Voir",
  /** Bandeau « aperçu périmé » après une modification (tone accent, sans role). */
  staleHint: (n: number): string => `Vous avez modifié ${n} ligne${n > 1 ? "s" : ""} : revérifiez pour mettre à jour les alertes et débloquer l'import.`,
  importErrorHeading: "L'import n'a pas abouti",
  importErrorGeneric: "Une erreur est survenue pendant l'import. Réessayez plus tard.",
  goToLine: "Aller à la ligne",

  // Grille de réservations par gymnase (`ParcCaseGrid`) + éditeur de créneau
  /** En-tête d'une grille de gymnase ENCORE À CRÉER. */
  gridVenueToCreate: (name: string): string => `${name} (à créer)`,
  /** Nom accessible d'une case EN ALERTE : la raison serveur, jamais la couleur seule. */
  caseToVerify: (raison: string): string => `Créneau à vérifier : ${raison}`,
  /** Infobulle d'une case de réservation. */
  caseTooltip: (nom: string, debut: string, fin: string): string => `${nom} · ${debut} → ${fin}`,
  /** Bandeau récapitulatif en tête de la grille : combien de cases sont à vérifier avant l'import. */
  gridAlertSummary: (n: number): string => `${n} créneau${n > 1 ? "x" : ""} à vérifier avant d'importer.`,
  gridGoTo: "Aller à :",
  editCaseTitle: "Corriger le créneau",
  editVenue: (libelle: string): string => `Gymnase du créneau — ${libelle}`,
  /** Finitions 3a — libellé VISIBLE du sélecteur de gymnase de l'éditeur de créneau (pas qu'un aria-label). */
  editVenueVisible: "Gymnase",
  /** Finitions 3c — libellés VISIBLES des champs de l'éditeur, iso-wizard (`VenuesStep`). */
  editDayLabel: "Jour",
  editStartLabel: "Début",
  editDurationLabel: "Durée",
  /** Nom accessible d'une case de MUTUALISATION dans la grille (lien + nom de groupe + équipes). */
  mutualisationCaseLabel: (groupe: string, equipes: string): string =>
    "" === groupe ? `Mutualisation · ${equipes}` : `Mutualisation · Groupe ${groupe} · ${equipes}`,
  /** Éditeur de case — Garder/Ignorer : cochée, la ligne est importée ; décochée, elle est ignorée. */
  caseKeep: "Garder cette ligne (l'importer)",
  save: "Enregistrer",
  cancel: "Annuler",

  // Étape Mutualisations (retours fondateur 2026-10-26) — sa propre étape du rail.
  /** EmptyHint de l'étape Séances communes vide (jamais sautée). */
  mutualisationsEmpty: "Aucune séance commune lue dans votre planning.",
  /** En-tête de l'étape Séances communes quand AU MOINS une est en alerte : combien à vérifier. */
  mutualisationsCount: (n: number): string => `${n} séance${n > 1 ? "s" : ""} commune${n > 1 ? "s" : ""} à vérifier.`,
  /** Finitions 2c — en-tête SANS alerte : « N séances communes lues » (« à vérifier » réservé aux alertes). */
  mutualisationsReadCount: (n: number): string => `${n} séance${n > 1 ? "s" : ""} commune${n > 1 ? "s" : ""} lue${n > 1 ? "s" : ""}.`,
  /** Nom accessible du contrôle Garder/Ignorer d'une séance commune. */
  mutualDecision: (libelle: string): string => `Garder ou ignorer la séance commune « ${libelle} »`,
  mutualKeep: "Garder",
  mutualIgnore: "Ignorer",
  /** D3 (incident d'un club pilote) — nom accessible du sélecteur MULTIPLE d'équipes d'une séance commune. */
  teamsForGroup: (libelle: string): string => `Équipes de la séance commune « ${libelle} »`,
  /** D3 — raison VISIBLE qui bloque quand moins de deux équipes composent la séance commune. */
  needsTwoTeams: "Une séance commune regroupe au moins deux équipes — choisissez-les.",

  // ─────────── Refonte PR2 (D9) — règle unique, genre obligatoire, étape Ententes ───────────
  /** Libellé de l'étape « Ententes (CTC) » du rail (juste après Équipes). */
  stepEntentes: "Ententes (CTC)",
  /** Nom accessible du Select Rang d'une ligne « Créer » (A11Y-28, contextualisé). */
  tierOf: (libelle: string): string => `Rang de « ${libelle} »`,
  /** Nom accessible du Select Genre d'une ligne « Créer » (A11Y-28, contextualisé). */
  genderOf: (libelle: string): string => `Genre de « ${libelle} »`,
  /** Libellés VISIBLES courts des deux Select de la ligne « Créer ». */
  tierFieldLabel: "Rang",
  genderFieldLabel: "Genre",
  /** E6 (essai fondateur 30/10) — en-têtes de colonnes de l'étape Équipes (disposition en colonnes). */
  colName: "Nom",
  colDecision: "Décision",
  /** Option vide du Select Genre — le genre est OBLIGATOIRE, à trancher. */
  genderPlaceholder: "À choisir",
  /** Libellés VISIBLES des options du Select Genre. */
  genderMasculin: "Masculin",
  genderFeminin: "Féminin",
  genderMixte: "Mixte",
  /** ⚠ sous un Select Genre vide — le genre manque, il bloque l'import (obligatoire). */
  genderRequired: "Genre requis",
  /** Phrase d'intro de l'étape Ententes. */
  ententesHelp: "Cochez les équipes à créer en entente (CTC) avec un autre club, et précisez le club partenaire.",
  /** Nom accessible de la case Entente (CTC) d'une équipe à créer (pré-cochée si suggérée). */
  ententeFor: (libelle: string): string => `Entente (CTC) pour « ${libelle} »`,
  /** Texte VISIBLE de la case Entente. */
  ententeCheckboxLabel: "En entente (CTC)",
  /** Nom accessible du champ « club partenaire » d'une entente. */
  ententePartnerFor: (libelle: string): string => `Club partenaire de l'entente — « ${libelle} »`,
  ententePartnerPlaceholder: "Club partenaire",
  /** Note en LECTURE SEULE pour une équipe déjà au club (l'import ne touche pas son entente). */
  ententeExistingManaged: "Déjà au club — l'entente se gère dans sa fiche, l'import ne la change pas.",
  /** EmptyHint de l'étape Ententes quand aucune équipe n'est à créer. */
  ententesNoCreations: "Aucune équipe à créer : rien à mettre en entente ici.",

  // Récap (remplace le contenu de l'aperçu, pas de 6ᵉ étape)
  recapNext: "Et maintenant : vos équipes, gymnases et réservations sont dans l'assistant. Poursuivez la saisie.",
  recapCreated: "Créées",
  recapMatched: "Appariées",
  recapUpdated: "Mises à jour",
  recapIgnored: "Ignorées",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
