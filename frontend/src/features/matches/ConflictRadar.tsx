import { AlertTriangle, ShieldCheck } from "lucide-react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { isManagementRole } from "@/shared/lib/roles";
import { useMe } from "@/shared/session/queries";

import type { Coach, Conflict, Team, Venue } from "./api";
import { ConflictSeverityGroups } from "./ConflictLine";
import { ConflictResolutionControl } from "./ConflictResolutionControl";
import { openConflictCount } from "./lib/conflictResolution";

interface ConflictRadarProps {
  conflicts: Conflict[];
  teams: Map<string, Team>;
  coaches: Map<string, Coach>;
  /** Les gymnases — pour nommer le lieu d'un entraînement dans le détail par côté. */
  venues: Map<string, Venue>;
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
export function ConflictRadar({ conflicts, teams, coaches, venues, newFingerprints }: ConflictRadarProps) {
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
          {/* A11Y-22 — le compteur d'à-traiter passe par StatusPill : `text-warning` sur `bg-warning/15`
              tombait sous AA ; le texte reste `text-foreground`, l'icône `AlertTriangle` (ci-dessus)
              porte le ton. Le complément « à traiter » n'est que pour le lecteur d'écran. */}
          {openCount > 0 ? (
            <StatusPill variant="warning" className="py-0">
              {openCount}
              <span className="sr-only"> à traiter</span>
            </StatusPill>
          ) : null}
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
            venues={venues}
            newFingerprints={newFingerprints}
            renderConflict={(conflict, meta) => (
              <ConflictResolutionControl conflict={conflict} teams={teams} coaches={coaches} venues={venues} tone={meta.tone} isNew={meta.isNew} canManage={canManage} />
            )}
          />
        )}
      </CardContent>
    </Card>
  );
}
