/**
 * Légende CONDITIONNELLE sous la grille week-end (lot 1, décision fondateur 2026-09-17) :
 * elle n'explique que ce que la semaine affichée contient RÉELLEMENT.
 *  - « à confirmer » (`toConfirmCount > 0`) : un échantillon hachuré + le compte + le
 *    pourquoi (gymnase et heure repris de l'import, pas encore placés).
 *  - « Habitude » (`showHabits`) : un échantillon pointillé + « fenêtre protégée » —
 *    montré seulement quand « Semaine type » est active ET qu'il y a des fantômes.
 *  - « Trajet aller-retour » (`showTravel`, correctif 10) : un échantillon hachuré `muted` +
 *    l'explication — montré seulement quand un bloc extérieur porte un trajet dessiné.
 *
 * Présentation PURE (l'appelant dérive les entrées du modèle de grille). Aucune entrée
 * présente ⇒ rendu NUL. Le vocabulaire visuel (hachures accent = match en attente, pointillé =
 * habitude, hachures muted = trajet) est celui de `WeekendGrid` — mêmes motifs, mêmes tokens.
 */
export function WeekendGridLegend({ toConfirmCount, showHabits, showTravel = false }: { toConfirmCount: number; showHabits: boolean; showTravel?: boolean }) {
  if (toConfirmCount <= 0 && !showHabits && !showTravel) {
    return null;
  }
  return (
    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
      {toConfirmCount > 0 ? (
        <span className="flex items-center gap-1.5">
          <span
            aria-hidden="true"
            className="size-3 shrink-0 rounded border border-border"
            style={{ backgroundImage: "repeating-linear-gradient(45deg, color-mix(in oklch, var(--accent) 22%, transparent) 0 4px, transparent 4px 9px)" }}
          />
          <span className="tabular-nums">
            {toConfirmCount} à confirmer — gymnase et heure repris de l'import, pas encore placé{toConfirmCount > 1 ? "s" : ""}
          </span>
        </span>
      ) : null}
      {showHabits ? (
        <span className="flex items-center gap-1.5">
          <span aria-hidden="true" className="size-3 shrink-0 rounded border border-dashed border-border" />
          <span>Habitude — fenêtre protégée</span>
        </span>
      ) : null}
      {showTravel ? (
        <span className="flex items-center gap-1.5">
          <span
            aria-hidden="true"
            className="size-3 shrink-0 rounded border border-border bg-surface-muted"
            style={{ backgroundImage: "repeating-linear-gradient(45deg, color-mix(in oklch, var(--muted-foreground) 14%, transparent) 0 2px, transparent 2px 7px)" }}
          />
          <span>Trajet aller-retour — depuis/vers le siège du club</span>
        </span>
      ) : null}
    </div>
  );
}
