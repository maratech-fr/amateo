/**
 * Lot L — « validé ligue » en lot : le vocabulaire et le corps chiffré de la
 * confirmation, PURS (aucun rendu). Vivent en `lib/` pour rester testables sans
 * monter de composant — et pour que `LeagueValidation.tsx` n'exporte que des
 * composants (fast-refresh).
 */

/** Deep-link vers la section « Échéances de saisie » de la configuration matchs. */
export const ENTRY_DEADLINES_PATH = "/matchs/configuration?section=echeances";

export const LEAGUE_VALIDATION_CONFIRM_LABEL = "Marquer « validé ligue »";

/** Le corps chiffré de la confirmation — annonce le nombre ET ce qui va changer. */
export function leagueValidationBody(count: number): string {
  const verb = count > 1 ? "portent" : "porte";
  return (
    `${count} rencontre${count > 1 ? "s" : ""} importée${count > 1 ? "s" : ""} ${verb} déjà leur date, leur heure et leur gymnase dans FBI — ` +
    `la fédération les connaît. Les marquer « validé ligue » verrouille leur placement : le solveur les traite comme des ancres. ` +
    `Les rencontres sans heure ou sans gymnase ne sont pas concernées ; refuser ne change rien.`
  );
}
