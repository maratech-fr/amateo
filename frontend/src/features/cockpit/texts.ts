// Textes centralisés de la feature cockpit (PR7 — fondation des textes centralisés).
// Valeurs = copie BYTE-IDENTIQUE des littéraux rendus par les écrans (RadarPanel, DayDialog).
// Convention : clé camelCase par intention ; interpolation par fonctions typées. Pas de lib i18n.
export const COCKPIT_TEXTS = {
  resumePlanning: "Planning en cours — à finaliser",
  weekPlanningToFinalize: "Planning de semaine à finaliser",
  whichWeeksToAdjust: "Quelles semaines ajuster ?",
  schoolZoneToSet: "Zone scolaire à renseigner",
  seasonPlanToValidate: "Planning de saison à valider",
  nothingToReport: "Rien à signaler",
  allClear: "Rien à l'horizon. Tout roule.",
  impactNotAssessedRetry: "Impact non évalué · réessayez",
  secondaryPlanValidated: "Planning secondaire validé",
  loadingItemsToHandle: "Chargement des éléments à traiter…",
  entryWillBeRemoved: "Cette entrée sera retirée du calendrier.",
  publicHolidayTitle: "Jour férié",
  holidayTag: "Vacances",
  outsideSeasonNothingToAdapt: "Hors de la saison en cours — rien à adapter.",
  closureMarker: "⛔",
  cutoffMarker: "🛑",
  weeksCoveredCount: (covered: number, total: number) => `${covered}/${total} semaines à venir couverte`,
  venueUnavailableTitle: (venue: string, label: string) => `${venue} indisponible (${label})`,
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
