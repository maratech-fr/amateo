/**
 * Identité produit LUE PAR UN HUMAIN — la maison UNIQUE côté frontend (P5-15).
 * Le nom commercial et l'éditeur (responsable de traitement RGPD) vivent ici et
 * nulle part ailleurs : un renommage se fait EN UN POINT, il ne rouvre pas une
 * chasse aux littéraux dans les écrans d'auth, la console admin ou la page de
 * confidentialité.
 *
 * (Le titre d'onglet ET les balises de partage `og:`/`twitter:` vivent dans `index.html`,
 * hors du système de modules TS — des littéraux à un emplacement CONNU, pas dispersés :
 * le titre, la copie de la carte de partage recopiée de la vitrine, et les URL absolues
 * de la page. Le nom de MARQUE, lui, n'y figure NULLE PART hors du `<title>` — gardé par
 * `tooling/noscript.test.ts` (bloc noscript) et `tooling/og.test.ts` (carte de partage).)
 */
export const PRODUCT_NAME = "Amateo";
export const PUBLISHER_NAME = "Maratech";

/**
 * Site public de la VITRINE (domaine nu, hors app) — URL ABSOLUE inter-domaines, SANS
 * slash final (la vitrine et l'app sont deux hôtes : `amateo.app` vs `app.amateo.app`).
 * Distinct de `LEGAL_NOTICE_URL` : celui-ci pointe la RACINE de la vitrine, pas une page.
 * Maison unique côté frontend du lien vers la vitrine — le pied « Propulsé par … découvrir »
 * de la page publique de doléances (`PublicWishPage`) le lit, seul consommateur à ce jour.
 */
export const PRODUCT_SITE_URL = "https://amateo.app";

/**
 * Mentions légales — page publique de la VITRINE (domaine nu, hors app), donc une URL
 * ABSOLUE inter-domaines (la vitrine et l'app sont deux hôtes : `amateo.app` vs
 * `app.amateo.app`). Maison unique côté frontend, comme le nom : la page de confidentialité
 * y renvoie, sans littéral dispersé. La vitrine tient les mentions elles-mêmes
 * (`landing/mentions-legales.html`), leurs VALEURS dans `landing/config.js` ; ceci n'est
 * que le LIEN vers cette page.
 */
export const LEGAL_NOTICE_URL = "https://amateo.app/mentions-legales.html";

/**
 * Accent PRODUIT par défaut — le teal signature du logo (`#46AFAC`), partagé PAR
 * CONVENTION avec la vitrine (`landing/index.html` `--accent`), jamais importé de
 * `landing/`. C'est la couleur d'un club qui n'a pas choisi la sienne : `useApplyClubTheme`
 * la fait passer par la MÊME dérivation par contraste que n'importe quel accent de club
 * (`accentForMode`/`readableForeground`/`accentHoverForMode`), il n'y a donc qu'une voie.
 * La maison UNIQUE de cet hex côté frontend — jamais un `#hex` d'accent ailleurs.
 */
export const PRODUCT_ACCENT = "#46AFAC";
