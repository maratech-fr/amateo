/**
 * Textes centralisés des matchs — fondation PR7 (vague 1), écran ConflictLine ; étendu lot 5 PR B
 * aux écrans Adversaires (`OpponentsPage`) et Engagements FFBB (`FfbbEngagementsDialog`).
 * Clé par intention ; valeur = copie byte-identique du libellé rendu. Interpolation = fonction
 * typée. JAMAIS en valeur : jour (→ shared/lib/days), heure/durée (→ time/duration),
 * marque (→ product), identifiant interne (noms d'équipe/gymnase/adversaire restent des données).
 */

// Le libellé de la bascule d'alignement — partagé par la pastille ET l'annonce de la région live.
const LEVEL_WILL_ALIGN = "Sera aligné à la confirmation";

export const MATCHES_TEXTS = {
  venueOverlapTitle: "Deux matchs sur le même créneau",
  calendarToCheckTitle: "Calendrier à vérifier",
  calendarIncoherentTitle: "Calendrier incohérent",
  calendarIncompleteTitle: "Calendrier incomplet",
  accessWindowLostTitle: "Hors accès match",
  clubRuleViolationTitle: "Hors règle du club",
  leagueWindowViolationTitle: "Hors fenêtre autorisée par la ligue",
  teamVenueForbiddenTitle: "Gymnase interdit à l'équipe",
  awayNoFootprintTitle: "Extérieur sans heure ni habitude",
  friendlyOnMatchSlotTitle: "Amical sur un créneau de match",
  teamLevelMissingTitle: "Niveau non renseigné",
  newChip: "Nouveau",
  homeSide: "domicile",
  estimated: "estimé",
  estimatedTimeTag: "heure estimée",
  sideDetailCaption: "Détail par équipe",
  departureHeader: "Départ",
  kickoffHeader: "Coup d'envoi",
  endReturnHeader: "Fin / retour",
  durationHeader: "Durée",
  venueHeader: "Gymnase",
  dateHeader: "Date",
  slotHeader: "Créneau",
  versus: (opponent: string) => `vs ${opponent}`,
  awayAt: (city: string) => `extérieur à ${city}`,
  matchVsTrainingSummary: (match: string, training: string) => `Match ${match} × entraînement ${training}`,
  teamLevelMissingSummary: (team: string) => `${team} — ses matchs sont placés sans les créneaux de sa ligue`,
  // M11 + M6 (retours Nico, BCCL 2026-10-27) : nomme l'équipe, la date (avec son jour) et le gymnase.
  accessWindowLostPhrase: (team: string, date: string, venue: string, windows: string) =>
    `${team} — match du ${date} placé hors des accès match de ${venue} (${windows}). Déplacez le match ou ajustez l'accès.`,
  accessWindowLostConfigureLink: "Configurer les accès",
  venueUnavailableConflictTitle: (label: string | null | undefined) => `Gymnase indisponible${null != label && "" !== label ? ` (${label})` : ""}`,

  // ── Onglet Adversaires (OpponentsPage) ─────────────────────────────────────────────────────
  opponentsTitle: "Adversaires",
  opponentsIntro: "Le gymnase de chaque adversaire et le trajet depuis le siège du club — appariez une salle, corrigez, recalculez.",
  opponentsUpdating: "Mise à jour…",
  opponentsUpdate: "Mettre à jour les adversaires",
  travelComputing: "Calcul des trajets en cours…",
  travelComputed: "Trajets calculés.",
  travelComputedWithFailuresPrefix: "Trajets calculés — ",
  travelUnavailableCount: (n: number) => `${n} indisponibles`,
  travelProgress: (done: number, total: number) => `${done} / ${total} trajets calculés`,
  seatNotLocated: "Trajets indisponibles : l'adresse du siège du club n'est pas localisée.",
  fillSeat: "Renseigner le siège",
  travelFailuresBanner: (n: number) => `${n} trajet${n > 1 ? "s" : ""} n'${n > 1 ? "ont" : "a"} pas pu être calculé${n > 1 ? "s" : ""} — ces matchs n'entrent pas dans le radar.`,
  // M14 (retours Nico, BCCL 2026-10-27) — le bandeau distingue le budget worker épuisé (une relance
  // reprend) de l'introuvable (IGN muet / gymnase mal géolocalisé — une relance seule n'y change rien).
  travelFailuresByReason: (awaitingRetry: number, unroutable: number) => {
    const parts: string[] = [];
    if (awaitingRetry > 0) {
      parts.push(`${awaitingRetry} en attente de relance`);
    }
    if (unroutable > 0) {
      parts.push(`${unroutable} introuvable${unroutable > 1 ? "s" : ""}`);
    }
    return `Trajets non calculés : ${parts.join(", ")} — ces matchs n'entrent pas dans le radar.`;
  },
  // Les gymnases concernés, nommés (le gestionnaire sait lesquels corriger) — plafonnés pour rester lisibles.
  travelFailureVenues: (names: string[]) => {
    const shown = names.slice(0, 10);
    const overflow = names.length - shown.length;
    return `Gymnases : ${shown.join(", ")}${overflow > 0 ? ` + ${overflow} autre${overflow > 1 ? "s" : ""}` : ""}.`;
  },
  retryMissing: "Réessayer les manquants",
  // D7 (retours Nico, BCCL 2026-10-27, précisé fondateur) — au-delà de 60, un run calcule un POOL
  // de 60 trajets non calculés et laisse le reste « en attente de relance » ; cette invite (rendue
  // SEULEMENT s'il reste des paires en attente) dit qu'une relance reprendra le pool suivant.
  travelRelaunchToContinue: "Il reste des trajets à calculer — relancez pour continuer.",
  noAwayThisSeason: "Aucun match à l'extérieur cette saison.",
  searchOpponentOrVenue: "Rechercher un club ou un gymnase",
  filterOpponents: "Filtrer les adversaires",
  opponentFilterNoVenue: "Sans gymnase",
  opponentFilterToPair: "À apparier",
  opponentFilterTravelUnavailable: "Trajet indisponible",
  opponentFilterAll: "Tous",
  unitClubs: "clubs",
  unitVenues: "salles",
  segmentAriaLabel: (label: string, count: number, unit: string) => `${label} — ${count} ${unit}`,
  noSearchResult: (query: string) => `Aucun adversaire pour « ${query} ».`,
  columnLogo: "Logo",
  columnTravel: "Trajet",
  columnFixtures: "Rencontres",
  columnActions: "Actions",
  clubsWithoutGymHint: (n: number) => `${n} club${n > 1 ? "s" : ""} adverse${n > 1 ? "s" : ""} sans gymnase — leurs rencontres n'entrent pas dans le radar.`,
  noVenueKnown: "Aucun gymnase connu",
  addVenue: "Ajouter un gymnase",
  editVenue: "Modifier le gymnase",
  mergeInto: (label: string) => `Fusionner dans « ${label} »`,
  removeVenue: "Retirer ce gymnase",
  travelInProgress: "en cours…",
  travelUnavailable: "indisponible",
  toPair: "à apparier",
  pair: "Apparier",
  venueActionsMenu: (venueLabel: string, clubName: string) => `Actions pour ${venueLabel} — ${clubName}`,
  venueLabelQuoted: (label: string) => `« ${label} »`,
  fixtureCount: (n: number) => `${n} rencontre${n > 1 ? "s" : ""}`,
  venueSubline: (n: number, minutes: number | null, approximated: boolean) => `${n} rencontre${n > 1 ? "s" : ""}${null !== minutes ? ` · ${approximated ? "~" : ""}${minutes} min` : ""}`,
  removeVenueTitle: (venueLabel: string, clubName: string) => `Retirer « ${venueLabel} » de ${clubName} ?`,
  removeConfirm: "Retirer",
  venueRemoved: "Gymnase retiré.",
  mergeVenueTitle: (sourceLabel: string, targetLabel: string) => `Fusionner « ${sourceLabel} » dans « ${targetLabel} » ?`,
  mergeConfirm: "Fusionner",
  mergeSuccess: (targetLabel: string, count: number) => `Fusionné — « ${targetLabel} » porte ${count} rencontre${count > 1 ? "s" : ""}.`,
  removeDescNoFallback: (clubName: string, count: number) => `${clubName} n'aura plus aucun gymnase connu. Ses ${count} rencontre${count > 1 ? "s" : ""} sortiront du radar de conflits et n'auront plus de trajet estimé.`,
  removeDescNoFixtures: "Aucune rencontre n'est rattachée à ce gymnase.",
  removeDescFallback: (count: number, fallback: string) => `${count} rencontre${count > 1 ? "s" : ""} retomberont sur « ${fallback} », le gymnase le plus fréquent de ce club ; leur trajet deviendra approché. Le catalogue fédéral n'est pas modifié.`,
  mergeDesc: (sourceCount: number, sourceLabel: string, targetLabel: string, resulting: number) => `Les ${sourceCount} rencontre${sourceCount > 1 ? "s" : ""} de « ${sourceLabel} » rejoindront « ${targetLabel} », qui en portera ${resulting}. Le libellé « ${sourceLabel} » restera reconnu à l'import.`,

  // ── Modale Engagements FFBB (FfbbEngagementsDialog) ────────────────────────────────────────
  ffbbDialogTitle: "Engagements FFBB",
  ffbbClose: "Fermer",
  ffbbSaving: "Enregistrement…",
  ffbbConfirm: (n: number) => `Confirmer ${n} appariement${n > 1 ? "s" : ""}`,
  ffbbIntroLead: "Les équipes engagées telles que la ligue les connaît — rattachez chacune à votre équipe puis confirmez en bloc. À chaque nouvelle phase, ré-ouvrez : tout est pré-rempli.",
  ffbbIntroFbiSuggestion: "Les rattachements marqués proviennent de votre dernier import FBI. ",
  ffbbIntroLeagueData: "Données de la ligue — un écart se corrige auprès d'elle.",
  ffbbUnavailable: "FFBB indisponible — réessayez plus tard.",
  ffbbNoEngagement: "Aucun engagement trouvé pour cette saison (les poules sortent généralement après le 20 juillet).",
  ffbbAttachedCount: (attached: number, total: number) => `${attached} rattachée${attached > 1 ? "s" : ""} sur ${total}`,
  ffbbTeamForCompetition: (competition: string) => `Équipe pour ${competition}`,
  ffbbNotPaired: "Non rattachée",
  ffbbSuggestedFromImport: "suggéré depuis l'import FBI",
  levelWillAlignOnConfirm: LEVEL_WILL_ALIGN,
  levelAlignLiveAnnounce: (n: number) => `${n} alignement${n > 1 ? "s" : ""} de niveau en attente. ${LEVEL_WILL_ALIGN}.`,
  levelMissing: "Niveau non renseigné",
  levelOnFile: (label: string) => `Fiche : ${label}`,
  levelFillAction: (label: string) => `Renseigner : ${label}`,
  levelAlignAction: (label: string) => `Aligner sur ${label}`,
  cancel: "Annuler",

  // ── Modale Import FBI (ImportFbiDialog) — chantier A PR 2 ───────────────────────────────────
  importFbiModalLabel: "Importer FBI",
  importFbiTitle: "Importer un export FBI",
  importFbiClose: "Fermer",
  importFbiImport: "Importer",
  importFbiImportAnyway: "Importer quand même",
  importFbiIntro: "L’export FBI global du club (« Saisie des résultats », .xlsx). Chaque division se relie une seule fois à l’une de vos équipes ; les matchs connus sont mis à jour, jamais dupliqués.",
  importFbiFileLabel: "Fichier FBI (.xlsx)",
  importFbiFileAria: "Fichier FBI",
  importFbiAnalyzing: "Analyse du fichier…",
  importFbiReadError: "Le fichier n'a pas pu être lu — est-il ouvert dans un autre logiciel ?",
  importFbiAnalysisSummary: (rows: number, divisions: number, exempted: number) =>
    `${rows} rencontre${rows > 1 ? "s" : ""} · ${divisions} division${divisions > 1 ? "s" : ""}${exempted > 0 ? ` · ${exempted} exempt${exempted > 1 ? "s" : ""}` : ""}`,
  importFbiFamiliesAria: "Familles de divisions",
  importFbiSuggestedByFfbb: "proposé par la FFBB",
  importFbiTeamFor: (division: string) => `Équipe pour ${division}`,
  importFbiAssociateTo: "Associer à…",
  importFbiRowMatchCount: (n: number) => `${n} match${n > 1 ? "s" : ""}`,
  importFbiPouleCheckAria: "Vérification des poules",
  importFbiOutOfPoule: (division: string, opponents: string) => `${division} : hors poule — ${opponents}`,
  importFbiAnalysisErrorsAria: "Erreurs d'analyse",
  importFbiReportSummary: (created: number, updated: number, unchanged: number, exempted: number) =>
    `${created} créé${created > 1 ? "s" : ""} · ${updated} mis à jour · ${unchanged} inchangé${unchanged > 1 ? "s" : ""}${exempted > 0 ? ` · ${exempted} exempt${exempted > 1 ? "s" : ""}` : ""}`,
  importFbiWarningsAria: "Avertissements de l'import",
  importFbiCompleteness: (name: string, imported: number, expected: number) => `${name} : ${imported}/${expected} journées — fichier partiel ou phase pas encore sortie.`,
  importFbiUnmapped: (list: string) => `Non associées : ${list}`,
  importFbiReportErrorsAria: "Erreurs de l'import",
  importFbiDeviations: (n: number) => `${n} écart${n > 1 ? "s" : ""} consigné${n > 1 ? "s" : ""} dans Importer — à traiter dans la file.`,
  importFbiOpenQueue: "Ouvrir la file",
  importFbiUnmappedTitle: "Des divisions restent sans équipe",
  importFbiUnmappedConfirm: (list: string) => `Les rencontres de ${list} ne seront pas importées — elles resteront à associer au prochain dépôt. Importer quand même ?`,
  // Section « Salles à domicile » : chaque salle à domicile → un gymnase, ou terrain neutre.
  importFbiVenuesTitle: "Salles à domicile",
  importFbiVenuesIntro: "Chaque salle à domicile se relie à un gymnase du club — ou se déclare « terrain neutre » si vous ne jouez pas chez vous.",
  importFbiVenueFor: (label: string) => `Gymnase pour ${label}`,
  importFbiHomesWithoutVenueFor: (division: string) => `Gymnase pour les domiciles sans salle de ${division}`,
  importFbiChooseVenue: "Associer à un gymnase…",
  importFbiNeutralGround: "Terrain neutre (pas chez nous)",
  importFbiHomesWithoutVenueCount: (n: number) => `${n} domicile${n > 1 ? "s" : ""} sans salle`,
  importFbiVenueMissingReason: "Associez un gymnase à chaque salle à domicile (ou déclarez-la « terrain neutre ») pour pouvoir importer.",
  importFbiVenuesRemaining: (n: number) => `${n} salle${n > 1 ? "s" : ""} encore sans gymnase — l'import reste bloqué.`,
  // ── MatchesLayout (en-tête + navigation du module, migré P4-356) ──────────────────────────────
  pageTitle: "Matchs",
  screenContext: (tab: string) => `Matchs · ${tab}`,
  lockedTitle: "Matchs verrouillés",
  lockedHint: "Validez d'abord votre planning de saison (accueil → Ouvrir) pour débloquer les matchs.",
  navAriaLabel: "Espaces matchs",
  tabConflicts: "Conflits",
  tabConflictsBadge: (n: number) => `Conflits · ${n}`,
  tabCalendar: "Calendrier",
  tabImport: "Importer",
  tabImportBadge: (n: number) => `Importer · ${n}`,
  tabConfiguration: "Configuration",
  tabOpponents: "Adversaires",
  tabTypicalWeek: "Semaine type",
  tabConstraints: "Contraintes",
  // Chantier H — écran MATCHS : l'indisponibilité d'un coach SEULEMENT adjoint ne bloque jamais un
  // MATCH (le mot juste sur cet écran), elle pèse seulement un peu au placement.
  adjointNeverBlocksMatch: "l'indisponibilité d'un adjoint ne bloque jamais un match — elle pèse peu au placement",
  // Chantier H — le tag d'un conflit de coach ADOUCI : un adjoint disponible peut tenir ce côté.
  // Rendu avec une icône (jamais la couleur seule, a11y).
  coachReliefTag: "Un adjoint peut couvrir",

  // ── Lisibilité BCCL (PR1, 2026-10-27) — écrans migrés dans le même lot (la migration ne recule jamais) ──
  // WeekendGridLegend
  legendHabit: "Habitude — fenêtre protégée",
  legendTravel: "Trajet aller-retour — depuis/vers le siège du club",
  // LeagueValidation (rattrapage « déjà dans FBI »)
  leagueTreatInQueue: "Traiter dans la file",
  entryDeadlinesLink: "Échéances de saisie",
  entryDeadlinesPrompt: "Des échéances de saisie à renseigner ? Complétez-les pour ne rien manquer.",
  // ReviewQueue (file d'import = acquittement)
  reviewShowTreated: "Afficher les traitées",
  reviewHideAway: "Masquer les extérieurs",
  reviewNothingToTreatTitle: "Rien à traiter",
  // ReviewQueueRow
  rowKickoffNotPublished: "heure non publiée",
  rowNeutralGround: "Terrain neutre",
  rowAmateoColumn: "Amateo",
  rowKeepAmateo: "Garder Amateo",
  rowChooseVenue: "Choisir un gymnase",
  rowAttach: "Rattacher",
  rowConfirm: "Confirmer",
  rowCancel: "Annuler",
  // ConflictsPage / ConflictRadar
  conflictsImportMissingTitle: "Importer les matchs manquants",
  noSeasonConflictsTitle: "Aucun conflit sur la saison",
  homeFilterGroupLabel: "Conflits à afficher",
  groupByLabel: "Regrouper par",
  familiesGroupLabel: "Familles",
  treatmentGroupLabel: "Traitement",
  radarToTreatSr: "à traiter",
  // MatchWindowsEditor (accès match d'un gymnase) — PR2 « jours fériés » (8ᵉ ligne)
  matchWindowsLoadError: "Les fenêtres d’accès match n’ont pas pu être chargées.",
  matchWindowsEmpty:
    "Aucune fenêtre d’accès match — ce gymnase n’accueille pas de matchs. Ajoutez les créneaux qui vous sont accordés les jours de match.",
  matchWindowDayLabel: "Jour",
  matchWindowStartLabel: "Début",
  matchWindowEndLabel: "Fin",
  matchWindowDayFieldName: "Jour de la fenêtre match",
  matchWindowStartFieldName: "Début de la fenêtre match",
  matchWindowEndFieldName: "Fin de la fenêtre match",
  matchWindowHolidayOption: "Jours fériés",
  matchWindowAdd: "Ajouter la fenêtre match",
  matchWindowRangeInvalid: "La fenêtre doit finir après son début, le même jour.",
  matchWindowDelete: (what: string) => `Supprimer la fenêtre ${what}`,
  matchWindowDeleteTitle: "Supprimer cette fenêtre d’accès match ?",
  matchWindowDeleteDescription: (what: string) => `La fenêtre ${what} sera retirée de ce gymnase.`,
  matchWindowDeleteConfirm: "Supprimer",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
