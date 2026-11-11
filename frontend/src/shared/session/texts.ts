/**
 * Textes de la SESSION partagée (chantier « un Membre lit tout, ne modifie rien ») — maison
 * unique des libellés que `shared/session/` rend sur plusieurs écrans : le bandeau de
 * consultation d'un Membre et le bandeau « club sans gestionnaire » (et son geste de reprise).
 */
export const SESSION_TEXTS = {
  readOnlyNotice: "Consultation : réservé aux gestionnaires",
  // Chantier Membre (PR 3) : la date d'effacement, puis le geste de reprise depuis le bandeau.
  clubClosing: (date: string) => `Ce club n'a plus de gestionnaire : ses données seront effacées le ${date}, sauf reprise.`,
  requestManagerRole: "Demander à devenir gestionnaire",
  // UXS-20 — confirmation de la demande (petites lignes, plan §2).
  requestManagerRoleConfirmTitle: "Demander à devenir gestionnaire ?",
  requestManagerRoleConfirmDescription:
    "Les gestionnaires du club recevront votre demande. Sans réponse de leur part, elle sera transmise à l'adresse officielle du club.",
  requestManagerRoleConfirmLabel: "Envoyer la demande",
  repriseRequestSent: (date: string) => `Votre demande de reprise a été transmise le ${date} à l'adresse officielle du club.`,
  managerRequestNoOfficialAddress: "Le club n'a pas d'adresse connue : le support validera.",
  managerRequestExpired: (date: string) => `Le club n'a pas répondu à votre demande du ${date}. Vous pouvez la renvoyer.`,
  managerRequestRefused: (date: string, until: string) =>
    `Le club a refusé votre demande le ${date}. Vous pourrez la renouveler à partir du ${until}.`,
  managerRequestSent: "Demande envoyée.",
  managerRequestCancelled: "Demande annulée.",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
