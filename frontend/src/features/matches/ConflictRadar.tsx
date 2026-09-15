import { AlertTriangle, ShieldCheck } from "lucide-react";

import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { isManagementRole } from "@/shared/lib/roles";
import { useMe } from "@/shared/session/queries";

import type { Coach, Conflict, Team } from "./api";
import { ConflictSeverityGroups } from "./ConflictLine";
import { ConflictResolutionControl } from "./ConflictResolutionControl";
import { openConflictCount } from "./lib/conflictResolution";

interface ConflictRadarProps {
  conflicts: Conflict[];
  teams: Map<string, Team>;
  coaches: Map<string, Coach>;
  /**
   * RMM-3 — les empreintes des conflits NOUVEAUX depuis la dernière visite (le
   * « gardien »). Un conflit dont l'empreinte est dedans porte une chip « Nouveau ».
   * ORNEMENT PUR : rien de la sévérité, du tri, des libellés ni des étapes de la
   * boucle n'en dépend ; absent (query non résolue) = aucune chip, radar intact.
   */
  newFingerprints?: ReadonlySet<string>;
}

/**
 * The GRADED diagnostic (P1-4 PR E2): server-computed findings grouped by
 * severity (1 = worst first), severity 7 folded behind a count — 40 blind away
 * matches must read as one line, not 40 alerts. Empty = green "no clash".
 *
 * PR A — la ligne et le regroupement par gravité vivent désormais dans
 * `ConflictLine`/`ConflictSeverityGroups` (une seule maison, partagée avec l'onglet
 * Conflits) ; le radar les CONSOMME, rendu inchangé.
 */
export function ConflictRadar({ conflicts, teams, coaches, newFingerprints }: ConflictRadarProps) {
  const { data: me } = useMe();
  const canManage = isManagementRole(me?.role);
  // Le badge ne compte que l'À TRAITER (P4-207) — un conflit annoté reste listé, mais
  // ne pèse plus sur le compteur.
  const openCount = openConflictCount(conflicts);
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <AlertTriangle className="size-4 text-warning" />
          Conflits
          {openCount > 0 ? <span className="rounded-full bg-warning/15 px-2 text-xs text-warning">{openCount}</span> : null}
        </CardTitle>
      </CardHeader>
      <CardContent>
        {0 === conflicts.length ? (
          <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <ShieldCheck className="size-4 text-success" />
            Aucun conflit détecté.
          </p>
        ) : (
          <ConflictSeverityGroups
            conflicts={conflicts}
            teams={teams}
            coaches={coaches}
            newFingerprints={newFingerprints}
            renderConflict={(conflict, meta) => (
              <ConflictResolutionControl conflict={conflict} teams={teams} coaches={coaches} tone={meta.tone} isNew={meta.isNew} canManage={canManage} />
            )}
          />
        )}
      </CardContent>
    </Card>
  );
}
