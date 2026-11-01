import { useId, useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { SegmentedControl } from "@/shared/components/ui/segmented-control";
import { Select } from "@/shared/components/ui/select";
import { useCredits } from "@/shared/credits/useCredits";
import { slugFilename } from "@/shared/lib/download";

import type { Team } from "./api";
import { useExportFixturesPdf } from "./queries";
import { MATCHES_EXPORT_TEXTS as TEXTS } from "./texts/export";

type Scope = "club" | "team";

interface ExportDefaults {
  teamId: string | null;
  from: string;
  to: string;
}

interface ExportMatchesDialogProps {
  open: boolean;
  onClose: () => void;
  teams: Team[];
  defaults: ExportDefaults;
}

/** La fenêtre naît à chaque ouverture : elle reprend ce qui est à l'écran à cet instant. */
export function ExportMatchesDialog({ open, onClose, teams, defaults }: ExportMatchesDialogProps) {
  return open ? <ExportForm onClose={onClose} teams={teams} defaults={defaults} /> : null;
}

function ExportForm({ onClose, teams, defaults }: Omit<ExportMatchesDialogProps, "open">) {
  const fieldId = useId();
  const [scope, setScope] = useState<Scope>(null === defaults.teamId ? "club" : "team");
  const [teamId, setTeamId] = useState(defaults.teamId ?? "");
  const [from, setFrom] = useState(defaults.from);
  const [to, setTo] = useState(defaults.to);
  const { run, busy } = useExportFixturesPdf();

  const credits = useCredits();
  const creditSuffix = null !== credits ? ` (${credits.remaining})` : "";
  const creditsBlocked = null !== credits && !credits.canExportPdf;

  const rangeInvalid = "" !== from && "" !== to && from > to;
  const incomplete = "" === from || "" === to || ("team" === scope && "" === teamId);

  const changeScope = (next: Scope): void => {
    setScope(next);
    if ("club" === next) {
      setTeamId("");
    }
  };

  const download = (): void => {
    const team = "team" === scope ? teams.find((candidate) => candidate.id === teamId) : undefined;
    const slug = undefined === team ? "club" : slugFilename(team.name);
    run({ from, to, teamId: "team" === scope ? teamId : null }, `matchs-${slug}-${from}-${to}.pdf`, onClose);
  };

  return (
    <Modal
      label={TEXTS.dialogTitle}
      title={TEXTS.dialogTitle}
      onClose={onClose}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>
            {TEXTS.cancel}
          </Button>
          <Button disabled={busy || rangeInvalid || incomplete || creditsBlocked} onClick={download}>
            {busy ? TEXTS.downloading : `${TEXTS.downloadButton}${creditSuffix}`}
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-4 text-sm">
        <div className="flex flex-col items-start gap-1">
          <span id={`${fieldId}-scope`} className="text-muted-foreground">
            {TEXTS.scopeLabel}
          </span>
          <SegmentedControl
            labelledBy={`${fieldId}-scope`}
            value={scope}
            onValueChange={changeScope}
            options={[
              { key: "club", label: TEXTS.scopeClub },
              { key: "team", label: TEXTS.scopeTeam },
            ]}
          />
        </div>

        {"team" === scope ? (
          <div className="flex flex-col gap-1">
            <label htmlFor={`${fieldId}-team`} className="text-muted-foreground">
              {TEXTS.teamLabel}
            </label>
            <Select id={`${fieldId}-team`} value={teamId} onChange={(event) => setTeamId(event.target.value)}>
              <option value="">{TEXTS.teamPlaceholder}</option>
              {teams.map((team) => (
                <option key={team.id} value={team.id}>
                  {team.name}
                </option>
              ))}
            </Select>
          </div>
        ) : null}

        <div className="grid grid-cols-2 gap-3">
          <div className="flex flex-col gap-1">
            <label htmlFor={`${fieldId}-from`} className="text-muted-foreground">
              {TEXTS.fromLabel}
            </label>
            <Input id={`${fieldId}-from`} type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
          </div>
          <div className="flex flex-col gap-1">
            <label htmlFor={`${fieldId}-to`} className="text-muted-foreground">
              {TEXTS.toLabel}
            </label>
            <Input id={`${fieldId}-to`} type="date" value={to} aria-invalid={rangeInvalid} aria-describedby={rangeInvalid ? `${fieldId}-range` : undefined} onChange={(event) => setTo(event.target.value)} />
          </div>
        </div>
        {rangeInvalid ? (
          <p id={`${fieldId}-range`} role="alert" className="text-destructive">
            {TEXTS.rangeError}
          </p>
        ) : null}

        <p className="text-muted-foreground">{TEXTS.fixedNote}</p>
        {null !== credits ? <p>{creditsBlocked ? TEXTS.creditsExhausted : TEXTS.creditNotice}</p> : null}
      </div>
    </Modal>
  );
}
