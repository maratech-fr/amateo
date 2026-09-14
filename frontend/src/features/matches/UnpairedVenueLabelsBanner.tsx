import { ArrowRight, Info, TriangleAlert } from "lucide-react";
import { useNavigate } from "react-router";

import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import { useVenueLabelInventory } from "./queries";

/**
 * E2 — le renvoi partagé vers l'écran d'appariement des salles (7ᵉ section de la
 * Configuration, deep-link `?section=libelles`). Un seul endroit décide du chemin
 * ET du libellé du bouton : le bandeau, le compteur de semaine et le rapport d'import
 * s'y réfèrent — « même chose, au même endroit, de la même façon ».
 */
export const PAIR_VENUES_PATH = "/matchs/configuration?section=libelles";
export const PAIR_VENUES_LABEL = "Apparier les salles";

/**
 * Le bouton de renvoi (ghost, flèche) — réutilisé par le bandeau et le compteur de
 * semaine. `onNavigate` permet à un appelant de FERMER d'abord (le rapport d'import
 * ferme sa modale avant de naviguer, patron « Ouvrir la file » — pas de modale sur modale).
 */
function PairVenuesButton() {
  const navigate = useNavigate();
  return (
    <Button variant="ghost" size="sm" className="shrink-0" onClick={() => void navigate(PAIR_VENUES_PATH)}>
      {PAIR_VENUES_LABEL}
      <ArrowRight className="size-4" aria-hidden="true" />
    </Button>
  );
}

/**
 * E2 (décision 3) — le SIGNAL partagé : « N libellés de salle non appariés — M
 * domiciles n'apparaissent pas sur la grille » + un renvoi vers l'écran d'appariement.
 * Rendu sur Importer, Semaine et Consulter (vue Semaine), au-dessus de la grille.
 *
 * Lit l'inventaire (`useVenueLabelInventory`) — présentation PURE, aucun calcul
 * métier : le backend a agrégé, on compte les lignes sans `venueId`. `undefined`
 * (chargement/échec) ⇒ rendu NUL (jamais un faux calme fabriqué, `readState`) ;
 * 0 libellé non apparié ⇒ rendu NUL (le bandeau ne s'affiche QUE s'il y a à faire).
 *
 * Ton : `warning` (quelque chose d'incomplet appelle une action) mais jamais rouge ;
 * `role="status"` + `aria-live="polite"` (informatif/actionnable présent au chargement —
 * jamais `alert`, réservé à l'urgent/erreur — passe design 2026-09-14). Le TEXTE reste
 * `text-foreground` (AA), la teinte vit dans la bordure/le fond et l'icône
 * (`text-warning`, élément graphique ≥ 3:1) — recette `StatusPill`, gotcha #11.
 */
export function UnpairedVenueLabelsBanner() {
  const inventory = useVenueLabelInventory();
  const rows = inventory.data;
  if (undefined === rows) {
    return null;
  }
  const unpaired = rows.filter((row) => null === row.venueId);
  if (0 === unpaired.length) {
    return null;
  }
  const labels = unpaired.length;
  const homes = unpaired.reduce((sum, row) => sum + row.unplacedCount, 0);

  return (
    <div role="status" aria-live="polite" className="flex flex-wrap items-center gap-2 rounded-lg border border-warning/50 bg-warning/10 px-3 py-2 text-sm text-foreground">
      <TriangleAlert className="size-4 shrink-0 text-warning" aria-hidden="true" />
      <span className="grow tabular-nums">
        {labels} libellé{labels > 1 ? "s" : ""} de salle non apparié{labels > 1 ? "s" : ""} — {homes} domicile{homes > 1 ? "s" : ""}{" "}
        {homes > 1 ? "n'apparaissent" : "n'apparaît"} pas sur la grille.
      </span>
      <PairVenuesButton />
    </div>
  );
}

/**
 * E2 (décision 4) — le compteur de semaine : « N domiciles de ce week-end sans
 * gymnase, non affichés », sous la grille de Semaine et de Consulter. Présentation
 * pure (`count` dérivé du cache `useFixtures` par l'appelant : HOME, `venueId` null,
 * dans la semaine affichée). Ton NEUTRE et discret (`text-muted-foreground`) : le
 * bandeau warning en tête porte l'alarme, cette ligne est un écho tranquille adossé à
 * la grille visible — mais perceptible (icône + texte + bouton, jamais la couleur
 * seule), jamais un faux calme (passe design 2026-09-14). `count <= 0` ⇒ rendu NUL.
 */
export function HiddenHomesWeekNotice({ count }: { count: number }) {
  if (count <= 0) {
    return null;
  }
  return (
    <div className={cn("flex flex-wrap items-center gap-2 text-sm text-muted-foreground")}>
      <Info className="size-4 shrink-0" aria-hidden="true" />
      <span className="grow tabular-nums">
        {count} domicile{count > 1 ? "s" : ""} de ce week-end sans gymnase, non affiché{count > 1 ? "s" : ""}.
      </span>
      <PairVenuesButton />
    </div>
  );
}
