// Textes centralisés de la console superadmin — les JOURNAUX (P4-346, PR B).
// Couvre les 3 sous-onglets de `features/admin/Journaux/*` : AuditSubtab, MessengerFailedSubtab,
// SystemErrorsSubtab. Les sections vivent dans `texts/sections.ts`, la coquille dans
// `texts/dashboard.ts`.
//
// Convention (patron features/planning/texts.ts) : une clé camelCase PAR INTENTION (préfixée par
// sous-onglet) ; valeur = copie BYTE-IDENTIQUE du libellé rendu ; interpolation = fonction typée
// (pluriels compris). JAMAIS comme valeur : une DONNÉE servie (email d'acteur, route, classe de
// message, source/message d'erreur, code HTTP).

// Même formateur d'entiers que les journaux (`new Intl.NumberFormat("fr-FR")`).
const nf = new Intl.NumberFormat("fr-FR");

export const ADMIN_JOURNAUX_TEXTS = {
  // ── Partagés par les 3 sous-onglets ──────────────────────────────────────────────────────────
  retry: "Réessayer",
  pagePrev: "Page précédente",
  pageNext: "Page suivante",

  // ── AuditSubtab ──────────────────────────────────────────────────────────────────────────────
  auditLoading: "Chargement du journal d’audit",
  auditError: "Le journal d’audit est indisponible.",
  auditEmpty: "Aucun SuperAdmin audité pour le moment",
  auditCaption: "Journal d’audit super-admin",
  auditColActor: "Acteur",
  auditColRoute: "Route",
  auditColStatus: "Statut",
  auditColDate: "Date",
  auditPagination: (total: number, page: number, pages: number) =>
    `${nf.format(total)} entrée${total > 1 ? "s" : ""} · page ${page} sur ${pages}`,
  auditStatusLabel: (status: "success" | "error" | "unknown"): string =>
    ({ success: "Succès", error: "Erreur", unknown: "—" } as const)[status],

  // ── MessengerFailedSubtab ────────────────────────────────────────────────────────────────────
  messengerLoading: "Chargement des messages en échec",
  messengerError: "Les messages en échec sont indisponibles.",
  messengerEyebrow: "File d’erreurs Messenger",
  messengerHeading: "Messages en échec",
  messengerRefreshHint: "Rafraîchi toutes les 60 s",
  messengerEmpty: "Aucun message en échec — le système est sain",
  messengerCaption: "Messages Messenger en échec (retry épuisé)",
  messengerColClass: "Classe",
  messengerColFailedAt: "Date d’échec",
  messengerColError: "Erreur",
  messengerPagination: (total: number, page: number, pages: number) =>
    `${nf.format(total)} message${total > 1 ? "s" : ""} · page ${page} sur ${Math.max(pages, 1)}`,

  // ── SystemErrorsSubtab ───────────────────────────────────────────────────────────────────────
  systemErrorsLoading: "Chargement des erreurs système",
  systemErrorsError: "Les erreurs système sont indisponibles.",
  systemErrorsEmpty: "Aucune erreur système enregistrée",
  systemErrorsCaption: "Erreurs système agrégées par source",
  systemErrorsColSource: "Source",
  systemErrorsColMessage: "Message",
  systemErrorsColSeverity: "Sévérité",
  systemErrorsColDate: "Date",
  systemErrorsPagination: (total: number, page: number, pages: number) =>
    `${nf.format(total)} erreur${total > 1 ? "s" : ""} · page ${page} sur ${pages}`,
  systemErrorSeverityLabel: (severity: "error" | "warning" | "info"): string =>
    ({ error: "Erreur", warning: "Avertissement", info: "Information" } as const)[severity],
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
