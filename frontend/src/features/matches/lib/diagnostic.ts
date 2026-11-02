import type { Conflict } from "../api";

/**
 * Grouping/labelling of the GRADED diagnostic (P1-4 PR E2, cadrage §8). The
 * severity itself is emitted by the SERVER — this lib only sorts, groups and
 * labels; it never re-derives gravity (one truth, not two).
 */

export interface DiagnosticGroup {
  /** Unique par groupe : la gravité 3 en donne plusieurs. */
  key: string;
  severity: number;
  title: string;
  /** Visual tone of the group (worst first). */
  tone: "destructive" | "warning" | "muted";
  /** Folded by default (severity 7: N info lines, not N alert cards). */
  folded: boolean;
  conflicts: Conflict[];
}

const GROUP_TITLES: Record<number, string> = {
  // AUD-UXC-13 — « gymnase » partout dans l'app ; « salle » ne subsistait que dans le
  // module matchs. Deux mots pour un objet, c'est un objet de plus à comprendre.
  1: "Collision de gymnase",
  2: "Hors fenêtre ligue",
  4: "Placement fragilisé",
  5: "À surveiller",
  6: "Calendriers incomplets",
  7: "Angles morts",
};

/**
 * La gravité 3 se lit par ce qu'il faut regarder (décision fondateur 2026-11-02), dans cet ordre.
 * Table de présentation seulement : la gravité reste celle du serveur. Un type absent de la table
 * tombe sous le repli « Autres ».
 */
const SEVERITY_3_GROUPS: { key: string; title: string; types: readonly string[] }[] = [
  { key: "3-person", title: "Personne", types: ["MATCH_MATCH", "MATCH_TRAINING", "MATCH_COACH_UNAVAILABLE"] },
  { key: "3-venue", title: "Gymnase", types: ["TEAM_VENUE_FORBIDDEN"] },
  { key: "3-rule", title: "Règle", types: ["CLUB_RULE_VIOLATION"] },
];

const OTHERS_TITLE = "Autres";

/** Tonalité d'une gravité (worst first) — exportée pour l'onglet Conflits, qui
 *  colorise ses lignes sans re-décider la gravité (émise par le serveur). */
export function toneOf(severity: number): DiagnosticGroup["tone"] {
  if (severity <= 3) {
    return "destructive";
  }
  return severity <= 5 ? "warning" : "muted";
}

/** Sort by severity (1 first) and group; severities 6-7 come folded with a count
 * (structural information, not collisions — N teams must read as N lines max). */
export function groupBySeverity(conflicts: Conflict[]): DiagnosticGroup[] {
  const bySeverity = new Map<number, Conflict[]>();
  for (const conflict of conflicts) {
    const severity = conflict.severity ?? 5;
    const bucket = bySeverity.get(severity) ?? [];
    bucket.push(conflict);
    bySeverity.set(severity, bucket);
  }

  return [...bySeverity.entries()]
    .sort(([a], [b]) => a - b)
    .flatMap(([severity, items]) => {
      const group = (key: string, title: string, conflicts: Conflict[]): DiagnosticGroup => ({ key, severity, title, tone: toneOf(severity), folded: severity >= 6, conflicts });
      if (3 !== severity) {
        return [group(String(severity), GROUP_TITLES[severity] ?? OTHERS_TITLE, items)];
      }
      const known = new Set(SEVERITY_3_GROUPS.flatMap((g) => g.types));
      return [
        ...SEVERITY_3_GROUPS.map((g) => group(g.key, g.title, items.filter((c) => g.types.includes(c.type)))),
        group("3-other", OTHERS_TITLE, items.filter((c) => !known.has(c.type))),
      ].filter((g) => g.conflicts.length > 0);
    });
}
