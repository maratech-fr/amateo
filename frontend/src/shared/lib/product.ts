/**
 * Identité produit LUE PAR UN HUMAIN — la maison UNIQUE côté frontend (P5-15).
 * Le nom commercial et l'éditeur (responsable de traitement RGPD) vivent ici et
 * nulle part ailleurs : un renommage se fait EN UN POINT, il ne rouvre pas une
 * chasse aux littéraux dans les écrans d'auth, la console admin ou la page de
 * confidentialité.
 *
 * (Le titre d'onglet vit dans `index.html`, hors du système de modules TS — c'est
 * l'unique littéral hors de ce fichier, et un emplacement connu, pas un littéral
 * dispersé.)
 */
export const PRODUCT_NAME = "Amateo";
export const PUBLISHER_NAME = "Maratech";

/**
 * Accent PRODUIT par défaut — le teal signature du logo (`#46AFAC`), partagé PAR
 * CONVENTION avec la vitrine (`landing/index.html` `--accent`), jamais importé de
 * `landing/`. C'est la couleur d'un club qui n'a pas choisi la sienne : `useApplyClubTheme`
 * la fait passer par la MÊME dérivation par contraste que n'importe quel accent de club
 * (`accentForMode`/`readableForeground`/`accentHoverForMode`), il n'y a donc qu'une voie.
 * La maison UNIQUE de cet hex côté frontend — jamais un `#hex` d'accent ailleurs.
 */
export const PRODUCT_ACCENT = "#46AFAC";
