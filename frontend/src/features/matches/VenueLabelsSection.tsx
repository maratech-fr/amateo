import { Sparkles } from "lucide-react";
import { useMemo, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/shared/components/ui/table";
import { VenueSelect } from "@/shared/components/ui/venue-select";
import { toast } from "@/shared/stores/toastStore";

import type { Venue, VenueLabelInventoryRow } from "./api";
import { useAttachVenueLabel, useDetachVenueLabel, useVenueLabelInventory } from "./queries";

/** Une confirmation en attente — ré-affectation (déplacer l'alias) ou retrait. */
type Pending =
  | { kind: "reassign"; row: VenueLabelInventoryRow; targetVenueId: string; targetVenueName: string }
  | { kind: "detach"; row: VenueLabelInventoryRow; venueId: string; venueName: string }
  | null;

/**
 * E2 (P4-205) — l'ÉCRAN D'APPARIEMENT des salles FBI/FFBB (7ᵉ section de
 * `/matchs/configuration`, ancre `?section=libelles`). Un tableau, une ligne par
 * libellé de salle IMPORTÉ (inventaire agrégé `GET /api/venues/fbi-labels`) :
 * `displayLabel`, les compteurs, un `VenueSelect` (valeur = gymnase confirmé, sinon la
 * SUGGESTION pré-sélectionnée avec une pastille neutre « d'après les rencontres »,
 * sinon placeholder « Non apparié »), et l'action.
 *
 * - Sans alias ET les domiciles ne portent aucun autre gymnase (ou on accepte la
 *   suggestion) : « Confirmer » = POST sans `reassign` (backfill des domiciles encore
 *   sans salle). Aucun dialogue (geste sûr, additif).
 * - Un alias existe et on choisit un AUTRE gymnase, OU (2026-09-14) pas d'alias mais les
 *   domiciles portent DÉJÀ un gymnase différent de celui choisi (`suggestedVenueId` ≠
 *   sélection — le cas vécu : alias retiré la veille, 83 domiciles restés sur le mauvais
 *   gymnase, « Confirmer » ne bougeait rien) : « Réaffecter » ouvre un `ConfirmDialog`
 *   (« M non placés basculeront vers X ; N placés conservent leur salle ») puis POST
 *   `reassign: true`. La correction en un geste (E1) qui manquait.
 * - « Retirer » (ghost, subordonné) sur les lignes à alias : `ConfirmDialog` inchangé
 *   (les matchs déjà rattachés gardent ce gymnase).
 *
 * Présentation PURE : le backend a agrégé/normalisé (clé = `labelKey` servi, renvoyé
 * BRUT à l'attache — le serveur re-normalise) ; le front ne redérive AUCUNE règle
 * (🔴 `.claude/rules/frontend.md`). `inventory`/`venues` `undefined` (chargement/échec)
 * ⇒ rendu NUL — jamais un « aucun libellé » crédible sur une liste pas chargée
 * (`readState`).
 */
export function VenueLabelsSection({ venues }: { venues: Venue[] | undefined }) {
  const inventory = useVenueLabelInventory();
  const attach = useAttachVenueLabel();
  const detach = useDetachVenueLabel();
  // Sélection utilisateur par libellé (override de la valeur dérivée). Effacée au
  // succès d'une écriture sur ce libellé (l'inventaire refetché redevient la source).
  const [selection, setSelection] = useState<Record<string, string>>({});
  const [pending, setPending] = useState<Pending>(null);

  const venueOptions = useMemo(() => (venues ?? []).map((v) => ({ id: v.id, name: v.name, color: v.color })), [venues]);
  const venueName = (id: string): string => (venues ?? []).find((v) => v.id === id)?.name ?? "?";

  if (undefined === venues || undefined === inventory.data) {
    return null;
  }
  const rows = inventory.data;

  const busy = attach.isPending || detach.isPending;

  const effectiveValue = (row: VenueLabelInventoryRow): string => selection[row.labelKey] ?? row.venueId ?? row.suggestedVenueId ?? "";
  const clearSelection = (labelKey: string): void =>
    setSelection((prev) => {
      const next = { ...prev };
      delete next[labelKey];
      return next;
    });

  const confirmAttach = (row: VenueLabelInventoryRow, venueId: string): void => {
    attach.mutate(
      { venueId, label: row.labelKey },
      {
        onSuccess: (r) => {
          clearSelection(row.labelKey);
          if (r.attached > 0) {
            toast.success(`${r.attached} domicile${r.attached > 1 ? "s" : ""} rattaché${r.attached > 1 ? "s" : ""} à ${venueName(venueId)}`);
          } else {
            toast.info(`Libellé confirmé — aucun domicile à rattacher à ${venueName(venueId)}`);
          }
        },
      },
    );
  };

  const confirmReassign = (row: VenueLabelInventoryRow, venueId: string): void => {
    attach.mutate(
      { venueId, label: row.labelKey, reassign: true },
      {
        onSuccess: (r) => {
          clearSelection(row.labelKey);
          toast.success(`Alias déplacé — ${r.attached} domicile${r.attached > 1 ? "s" : ""} basculé${r.attached > 1 ? "s" : ""}, ${r.kept ?? 0} placé${(r.kept ?? 0) > 1 ? "s" : ""} conservé${(r.kept ?? 0) > 1 ? "s" : ""} sur leur gymnase`);
        },
      },
    );
  };

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        Chaque libellé de salle importé de la FFBB pointe vers un gymnase du club — c'est ce qui rend ses domiciles visibles
        sur la grille et du radar de conflits. La suggestion « d'après les rencontres » vient des matchs déjà placés ; à confirmer.
      </p>
      {0 === rows.length ? (
        <EmptyHint>Aucun libellé de salle importé pour l'instant. Ils arrivent avec un import FBI ou le canal API FFBB.</EmptyHint>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Libellé FFBB</TableHead>
              <TableHead>Domiciles</TableHead>
              <TableHead>Gymnase</TableHead>
              <TableHead className="text-right">Action</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((row) => {
              const selected = effectiveValue(row);
              const confirmed = row.venueId;
              const showsSuggestion = null === confirmed && null !== row.suggestedVenueId && selected === row.suggestedVenueId;
              // Réaffecter dès que des domiciles PORTENT un autre gymnase que celui choisi :
              // alias confirmé ailleurs, ou pas d'alias mais un gymnase unanime différent.
              // `suggestedVenueId` est nul quand il égale l'alias confirmé (inventaire E1) : non nul,
              // il dit que des domiciles portent ENCORE un autre gymnase → il y a quelque chose à
              // réaffecter même sans changer la sélection (cas vécu : alias posé sur JDR par
              // « Confirmer », 83 domiciles restés sur ADN, bouton grisé faute de « changement »).
              const drift = null !== row.suggestedVenueId && selected !== row.suggestedVenueId;
              const needsReassign = null !== confirmed || drift;
              const hasChange = selected !== (confirmed ?? "") || drift;
              const canWrite = "" !== selected && hasChange;
              return (
                <TableRow key={row.labelKey}>
                  <TableCell className="font-medium">{row.displayLabel}</TableCell>
                  <TableCell className="text-xs text-muted-foreground tabular-nums whitespace-nowrap">
                    {row.homeCount} domicile{row.homeCount > 1 ? "s" : ""} · {row.placedCount} placé{row.placedCount > 1 ? "s" : ""} · {row.unplacedCount} non placé{row.unplacedCount > 1 ? "s" : ""}
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-col gap-1">
                      <VenueSelect
                        aria-label={`Gymnase pour le libellé ${row.displayLabel}`}
                        venues={venueOptions}
                        value={selected}
                        placeholder="Non apparié"
                        disabled={busy}
                        onValueChange={(value) => setSelection((prev) => ({ ...prev, [row.labelKey]: value }))}
                      />
                      {showsSuggestion ? (
                        <StatusPill variant="neutral" icon={<Sparkles className="size-3" aria-hidden="true" />} className="self-start">
                          d'après les rencontres
                        </StatusPill>
                      ) : null}
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-wrap items-center justify-end gap-1.5">
                      <Button
                        size="sm"
                        disabled={busy || !canWrite}
                        onClick={() => {
                          if (needsReassign) {
                            setPending({ kind: "reassign", row, targetVenueId: selected, targetVenueName: venueName(selected) });
                          } else {
                            confirmAttach(row, selected);
                          }
                        }}
                      >
                        {needsReassign ? "Réaffecter" : "Confirmer"}
                      </Button>
                      {null !== confirmed ? (
                        <Button
                          variant="ghost"
                          size="sm"
                          disabled={busy}
                          onClick={() => setPending({ kind: "detach", row, venueId: confirmed, venueName: venueName(confirmed) })}
                        >
                          Retirer
                        </Button>
                      ) : null}
                    </div>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      )}

      <ConfirmDialog
        open={null !== pending && "reassign" === pending.kind}
        title={null !== pending && "reassign" === pending.kind ? `Réaffecter « ${pending.row.displayLabel} » à ${pending.targetVenueName} ?` : ""}
        description={
          null !== pending && "reassign" === pending.kind
            ? `${pending.row.unplacedCount} domicile${pending.row.unplacedCount > 1 ? "s" : ""} non placé${pending.row.unplacedCount > 1 ? "s" : ""} basculeront vers ${pending.targetVenueName} ; ${pending.row.placedCount} placé${pending.row.placedCount > 1 ? "s" : ""} ${pending.row.placedCount > 1 ? "conservent" : "conserve"} leur gymnase.`
            : undefined
        }
        confirmLabel="Réaffecter"
        onConfirm={() => {
          if (null !== pending && "reassign" === pending.kind) {
            confirmReassign(pending.row, pending.targetVenueId);
          }
          setPending(null);
        }}
        onCancel={() => setPending(null)}
      />

      <ConfirmDialog
        open={null !== pending && "detach" === pending.kind}
        title={null !== pending && "detach" === pending.kind ? `Retirer le libellé « ${pending.row.displayLabel} » ?` : ""}
        description="Les matchs déjà rattachés gardent ce gymnase ; seuls les prochains imports ne le seront plus."
        confirmLabel="Retirer"
        onConfirm={() => {
          if (null !== pending && "detach" === pending.kind) {
            detach.mutate({ venueId: pending.venueId, label: pending.row.labelKey });
          }
          setPending(null);
        }}
        onCancel={() => setPending(null)}
      />
    </div>
  );
}
