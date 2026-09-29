// ── Configuration de la page de vente ────────────────────────────────────────
// LE nom et LES liens vivent ici, et seulement ici (décision fondateur
// 2026-08-10 : nom paramétrable). Le nom est TRANCHÉ depuis le 2026-08-15 —
// produit **Amateo**, éditeur **Maratech**, `amateo.app` acheté (P5-15).
// Changer le nom = changer UNE ligne, recharger, publier.
//
// ⚠ CACHE : ce fichier est chargé par `index.html` avec `?v=AAAA-MM-JJ` (cache-bust).
// Caddy `file_server` n'envoie pas de Cache-Control → un visiteur peut garder un ancien
// config.js en cache avec un index.html neuf. À CHAQUE ajout/retrait/renommage de clé ici,
// bumper la date du `?v=` dans `index.html` pour forcer la revalidation. (Le script d'injection
// dégrade proprement si une clé manque — repli texte, jamais d'image cassée — mais le `?v=`
// évite d'afficher ce repli à un visiteur qui revient.)
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
  // Éditeur (responsable de publication / de traitement RGPD) — variable, jamais
  // un littéral dans le HTML. Miroir par CONVENTION de `PUBLISHER_NAME`
  // (`frontend/src/shared/lib/product.ts`), jamais importé de `frontend/`.
  editor: "Maratech",
  // Le logo suit désormais le patron de l'app (BrandMark) : l'ICÔNE (les trois arcs,
  // `mark.svg` — arcs SEULS, comme BrandIcon, jamais le favicon `icon.svg` à disque blanc)
  // sert de mark thème-neutre, et le MOT (`brand`) est rendu en TEXTE à la couleur du thème —
  // un seul rendu en clair comme en sombre. Point unique : le chemin de l'icône vit ici
  // (injecté dans `[data-brand-logo]`), le mot vient de `brand`.
  logo: "assets/brand/mark.svg",
  // Mentions légales (LCEN + RGPD) — consommées par `mentions-legales.html`. Point unique.
  // Éditeur = micro-entreprise Maratech EN COURS DE CRÉATION (bêta) : nom affiché, pas
  // d'adresse postale (décision fondateur), contact = `contactEmail` ci-dessus.
  legal: {
    editorStatus: "micro-entreprise en cours de création",
    // Hébergeur — Scaleway SAS (siège social vérifié le 2026-09-29 sur la mention
    // légale officielle scaleway.com/legal-notice + registres RCS). LCEN art. 6-III :
    // dénomination, adresse et téléphone de l'hébergeur.
    host: {
      name: "Scaleway SAS",
      address: "8 rue de la Ville-l'Évêque, 75008 Paris, France",
      rcs: "RCS Paris 433 115 904",
      phone: "+33 1 84 13 00 00",
    },
  },
};
