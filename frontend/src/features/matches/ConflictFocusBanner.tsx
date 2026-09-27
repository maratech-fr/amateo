import { AlertTriangle } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { coachFullName } from "@/shared/lib/coachName";

import type { Coach, Conflict, Team, Venue } from "./api";
import { buildConflictSideLines } from "./lib/conflictSideLines";

/**
 * Correctif 2 (retour terrain 2026-09-27) — le BANDEAU de focus en tête de la semaine du
 * Calendrier : quand un conflit est focalisé (« Voir la semaine » depuis Conflits, ou « Voir »
 * dans le rappel du radar), il rappelle DE QUOI il s'agit — « Conflit {coach} : {équipe A} ×
 * {équipe B}, chevauchement 15:30–16:10 » — et offre « Quitter le focus » (retire le filtre coach
 * et la surbrillance). Réutilise `buildConflictSideLines` (les noms d'équipes + le chevauchement)
 * et la primitive `NoticeBanner` — PRÉSENTATION pure (🔴 `.claude/rules/frontend.md`).
 */
export function ConflictFocusBanner({
  conflict,
  teams,
  coaches,
  venues,
  onQuit,
}: {
  conflict: Conflict;
  teams: Map<string, Team>;
  coaches: Map<string, Coach>;
  venues: Map<string, Venue>;
  onQuit: () => void;
}) {
  const model = buildConflictSideLines(conflict, teams, venues);
  const coach = undefined !== conflict.coachId ? coachFullName(coaches.get(conflict.coachId)) : null;
  const teamsPart = null !== model ? model.sides.map((side) => side.teamName).join(" × ") : null;
  const overlap = null !== model ? `, chevauchement ${model.overlap.start}–${model.overlap.end}` : "";
  const message = `Conflit${null !== coach ? ` ${coach}` : ""}${null !== teamsPart ? ` : ${teamsPart}` : ""}${overlap}`;

  return (
    <NoticeBanner tone="warning" role="status" icon={<AlertTriangle className="size-4 text-warning" aria-hidden="true" />} message={message}>
      <Button variant="outline" size="sm" onClick={onQuit}>
        Quitter le focus
      </Button>
    </NoticeBanner>
  );
}
