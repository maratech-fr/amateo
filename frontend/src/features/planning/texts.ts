// Textes centralisés de la feature planning (PR7 — fondation des textes centralisés).
// Valeurs = copie BYTE-IDENTIQUE des littéraux rendus par les écrans (PlanningPage, SlotDetail,
// DiagnosticsPanel). Convention : clé camelCase par intention ; interpolation par fonctions typées.
// Pas de lib i18n. JAMAIS en valeur : jour (→ shared/lib/days), heure/durée (→ time/duration),
// marque (→ product), identifiant interne.
import { dayLabelLong } from "@/shared/lib/days";

import type { PlacedAbsence } from "./api";

const frenchList = (items: string[]): string => (items.length < 2 ? (items[0] ?? "") : `${items.slice(0, -1).join(", ")} et ${items.at(-1)}`);

export const PLANNING_TEXTS = {
  heldBy: (coach: string) => `tenue par ${coach}`,
  lockWillBeLost: "Elle est verrouillée — le verrou sera perdu.",
  emptyPlanningTitle: "Planning vide",
  emptyPlanningDescription: "Ce planning ne contient aucun créneau placé pour le moment.",
  noPlanning: "Aucun planning",
  noPlanningDescription: "Passez par l'assistant pour saisir vos données et générer un premier planning.",
  noPeriodVersionTitle: "Aucune version pour cette période",
  noPeriodVersionDescription: "Générez le planning de cette période pour le voir apparaître ici.",
  generationFailed: "Génération en échec",
  generationFailedDescription: "Aucun créneau n'a été placé, et ce planning n'a aucune réservation à afficher. Lisez les diagnostics, corrigez, puis régénérez.",
  generationFailedNoSlots:
    "La génération a échoué : aucun créneau n'a été placé. Seuls vos créneaux réservés sont affichés — ils restent acquis. Les exports sont vides pour ce planning. Ouvrez les diagnostics du système pour voir ce qui a été constaté.",
  scopeThisTeam: "Cette équipe",
  scopeWholeClub: "Tout le club",
  verifyTimeout: "La vérification a pris trop de temps, réessayez.",
  viaMutualization: "via mutualisation",
  mutualizedBadge: "Mutualisé",
  seasonPlanBadge: "principal",
  planningNameLabel: "Nom du planning",
  renamePlanning: "Renommer le planning",
  earlierVersionNotice: "Vous regardez une version antérieure du planning.",
  openLatestVersion: "Ouvrir la dernière version",
  fillAutomatically: "Combler automatiquement",
  fillHint: "Place les séances à replacer sans toucher au reste du planning.",
  ignore: "Ignorer",
  diagnosticsSystemTitle: "Diagnostics du système",
  seeManualLocks: "Voir les verrous posés à la main",
  undoLastGesture: "Annuler le dernier geste",
  unlockReservedTitle: "Déverrouiller ce créneau réservé ?",
  unlockReservedDescription:
    "Ce créneau vient d'une réservation de gymnase. En le déverrouillant, la prochaine génération pourra le déplacer ou le libérer — vérifiez auprès du gymnase avant de continuer.",
  loadVersionTitle: "Charger cette version ?",
  loadVersionDescriptionGeneric: "La structure actuelle sera remplacée par celle de cette version et son planning s'affichera. Vous pourrez ensuite « Régénérer » pour créer une nouvelle version.",
  // Version chargée : une version non chargée se consulte, ne se modifie pas.
  notLoadedBanner: (viewed: string, loaded: string, canLoad: boolean, reason?: string | null): string =>
    canLoad
      ? `Vous consultez la version ${viewed} en lecture seule. La version chargée est la version ${loaded} : chargez la version ${viewed} pour la modifier, ou régénérez à partir de la version ${loaded}.`
      : `Vous consultez la version ${viewed} en lecture seule. Elle ne peut pas être chargée${null === reason || undefined === reason ? "." : ` : ${reason}`} La version chargée est la version ${loaded} : modifiez-la, ou régénérez à partir d'elle.`,
  notSourceBanner: (viewed: string, source: string, canLoad: boolean): string =>
    `Vous consultez la version ${viewed} en lecture seule. La version source est la version ${source}${canLoad ? ` : chargez la version ${viewed} pour la modifier, ou régénérez à partir de la version ${source}.` : ` : modifiez-la, ou régénérez à partir d'elle.`}`,
  openSourceVersion: "Ouvrir la version source",
  // Un geste grisé dit son motif en clair : une ligne par zone, seule dans son élément.
  notLoadedToolbarLine: "Version en consultation : chargez-la pour la modifier ou la valider.",
  cannotLoadLine: (reason: string): string => `Version en consultation, impossible à charger : ${reason}`,
  openLoadedVersion: "Ouvrir la version chargée",
  gesturesBlockedNotLoaded: "Chargez cette version pour la modifier.",
  validateNeedsLoad: "Chargez cette version pour la valider.",
  loadedVersionSuffix: (label: string): string => `(${label} chargée)`,
  sourceVersionSuffix: (label: string): string => `(${label} source)`,
  regenerateFromLoadedDescription: (o: { viewed: string; loaded: string; lockCount: number; viewedHasLocks: boolean; canLoad: boolean }): string =>
    [
      `Vous regardez la version ${o.viewed}, mais la version chargée est la version ${o.loaded}. La régénération repartira de la version ${o.loaded} (${o.lockCount} verrou${o.lockCount > 1 ? "s" : ""}).`,
      o.viewedHasLocks ? `Les verrous de la version ${o.viewed} ne seront pas repris.` : null,
      o.canLoad ? `Pour repartir de la version ${o.viewed}, chargez-la d'abord.` : `La version ${o.viewed} ne peut pas être chargée.`,
    ]
      .filter((sentence) => null !== sentence)
      .join(" "),
  regenerateFromLoadedConfirm: (label: string): string => `Régénérer depuis la version ${label}`,
  regenerateFromLoadedToast: (label: string): string => `Régénération lancée depuis la version ${label}.`,
  regenerateKeepsLoadedLocks: (label: string): string => `La régénération repart de la version ${label} : ses créneaux verrouillés sont conservés.`,
  deleteLoadedVersionNote: (next: string): string => `C'est la version chargée : la version ${next} prendra le relais.`,
  regenerateFromCurrentTitle: "Régénérer à partir des données en cours ?",
  // PlanningToolbar
  viewByVenue: "Par gymnase",
  viewByCoach: "Par coach",
  viewByTeam: "Par équipe",
  viewByDay: "Par jour",
  viewByClub: "Par club",
  versionSelectLabel: "Version du planning",
  versionInForceSuffix: " · en vigueur",
  versionPeriodSuffix: " · période",
  deleteVersion: "Supprimer cette version",
  periodBadge: "Période",
  validate: "Valider",
  reopen: "Rouvrir",
  planningViewLabel: "Vue du planning",
  generating: "Génération…",
  regenerate: "Régénérer",
  regenerateKeepsLocks: "Vos créneaux verrouillés sont conservés à la régénération.",
  loadVersion: "Charger cette version",
  loadVersionAlreadyCurrent: "Déjà le contexte courant — rien à recharger (utilisez « Régénérer »)",
  loadVersionHint: "Recharge la structure de cette version (sans régénérer) et affiche son planning",
  loadLocksHint: "Fait des verrous de cette version les données en cours : la prochaine régénération repartira d'eux",
  // SlotDetail
  closeLabel: "Fermer",
  fixConstraintLink: "Corriger cette contrainte",
  noSlotConstraints: "Aucune contrainte spécifique à ce créneau.",
  readOnlyPlanningSlot: "Planning validé (lecture seule). Rouvrez-le pour modifier ce créneau.",
  escKey: "Échap",
  moveRejected: "Déplacement refusé — le créneau n’a pas bougé.",
  moveBlocked: "Une génération est en cours pour ce club — réessayez le déplacement une fois qu’elle est terminée.",
  moveInterrupted: "La vérification a été interrompue avant la réponse — rien n’a été modifié, réessayez.",
  moveError: "Le moteur n’a pas répondu — rien n’a été modifié, réessayez.",
  // DiagnosticsPanel
  collapseDiagnosticsLabel: "Réduire les diagnostics",
  collapseDiagnosticsTitle: "Réduire (plus de place pour la grille)",
  adjustRuleLink: "Ajuster cette règle",
  reservationCause: (what: string): string => `En cause : ${what}.`,
  reservationCauseFallback: "une séance fixée",
  showFixedSession: "Voir la séance fixée",
  validateMissingSessions: (team: string, n: number): string => `${team} : ${n} séance${n > 1 ? "s" : ""} non placée${n > 1 ? "s" : ""}`,
  fixRuleLink: "Corriger cette règle",
  diagnosticsLoading: "Lecture des diagnostics…",
  diagnosticsClean: "Aucun diagnostic — le planning est propre.",
  // ClubViewTable (lot 5 PR E — migration texts + primitive Table)
  clubViewEmpty: "Aucun créneau à afficher pour cette sélection.",
  clubViewFreeSlotWord: "créneau libre",
  clubViewTeamColumn: "Équipe",
  clubViewNoSession: "aucune séance",
  // ExportMenu (chantier E — migration en même temps que l'ajout de la case « coachs »)
  exportButton: "Exporter",
  exportOptionsGroup: "Options d'export",
  exportScopeFieldLabel: "Périmètre",
  exportScopeAriaLabel: "Périmètre de l'export",
  exportScopePlaceholder: "Tous les gymnases",
  exportBothViews: "Chaque export contient les deux vues : la grille (jours × gymnases) et la vue par club (équipes × jours).",
  exportOnePage: "Une page paysage, ajustée pour rester lisible.",
  exportShowCoaches: "Afficher le nom des coachs",
  exportScopeAll: "tous les gymnases",
  exportScopeOne: "un gymnase",
  exportScreenFilteredPrefix: (count: number): string =>
    `L'écran est filtré (${count} ${1 === count ? "ressource" : "ressources"}), mais l'export porte sur`,
  exportScreenFilteredSuffix: " — le filtre d'affichage ne le restreint pas.",
  absencesHeading: (count: number) => (1 === count ? "Une séance sans coach" : `${count} séances sans coach`),
  absenceLine: (absence: PlacedAbsence) =>
    `${frenchList(absence.coachNames)} ${1 === absence.coachNames.length ? "est indisponible" : "sont indisponibles"} le ${dayLabelLong(absence.dayOfWeek)} à ${absence.startTime} : personne pour tenir ${frenchList(absence.teamNames)} (${absence.venueName}).`,
  fillBlocked: (reason: string) => `Le comblement n'a pas été lancé : ${reason}`,
  seeRecap: "Voir le Récapitulatif",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
