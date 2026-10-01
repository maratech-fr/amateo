import { Check, X } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Card, CardContent } from "@/shared/components/ui/card";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Select } from "@/shared/components/ui/select";
import { Spinner } from "@/shared/components/ui/spinner";
import { ASSIGNABLE_ROLES, roleLabel, type AssignableRole } from "@/shared/lib/roles";

import { useApproveMember, usePendingMembers, useRejectMember } from "./queries";

/**
 * Member-approval list, embeddable (e.g. as a section of the Club hub).
 * Renders no page chrome (title/description live in the host).
 *
 * PR C : approuver EXIGE un rôle (le serveur refuse un corps sans `role`). Le
 * défaut proposé est MEMBRE — moindre privilège : donner les clés (Gestionnaire)
 * est un choix ACTIF du gestionnaire, jamais un défaut subi.
 */
export function PendingMembersSection() {
  const { data, isLoading, isError } = usePendingMembers(true);
  const approve = useApproveMember();
  const reject = useRejectMember();
  // Rôle choisi par ligne, défaut Membre. Non-saisi = Membre (moindre privilège).
  const [roles, setRoles] = useState<Record<string, AssignableRole>>({});

  if (isLoading) {
    return (
      <div className="flex justify-center py-6">
        <Spinner className="size-5" />
      </div>
    );
  }

  if (isError) {
    return <LoadErrorHint className="justify-center py-4">Impossible de charger les demandes. Réessayez plus tard.</LoadErrorHint>;
  }

  const members = data?.members ?? [];

  if (members.length === 0) {
    return <EmptyHint className="py-4 text-center">Aucune demande en attente.</EmptyHint>;
  }

  return (
    <ul className="flex flex-col gap-2">
      {members.map((member) => {
        const busy = approve.isPending || reject.isPending;
        const role = roles[member.id] ?? "member";
        const roleFieldId = `approve-role-${member.id}`;
        return (
          <li key={member.id}>
            <Card>
              <CardContent className="flex flex-wrap items-center justify-between gap-4 py-4">
                <div>
                  <p className="font-medium">
                    {member.firstName} {member.lastName}
                  </p>
                  <p className="text-sm text-muted-foreground">{member.email}</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <label htmlFor={roleFieldId} className="text-sm text-muted-foreground">
                    Rôle
                  </label>
                  <Select
                    id={roleFieldId}
                    wrapperClassName="w-40"
                    value={role}
                    disabled={busy}
                    onChange={(e) => setRoles((prev) => ({ ...prev, [member.id]: e.target.value as AssignableRole }))}
                  >
                    {ASSIGNABLE_ROLES.map((r) => (
                      <option key={r} value={r}>
                        {roleLabel(r)}
                      </option>
                    ))}
                  </Select>
                  <Button size="sm" disabled={busy} onClick={() => approve.mutate({ id: member.id, role })}>
                    <Check /> Approuver
                  </Button>
                  <Button size="sm" variant="outline" disabled={busy} onClick={() => reject.mutate(member.id)}>
                    <X /> Refuser
                  </Button>
                </div>
              </CardContent>
            </Card>
          </li>
        );
      })}
    </ul>
  );
}
