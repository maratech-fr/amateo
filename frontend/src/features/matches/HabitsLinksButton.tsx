import { Link2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";

import { HabitsLinksDialog } from "./HabitsLinksDialog";
import { usePriorityTiers, useTeams } from "./queries";

/**
 * Lot PASSERELLES PR-3 — the « Passerelles » screen, opened from OUTSIDE the matches page (today:
 * the wizard's mutualisation step). Self-contained on purpose: it owns the open state and fetches
 * its OWN matches-shaped data, so the caller (the wizard, whose Team shape differs) only has to
 * drop the button in. The dialog itself stays in the matches feature — `frontend → backend` only,
 * no cross-feature type threading. (Les créneaux idéaux se saisissent ailleurs, `IdealSlotsEditor`.)
 */
export function HabitsLinksButton({ className, label = "Gérer les passerelles" }: { className?: string; label?: string }) {
  const [open, setOpen] = useState(false);
  const teams = useTeams();
  const tiers = usePriorityTiers();

  return (
    <>
      <Button variant="outline" size="sm" className={className} onClick={() => setOpen(true)}>
        <Link2 className="size-4" />
        {label}
      </Button>
      {open ? <HabitsLinksDialog teams={teams.data ?? []} tiers={tiers.data ?? []} onClose={() => setOpen(false)} /> : null}
    </>
  );
}
