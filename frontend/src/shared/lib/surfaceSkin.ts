/**
 * Quelle PEAU une surface porte — la console superadmin (sombre : jetons `--console-*`,
 * ancres `white`/`cyan`) ou l'application club (jetons du thème). Un seul foyer pour la
 * distinction, parce qu'elle n'appartient à AUCUN composant en particulier : les onglets
 * (`ui/tabs.tsx`), les états vides (`ui/empty-hint.tsx`) et tout futur primitive à double
 * peau la partagent. La laisser dans `tabs.tsx` obligerait `empty-hint.tsx` à importer un
 * type depuis un composant frère pour une notion qui n'est pas la sienne.
 *
 * `app` est TOUJOURS le défaut d'un composant qui l'accepte : la console doit demander sa
 * peau explicitement (l'oublier peint des jetons clairs sur fond sombre).
 */
export type SurfaceSkin = "console" | "app";
