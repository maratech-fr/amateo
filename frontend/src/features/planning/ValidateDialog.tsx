import { AlertTriangle, CheckCircle2, Loader2, MapPinOff } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { DeclaredFixturesNotice } from "@/shared/components/ui/declared-fixtures-notice";
import { Modal } from "@/shared/components/ui/modal";

/** P2-52 — l'impact de dépointage de la validation, tel que l'écran le connaît au moment de
 *  confirmer. `orphanCount` = matchs qui perdront leur salle ; `declaredCount` = sous-ensemble
 *  déjà déclaré à la fédération. `loading`/`failed` : on ne laisse JAMAIS confirmer sur un impact
 *  inconnu (jamais « inconnu = vide »). */
export interface OrphanImpact {
  orphanCount: number;
  declaredCount: number;
  loading: boolean;
  failed: boolean;
  onRetry: () => void;
}

/**
 * La confirmation de validation d'un planning. Elle S'AFFICHE comme aujourd'hui (aucun dialogue
 * ajouté) ; l'annonce « salle perdue » de P2-52 n'apparaît QUE si N>0 (aucun bruit préventif),
 * et le bouton Valider reste désactivé tant que l'impact n'est pas connu (en vol / échec).
 */
export function ValidateDialog({ hasAlerts, siblingCount, busy, orphan, onConfirm, onCancel }: { hasAlerts: boolean; siblingCount: number; busy: boolean; orphan: OrphanImpact; onConfirm: () => void; onCancel: () => void }) {
  // On ne laisse pas confirmer tant que l'impact n'est pas connu (en vol / échec) : confirmer à
  // l'aveugle est exactement ce que la route d'impact existe pour éviter.
  const confirmDisabled = busy || orphan.loading || orphan.failed;
  return (
    <Modal
      size="sm"
      label="Valider le planning"
      title={
        <span className="flex items-center gap-2">
          {hasAlerts ? <AlertTriangle aria-hidden="true" className="size-5 text-warning" /> : <CheckCircle2 aria-hidden="true" className="size-5 text-muted-foreground" />}
          Valider ce planning ?
        </span>
      }
      // Block Escape/overlay/X dismissal while the validation is in flight: dismissing
      // mid-request would hide the dialog but let the un-aborted mutation still lock the
      // planning read-only (the raw dialog had no escape at all during busy).
      onClose={() => {
        if (!busy) {
          onCancel();
        }
      }}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onCancel} disabled={busy}>
            Annuler
          </Button>
          <Button size="sm" onClick={onConfirm} disabled={confirmDisabled}>
            Valider
          </Button>
        </>
      }
    >
      <p className="mt-2 text-sm text-muted-foreground">
        {hasAlerts
          ? "Ce planning présente des alertes du système (créneaux non placés, contraintes non satisfaites…). En le validant, vous assumez ces contre-indications sous votre responsabilité. Le planning passera en lecture seule."
          : "Le planning passera en lecture seule (« Validé »). Vous pourrez le rouvrir pour le modifier."}
      </p>
      {/* P2-52 — l'annonce « salle perdue » N'APPARAÎT QUE si N>0 (aucun bruit préventif sinon).
          Ton destructif encadré, distinct des deux paragraphes en prose ci-dessus/dessous. */}
      {orphan.orphanCount > 0 ? (
        <div className="mt-3 flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-foreground">
          <MapPinOff aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-destructive" />
          <div>
            <p>
              {orphan.orphanCount > 1 ? `${orphan.orphanCount} matchs perdront leur salle` : "1 match perdra sa salle"}
              {orphan.declaredCount > 0 ? `, dont ${orphan.declaredCount} déjà ${orphan.declaredCount > 1 ? "déclarés" : "déclaré"} à la fédération` : ""}.
            </p>
            <p className="mt-1 text-muted-foreground">
              {orphan.orphanCount > 1 ? "Ils repasseront « à placer », leur horaire conservé — vous pourrez leur réattribuer un gymnase." : "Il repassera « à placer », son horaire conservé — vous pourrez lui réattribuer un gymnase."}
            </p>
            <DeclaredFixturesNotice count={orphan.declaredCount} />
          </div>
        </div>
      ) : null}
      {/* En vol / échec : le bouton Valider est désactivé, et on DIT pourquoi (jamais un bouton
          grisé muet, jamais un impact inconnu présenté comme vide). */}
      {orphan.loading ? (
        <p aria-live="polite" className="mt-3 flex items-center gap-1.5 text-sm text-muted-foreground">
          <Loader2 aria-hidden="true" className="size-3.5 animate-spin" />
          Vérification des matchs concernés…
        </p>
      ) : null}
      {orphan.failed ? (
        <p aria-live="polite" className="mt-3 text-sm text-destructive">
          Vérification impossible pour l'instant —{" "}
          <button type="button" onClick={orphan.onRetry} className="underline underline-offset-2 hover:no-underline">
            Réessayer
          </button>
          .
        </p>
      ) : null}
      {siblingCount > 0 ? (
        <p className="mt-3 text-sm font-medium text-foreground">
          Seule cette version sera conservée — {siblingCount > 1 ? `les ${siblingCount} autres versions seront définitivement supprimées` : "l'autre version sera définitivement supprimée"}.
        </p>
      ) : null}
    </Modal>
  );
}
