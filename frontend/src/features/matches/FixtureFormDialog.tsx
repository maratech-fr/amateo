import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { TeamSelect } from "@/shared/components/ui/team-select";

import type { Competition, Fixture, HomeAway, PriorityTier, Team } from "./api";
import { useCreateFixture, useUpdateFixture } from "./queries";

interface FixtureFormDialogProps {
  teams: Team[];
  tiers: PriorityTier[];
  competitions: Competition[];
  /** P1-4 PR E1 — edit mode: the fixture whose identity fields are being changed
   * (HOME and AWAY alike). The team is fixed — another team is another engagement,
   * delete + recreate. A manual date change KEEPS the placement (the manager IS
   * the decision; the diagnostic flags what the new date breaks). */
  fixture?: Fixture | null;
  onClose: () => void;
}

const fieldClass = "h-9 w-full rounded-md border border-input bg-background px-2 text-sm";

/** Manual entry / edition of a fixture. Friendly = no competition. */
export function FixtureFormDialog({ teams, tiers, competitions, fixture = null, onClose }: FixtureFormDialogProps) {
  const createFixture = useCreateFixture();
  const updateFixture = useUpdateFixture();
  const editing = null !== fixture;
  const [teamId, setTeamId] = useState(fixture?.teamId ?? teams[0]?.id ?? "");
  const [matchDate, setMatchDate] = useState(fixture?.matchDate ?? "");
  const [homeAway, setHomeAway] = useState<HomeAway>(fixture?.homeAway ?? "HOME");
  const [opponentLabel, setOpponentLabel] = useState(fixture?.opponentLabel ?? "");
  const [competitionId, setCompetitionId] = useState(fixture?.competitionId ?? "");

  const valid = "" !== teamId && "" !== matchDate && "" !== opponentLabel.trim();
  const busy = createFixture.isPending || updateFixture.isPending;
  const teamCompetitions = competitions.filter((c) => c.teamId === teamId);

  const submit = (): void => {
    if (!valid) {
      return;
    }
    const fields = { matchDate, homeAway, opponentLabel: opponentLabel.trim(), competitionId: "" === competitionId ? null : competitionId };
    if (editing) {
      updateFixture.mutate({ fixture, input: fields }, { onSuccess: onClose });
    } else {
      createFixture.mutate({ teamId, ...fields }, { onSuccess: onClose });
    }
  };

  const title = editing ? "Modifier le match" : "Nouveau match";
  return (
    <Modal
      label={title}
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>
            Annuler
          </Button>
          <Button size="sm" disabled={!valid || busy} onClick={submit}>
            {editing ? "Enregistrer" : "Créer"}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Équipe</span>
          <TeamSelect
            aria-label="Équipe"
            teams={teams}
            tiers={tiers}
            value={teamId}
            disabled={editing}
            onChange={(e) => {
              setTeamId(e.target.value);
              setCompetitionId(""); // the old team's competition no longer applies
            }}
          />
        </label>

        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Date</span>
          <input aria-label="Date" type="date" className={fieldClass} value={matchDate} onChange={(e) => setMatchDate(e.target.value)} />
        </label>

        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Domicile / Extérieur</span>
          <select aria-label="Domicile ou extérieur" className={fieldClass} value={homeAway} onChange={(e) => setHomeAway(e.target.value as HomeAway)}>
            <option value="HOME">Domicile</option>
            <option value="AWAY">Extérieur</option>
          </select>
        </label>

        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Adversaire</span>
          <input aria-label="Adversaire" type="text" className={fieldClass} value={opponentLabel} onChange={(e) => setOpponentLabel(e.target.value)} placeholder="Nom de l'équipe adverse" />
        </label>

        <label className="flex flex-col gap-1 text-sm">
          <span className="text-muted-foreground">Compétition (vide = amical)</span>
          <select aria-label="Compétition" className={fieldClass} value={competitionId ?? ""} onChange={(e) => setCompetitionId(e.target.value)}>
            <option value="">Amical</option>
            {teamCompetitions.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </label>

        {editing && "PLACED" === fixture.status && "HOME" !== homeAway ? (
          <p className="text-xs text-warning">Passer ce match à l'extérieur libèrera son créneau — il quittera la grille.</p>
        ) : null}

      </div>
    </Modal>
  );
}
