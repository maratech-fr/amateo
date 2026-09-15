import type { ReactNode } from "react";

/**
 * FilterToggle — la case à cocher PARTAGÉE d'un filtre d'affichage (« Masquer les
 * traités », « Afficher les traitées »…). Maison unique du patron né inline dans
 * `ReviewQueue` (P4-207) : une case `size-4` + un libellé en `text-muted-foreground`,
 * la ligne entière cliquable (le `<label>` enveloppe l'input). L'état vit chez
 * l'appelant (miroir de l'URL) — ce composant n'est que la présentation.
 */
export function FilterToggle({ checked, onChange, children }: { checked: boolean; onChange: (next: boolean) => void; children: ReactNode }) {
  return (
    <label className="flex w-fit items-center gap-2 text-sm text-muted-foreground">
      <input type="checkbox" checked={checked} onChange={(event) => onChange(event.target.checked)} className="size-4" />
      {children}
    </label>
  );
}
