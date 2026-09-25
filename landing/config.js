// ── Configuration de la page de vente ────────────────────────────────────────
// LE nom et LES liens vivent ici, et seulement ici (décision fondateur
// 2026-08-10 : nom paramétrable). Le nom est TRANCHÉ depuis le 2026-08-15 —
// produit **Amateo**, éditeur **Maratech**, `amateo.app` acheté (P5-15).
// Changer le nom = changer UNE ligne, recharger, publier.
window.LANDING_CONFIG = {
  brand: "Amateo",
  // L'URL de l'app (register/login). Convention retenue le 2026-08-18 : le domaine
  // NU est la vitrine (cette page), le sous-domaine `app.` est l'application —
  // ce qu'on vend mérite l'adresse qu'on écrit sur une plaquette.
  // ⚠ SANS slash final : les CTA concatènent (`appUrl + "/register"`).
  appUrl: "https://app.amateo.app",
  // Contact démo / questions — adresse pro du domaine produit (décision fondateur
  // 2026-08-17). Règle business tenue : jamais un Gmail perso sur la page.
  contactEmail: "contact@amateo.app",
  // Le logo (mark de marque) vit ici comme le nom : point unique. Injecté dans
  // l'en-tête et le pied (`[data-brand-logo]`). Source définitive du handoff marque.
  logo: "assets/brand/logo-couleur.png",
};
