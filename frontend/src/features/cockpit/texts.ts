// Textes centralisés de la feature cockpit (PR7 — fondation des textes centralisés).
// Valeurs = copie BYTE-IDENTIQUE des littéraux rendus par les écrans (RadarPanel, DayDialog).
// Convention : clé camelCase par intention ; interpolation par fonctions typées. Pas de lib i18n.
// JAMAIS en valeur : jour (→ shared/lib/days), heure/durée (→ time/duration), marque (→ product),
// identifiant interne.
import { dayLabelLong } from "@/shared/lib/days";

const plural = (n: number, one: string, many: string): string => (1 === n ? one : many);

export const COCKPIT_TEXTS = {
  toHandle: "À traiter",
  resumePlanning: "Planning en cours — à finaliser",
  weekPlanningToFinalize: "Planning de semaine à finaliser",
  whichWeeksToAdjust: "Quelles semaines ajuster ?",
  schoolZoneToSet: "Zone scolaire à renseigner",
  setZoneHint: "Renseignez la zone pour voir les vacances.",
  setZoneCta: "Renseigner",
  seasonPlanToValidate: "Planning de saison à valider",
  validateSeasonPlanHint: "Validez le planning de saison pour débloquer les ajustements.",
  validateSeasonPlanCta: "Valider le planning",
  nothingToReport: "Rien à signaler",
  allClear: "Rien à l'horizon. Tout roule.",
  impactNotAssessedRetry: "Impact non évalué · réessayez",
  secondaryPlanValidated: "Planning secondaire validé",
  loadingItemsToHandle: "Chargement des éléments à traiter…",
  cannotLoadItemsToHandle: "Impossible de charger les éléments à traiter — cette liste est peut-être incomplète.",
  entryWillBeRemoved: "Cette entrée sera retirée du calendrier.",
  publicHolidayTitle: "Jour férié",
  holidayTag: "Vacances",
  outsideSeasonNothingToAdapt: "Hors de la saison en cours — rien à adapter.",
  closureMarker: "⛔",
  cutoffMarker: "🛑",
  resume: "Reprendre",
  viewPlanning: "Voir le planning",
  adapt: "Adapter",
  adjust: "Ajuster",
  consult: "Consulter",
  nothingThisDay: "Rien ce jour-là — la semaine type tourne normalement.",
  eventCta: "Événement",
  reportUnavailabilityCta: "Signaler une indisponibilité",
  createFreePeriodSoon: "Créer une période libre : à venir. En attendant, utilisez « Signaler une indisponibilité » ou le radar vacances.",
  createPeriodCta: "Créer une période…",
  close: "Fermer",
  eventTitleLabel: "Titre de l'événement",
  eventTitlePlaceholder: "Titre (AG, tournoi…)",
  save: "Enregistrer",
  unavailableVenueLabel: "Gymnase indisponible",
  unavailableVenuePlaceholder: "Gymnase indisponible…",
  unavailabilityTitleLabel: "Intitulé de l'indisponibilité (optionnel)",
  optionalTitlePlaceholder: "Intitulé (optionnel)",
  cutoffTitleLabel: "Intitulé de la coupure (optionnel)",
  cutoffTitlePlaceholder: "Intitulé (optionnel, ex. Coupure de Noël)",
  cutoffReminderHint: "Rappel affiché au calendrier (🛑) et au radar — le planning de saison reste inchangé, rien à générer.",
  redateClosureHint: "Déplacez la fenêtre de cette fermeture. Son planning devra être régénéré.",
  redateEffectsTitle: "Effets de ce re-datage",
  redateSplitHint: "Déplacez la fenêtre de cette indisponibilité découpée. Voyez d'abord les effets, puis confirmez.",
  weeksCoveredCount: (covered: number, total: number) => `${covered}/${total} semaines à venir couverte`,
  venueUnavailableTitle: (venue: string, label?: string | null) => (null != label && "" !== label ? `${venue} indisponible (${label})` : `${venue} indisponible`),
  // WeekPickerDialog (choix des semaines hors socle, P2-5 E1 / P2-36 / P2-38 / P2-40 / P2-41 / P2-64).
  chooseWeeks: "Choisir les semaines",
  continueBlock: "Continuer d'un bloc",
  adaptWholePeriodBlock: "Adapter toute la période d'un bloc",
  alreadyPlannedElsewhere: "Une partie de ces dates est déjà planifiée par ailleurs — adaptez les semaines restantes une à une.",
  holidayCoversPart: "Des vacances couvrent une partie de cette période — adaptez les semaines restantes une à une.",
  startedWeekSplitByParts: "Cette indisponibilité a une semaine entamée — adaptez-la par début, milieu, fin.",
  recordUnavailability: "Consigner l'indisponibilité",
  nothingLeftToAdjustHere: "Aucune semaine de cette indisponibilité ne reste à ajuster ici.",
  mergeCta: "Fusionner",
  splitCta: "Scinder",
  multiWeekPedagogy: "Des semaines identiques donnent un planning exact ; si elles diffèrent, la fermeture la plus large s'applique à toutes.",
  chooseWeeksOutsideHoliday: "Choisissez les semaines à ajuster, hors vacances. Chaque segment coché devient un planning indépendant.",
  allWeeksUnderHoliday: "Toutes les semaines de cette indisponibilité sont couvertes par des vacances — il n'y a rien à ajuster en dehors.",
  blockValidatedHint: "Ce planning de bloc est validé. Pour le découper en semaines, rouvrez-le puis supprimez-le d'abord (depuis « Voir le planning »), puis revenez adapter.",
  someVersionsNotDeleted: "Certaines versions n'ont pas pu être supprimées — réessayez.",
  deleteVersionsAndSplit: "Supprimer les versions et découper en semaines",
  generationInFlightHint: "Une génération est en cours — la découpe sera possible ensuite.",
  splitPeriodConfirmTitle: "Découper cette période en semaines ?",
  confirmDeleteAndSplit: "Supprimer et découper",
  versionsLabel: (count: number) => `${count} version${count > 1 ? "s" : ""}`,
  createPlannings: (count: number) => (count > 1 ? `Créer les ${count} plannings` : "Créer le planning"),
  mergeSegmentLabel: (label: string) => `Fusionner « ${label} » avec le segment précédent`,
  splitSegmentLabel: (label: string) => `Scinder « ${label} » en semaines`,
  multiWeekIntro: (title: string) => `« ${title} » couvre plusieurs semaines. Chaque segment coché devient un planning indépendant — scindez-le en semaines ou fusionnez des segments voisins à votre main.`,
  holidayExcludedRange: (from: string, to: string, labels: string) => `Semaines du ${from} au ${to} couvertes par ${labels} — le rappel vous attend dans son planning.`,
  checkingState: (title: string) => `On vérifie l'état de « ${title} »… Le choix « semaine par semaine ou d'un bloc » s'affiche dès que c'est chargé.`,
  blockAlreadyAdapted: (title: string, versions: string) => `« ${title} » a déjà été adaptée d'un bloc — ${versions}. Continuez sur ce planning, ou repartez de zéro en le découpant en semaines.`,
  splitPeriodConfirmDescription: (versions: string, count: number, title: string) =>
    `Cela supprime ${versions} déjà générée${count > 1 ? "s" : ""} d'un bloc pour « ${title} », puis permet de la découper. Les réglages de cette période repartiront de la saison. Action définitive.`,
  // Chantier H — relais d'adjoint du planning en vigueur, résumé DISTINCT des conflits « à deux
  // endroits » (un relais est toléré, pas un conflit).
  relayPill: (count: number) => `${count} relais d'adjoint`,
  // PR2 « Modifier les dates d'une fermeture » (ClosureWindowDialog + geste de « Tous les plannings »
  // et de la page du planning). Le dialogue n'invente aucune règle : il affiche l'aperçu SERVI.
  closureWindowTitle: (title: string) => `Modifier les dates — ${title}`,
  modifyClosureDates: (title: string) => `Modifier les dates de ${title}`,
  closureDatesButton: "Dates",
  seeEffects: "Voir les effets",
  confirm: "Confirmer",
  confirmNeedsPreview: "Voyez d'abord les effets des nouvelles dates avant de confirmer.",
  effectsSectionTitle: "Ce qui va changer",
  loadingEffects: "Calcul des effets en cours…",
  startFrozenHint: "Déjà commencée — le début ne bouge plus.",
  futurePlansWarning: "Des plannings de période futurs seraient balayés si vous validez ou rouvrez le socle ensuite.",
  datesChangedToast: "Dates modifiées — les nouveaux plannings sont dans « Tous les plannings ».",
  datesChangedToastFromList: "Dates modifiées.",
  // FbiDeadlineCard (carte « Saisie FBI » du cockpit) — migrée au lot lisibilité BCCL (PR1, 2026-10-27).
  fbiDeadlineProposed: "proposée",
  fbiSinceLastVisit: "Depuis votre dernière visite :",
  fbiOpenList: "Ouvrir la liste FBI",
  fbiPlaceMatches: "Placer les matchs",
  // Fermetures de gymnase (PR 1) — carte du cockpit, formulaire unique (carte ET jour du
  // calendrier), aperçu « Enregistrer vaut geste », radar et suppression.
  closureCardTitle: "Indisponibilités de gymnase",
  closureCardEmpty: "Aucune indisponibilité à venir — déclarez ici un gymnase fermé (travaux, reprise mairie…) : elle vaut pour les matchs comme pour les entraînements.",
  closureDialogTitle: "Déclarer une indisponibilité de gymnase",
  declareCta: "Déclarer",
  deleteCta: "Supprimer",
  deleteClosureLabel: (title: string) => `Supprimer ${title}`,
  closureNoVenue: "Aucun gymnase : sans effet sur le planning.",
  closureImpactEvaluating: "Évaluation de l'impact…",
  confirmPinRemoval: "Retirer et enregistrer",
  closureSaveNeedsInput: "Choisissez le gymnase et les dates de la fermeture.",
  closureSaveOutOfSeason: "Ces dates sortent de la saison.",
  closureGovernedBy: (count: number, plan: string) => `${count} séance${count > 1 ? "s" : ""} à replacer · planning « ${plan} » à régénérer`,
  closureSaveNeedsPreview: "L'aperçu de ce que la fermeture retire doit d'abord être lu.",
  dateFrom: "Du",
  dateUntil: "Jusqu'au",
  closureImpactFailed: "Impact non évalué — réessayez.",
  closureImpactNone: "Aucune séance sur ces dates.",
  closureImpactSeasonUnvalidated: "Planning de la saison non validé : impact sur les entraînements non évalué.",
  closureImpactTeams: (n: number, team: string, tier: string) =>
    1 === n ? `1 équipe à l'entraînement : ${team} (rang ${tier})` : `${n} équipes à l'entraînement, dont ${team} (rang ${tier})`,
  closureMatchesHeading: "Matchs à déplacer :",
  closureMatchLine: (team: string, opponent: string, weekday: string, date: string, time?: string) =>
    `${team} contre ${opponent}, ${weekday} ${date}${undefined !== time ? ` à ${time}` : ""}`,
  closureMatchesMore: (n: number) => `+ ${n} ${plural(n, "autre", "autres")}`,
  closureNoMatches: "Aucun match placé à déplacer.",
  closureLocksRemoval: (n: number, venue: string, plan: string) =>
    1 === n
      ? `1 séance verrouillée à ${venue} pendant « ${plan} » : son verrou sera retiré pour que le plan se régénère sans ${venue}.`
      : `${n} séances verrouillées à ${venue} pendant « ${plan} » : leur verrou sera retiré pour que le plan se régénère sans ${venue}.`,
  closureReservationsRemoval: (reservations: { teamName: string; dayOfWeek: number }[], venue: string) =>
    1 === reservations.length
      ? `1 réservation de ${reservations[0].teamName} le ${dayLabelLong(reservations[0].dayOfWeek)} à ${venue} sera retirée.`
      : `${reservations.length} réservations seront retirées : ${reservations.map((r) => `${r.teamName} le ${dayLabelLong(r.dayOfWeek)}`).join(", ")}, à ${venue}.`,
  planStaleByClosureFuture: (plan: string) => `Le plan « ${plan} » sera périmé : régénérez-le après l'enregistrement.`,
  planInProgressClosedFuture: (plan: string) => `Le plan « ${plan} » est en cours : ouvrez-le et régénérez-le après l'enregistrement.`,
  closureOutOfSeason: (start: string, end: string) => `Ces dates sortent de la saison (du ${start} au ${end}).`,
  closureSeasonClipped: (start: string, seasonEnd: string) => `Seules les dates du ${start} au ${seasonEnd} comptent (fin de saison).`,
  /** « — 3 verrous et 1 réservation retirés » (vide si rien retiré) : suffixe des toasts de re-datage et d'élargissement. */
  removedSuffix: (locks: number, reservations: number) => {
    if (0 === locks && 0 === reservations) return "";
    const parts = [
      ...(locks > 0 ? [`${locks} ${plural(locks, "verrou", "verrous")}`] : []),
      ...(reservations > 0 ? [`${reservations} ${plural(reservations, "réservation", "réservations")}`] : []),
    ];
    return ` — ${parts.join(" et ")} ${plural(locks + reservations, "retiré", "retirés")}${0 === locks && 1 === reservations ? "e" : ""}`;
  },
  /** « 3 verrous et 1 réservation retirés. » (vide si rien retiré) : phrase ajoutée au toast d'élargissement. */
  removedSentence: (locks: number, reservations: number) => {
    if (0 === locks && 0 === reservations) return "";
    const parts = [
      ...(locks > 0 ? [`${locks} ${plural(locks, "verrou", "verrous")}`] : []),
      ...(reservations > 0 ? [`${reservations} ${plural(reservations, "réservation", "réservations")}`] : []),
    ];
    return ` ${parts.join(" et ")} ${plural(locks + reservations, "retiré", "retirés")}${0 === locks && 1 === reservations ? "e" : ""}.`;
  },
  closureSavedToast: (locks: number, reservations: number, venue: string) => {
    if (0 === locks && 0 === reservations) return "Indisponibilité enregistrée.";
    const parts = [
      ...(locks > 0 ? [`${locks} ${plural(locks, "verrou", "verrous")}`] : []),
      ...(reservations > 0 ? [`${reservations} ${plural(reservations, "réservation", "réservations")}`] : []),
    ];
    return `Indisponibilité enregistrée — ${parts.join(" et ")} ${plural(locks + reservations, "retiré", "retirés")}${0 === locks && 1 === reservations ? "e" : ""} à ${venue}.`;
  },
  generationInProgress: "Une génération est en cours pour ce club : réessayez quand elle est terminée.",
  openPlanCta: "Ouvrir le plan",
  deleteClosureTitle: (title: string) => `Supprimer « ${title} » ?`,
  deleteClosureWithPlan: (versions: number) => `Son planning de période (validé, ${versions} ${plural(versions, "version", "versions")}) sera supprimé aussi.`,
  deleteClosureInProgress: (versions: number) => `Son planning de période (en cours, ${versions} ${plural(versions, "version", "versions")}) sera supprimé aussi.`,
  deleteClosureSplit: (weeks: number) => (1 === weeks ? "Sa semaine et son planning seront supprimés aussi." : `Ses ${weeks} semaines et leurs plannings seront supprimés aussi.`),
  deleteClosureBare: "Cette indisponibilité sera retirée du calendrier.",
  deleteClosureNothingRestored: "Les verrous et réservations retirés à sa déclaration ne sont pas remis.",
  deleteClosureRegeneratedWithout: (plan: string, venue: string, weekdays: number[]) =>
    `Le plan « ${plan} » a été régénéré sans ${venue} : régénérez-le pour retrouver ${0 === weekdays.length ? "ses séances" : weekdays.map((d) => `le ${dayLabelLong(d)}`).join(", ")}.`,
  closureDeletedToast: (venue: string, regeneratedPlan?: string) =>
    undefined !== regeneratedPlan
      ? `Indisponibilité supprimée — les séances de ${venue} reprennent, sauf dans « ${regeneratedPlan} » (régénéré sans ${venue}).`
      : `Indisponibilité supprimée — les séances de ${venue} reprennent le planning en vigueur.`,
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
