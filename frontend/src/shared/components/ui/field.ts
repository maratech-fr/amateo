/**
 * La classe de champ natif PARTAGÉE — la MÊME apparence que la primitive `Input` (bordure
 * `border-input`, fond OPAQUE `bg-card`, focus ring), pour les rares champs qui ne peuvent PAS être
 * un `<Input>` : le `<textarea>` multi-lignes. Un vrai champ texte sur une ligne passe par `<Input>`
 * (jamais un `<input>` nu dans `features/`) ; cette constante n'habille QUE le textarea — foyer
 * unique, série « uniformité des écrans », PR 7/7, 2026-10-01. La hauteur reste libre (multi-lignes),
 * d'où l'absence de `h-9` ici.
 */
export const FIELD_CLASS =
  "w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50";
