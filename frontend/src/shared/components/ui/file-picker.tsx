import { Upload } from "lucide-react";
import { useState, type ChangeEvent } from "react";

import { cn } from "@/shared/lib/utils";

interface FilePickerProps {
  /**
   * Nom accessible du champ (posé en `aria-label` sur l'input natif) ET libellé
   * VISIBLE du bouton-label. Une seule source : le même mot est lu par la souris et
   * par le lecteur d'écran, et `journey.spec.ts` cible ce nom via `getByLabel`.
   */
  label: string;
  accept?: string;
  disabled?: boolean;
  onFileChange: (file: File | null) => void;
  /** La largeur vit ici (le bouton-label est `self-start` par défaut), jamais sur l'input. */
  wrapperClassName?: string;
}

/**
 * #3 (retours Rillieux) — maison UNIQUE du dépôt de fichier, extraite du patron de
 * `ClubPage` (un `<label>` bordé cliquable, curseur main, input natif caché, nom
 * accessible conservé). La zone « choisir un fichier » des modales d'import était
 * peu visible, collée au texte et sans curseur main : la primitive la rend lisible
 * (bouton bordé + nom du fichier choisi affiché dessous) SANS changer la couture
 * a11y — l'input natif reste dans le DOM, son nom accessible est `label`.
 *
 * Surface OPAQUE (`bg-card`) sur le fond à motifs du body (P4-265) ; le survol garde
 * une teinte via une variante préfixée (`hover:bg-muted`). La largeur passe par
 * `wrapperClassName` (jamais `className` sur l'input).
 */
export function FilePicker({ label, accept, disabled = false, onFileChange, wrapperClassName }: FilePickerProps) {
  const [fileName, setFileName] = useState<string | null>(null);

  const handleChange = (e: ChangeEvent<HTMLInputElement>): void => {
    const file = e.target.files?.[0] ?? null;
    setFileName(file?.name ?? null);
    onFileChange(file);
  };

  return (
    <div className={cn("flex flex-col gap-1", wrapperClassName)}>
      <label className="inline-flex cursor-pointer items-center gap-2 self-start rounded-md border border-input bg-card px-3 py-1.5 text-sm hover:bg-muted">
        <Upload className="size-4 shrink-0" />
        <span>{label}</span>
        <input aria-label={label} type="file" accept={accept} className="hidden" disabled={disabled} onChange={handleChange} />
      </label>
      {null !== fileName ? <span className="text-xs text-muted-foreground">{fileName}</span> : null}
    </div>
  );
}
