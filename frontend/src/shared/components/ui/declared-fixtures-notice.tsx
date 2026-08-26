/**
 * P2-52 (RMM-10) — la phrase PARTAGÉE : des matchs DÉJÀ DÉCLARÉS à la fédération vont perdre
 * leur salle et devront être re-soumis. Extraite de `delete-confirm.tsx` pour être consommée
 * TELLE QUELLE par les deux gestes qui provoquent cette perte : la suppression d'un gymnase
 * (`DeleteConfirm`) et la validation d'un planning (`ValidateDialog` dans PlanningPage).
 *
 * Un match qui perd sa salle redevient « à placer » (récupérable) — mais un match déjà déposé
 * à la fédération est un engagement pris : il faudra le re-soumettre, et cela doit être DIT
 * avant de confirmer. C'est un ton d'AVERTISSEMENT, pas de catastrophe.
 */
export function DeclaredFixturesNotice({ count }: { count: number }) {
  if (count <= 0) {
    return null;
  }
  return (
    <p className="mt-3 text-foreground">
      <strong>{count}</strong> {count > 1 ? "matchs déjà déclarés" : "match déjà déclaré"} à la fédération {count > 1 ? "devront" : "devra"} être re-soumis à la fédération.
    </p>
  );
}
