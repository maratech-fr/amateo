import type { SharedTrainingGroup, Team, TeamPeriodOverride } from "../api";

/**
 * P2-27 PR B — helpers PURS de la saisie de mutualisation au wizard (N équipes ensemble,
 * K séances communes).
 *
 * 🔴 RIEN ICI N'AUTORISE NI N'INTERDIT — le serveur reste seul juge. La pré-validation de ce
 * module (K plafonné, équipe déjà mutualisée) est FAIL-SAFE : elle guide la saisie sans jamais
 * remplacer le verdict serveur `App\State\Processor\SharedTrainingGroupStateProcessor::assertDeclarationValid`
 * (2..10 équipes, doublon, K > min sessionsPerWeek effectif, équipe d'un autre groupe du même
 * plan). Un 422 doit rester géré et affiché côté écran, jamais supposé impossible.
 *
 * ⚠ Le CLASSEMENT des candidats (`splitByLinks`) est un ORDRE D'AFFICHAGE, jamais une permission :
 * « équipes liées » remonte en tête les équipes PASSERELÉES à l'ancre — une donnée SERVIE par le
 * backend (`GET /api/team_links`, `matches/api.ts`), jamais redérivée côté client (régime (1) de
 * `.claude/rules/frontend.md`) — et rien n'est interdit, tout reste cochable en un clic. C'est de
 * la présentation (cf. `matches/lib/diagnostic.ts`), pas une décision de comportement sur un enum
 * de contrainte : ce module n'est donc PAS un miroir déclaré, et il ne doit pas se déclarer comme
 * tel (le registre des miroirs se repère au NOM de son test de garde écrit en source — le citer
 * ici enrôlerait ce module par accident).
 *
 * Solde P2-34 : l'heuristique catégorie+genre (`rankCandidates`, supprimée avec `gendersCompatible`)
 * a cédé la place aux passerelles déclarées, le seul lien inter-équipes que le club a lui-même posé.
 */

/**
 * Séances/semaine EFFECTIVES d'une équipe — l'override de période est PRIORITAIRE. Miroir de
 * `SharedTrainingGroupStateProcessor::effectiveSessionsPerWeek` : un override qui porte un
 * `sessionsPerWeek` non nul l'emporte, sinon on retombe sur la valeur de saison. En portée socle
 * il n'y a pas d'override (la carte est vide) → toujours la valeur de saison.
 */
export function effectiveSessionsPerWeek(team: Team, override: TeamPeriodOverride | undefined): number {
  if (undefined !== override && null !== override.sessionsPerWeek) {
    return override.sessionsPerWeek;
  }

  return team.sessionsPerWeek;
}

/**
 * Le plus petit `sessionsPerWeek` effectif de la sélection = le plafond de K (au-delà, la
 * mutualisation exige plus de séances communes qu'une équipe n'en a → jamais satisfiable, le
 * serveur rend 422). 0 pour une sélection vide (aucun plafond signifiant).
 */
export function maxCommonSessions(teams: Team[], overrideByTeamId: ReadonlyMap<string, TeamPeriodOverride>): number {
  if (0 === teams.length) {
    return 0;
  }

  return Math.min(...teams.map((t) => effectiveSessionsPerWeek(t, overrideByTeamId.get(t.id))));
}

/**
 * Les ids d'équipe déjà pris dans un groupe de la PORTÉE COURANTE, hormis le groupe en cours
 * d'édition (`excludeGroupId`) — dont les membres doivent rester libres de leur propre formulaire.
 */
export function alreadyGroupedTeamIds(groups: SharedTrainingGroup[], excludeGroupId: string | null): Set<string> {
  const taken = new Set<string>();
  for (const group of groups) {
    if (group.id === excludeGroupId) {
      continue;
    }
    for (const teamId of group.teamIds) {
      taken.add(teamId);
    }
  }

  return taken;
}

/** Le groupe (AUTRE que celui édité) auquel une équipe appartient — pour nommer la raison « déjà mutualisée avec … ». */
export function groupContainingTeam(groups: SharedTrainingGroup[], teamId: string, excludeGroupId: string | null): SharedTrainingGroup | undefined {
  return groups.find((group) => group.id !== excludeGroupId && group.teamIds.includes(teamId));
}

/**
 * P2-51 PR-6 — la sous-ligne « Mutualisée avec … » d'une équipe, fusionnant les DEUX sources
 * pendant la transition : le groupe {équipes, K} historique ET le nouveau bloc de mutualisation.
 * Les co-équipières des deux sources s'unissent SANS doublon (une partenaire présente dans les deux
 * n'est nommée qu'une fois), l'ordre d'insertion préservé (groupes d'abord, puis blocs). `null` si
 * l'équipe n'appartient à AUCUNE mutualisation (ni groupe ni bloc). Présentation pure (patron
 * `matches/lib/diagnostic.ts`) : le serveur reste seul maître de la mutualisation, l'écran la reflète.
 * PR-7 retirera la source groupe et cette fusion redeviendra une source unique. Types STRUCTURELS
 * `{ teamIds }` pour lire groupe et bloc par leur forme sans coupler ce lib à l'une des deux entités.
 */
export function mutualisedTeammateLabel(
  teamId: string,
  groups: readonly { teamIds: string[] }[],
  blocks: readonly { teamIds: string[] }[],
  teamName: (id: string) => string,
): string | null {
  const others: string[] = [];
  const seen = new Set<string>();
  let member = false;
  for (const src of [...groups, ...blocks]) {
    if (!src.teamIds.includes(teamId)) {
      continue;
    }
    member = true;
    for (const id of src.teamIds) {
      if (id !== teamId && !seen.has(id)) {
        seen.add(id);
        others.push(id);
      }
    }
  }
  if (!member) {
    return null;
  }

  return others.length > 0 ? `Mutualisée avec ${others.map(teamName).join(", ")}` : "Mutualisée";
}

/** Libellé d'un groupe : « SM1 + SM2 — 1 séance commune » (pluriel géré). */
export function sharedGroupLabel(teamIds: string[], commonSessions: number, teamName: (id: string) => string): string {
  const names = teamIds.map(teamName).join(" + ");
  const sessions = `${commonSessions} séance${commonSessions > 1 ? "s" : ""} commune${commonSessions > 1 ? "s" : ""}`;

  return `${names} — ${sessions}`;
}

/**
 * Les ids des équipes PASSERELÉES à `anchorId` — l'autre bout de chaque lien qui la nomme. Données
 * SERVIES (les `TeamLink` du club+saison viennent du backend, jamais redérivées). Signature
 * structurelle `{ teamAId, teamBId }` pour ne pas coupler ce lib wizard au type `TeamLink` du module
 * matchs : c'est la MÊME donnée, lue par sa forme.
 */
export function teamsLinkedTo(links: readonly { teamAId: string; teamBId: string }[], anchorId: string): Set<string> {
  const linked = new Set<string>();
  for (const link of links) {
    if (link.teamAId === anchorId) {
      linked.add(link.teamBId);
    } else if (link.teamBId === anchorId) {
      linked.add(link.teamAId);
    }
  }

  return linked;
}

/**
 * Classe les candidats en deux blocs D'AFFICHAGE relativement à l'équipe d'ancrage (la première
 * cochée) : « liées » = équipes PASSERELÉES à l'ancre (`linkedIds`, servi par le backend), OU déjà
 * cochées (un candidat coché doit toujours rester atteignable pour être décoché) ; le reste va dans
 * « far », déplié en un clic. Sans ancre (rien de coché) : tout reste en `far` (liste plate, pas de
 * bloc « liées »). L'ORDRE des candidats est préservé dans chaque bloc.
 */
export function splitByLinks(
  candidates: Team[],
  anchor: Team | null,
  checkedIds: ReadonlySet<string>,
  linkedIds: ReadonlySet<string>,
): { near: Team[]; far: Team[] } {
  const near: Team[] = [];
  const far: Team[] = [];
  for (const candidate of candidates) {
    const isNear = null !== anchor && (checkedIds.has(candidate.id) || linkedIds.has(candidate.id));
    (isNear ? near : far).push(candidate);
  }

  return { near, far };
}

/** Minuscule + accents retirés, pour une recherche insensible à la CASSE ET aux ACCENTS. */
function normalizeForSearch(value: string): string {
  return value
    .normalize("NFD")
    .replace(/\p{Diacritic}/gu, "")
    .toLowerCase();
}

/**
 * Filtre les candidats de la mutualisation par une requête texte (insensible casse+accents).
 * Deux garde-fous d'ergonomie, jamais une permission (c'est de l'affichage) :
 *  - une requête VIDE (ou blanche) ne filtre rien — tous les candidats reviennent ;
 *  - une équipe COCHÉE est EXEMPTÉE du filtre — on ne perd jamais une sélection de vue, même quand
 *    son nom ne correspond pas à la requête (exigence fondateur).
 * L'ORDRE des candidats est préservé.
 */
export function filterCandidates(candidates: Team[], query: string, checkedIds: ReadonlySet<string>): Team[] {
  const needle = normalizeForSearch(query.trim());
  if ("" === needle) {
    return candidates;
  }

  return candidates.filter((t) => checkedIds.has(t.id) || normalizeForSearch(t.name).includes(needle));
}
