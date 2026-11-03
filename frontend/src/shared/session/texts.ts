/**
 * Textes de la SESSION partagée (chantier « un Membre lit tout, ne modifie rien ») — maison
 * unique des libellés que `shared/session/` rend sur plusieurs écrans : le bandeau de
 * consultation d'un Membre et le bandeau « club sans gestionnaire ».
 */
export const SESSION_TEXTS = {
  readOnlyNotice: "Consultation : réservé aux gestionnaires",
  clubClosing: (date: string) =>
    `Ce club n'a plus de gestionnaire : ses données seront effacées le ${date}, sauf reprise par un gestionnaire (inscription avec le code FFBB du club, validée par son adresse officielle).`,
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
