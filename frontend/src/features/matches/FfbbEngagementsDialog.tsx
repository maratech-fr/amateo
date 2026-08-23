import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { Spinner } from "@/shared/components/ui/spinner";
import { TeamSelect } from "@/shared/components/ui/team-select";

import type { PriorityTier, Team } from "./api";
import { useConfirmFfbbPairings, useFfbbEngagements } from "./queries";

interface FfbbEngagementsDialogProps {
  teams: Team[];
  tiers: PriorityTier[];
  onClose: () => void;
}

/**
 * FFBB pairing (P1-4 PR F, appariement §3): the club's engagements of the
 * season, each to be attached to an app team — confirmed IN BLOCK. Re-paired
 * at each phase (« 1 clic contre un calendrier fiable ») : next phases come
 * pre-filled from the previous pairing. An empty row = not paired — nothing
 * else to model (the absence of a link IS the state).
 */
export function FfbbEngagementsDialog({ teams, tiers, onClose }: FfbbEngagementsDialogProps) {
  const engagements = useFfbbEngagements(true);
  const confirm = useConfirmFfbbPairings();
  // ffbbCompetitionId → teamId chosen ("" = not paired).
  const [choices, setChoices] = useState<Record<string, string>>({});

  const rows = engagements.data?.engagements ?? [];
  const chosenOf = (id: string, suggested: string | null): string => choices[id] ?? suggested ?? "";
  const pairings = rows
    .map((row) => ({ ffbbCompetitionId: row.ffbbCompetitionId, teamId: chosenOf(row.ffbbCompetitionId, row.suggestedTeamId) }))
    .filter((pairing) => "" !== pairing.teamId);

  const teamName = (id: string): string => teams.find((team) => team.id === id)?.name ?? "";

  return (
    <Modal
      label="Engagements FFBB"
      title="Engagements FFBB"
      onClose={onClose}
      size="lg"
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>
            Fermer
          </Button>
          <Button
            size="sm"
            disabled={0 === pairings.length || confirm.isPending}
            onClick={() => confirm.mutate(pairings, { onSuccess: onClose })}
          >
            {confirm.isPending ? "Enregistrement…" : `Confirmer ${pairings.length} appariement${pairings.length > 1 ? "s" : ""}`}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-xs text-muted-foreground">
          Les équipes engagées telles que la ligue les connaît — rattachez chacune à votre équipe puis
          confirmez en bloc. À chaque nouvelle phase, ré-ouvrez : tout est pré-rempli.{" "}
          <strong>Données de la ligue — un écart se corrige auprès d'elle.</strong>
        </p>

        {engagements.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner />
          </div>
        ) : engagements.isError ? (
          <p className="rounded-md border border-destructive/50 bg-destructive/5 px-3 py-2 text-sm">
            FFBB indisponible — réessayez plus tard.
          </p>
        ) : 0 === rows.length ? (
          <p className="text-sm text-muted-foreground">
            Aucun engagement trouvé pour cette saison (les poules sortent généralement après le 20 juillet).
          </p>
        ) : (
          <ul className="flex flex-col gap-2">
            {rows.map((row) => {
              // Le chiffre discriminant (« …Division 2 » vs « …Division 3 ») est en QUEUE de
              // chaîne : on LAISSE le libellé s'enrouler (jamais tronqué → toujours visible, y
              // compris au doigt) et on double d'un `title` de secours (§6bis B1/B2).
              const subLabel = `${row.pouleName} · ${row.pouleSize} clubs${null !== row.category ? ` · ${row.category}` : ""}${null !== row.level ? ` · ${row.level}` : ""}${null !== row.gender ? ` · ${row.gender}` : ""}`;
              const chosen = chosenOf(row.ffbbCompetitionId, row.suggestedTeamId);
              return (
                <li key={row.ffbbCompetitionId} className="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
                  <span className="min-w-0 text-sm">
                    <span className="block font-medium" title={row.competitionName}>
                      {row.competitionName}
                    </span>
                    <span className="block text-xs text-muted-foreground" title={subLabel}>
                      {subLabel}
                    </span>
                  </span>
                  <TeamSelect
                    aria-label={`Équipe pour ${row.competitionName}`}
                    // La valeur sélectionnée se lit sans ouvrir le select (§6bis B4) : élargi,
                    // et un `title` en secours pour le nom d'équipe qui déborderait encore.
                    title={"" !== chosen ? teamName(chosen) : "Non rattachée"}
                    className="h-9 w-52 shrink-0 rounded-md border border-input bg-background px-2 text-sm"
                    teams={teams}
                    tiers={tiers}
                    placeholder="Non rattachée"
                    value={chosen}
                    onChange={(e) => setChoices({ ...choices, [row.ffbbCompetitionId]: e.target.value })}
                  />
                </li>
              );
            })}
          </ul>
        )}

      </div>
    </Modal>
  );
}
