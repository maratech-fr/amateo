// ── Configuration de la page de vente ────────────────────────────────────────
// Source de ce que `assets/site.js` consomme : liens, mailto de démo, logo, mesure d'audience,
// pages `system-pages/` (décision fondateur 2026-08-10 : nom paramétrable). Le nom du produit
// est TRANCHÉ depuis le 2026-08-15 — **Amateo**, `amateo.app` acheté (P5-15).
// ⚠ Depuis P4-391 la marque est AUSSI écrite en clair dans le HTML (un robot sans JS la lit),
// comme les `href` des boutons d'appel (P4-407) et les mentions légales (éditeur, hébergeur) :
// renommer = changer la valeur ici ET `grep -rn` la valeur dans `landing/` et `system-pages/` ;
// `LandingDiscoverabilityTest` et `LandingClaimsTest` rougissent sur toute dérive.
//
// ⚠ CACHE : ce fichier est chargé par chaque page avec `?v=AAAA-MM-JJ` (cache-bust), le MÊME
// que `assets/site.css` et `assets/site.js`. Caddy `file_server` n'envoie pas de Cache-Control →
// un visiteur peut garder un ancien config.js en cache avec une page neuve. À CHAQUE
// ajout/retrait/renommage de clé ici, bumper le `?v=` dans les six pages. (`site.js` dégrade
// proprement si une clé ou tout le fichier manque — mais le `?v=` évite d'afficher ce repli.)
window.LANDING_CONFIG = {
  brand: "Amateo",
  // L'URL de l'app (register/login). Convention retenue le 2026-08-18 : le domaine
  // NU est la vitrine (cette page), le sous-domaine `app.` est l'application —
  // ce qu'on vend mérite l'adresse qu'on écrit sur une plaquette.
  // ⚠ SANS slash final : les CTA concatènent (`appUrl + "/register"`).
  appUrl: "https://app.amateo.app",
  // L'URL de la VITRINE (domaine nu). Même convention que `appUrl` : SANS slash final.
  // Consommée par les pages système (503/maintenance) pour pointer le logotype
  // cliquable vers la page de vente plutôt que vers l'app morte. Point unique :
  // un changement de domaine ne touche que cette ligne.
  siteUrl: "https://amateo.app",
  // Contact démo / questions — adresse pro du domaine produit (décision fondateur
  // 2026-08-17). Règle business tenue : jamais un Gmail perso sur la page.
  contactEmail: "contact@amateo.app",
  // Éditeur et responsable de publication : une PERSONNE, à titre personnel, tant qu'aucune
  // entreprise n'est immatriculée (raison sociale à poser le jour où elle existe — ligne de
  // roadmap). Écrit en clair dans `mentions-legales.html`, `qui-fait.html` et le JSON-LD
  // (`Person`) de `index.html` ; les tests lient ces copies à cette valeur.
  editor: "Franck Mpemba Boni",
  // Le logo suit désormais le patron de l'app (BrandMark) : l'ICÔNE (les trois arcs,
  // `mark.svg` — arcs SEULS, comme BrandIcon, jamais le favicon `icon.svg` à disque blanc)
  // sert de mark thème-neutre, et le MOT (`brand`) est rendu en TEXTE à la couleur du thème —
  // un seul rendu en clair comme en sombre. Point unique : le chemin de l'icône vit ici
  // (injecté dans `[data-brand-logo]`), le mot vient de `brand`.
  logo: "assets/brand/mark.svg",
  // Mentions légales (LCEN + RGPD) — écrites en clair dans `mentions-legales.html` (l'obligation
  // ne dépend d'aucun script) ; ces valeurs en sont la référence, gardée par `LandingClaimsTest`.
  // Pas d'adresse postale (décision fondateur), contact = `contactEmail` ci-dessus.
  legal: {
    editorStatus: "à titre personnel",
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
  // Mesure d'audience de la vitrine (Umami auto-hébergé, sans cookie — P4-276).
  // BRANCHÉ (F7, 2026-10-19) : l'instance Umami de prod existe et le site est créé
  // dans l'UI, d'où le `websiteId` ci-dessous (fourni par le fondateur, jamais inventé).
  // Double garde à l'injection (`assets/site.js`, chargé par chaque page) : le script n'est
  // injecté que si les deux clés sont non vides ET que le hostname courant = l'hôte de
  // `siteUrl` — rien ne part en local/:5173 ni sur un autre domaine, même ces valeurs
  // renseignées. Le `websiteId` n'est PAS un secret (il est visible dans toute page
  // trackée), sa place en git est correcte.
  analytics: {
    scriptUrl: "https://stats.amateo.app/script.js",
    websiteId: "881b2fa1-6b8e-4ed3-924c-96dc5a0b7a0b",
  },
};
