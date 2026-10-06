/**
 * « PRÊT » (décision fondateur) = le login a abouti ET la query `me` est en succès ET la
 * navigation est posée (idle) ET on n'est plus sur /login — une adhésion en attente rendue sur
 * /waiting compte donc comme prête, elle aussi.
 *
 * FRT-39 — vit à côté de `LoginSplash` (et non DANS son fichier) pour que le module du composant
 * n'exporte qu'un composant : un export de fonction utilitaire déclenchait un avertissement
 * `react-refresh/only-export-components` (le lint tourne désormais en `--max-warnings 0`).
 */
export function isConnectionReady(p: { pathname: string; navIdle: boolean; meReady: boolean }): boolean {
  return p.meReady && p.navIdle && "/login" !== p.pathname;
}
