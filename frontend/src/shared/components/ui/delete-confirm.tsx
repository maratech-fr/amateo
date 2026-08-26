import type { DeletionImpact } from "@/shared/api/deletionImpact";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { DeclaredFixturesNotice } from "@/shared/components/ui/declared-fixtures-notice";

interface DeleteConfirmProps {
  open: boolean;
  /** The thing being deleted, shown in the title + sentence (e.g. a team/coach/venue name). */
  entityName: string;
  /**
   * P3-16 — l'impact CALCULÉ PAR LE SERVEUR (`GET /api/{venues|teams|coaches}/{id}/deletion-impact`).
   * `undefined` = pas encore répondu, `null` = pas d'impact serveur pour ce type d'objet.
   */
  impact?: DeletionImpact | null;
  /** L'impact serveur est en vol : on ne laisse pas confirmer à l'aveugle. */
  impactLoading?: boolean;
  /** L'impact serveur n'a pas pu être lu : on le DIT, on ne prétend pas qu'il n'y a rien. */
  impactFailed?: boolean;
  /**
   * The entity also owns reservations that can live in period plans (overlays):
   * the base-scope impact counts under-report those, so the permanence caution
   * names them explicitly. Set for team / venue / slot (not coach).
   */
  affectsPeriodPlans?: boolean;
  confirmLabel?: string;
  onConfirm: () => void;
  onCancel: () => void;
}

/**
 * Destructive-delete confirmation that spells out the IMPACT: the manager sees exactly what
 * interlinked data the cascade will remove — before it happens.
 *
 * ⚑ **P3-16 : les comptes viennent du SERVEUR.** Ils étaient auparavant calculés par chaque
 * appelant depuis son cache react-query, et la modale annonçait 2 ou 3 familles quand la
 * cascade en emportait dix : l'écran ne pouvait pas dire vrai, il n'avait chargé ni les
 * matchs, ni les contraintes, ni les séances des autres plannings. Les libellés eux-mêmes
 * viennent du serveur, pour qu'une famille ajoutée à la cascade s'affiche d'office au lieu de
 * disparaître en silence faute de traduction ici.
 *
 * Trois précisions que le serveur ajoute et qui décident du geste : le geste est-il REFUSÉ
 * (périmètre engagé), combien de séances touchées vivent dans le planning **en vigueur**, et
 * combien de matchs **déjà déclarés à la fédération** perdront leur salle (DOC-2).
 *
 * Tant que l'impact n'a pas répondu, la confirmation reste DÉSACTIVÉE — confirmer à l'aveugle
 * est exactement le défaut corrigé ici.
 */
export function DeleteConfirm({
  open,
  entityName,
  impact,
  impactLoading = false,
  impactFailed = false,
  affectsPeriodPlans = false,
  confirmLabel = "Supprimer",
  onConfirm,
  onCancel,
}: DeleteConfirmProps) {
  // P4-108 — plus AUCUN compte local : les quatre gestes de suppression (salle, équipe,
  // coach, créneau) lisent l'impact du serveur. La prop `impacts` a disparu avec son dernier
  // appelant, pour qu'on ne puisse plus recompter ici par commodité.
  const lines = impact?.lines ?? [];
  const blocked = true === impact?.blocked;
  const description = (
    <>
      {blocked ? (
        <p className="font-medium text-foreground">{impact?.reason}</p>
      ) : (
        <>
          {impactLoading ? <p className="text-muted-foreground">Calcul de ce qui sera supprimé…</p> : null}
          {impactFailed ? (
            // Ne JAMAIS présenter un impact inconnu comme un impact vide.
            <p className="font-medium text-foreground">Impossible de vérifier ce que cette suppression emportera. Réessayez plus tard.</p>
          ) : null}
          {lines.length > 0 ? (
            <>
              La suppression de « {entityName} » retirera aussi&nbsp;:
              <ul className="mt-2 list-disc space-y-0.5 pl-5">
                {lines.map((line) => (
                  <li key={line.one}>
                    {line.count} {line.count > 1 ? line.many : line.one}
                  </li>
                ))}
              </ul>
            </>
          ) : null}
          {undefined !== impact && null !== impact && impact.slotsInForce > 0 ? (
            <p className="mt-3 text-foreground">
              Dont <strong>{impact.slotsInForce}</strong> {impact.slotsInForce > 1 ? "séances" : "séance"} du planning <strong>en vigueur</strong>. Vos plannings terminés passeront en «&nbsp;périmé&nbsp;» — régénérez pour retrouver un état sûr.
            </p>
          ) : null}
          {/* P2-52 — on ne refuse pas le geste (un gymnase qui ferme, ça arrive), on avertit :
              le match redevient « à placer », mais un match déjà déclaré devra être re-soumis.
              Phrase PARTAGÉE avec la validation de planning (même perte de salle). */}
          {undefined !== impact && null !== impact ? <DeclaredFixturesNotice count={impact.declaredFixtures} /> : null}
          <p className={lines.length > 0 ? "mt-3 font-medium text-foreground" : "font-medium text-foreground"}>
            Cette action est définitive{affectsPeriodPlans ? ", y compris les réservations des plannings de période" : ""}.
          </p>
        </>
      )}
    </>
  );

  return (
    <ConfirmDialog
      open={open}
      title={`Supprimer « ${entityName} » ?`}
      description={description}
      confirmLabel={confirmLabel}
      destructive
      // Confirmer sans savoir ce qu'on détruit — ou alors que le serveur refusera — n'est
      // jamais offert.
      confirmDisabled={blocked || impactLoading || impactFailed}
      onConfirm={onConfirm}
      onCancel={onCancel}
    />
  );
}
