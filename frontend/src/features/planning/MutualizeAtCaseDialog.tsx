import { Users } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { Spinner } from "@/shared/components/ui/spinner";

const LABEL_MAX = 40;

/**
 * Lot 2 — MUTUALISER DEPUIS LE DÉPÔT (rejoindre / fusion), déclenché par « Mutualiser » du menu de
 * dépôt (`DropActionMenu`). La séance SOURCE déposée et la séance CIBLE deviennent UNE séance commune
 * sur la case de la cible — « on répartit, on ne crée rien » : chaque équipe garde son total de
 * séances. La modale CONFIRME le geste (nomme les deux côtés, le nombre d'équipes regroupées) et
 * propose un nom de groupe optionnel ; le serveur reste seul juge (capacité, Σ, > 10, verrou…) et un
 * refus 422 est AFFICHÉ tel quel, jamais re-dérivé. Réutilise `Modal`/`Button`/`Input`/`NoticeBanner`.
 */
interface MutualizeAtCaseDialogProps {
  /** Nom lisible du côté déposé (une équipe, ou « le groupe (N équipes) »). */
  sourceName: string;
  /** Nom lisible du côté occupant (idem). */
  occupantName: string;
  /** Nombre d'équipes du bloc résultant (union des deux côtés), pour l'annonce. */
  teamCount: number;
  busy: boolean;
  /** Message serveur d'un refus 422/409 (déjà humain), affiché TEL QUEL ; `null` = aucun. */
  error: string | null;
  onClose: () => void;
  onConfirm: (label: string | undefined) => void;
}

export function MutualizeAtCaseDialog({ sourceName, occupantName, teamCount, busy, error, onClose, onConfirm }: MutualizeAtCaseDialogProps) {
  const [label, setLabel] = useState("");

  const submit = () => {
    if (busy) {
      return;
    }
    const trimmed = label.trim();
    onConfirm("" === trimmed ? undefined : trimmed);
  };

  return (
    <Modal
      label="Mutualiser les deux séances"
      title={
        <span className="flex items-center gap-2">
          <Users className="size-5 text-accent" aria-hidden="true" />
          Mutualiser sur ce créneau
        </span>
      }
      size="md"
      onClose={onClose}
      footer={
        <div className="flex items-center justify-end gap-2">
          <Button variant="ghost" onClick={onClose} disabled={busy}>
            Annuler
          </Button>
          <Button onClick={submit} disabled={busy}>
            {busy ? (
              <>
                <Spinner className="size-4" />
                Mutualisation…
              </>
            ) : (
              "Mutualiser"
            )}
          </Button>
        </div>
      }
    >
      <p className="text-sm text-muted-foreground">
        <span className="font-medium text-foreground">{sourceName}</span> et <span className="font-medium text-foreground">{occupantName}</span> s'entraîneront ENSEMBLE sur ce créneau, en une séance commune — chaque équipe garde son nombre de séances.
      </p>
      <p className="mt-2 text-sm text-muted-foreground">
        {teamCount} équipe{teamCount > 1 ? "s" : ""} seront regroupées sur la case.
      </p>

      <div className="mt-4">
        <label className="block text-sm font-medium text-foreground" htmlFor="mutualize-at-case-label">
          Nom du groupe <span className="font-normal text-muted-foreground">(optionnel)</span>
        </label>
        <Input
          id="mutualize-at-case-label"
          className="mt-1"
          maxLength={LABEL_MAX}
          placeholder="Ex. U18"
          value={label}
          onChange={(e) => setLabel(e.target.value)}
        />
        <p className="mt-1 text-xs text-muted-foreground">Affiché sur la grille, la fiche et les exports. {LABEL_MAX} caractères maximum. Ignoré si le groupe rejoint un bloc existant.</p>
      </div>

      {null !== error ? <NoticeBanner tone="destructive" role="alert" className="mt-4" message={error} /> : null}
    </Modal>
  );
}
