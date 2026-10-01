import { RotateCcw, UserX } from "lucide-react";

import type { ActiveMember, DeactivatedMember } from "@/features/auth/api";
import { useChangeMemberRole, useDeactivateMember, useMembers, useReactivateMember } from "@/features/auth/queries";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent } from "@/shared/components/ui/card";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Select } from "@/shared/components/ui/select";
import { Spinner } from "@/shared/components/ui/spinner";
import { ASSIGNABLE_ROLES, isManagementRole, roleLabel, type AssignableRole } from "@/shared/lib/roles";
import { readFailed, readLoading } from "@/shared/lib/readState";

const SELF_LAST_MANAGER_HINT = "Vous êtes le seul gestionnaire actif : désignez-en un autre avant de vous retirer.";

/**
 * Écran de gestion des membres actifs + désactivés (management-only, embarqué
 * dans le hub Club).
 *
 * Règles maison :
 *  - readState à 3 états : le chargement ne crie pas « échec », et un refetch raté
 *    en arrière-plan ne détruit pas une liste déjà lue ;
 *  - le front ne RECALCULE aucune règle serveur. La SEULE anticipation autorisée
 *    est le geste sur SOI quand on est le dernier gestionnaire VISIBLE (rôle/
 *    désactivation désactivés avec explication) ; tout autre refus (dernier
 *    gestionnaire non-soi, course concurrente) est RESTITUÉ via le 409 serveur
 *    (toast, câblé dans les hooks).
 */
export function MembersSection() {
  const membersQuery = useMembers(true);
  const changeRole = useChangeMemberRole();
  const deactivate = useDeactivateMember();
  const reactivate = useReactivateMember();

  if (readLoading(membersQuery)) {
    return (
      <div className="flex justify-center py-6">
        <Spinner className="size-5" />
      </div>
    );
  }

  if (readFailed(membersQuery)) {
    return <LoadErrorHint>Impossible de charger les membres.</LoadErrorHint>;
  }

  const active: ActiveMember[] = membersQuery.data?.members ?? [];
  const deactivated: DeactivatedMember[] = membersQuery.data?.deactivated ?? [];
  // Nombre de gestionnaires ACTIFS visibles : sert la seule anticipation permise
  // (le geste sur soi comme dernier gestionnaire). Jamais utilisé pour prédire le
  // refus d'un geste sur AUTRUI — le serveur reste seul juge de l'invariant.
  const visibleManagers = active.filter((m) => isManagementRole(m.role)).length;
  const busy = changeRole.isPending || deactivate.isPending || reactivate.isPending;

  return (
    <div className="space-y-6">
      <section>
        <ul className="flex flex-col gap-2">
          {active.map((member) => {
            const selfIsOnlyManager = member.isSelf && isManagementRole(member.role) && visibleManagers === 1;
            // 'owner' (hérité) se LIT gestionnaire mais n'est pas assignable : le
            // sélecteur le représente comme « Gestionnaire » (admin) sans jamais
            // muter tant que le gestionnaire ne choisit pas activement.
            const selectValue: AssignableRole = (ASSIGNABLE_ROLES as readonly string[]).includes(member.role) ? (member.role as AssignableRole) : "admin";
            const roleFieldId = `member-role-${member.id}`;
            return (
              <li key={member.id}>
                <Card>
                  <CardContent className="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div>
                      <p className="flex items-center gap-2 font-medium">
                        {member.firstName} {member.lastName}
                        {member.isSelf ? <span className="rounded-full bg-accent px-2 py-0.5 text-xs font-medium text-accent-foreground">vous</span> : null}
                      </p>
                      <p className="text-sm text-muted-foreground">{member.email}</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      <label htmlFor={roleFieldId} className="sr-only">
                        Rôle de {member.firstName} {member.lastName}
                      </label>
                      <Select
                        id={roleFieldId}
                        wrapperClassName="w-40"
                        value={selectValue}
                        disabled={busy || selfIsOnlyManager}
                        title={selfIsOnlyManager ? SELF_LAST_MANAGER_HINT : undefined}
                        onChange={(e) => changeRole.mutate({ id: member.id, role: e.target.value as AssignableRole })}
                      >
                        {ASSIGNABLE_ROLES.map((r) => (
                          <option key={r} value={r}>
                            {roleLabel(r)}
                          </option>
                        ))}
                      </Select>
                      <Button
                        size="sm"
                        variant="ghost"
                        className="text-destructive"
                        disabled={busy || selfIsOnlyManager}
                        title={selfIsOnlyManager ? SELF_LAST_MANAGER_HINT : undefined}
                        onClick={() => deactivate.mutate(member.id)}
                      >
                        <UserX className="size-4" /> Désactiver
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              </li>
            );
          })}
        </ul>
      </section>

      <section>
        <h3 className="mb-2 text-sm font-semibold">Membres désactivés</h3>
        {deactivated.length === 0 ? (
          <EmptyHint>Aucun membre désactivé.</EmptyHint>
        ) : (
          <ul className="flex flex-col gap-2">
            {deactivated.map((member) => (
              <li key={member.id}>
                <Card>
                  <CardContent className="flex flex-wrap items-center justify-between gap-4 py-4">
                    <div>
                      <p className="font-medium">
                        {member.firstName} {member.lastName}
                      </p>
                      <p className="text-sm text-muted-foreground">
                        {member.email} · {roleLabel(member.role)}
                      </p>
                    </div>
                    <Button size="sm" variant="outline" disabled={busy} onClick={() => reactivate.mutate(member.id)}>
                      <RotateCcw className="size-4" /> Réactiver
                    </Button>
                  </CardContent>
                </Card>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
