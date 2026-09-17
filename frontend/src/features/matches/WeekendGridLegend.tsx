/**
 * Légende CONDITIONNELLE sous la grille week-end (lot 1, décision fondateur 2026-09-17) :
 * elle n'explique que ce que la semaine affichée contient RÉELLEMENT.
 *  - « à confirmer » (`toConfirmCount > 0`) : un échantillon hachuré + le compte + le
 *    pourquoi (gymnase et heure repris de l'import, pas encore placés).
 *  - « Habitude » (`showHabits`) : un échantillon pointillé + « fenêtre protégée » —
 *    montré seulement quand « Semaine type » est active ET qu'il y a des fantômes.
 *
 * Présentation PURE (l'appelant dérive les deux entrées du modèle de grille). Aucune
 * entrée présente ⇒ rendu NUL. Le vocabulaire visuel (hachures = match en attente,
 * pointillé = habitude) est celui de `WeekendGrid` — mêmes motifs, mêmes tokens.
 */
export function WeekendGridLegend({ toConfirmCount, showHabits }: { toConfirmCount: number; showHabits: boolean }) {
  if (toConfirmCount <= 0 && !showHabits) {
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
    </div>
  );
}
