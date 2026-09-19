/**
 * C7 — les INITIALES d'un adversaire, repli quand aucun logo fédéral n'est connu. On retire
 * les mots « génériques » du basket français (BC, BB, AS, …, CLUB, BASKET, BALL, UNION,
 * SPORTIVE, ASSOCIATION) qui n'identifient rien, puis on prend la 1ʳᵉ lettre des deux premiers
 * mots restants ; un seul mot → ses deux premières lettres ; plus rien → les deux premières du
 * libellé brut. Majuscules, ACCENTS CONSERVÉS (« École » → « ÉD »).
 */
const STOP_WORDS = new Set(["BC", "BB", "AS", "US", "ES", "CS", "SC", "JS", "AL", "CLUB", "BASKET", "BALL", "UNION", "SPORTIVE", "ASSOCIATION"]);

export function opponentInitials(label: string): string {
  const raw = label.trim();
  const words = raw.toUpperCase().split(/[\s-]+/).filter((w) => "" !== w);
  const kept = words.filter((w) => !STOP_WORDS.has(w));

  if (kept.length >= 2) {
    return kept[0].slice(0, 1) + kept[1].slice(0, 1);
  }
  if (1 === kept.length) {
    return kept[0].slice(0, 2);
  }
  // Que des mots génériques (ou vide) : les deux premières lettres du libellé brut, pour
  // toujours montrer QUELQUE CHOSE (séparateurs retirés pour ne pas rendre un espace).
  return raw.toUpperCase().replace(/[\s-]+/g, "").slice(0, 2);
}
