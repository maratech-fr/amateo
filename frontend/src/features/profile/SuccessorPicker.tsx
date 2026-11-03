import { useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router";

import type { ActiveMember } from "@/features/auth/api";
import { useChangeMemberRole } from "@/features/auth/queries";
import { Button } from "@/shared/components/ui/button";
import { isManagementRole } from "@/shared/lib/roles";
import { toast } from "@/shared/stores/toastStore";

import { PROFILE_TEXTS } from "./texts";

/**
 * Chantier Membre (PR 3, prévention) — le SEUL gestionnaire du club courant nomme un successeur
 * avant de supprimer son compte : la liste des Membres actifs, un clic chacun. « Nommer
 * gestionnaire » EST le geste du sélecteur de rôle (`changeMemberRole(id, "admin")`, même route,
 * même verrou) ; après succès la session est relue — l'avertissement (servi par le serveur,
 * `me.soleManagerClubs`) disparaît de lui-même. Aucun Membre actif : la phrase qui renvoie vers
 * l'écran Club (inviter un gestionnaire).
 */
export function SuccessorPicker({ members }: { members: ActiveMember[] }) {
  const changeRole = useChangeMemberRole();
  const queryClient = useQueryClient();
  const candidates = members.filter((member) => !member.isSelf && !isManagementRole(member.role));

  if (0 === candidates.length) {
    return (
      <p className="text-sm text-muted-foreground">
        {PROFILE_TEXTS.noOtherActiveMember}{" "}
        <Link to="/club" className="underline">
          {PROFILE_TEXTS.openClubScreen}
        </Link>
      </p>
    );
  }

  return (
    <ul className="flex flex-col gap-2">
      {candidates.map((member) => {
        const name = `${member.firstName} ${member.lastName}`;
        return (
          <li key={member.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
            <span>{name}</span>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={changeRole.isPending}
              aria-label={PROFILE_TEXTS.nameSuccessorOf(name)}
              onClick={() =>
                changeRole.mutate(
                  { id: member.id, role: "admin" },
                  {
                    onSuccess: () => {
                      toast.success(PROFILE_TEXTS.successorNamed(name));
                      void queryClient.invalidateQueries({ queryKey: ["me"] });
                    },
                  },
                )
              }
            >
              {PROFILE_TEXTS.nameSuccessor}
            </Button>
          </li>
        );
      })}
    </ul>
  );
}
