// Textes centralisés de la console superadmin — coquille (P4-346, PR A).
// Couvre AdminDashboardPage, AdminShell, AdminLoginPage, AdminAuthLayout, tabs/tabsConfig et les
// toasts de queries.ts. Les SECTIONS (sections/*) et les journaux vivent dans leurs propres modules
// (texts/sections.ts, texts/journaux.ts — PR B).
//
// Convention (patron features/planning/texts.ts) : une clé camelCase PAR INTENTION (préfixée par
// écran) ; valeur = copie BYTE-IDENTIQUE du libellé rendu par l'écran ; interpolation = fonction
// typée (pluriels compris). JAMAIS comme valeur : un libellé de jour (→ shared/lib/days), une
// heure/durée (→ shared/lib/time · duration), la marque (→ shared/lib/product), un identifiant
// interne, ni une DONNÉE servie par le backend (nom de club, libellé d'action/d'argument/de job,
// raison « coince », nom d'offre/de saison, statut solveur).
import type { AdminJobStatus } from "../api";

// Même formateur d'entiers que la page (`new Intl.NumberFormat("fr-FR")`) : garde les pluriels
// byte-identiques quand le compte passait par `integerFormatter`.
const nf = new Intl.NumberFormat("fr-FR");

export const ADMIN_DASHBOARD_TEXTS = {
  // AdminShell
  shellTitle: "Console superadmin",
  shellSubtitle: "Surface d’exploitation transverse",
  shellLogout: "Sortir",

  // AdminAuthLayout
  authSecureConsole: "Console sécurisée",
  authRestrictedAccess: "Accès restreint",
  authJournaled: "Chaque accès à cette console est journalisé.",

  // AdminLoginPage
  loginTotpTitle: "Vérification en deux étapes",
  loginTotpDescription: "Saisissez le code à six chiffres de votre application d’authentification.",
  loginTotpLabel: "Code TOTP",
  loginTotpSubmit: "Ouvrir la console",
  loginBackToIdentification: "Revenir à l’identification",
  loginTitle: "Connexion superadmin",
  loginDescription: "Cette surface donne accès aux opérations transverses de la plateforme.",
  loginEmailLabel: "Email",
  loginPasswordLabel: "Mot de passe",
  loginSubmit: "Continuer",

  // tabs/tabsConfig — onglets et sous-onglets Incidents
  tabClubs: "Clubs",
  tabStatistiques: "Statistiques",
  tabSante: "Santé",
  tabIncidents: "Incidents",
  tabExploitation: "Exploitation",
  subSignalements: "Signalements",
  subErrors: "Erreurs système",
  subMessenger: "Échecs async",
  subAudit: "Audit",

  // AdminDashboardPage — en-tête + barres d'onglets
  headerTitle: "Console d'administration",
  refresh: "Actualiser",
  tabsAriaLabel: "Sections admin",
  incidentsTabsAriaLabel: "Incidents",
  tabAlertBadge: "à vérifier",

  // Onglet Clubs — parc
  clubsEyebrow: "Parc client",
  clubsHeading: "Comptes clubs",
  clubSearchLabel: "Rechercher un club",
  clubSearchPlaceholder: "Nom, slug ou code FFBB",
  clubSearchSubmit: "Rechercher",
  clubsLoading: "Chargement des clubs",
  clubsError: "Les comptes clubs sont indisponibles.",

  // Statistiques — activité globale (OverviewSection)
  overviewLoading: "Chargement de l’activité",
  overviewError: "Les indicateurs d’activité sont indisponibles.",
  metricClubs: "Clubs",
  metricActive7d: "Actifs sur 7 j",
  metricGenerations: "Générations",
  metricInfeasibleRate: "Taux infaisable",
  overviewEyebrow: "Activité globale",
  overviewHeading: "Parc et solveur",
  solverPerformance: "Performance du solveur",
  solverCompleted: "Terminées",
  solverFailed: "Échecs",
  solverMedianDuration: "Durée médiane",
  solverP95: "P95",
  metricNew7d: (formatted: string) => `${formatted} nouveaux sur 7 j`,
  metricActive30d: (formatted: string) => `${formatted} actifs sur 30 j`,
  metricWindowDays: (days: number) => `Fenêtre de ${days} jours`,
  metricInfeasibleCount: (formatted: string) => `${formatted} générations`,
  unsubscribedCount: (n: number) => `${nf.format(n)} compte${n > 1 ? "s" : ""} désabonné${n > 1 ? "s" : ""}`,

  // Statistiques — usage produit (UsageSection)
  usageLoading: "Chargement de l’usage",
  usageError: "Les statistiques d’usage sont indisponibles.",
  usageEyebrow: "Usage produit",
  usageHeading: "Plans, clôtures et tailles de clubs",
  usagePlansByType: "Plans par type",
  usagePlansByTypeHint: "Parc actuel — un reset ou un effacement retire ses plans",
  usageNoPlan: "Aucun plan.",
  usageCloseTime: "Temps de clôture",
  usageCloseTimeHint: "Création du plan → première validation",
  usageSeasonMedian: "Saison · médiane",
  usageSeasonP95: "Saison · P95",
  usagePeriodMedian: "Périodes · médiane",
  usagePeriodP95: "Périodes · P95",
  usageSolverByType: "Solveur par type · 30 j",
  usageNoGenerationWindow: "Aucune génération sur la fenêtre.",
  usageClubSizes: "Tailles de clubs",
  usageClubSizesHint: "Équipes actives de la saison courante, parc non désabonné",
  usageColTeams: "Équipes",
  usageColClubs: "Clubs",
  usageColVenuesMedian: "Gymnases (médiane)",
  usagePlansValidated: (n: number) => `dont ${nf.format(n)} validé${n > 1 ? "s" : ""}`,
  usageCloseCounts: (seasonCount: number, periodCount: number) =>
    `${nf.format(seasonCount)} saison${seasonCount > 1 ? "s" : ""} · ${nf.format(periodCount)} période${periodCount > 1 ? "s" : ""} clôturée${periodCount > 1 ? "s" : ""}`,
  usageSolverRowMeta: (p50: string, p95: string) => `méd. ${p50} · P95 ${p95}`,

  // Statistiques — volume quotidien (SolverChart)
  solverDailyTitle: "Volume quotidien",
  solverDailyLegend: "Générations",
  solverNoGenerationPeriod: "Aucune génération sur cette période.",
  solverDailyHint: (days: number) => `Générations sur les ${days} derniers jours`,
  solverDayCaption: (date: string, generations: number) => `${date} : ${generations} générations`,

  // Santé — infrastructure (HealthSection)
  healthLoading: "Chargement de la santé technique",
  healthError: "La santé technique est indisponible.",
  healthServiceDatabase: "Base de données",
  healthServiceRedis: "Redis",
  healthServiceEngine: "Moteur",
  healthServiceMercure: "Mercure",
  healthEyebrow: "Infrastructure",
  healthHeading: "Santé technique",
  healthDependencies: "Dépendances",
  healthAsyncProcessing: "Traitements asynchrones",
  healthBacklog: "En attente",
  healthFailed: "Échecs",
  healthRetries: "Retries",
  healthWorker: "Worker",
  healthCheckedAt: (datetime: string) => `Vérifié le ${datetime}`,
  heartbeatAgo: (seconds: number) => `Heartbeat il y a ${seconds} s`,
  heartbeatAt: (datetime: string) => `Heartbeat ${datetime}`,
  heartbeatNone: "Aucun heartbeat reçu",

  // Santé — jobs opérationnels (JobsSection)
  jobsLoading: "Chargement des jobs opérationnels",
  jobsError: "Les jobs opérationnels sont indisponibles.",
  jobsEyebrow: "Exploitation",
  jobsHeading: "Jobs opérationnels",
  jobsHint: "Cadence, prochain passage et dernière exécution connue",
  jobsEmpty: "Aucun job opérationnel configuré.",
  jobsCaption: "État des jobs opérationnels allowlistés",
  jobColJob: "Job",
  jobColCadence: "Cadence",
  jobColNextRun: "Prochain passage",
  jobColLastRun: "Dernière exécution",
  jobColDuration: "Durée",
  jobColResult: "Résultat",
  jobColAction: "Action",
  jobConfirmTitle: "Relancer cet import ?",
  jobConfirmLabel: "Relancer l’import",
  jobNeverRun: "Jamais exécuté",
  jobRunning: "Exécution…",
  jobRerun: "Relancer",
  jobSupervisionOnly: "Supervision seule",
  jobNoHistory: "Sans historique",
  cadenceEvery10Minutes: "Toutes les 10 minutes",
  cadenceDaily: "Quotidien",
  cadenceWeekly: "Hebdomadaire",
  cadenceQuarterly: "Trimestriel",
  jobSourceScheduled: "Planifié",
  jobSourceCli: "CLI",
  jobSourceSuperadmin: "Superadmin",
  jobStatusLabel: (status: AdminJobStatus): string =>
    ({ running: "En cours", succeeded: "Réussi", failed: "Échec", interrupted: "Interrompu" } as const)[status],
  jobRunSuccess: (label: string) => `${label} terminé.`,
  jobRunError: (label: string) => `Impossible d’exécuter « ${label} ».`,
  jobConfirmDescription: (label: string) =>
    `« ${label} » va interroger la source officielle et mettre à jour la référence globale. L’opération est idempotente.`,
  jobExitCode: (code: number) => `Code ${code}`,

  // Santé — fraîcheur des données (FreshnessSection)
  freshnessLoading: "Chargement de la fraîcheur des données",
  freshnessError: "La fraîcheur des données est indisponible.",
  freshnessEyebrow: "Données de référence",
  freshnessHeading: "Fraîcheur des données",
  freshnessStale: "Périmé",
  freshnessFresh: "À jour",
  freshnessNeverImported: "Jamais importé",
  freshnessLastUpdated: (date: string) => `Dernière mise à jour : ${date}`,
  freshnessThreshold: (formatted: string) => `Seuil : ${formatted} jours`,

  // Onglet Clubs — table (ClubsTable)
  clubsEmpty: "Aucun club à afficher.",
  clubsCaption: "Liste des comptes clubs et de leurs métriques",
  clubColClub: "Club",
  clubColActivity: "Activité",
  clubColOffer: "Offre",
  clubColSeasonVolume: "Saison / volume",
  clubColSolver: "Solveur · 30 j",
  clubColSupport: "Support",
  clubPagePrev: "Page précédente",
  clubPageNext: "Page suivante",
  clubsEmptySearch: (query: string) => `Aucun club ne correspond à « ${query} ».`,
  clubsPagination: (total: number, page: number, pages: number) =>
    `${nf.format(total)} compte${total > 1 ? "s" : ""} · page ${page} sur ${Math.max(pages, 1)}`,

  // Onglet Clubs — ligne (ClubRow)
  clubDemo: "Démo",
  clubUnsubscribed: "Désabonné",
  clubNeverActive: "Jamais",
  clubNoSeason: "Aucune saison",
  clubRowActions: "Actions",
  clubToggleDetail: (expanded: boolean, name: string) => `${expanded ? "Masquer" : "Afficher"} le détail de ${name}`,
  clubPlanDiverges: (planName: string) => `${planName} posée — saison non réglée`,
  clubGenerationCount: (n: number) => `${n} génération${n > 1 ? "s" : ""}`,
  clubCreatedAt: (date: string) => `Créé le ${date}`,
  clubTeamsCoaches: (teams: number, coaches: number) => `${teams} équipes · ${coaches} coachs`,
  clubVenues: (venues: number) => `${venues} salles`,
  clubConstraints: (constraints: number) => `${constraints} contraintes`,
  // Le nombre de générations vit dans un <span> stylé (gras/blanc) à côté : pour garder ce DOM
  // byte-identique, la fonction ne rend QUE le fragment qui SUIT le nombre (espace de tête compris).
  clubSolverSummary: (rate: string) => ` générations · ${rate} inf.`,
  clubSolverPercentiles: (p50: string, p95: string) => `P50 ${p50} · P95 ${p95}`,
  clubLatestStatus: (status: string) => `Dernière : ${status}`,

  // Onglet Clubs — détail déplié (ClubDetailRow)
  clubDetailMembers: "Membres",
  clubDetailOnboarding: "Onboarding",
  clubDetailCredits: "Crédits",
  clubDetailSignalements: "Signalements",
  clubDetailStuck: "Ce club coince",
  clubOnboardingDone: "Onboarding terminé",
  clubOnboardingNotDone: "Onboarding non terminé",
  clubFfbbNotImported: "Équipes FFBB non importées",
  clubMembersActive: (n: number) => `${n} membres actifs`,
  clubMembersPending: (n: number) => `${n} en attente`,
  clubSocle: (chosen: boolean) => `Socle ${chosen ? "choisi" : "non choisi"}`,
  clubFfbbImportedAt: (date: string) => `Équipes FFBB importées le ${date}`,
  clubCreditsUsed: (n: number) => `Crédits de sortie consommés : ${n}`,
  clubSignalements: (n: number) => `${n} signalement${n > 1 ? "s" : ""} non traité${n > 1 ? "s" : ""}`,

  // Onglet Clubs — actions support (ClubActionsDialog)
  clubActionBack: "Retour",
  clubActionExecute: "Exécuter",
  clubActionCatalogLoading: "Chargement du catalogue…",
  clubActionCatalogError: "Catalogue d’actions indisponible.",
  clubActionDangerous: "Destructif",
  clubActionArgPlaceholder: "Choisir…",
  clubActionConfirmFallbackTitle: "Confirmer",
  clubActionConfirmAttribution: "Confirmer l’attribution",
  clubActionsModalTitle: (name: string) => `Actions support — ${name}`,
  clubActionRunSuccess: (label: string, name: string) => `${label} — terminé pour ${name}.`,
  clubActionRunError: (label: string, name: string) => `Impossible d’exécuter « ${label} » sur ${name}.`,
  clubActionDangerConfirmLabel: (name: string) =>
    `Action destructive — tapez le nom exact du club (« ${name} ») pour confirmer :`,
  clubActionConfirmTitle: (label: string) => `Confirmer : ${label}`,
  clubActionConfirmFor: (name: string) => `Pour ${name} :`,

  // Puce d'état des services (StatusChip)
  statusUp: "Opérationnel",
  statusDown: "Indisponible",
  statusUnknown: "Inconnu",
  statusDegraded: "Dégradé",

  // Panneau d'erreur générique (PanelError)
  panelRetry: "Réessayer",

  // Libellés FR des types de plan (ADR-0002) + bucket télémétrie UNKNOWN (historique).
  planTypeLabel: (type: string): string =>
    ({ SEASON: "Saison", CLOSURE: "Fermeture", HOLIDAY: "Vacances", UNKNOWN: "Inconnu (historique)" } as Record<string, string>)[type] ?? type,

  // queries.ts — toasts d'erreur des mutations (niveau hook, FRT-38)
  toastReleaseNoteSaveError: "Impossible d'enregistrer la note.",
  toastReleaseNotePublishError: "Impossible de publier.",
  toastReleaseNoteDeleteError: "Impossible de supprimer.",
  toastDemoActivateError: "Impossible d’activer le compte de démonstration.",
  toastDemoDeactivateError: "Impossible de désactiver le compte de démonstration.",
  toastDemoResetError: "La réinitialisation de la démo n’a pas pu être lancée.",
  toastDemoClockClearError: "Impossible de revenir à aujourd’hui.",
  toastDemoClockSetError: "Impossible d’appliquer la date simulée.",
  toastDemoRetainError: "Impossible de conserver le club de démonstration.",
  toastDeploymentScheduleError: "Impossible de programmer la mise en production.",
  toastDeploymentCancelError: "Impossible d’annuler la mise en production.",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
