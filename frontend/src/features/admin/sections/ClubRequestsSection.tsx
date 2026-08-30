import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Spinner } from "@/shared/components/ui/spinner";

import { useActivateAdminMembership, useAdminClubRequests, useAdminPendingMemberships, useDecideAdminClubRequest } from "../queries";

/**
 * P3-4 PR B — l'arbitrage superadmin : demandes de création de club (sans mail
 * FFBB, ou expirées — le lien public est mort, la console garde la main) et
 * adhésions en attente (« activer une adhésion » : l'ancien gestionnaire parti
 * fâché ne fait pas la passation — décision fondateur 2026-08-05).
 *
 * Le REFUS d'une demande passe par une confirmation à deux clics (pas de modale
 * nominative : l'acte est réversible côté demandeur — il peut re-candidater).
 */
export function ClubRequestsSection() {
  const requests = useAdminClubRequests();
  const memberships = useAdminPendingMemberships();
  const decide = useDecideAdminClubRequest();
  const activate = useActivateAdminMembership();
  const [confirmRefuseId, setConfirmRefuseId] = useState<string | null>(null);

  const requestItems = requests.data?.items ?? [];
  const membershipItems = memberships.data?.items ?? [];

  return (
    <section aria-labelledby="club-requests-heading" className="space-y-4">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-console-muted">Arbitrages</p>
        <h2 id="club-requests-heading" className="mt-2 text-xl font-semibold text-white">
          Demandes de création & adhésions en attente
        </h2>
      </div>

      <div className="rounded-xl border border-white/10 bg-white/5 p-4">
        <h3 className="text-sm font-semibold text-white">Demandes de création de club</h3>
        <p className="mt-1 text-xs text-console-text-dim">
          Sans mail FFBB (aucun lien envoyé) ou expirées : la console est la seule voie. Approuver crée l'espace du club.
        </p>
        {requests.isPending ? (
          <p className="mt-3 flex items-center gap-2 text-sm text-console-text-dim"><Spinner className="size-4" /> Chargement…</p>
        ) : requests.isError ? (
          <p className="mt-3 text-sm text-console-destructive">
            La liste n'a pas pu être lue.{" "}
            <button type="button" className="underline" onClick={() => void requests.refetch()}>Réessayer</button>
          </p>
        ) : 0 === requestItems.length ? (
          <p className="mt-3 text-sm text-console-text-dim">Aucune demande en attente.</p>
        ) : (
          <ul className="mt-3 divide-y divide-white/10">
            {requestItems.map((item) => (
              <li key={item.id} className="flex flex-col gap-2 py-3 md:flex-row md:items-center md:justify-between">
                <div className="text-sm text-console-text-bright">
                  <span className="font-medium text-white">{item.clubName}</span>{" "}
                  <span className="text-console-text-dim">({item.ara})</span>
                  {"expired" === item.status ? <span className="ml-2 rounded bg-console-warning-surface/15 px-1.5 py-0.5 text-xs font-semibold uppercase text-console-warning">Expirée</span> : null}
                  {null === item.clubEmail ? <span className="ml-2 rounded bg-console-muted/20 px-1.5 py-0.5 text-xs font-semibold uppercase text-console-text">Sans mail FFBB</span> : null}
                  <div className="text-xs text-console-text-dim">
                    Demandé par {item.requesterName} ({item.requesterEmail}) le {item.createdAt} — expire le {item.expiresAt}
                  </div>
                </div>
                <div className="flex shrink-0 gap-2">
                  <Button
                    type="button"
                    size="sm"
                    disabled={decide.isPending}
                    onClick={() => decide.mutate({ id: item.id, decision: "approve" })}
                  >
                    Approuver
                  </Button>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="border-console-destructive-edge/40 text-console-destructive hover:bg-console-destructive-surface/10"
                    disabled={decide.isPending}
                    onClick={() => {
                      if (confirmRefuseId === item.id) {
                        decide.mutate({ id: item.id, decision: "refuse" });
                        setConfirmRefuseId(null);
                      } else {
                        setConfirmRefuseId(item.id);
                      }
                    }}
                  >
                    {confirmRefuseId === item.id ? "Confirmer le refus" : "Refuser"}
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>

      <div className="rounded-xl border border-white/10 bg-white/5 p-4">
        <h3 className="text-sm font-semibold text-white">Adhésions en attente</h3>
        <p className="mt-1 text-xs text-console-text-dim">
          Normalement approuvées par un gestionnaire du club — activer ici quand la passation n'a pas lieu.
        </p>
        {memberships.isPending ? (
          <p className="mt-3 flex items-center gap-2 text-sm text-console-text-dim"><Spinner className="size-4" /> Chargement…</p>
        ) : memberships.isError ? (
          <p className="mt-3 text-sm text-console-destructive">
            La liste n'a pas pu être lue.{" "}
            <button type="button" className="underline" onClick={() => void memberships.refetch()}>Réessayer</button>
          </p>
        ) : 0 === membershipItems.length ? (
          <p className="mt-3 text-sm text-console-text-dim">Aucune adhésion en attente.</p>
        ) : (
          <ul className="mt-3 divide-y divide-white/10">
            {membershipItems.map((item) => (
              <li key={item.id} className="flex flex-col gap-2 py-3 md:flex-row md:items-center md:justify-between">
                <div className="text-sm text-console-text-bright">
                  <span className="font-medium text-white">{item.userName}</span>{" "}
                  <span className="text-console-text-dim">({item.userEmail})</span>
                  <div className="text-xs text-console-text-dim">
                    → {item.clubName} ({item.ara}) — demandé le {item.createdAt}
                  </div>
                </div>
                <Button type="button" size="sm" disabled={activate.isPending} onClick={() => activate.mutate(item.id)}>
                  Activer l'adhésion
                </Button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}
