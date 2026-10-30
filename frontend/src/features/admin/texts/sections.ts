// Textes centralisés de la console superadmin — les SECTIONS (P4-346, PR B).
// Couvre les 9 sections de `features/admin/sections/*` : Audience, Capacity, ClubRequests,
// Containers, Demos, Deployment, ExternalDeps, Feedback, ReleaseNotes. Les journaux (Journaux/*)
// vivent dans `texts/journaux.ts` ; la coquille (Dashboard/Shell/Login/tabs) dans `texts/dashboard.ts`.
//
// Convention (patron features/planning/texts.ts) : une clé camelCase PAR INTENTION (préfixée par
// section) ; valeur = copie BYTE-IDENTIQUE du libellé rendu par l'écran ; interpolation = fonction
// typée (pluriels compris). JAMAIS comme valeur : un libellé de jour (→ shared/lib/days), une
// heure/durée (→ shared/lib/time · duration), la marque (→ shared/lib/product), un identifiant
// interne, ni une DONNÉE servie par le backend (nom de club, email, message, classe/source, statut
// solveur, libellé d'offre/de saison, bucket d'issue).
import type { AdminDeployment, AdminFeedbackStatus, AdminFeedbackTopic } from "../api";

// Même formateur d'entiers que les sections (`new Intl.NumberFormat("fr-FR")`).
const nf = new Intl.NumberFormat("fr-FR");

export const ADMIN_SECTIONS_TEXTS = {
  // ── Partagés par plusieurs sections ──────────────────────────────────────────────────────────
  loading: "Chargement…",
  retry: "Réessayer",
  listReadError: "La liste n'a pas pu être lue.",
  colService: "Service",
  colStatus: "Statut",
  statusUp: "Opérationnel",
  statusDown: "Indisponible",

  // ── AudienceSection ──────────────────────────────────────────────────────────────────────────
  audienceLoading: "Chargement de la mesure d’audience",
  audienceError: "La mesure d’audience est indisponible.",
  audienceNotConfigured:
    "Mesure d’audience non configurée — la clé Umami ou l’identifiant du site de la vitrine n’est pas renseigné.",
  audienceUnavailable:
    "Mesure d’audience momentanément indisponible — Umami ne répond pas. La console reste opérante.",
  audienceEyebrow: "Vitrine",
  audienceHeading: "Mesure d’audience",
  audienceWindow7d: "7 derniers jours",
  audienceWindow30d: "30 derniers jours",
  audienceTopPages: "Pages les plus vues",
  audienceTopSources: "Principales sources",
  audienceUnitPageviews: "vues",
  audienceUnitVisitors: "visiteurs",
  audienceMetricVisitors: "Visiteurs",
  audienceMetricVisits: "Visites",
  audienceMetricPageviews: "Pages vues",
  audienceNoData: "Aucune donnée sur la fenêtre.",
  audienceTrendStable: "stable",
  audienceTrend: (delta: string) => `${delta} % vs période précédente`,

  // ── CapacitySection ──────────────────────────────────────────────────────────────────────────
  capacityLoading: "Chargement de la capacité",
  capacityError: "Les indicateurs de capacité sont indisponibles.",
  capacityEyebrow: "Charge & capacité",
  capacityHeading: (windowDays: number) => `Capacité — ${nf.format(windowDays)} derniers jours`,
  capacitySolvesWindow: (total: number) => `solve${total > 1 ? "s" : ""} sur la fenêtre`,
  capacityEmpty: (windowDays: number) =>
    `Aucun solve sur la fenêtre de ${nf.format(windowDays)} jours — rien à mesurer pour l’instant.`,
  capacityVolume: "Volume",
  capacitySolvesPerDayMedian: "Solves/jour · médiane",
  capacitySolvesPerDayPeak: "Solves/jour · pic",
  capacityPeakOn: (date: string) => `le ${date}`,
  capacityHourlyProfile: "Profil horaire (heure locale)",
  capacityNoHour: "Aucune heure de mise en file connue.",
  capacityHourlyCaption: "Nombre de solves par heure de mise en file",
  capacityColHour: "Heure",
  capacityColSolves: "Solves",
  capacityWait: "Attente en file",
  capacityWaitHint: "De la mise en file au démarrage du solve",
  capacityMedian: "Médiane",
  capacityP95: "P95",
  capacityMax: "Max",
  capacityEngineWaitLabel: "Côté engine (sémaphore) · P95",
  capacitySize: "Durée par tranche",
  capacitySizeHint: "Tranches de taille = équipes × gymnases (tiers du moteur)",
  capacityNoSize: "Aucune tentative dimensionnée sur la fenêtre.",
  capacitySizeCaption: "Durée par tranche de taille de problème",
  capacityColBucket: "Tranche",
  capacityColP50: "P50 durée",
  capacityColP95Duration: "P95 durée",
  capacityColBudget: "P95 % budget",
  capacityColUnclosed: "Preuve non fermée",
  capacitySizeFootnote:
    "Taille = celle du club (saison entière) ; un overlay ne résout qu’un sous-ensemble mais porte le compte saison.",
  capacityMemory: "Mémoire engine",
  capacityMemoryHint: (limit: number) => `Pic RSS du solve · limite prod ${nf.format(limit)} MiB`,
  capacityBaselineLabel: "Baseline du process (avant solve)",
  capacityIssues: "Issues",
  capacityNoIssue: "Aucune issue enregistrée.",
  capacityPayloadLabel: "Payload engine · P95",
  capacityBucketLabel: (bucket: string): string =>
    ({ small: "≤ 50", medium: "51–200", large: "> 200" } as Record<string, string>)[bucket] ?? bucket,

  // ── ClubRequestsSection ──────────────────────────────────────────────────────────────────────
  clubRequestsEyebrow: "Arbitrages",
  clubRequestsHeading: "Demandes de création & adhésions en attente",
  clubRequestsCreationHeading: "Demandes de création de club",
  clubRequestsCreationHint:
    "Sans mail FFBB (aucun lien envoyé) ou expirées : la console est la seule voie. Approuver crée l'espace du club.",
  clubRequestsCreationEmpty: "Aucune demande en attente.",
  clubRequestsBadgeExpired: "Expirée",
  clubRequestsBadgeNoEmail: "Sans mail FFBB",
  clubRequestsRequestedBy: (requester: string, email: string, createdAt: string, expiresAt: string) =>
    `Demandé par ${requester} (${email}) le ${createdAt} — expire le ${expiresAt}`,
  clubRequestsApprove: "Approuver",
  clubRequestsRefuseConfirm: "Confirmer le refus",
  clubRequestsRefuse: "Refuser",
  clubRequestsMembershipsHeading: "Adhésions en attente",
  clubRequestsMembershipsHint:
    "Normalement approuvées par un gestionnaire du club — activer ici quand la passation n'a pas lieu.",
  clubRequestsMembershipsEmpty: "Aucune adhésion en attente.",
  clubRequestsMembershipMeta: (clubName: string, ara: string, createdAt: string) =>
    `→ ${clubName} (${ara}) — demandé le ${createdAt}`,
  clubRequestsActivateMembership: "Activer l'adhésion",

  // ── ContainersSection ────────────────────────────────────────────────────────────────────────
  containersLoading: "Chargement des conteneurs",
  containersError: "Les conteneurs sont indisponibles.",
  containersEyebrow: "Infrastructure",
  containersHeading: "Conteneurs",
  containersEmpty: "Aucun conteneur à monitorer.",
  containersCaption: "État des conteneurs monitorés",
  containersColSignal: "Dernier signal",
  containerStatusLabel: (status: "up" | "down" | "unknown"): string =>
    ({ up: "Opérationnel", down: "Indisponible", unknown: "Inconnu" } as const)[status],
  containerSignalAge: (seconds: number) => `il y a ${seconds} s`,
  containersNoSignal: "Aucun signal",

  // ── DemosSection ─────────────────────────────────────────────────────────────────────────────
  demosLoading: "Chargement des comptes de démonstration",
  demosError: "Les comptes de démonstration sont indisponibles.",
  demosEyebrow: "Démonstrations",
  demosHeading: "Comptes de démonstration",
  demosRetainedHeading: "Clubs conservés",
  demosRetainedUntil: (date: string) => `jusqu’au ${date}`,
  demosBcclTitle: "Démo BCCL",
  demosProspectTitle: "Démo prospect",
  demosReset: "Réinitialiser",
  demosResetRunning: "Réinitialisation en cours…",
  demosLastResetAt: (datetime: string) => `Dernière réinitialisation lancée le ${datetime}`,
  demosNeverReset: "Jamais réinitialisée",
  demosResetConfirmTitle: "Réinitialiser la démo BCCL ?",
  demosResetConfirmDescription:
    "Tout ce qui a été modifié dans la démo sera effacé et la date simulée revient à aujourd’hui.",
  demosRetain: "Conserver 14 jours",
  demosRetainBlocked: "Fermez d’abord l’accès de la démonstration pour conserver le club.",
  demosRetainConfirmTitle: "Conserver le club de démonstration ?",
  demosRetainConfirmDescription:
    "Le club est détaché de la démonstration et conservé 14 jours : le contact officiel du club pourra ensuite le reprendre en s’inscrivant avec son code FFBB.",
  demosClockTitle: "Date simulée",
  demosClockLabel: (label: string) => `Date simulée — ${label}`,
  demosClockLivesAt: (date: string) => `La démo vit au ${date}.`,
  demosClockLivesToday: "La démo vit à la date du jour.",
  demosApply: "Appliquer",
  demosClockReset: "Revenir à aujourd’hui",
  demosActiveUntil: (time: string) => `Active jusqu’à ${time}`,
  demosInactive: "Inactive",
  demosReactivate: "Réactiver 4 h",
  demosActivate: "Activer 4 h",
  demosDeactivate: "Désactiver",
  // toasts (succès des mutations démo)
  demosToastResetStarted: "Réinitialisation de la démo BCCL lancée.",
  demosToastRetained: "Le club de démonstration est conservé 14 jours.",
  demosToastClockApplied: "Date simulée appliquée.",
  demosToastClockCleared: "La démo est revenue à aujourd’hui.",
  demosToastActivated: "Compte de démonstration activé pour 4 h.",
  demosToastDeactivated: "Compte de démonstration désactivé.",

  // ── DeploymentSection ────────────────────────────────────────────────────────────────────────
  deploymentHeading: "Mise en production programmée",
  deploymentEyebrow: "Exploitation",
  deploymentDescription:
    "Planifiez un déploiement : à l'heure dite, la maintenance s'active, la version choisie part, puis la maintenance se retire — sans intervention manuelle.",
  deploymentNotConfigured:
    "La mise en production programmée n'est pas configurée : un jeton GitHub (et le dépôt cible) doit être posé côté serveur avant de pouvoir programmer un déploiement.",
  deploymentGithubUnreachableTags: "GitHub est injoignable — la liste des versions n'a pas pu être chargée.",
  deploymentGithubUnreachableSchedule: "GitHub est injoignable — réessayez dans un instant.",
  deploymentActiveLabel: "Déploiement en attente",
  deploymentCancel: "Annuler la mise en production",
  deploymentVersionLabel: "Version",
  deploymentVersionPlaceholder: "— Choisir un tag —",
  deploymentDateLabel: "Date et heure de la mise en production",
  deploymentWindowLabel: "Durée de la maintenance (minutes)",
  deploymentSubmit: "Programmer",
  deploymentHistoryHeading: "Historique récent",
  deploymentHistoryError: "L'historique n'a pas pu être lu.",
  deploymentHistoryEmpty: "Aucun déploiement passé.",
  deploymentCancelConfirmTitle: "Annuler la mise en production ?",
  deploymentCancelConfirmDescription:
    "La mise en production programmée ne sera pas lancée. Vous pourrez en reprogrammer une autre.",
  deploymentCancelConfirmLabel: "Confirmer",
  deploymentCancelConfirmCancel: "Revenir",
  deploymentStatusLabel: (status: AdminDeployment["status"]): string =>
    ({
      scheduled: "Programmée",
      dispatched: "En cours",
      succeeded: "Réussie",
      failed: "Échec",
      cancelled: "Annulée",
    } as const)[status],

  // ── ExternalDepsSection ──────────────────────────────────────────────────────────────────────
  externalDepsLoading: "Chargement des dépendances externes",
  externalDepsError: "Les dépendances externes sont indisponibles.",
  externalDepsEyebrow: "Dépendances externes",
  externalDepsHeading: "Services externes",
  externalDepsEmpty: "Aucune dépendance externe configurée.",
  externalDepsCaption: "État des dépendances externes",
  externalDepsColLatency: "Latence",

  // ── FeedbackSection ──────────────────────────────────────────────────────────────────────────
  feedbackEyebrow: "Retours terrain",
  feedbackHeading: "Signalements",
  feedbackListLabel: "Liste des signalements",
  feedbackFilterGroupLabel: "Filtrer par statut",
  feedbackFilterAll: "Tous",
  feedbackFilterUntreated: "Non traités",
  feedbackFilterTreated: "Traités",
  feedbackEmpty: "Aucun signalement.",
  feedbackClubUnknown: "Club inconnu",
  feedbackClubUnknownLower: "club inconnu",
  feedbackViewSignalement: (club: string) => `Voir le signalement de ${club}`,
  feedbackQos: "Qualité de service",
  feedbackTreatedShare: "Part traitée",
  feedbackOldestUntreated: "Plus vieux non traité",
  feedbackDelayByMonth: "Délai dépôt → traité (par mois)",
  feedbackColMonth: "Mois",
  feedbackColAvg: "Moyenne",
  feedbackColP95: "p95",
  feedbackVolumeByTopic: "Volume par type (par mois)",
  feedbackColType: "Type",
  feedbackColVolume: "Volume",
  feedbackModalLabel: "Détail du signalement",
  feedbackModalTitle: "Signalement",
  feedbackCancel: "Annuler",
  feedbackConfirmTreat: "Confirmer le traitement",
  feedbackConfirmReopen: "Confirmer la réouverture",
  feedbackTreat: "Traiter",
  feedbackReopen: "Rouvrir",
  feedbackMetaClub: "Club",
  feedbackMetaType: "Type",
  feedbackMetaReceivedAt: "Reçu le",
  feedbackMetaStatus: "Statut",
  feedbackMessage: "Message",
  feedbackPurgedPlaceholder: "Contenu purgé (rétention)",
  feedbackLoadingContext: "Chargement du contexte…",
  feedbackContextError: "Le contexte n'a pas pu être lu.",
  feedbackContext: "Contexte",
  feedbackMetaScreen: "Écran",
  feedbackMetaUrl: "URL",
  feedbackMetaIncidentRef: "Réf. incident",
  feedbackMetaBrowser: "Navigateur",
  feedbackMetaSeason: "Saison",
  feedbackMetaSchedule: "Planning",
  feedbackMetaScheduleStatus: "État planning",
  feedbackSnapshotMeta: (bytes: string, count: number) =>
    `Snapshot : ${bytes} caractères · ${count} diagnostic${count > 1 ? "s" : ""}`,
  feedbackCopied: "Copié",
  feedbackCopyJson: "Copier le JSON",
  // Chantier F PR2 — recherche (club ou mot du message) + contexte large en 5 blocs titrés.
  feedbackSearchLabel: "Rechercher un signalement",
  feedbackSearchPlaceholder: "Club ou mot du message",
  feedbackBlockPlanning: "Planning",
  feedbackBlockPeriod: "Période",
  feedbackBlockScreen: "Écran",
  feedbackBlockLastAction: "Dernier geste",
  feedbackBlockModalState: "État de la modale",
  feedbackModalStateTruncated: "tronqué",
  feedbackBlockTechnical: "Technique",
  feedbackScreenshot: "Capture d'écran",
  feedbackScreenshotAlt: "Capture d'écran jointe au signalement",
  // Libellés de ligne du contexte large (5 blocs). « Écran »/« URL »/« Saison »/« Navigateur »/
  // « Réf. incident » sont réutilisés des clés feedbackMeta* ci-dessus.
  feedbackAutoSocle: "Socle",
  feedbackAutoVersions: "Versions",
  feedbackAutoFilters: "Filtres actifs",
  feedbackAutoRange: "Plage affichée",
  feedbackAutoLastClick: "Dernier clic",
  feedbackAutoIncident: "Incident",
  feedbackAutoOffer: "Offre",
  feedbackAutoCounters: "Volumétrie",
  feedbackAutoRole: "Rôle",
  feedbackAutoClock: "Horloge simulée",
  feedbackAutoGeneration: "Génération",
  // P4-355 — versions App (bundle front déclaré) / API (résolue serveur) + mention d'un écart.
  feedbackAutoVersion: "Versions",
  feedbackAutoGenerationFailure: "Échec de génération récent",
  feedbackSocleValue: (inForce: boolean, validatedAt: string | null): string =>
    inForce ? (null === validatedAt ? "En vigueur" : `En vigueur (validé le ${validatedAt})`) : "Aucune version pointée",
  feedbackVersionsValue: (displayed: number | null, pointed: number | null): string => {
    const parts: string[] = [];
    if (null !== displayed) parts.push(`affichée v${displayed}`);
    if (null !== pointed) parts.push(`en vigueur v${pointed}`);
    return 0 === parts.length ? "—" : parts.join(" · ");
  },
  feedbackPeriodValue: (name: string, startDate: string, endDate: string, progressPct: number): string =>
    `${name} · ${startDate} → ${endDate} · ${progressPct} %`,
  feedbackIncidentValue: (status: string, url: string, code: string | null): string =>
    null === code ? `${status} · ${url}` : `${status} · ${url} · ${code}`,
  feedbackCountersValue: (teams: number, venues: number): string =>
    `${teams} équipe${teams > 1 ? "s" : ""} · ${venues} gymnase${venues > 1 ? "s" : ""}`,
  feedbackGenerationValue: (running: boolean): string => (running ? "En cours au dépôt" : "Aucune"),
  feedbackVersionValue: (app: string | null, api: string | null): string => {
    const parts: string[] = [];
    if (null !== app && "" !== app) parts.push(`App v${app}`);
    if (null !== api && "" !== api) parts.push(`API v${api}`);
    return 0 === parts.length ? "—" : parts.join(" · ");
  },
  feedbackVersionMismatch: "Le bundle du navigateur est probablement en cache (version différente de l'API).",
  // Nature d'un échec de génération : panne d'infra (technique) vs contraintes incompatibles (métier).
  feedbackFailureKindLabel: (kind: "technical" | "business"): string => ("technical" === kind ? "technique" : "métier"),
  // Résumé d'un échec : nature, type du diagnostic, version fautive — le 1ᵉʳ message est rendu à part.
  feedbackFailureLead: (kind: string, type: string, versionNumber: number): string => `Échec ${kind} · ${type} · v${versionNumber}`,
  feedbackTopicLabel: (topic: AdminFeedbackTopic): string =>
    ({ bug: "Bug", missing_constraint: "Contrainte manquante", idea: "Idée" } as const)[topic],
  feedbackStatusLabel: (status: AdminFeedbackStatus): string =>
    ({ untreated: "Non traité", treated: "Traité" } as const)[status],

  // ── ReleaseNotesSection ──────────────────────────────────────────────────────────────────────
  releaseNotesEyebrow: "Communication",
  releaseNotesHeading: "Journal de nouveautés",
  releaseNotesDescription:
    "Ce que les membres voient dans « Nouveautés » et la modale « quoi de neuf ». Un brouillon reste invisible jusqu'à sa publication.",
  releaseNotesTitleLabel: "Titre",
  releaseNotesDateLabel: "Date",
  releaseNotesBodyLabel: "Contenu",
  releaseNotesSubmit: "Enregistrer",
  releaseNotesEmpty: "Aucune note pour le moment.",
  releaseNotesBadgeDraft: "Brouillon",
  releaseNotesBadgePublished: "Publiée",
  releaseNotesPublish: "Publier",
  releaseNotesDelete: "Supprimer",
  releaseNotesToastSaved: "Note enregistrée (brouillon).",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
