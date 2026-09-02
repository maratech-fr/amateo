const plural = (n: number, singular: string, plural: string): string => `${n} ${1 === n ? singular : plural}`;

/**
 * P2-44 PR-4 — le COMPTEUR DE CARENCE : une phrase factuelle et neutre (jamais une alarme) qui dit
 * au démarrage d'une FERMETURE combien de places manquent. Les nombres viennent du récap serveur
 * (`capacity.demand` bloc-aware / `capacity.offer`), présentation pure. Singulier/pluriel corrects.
 */
export function capacityShortfallSentence(demand: number, offer: number): string {
  const base = `${plural(demand, "séance demandée", "séances demandées")} pour ${plural(offer, "place disponible", "places disponibles")}`;
  return demand > offer ? `${base} — il manque ${plural(demand - offer, "place", "places")}.` : `${base}.`;
}
