import { AlertTriangle, Eye, ShieldCheck } from "lucide-react";
import { useMemo } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/shared/components/ui/card";
import { SegmentedControl } from "@/shared/components/ui/segmented-control";
import { isManagementRole } from "@/shared/lib/roles";
import { useMe } from "@/shared/session/queries";

import type { Coach, Conflict, Team, Venue } from "./api";
import { ConflictSeverityGroups } from "./ConflictLine";
import { ConflictResolutionControl } from "./ConflictResolutionControl";
import { isFocusableConflict } from "./lib/conflictFocus";
import { openConflictCount } from "./lib/conflictResolution";
import { hasHomeSide } from "./lib/consultFilter";
import { useMatchesStore } from "./store";

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
  /**
   * Correctif 2 — « Voir » focalise CE conflit sur place (filtre coach + surbrillance des deux
   * rencontres, sans quitter la semaine). Absent (onglet Conflits, qui a son propre « Voir la
   * semaine ») ⇒ pas de bouton.
   */
  onFocusConflict?: (conflict: Conflict) => void;
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
export function ConflictRadar({ conflicts, teams, coaches, venues, newFingerprints, onFocusConflict }: ConflictRadarProps) {
  const { data: me } = useMe();
  const canManage = isManagementRole(me?.role);
  // M4 (retours Nico, BCCL 2026-10-27) — le radar de la vue Semaine filtre lui aussi « avec un match
  // à domicile » (COCHÉ par défaut, même préférence de session que l'onglet Conflits), là où le
  // gestionnaire peut agir. Comptes via `hasHomeSide` (aucune redérivation métier nouvelle).
  const homeOnly = useMatchesStore((s) => s.conflictsHomeOnly);
  const setHomeOnly = useMatchesStore((s) => s.setConflictsHomeOnly);
  const homeConflictCount = useMemo(() => conflicts.filter(hasHomeSide).length, [conflicts]);
  const displayed = useMemo(() => (homeOnly ? conflicts.filter(hasHomeSide) : conflicts), [conflicts, homeOnly]);
  // Le badge ne compte que l'À TRAITER (P4-207) — un conflit annoté reste listé, mais
  // ne pèse plus sur le compteur ; il suit la sélection domicile/tous affichée.
  const openCount = openConflictCount(displayed);
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
      <CardContent className="flex flex-col gap-3">
        {conflicts.length > 0 ? (
          <SegmentedControl
            mode="single"
            label="Conflits à afficher"
            className="self-start"
            options={[
              { key: "home", label: "Avec un match à domicile", count: homeConflictCount, ariaLabel: `Avec un match à domicile (${homeConflictCount})` },
              { key: "all", label: "Tous", count: conflicts.length, ariaLabel: `Tous (${conflicts.length})` },
            ]}
            value={homeOnly ? "home" : "all"}
            onValueChange={(key) => setHomeOnly("home" === key)}
          />
        ) : null}
        {0 === displayed.length ? (
          <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <ShieldCheck className="size-4 text-success" />
            {0 === conflicts.length ? "Aucun conflit détecté." : "Aucun conflit avec un match à domicile — basculez sur « Tous » pour voir les autres."}
          </p>
        ) : (
          <ConflictSeverityGroups
            conflicts={displayed}
            teams={teams}
            coaches={coaches}
            venues={venues}
            newFingerprints={newFingerprints}
            renderConflict={(conflict, meta) => (
              <ConflictResolutionControl
                conflict={conflict}
                teams={teams}
                coaches={coaches}
                venues={venues}
                tone={meta.tone}
                isNew={meta.isNew}
                canManage={canManage}
                extraTrailing={
                  undefined !== onFocusConflict && isFocusableConflict(conflict) ? (
                    <Button variant="outline" size="sm" onClick={() => onFocusConflict(conflict)}>
                      <Eye className="size-4" aria-hidden="true" />
                      Voir
                    </Button>
                  ) : undefined
                }
              />
            )}
          />
        )}
      </CardContent>
    </Card>
  );
}
