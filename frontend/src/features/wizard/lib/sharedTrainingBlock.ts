import type { SharedTrainingBlock, Team, TeamPeriodOverride } from "../api";
import { maxCommonSessions } from "./sharedTraining";

/**
 * P2-51 PR-4 — helpers PURS de la saisie d'un BLOC de mutualisation au wizard (un ensemble
 * d'équipes qui se comporte comme UNE équipe, avec SES séances communes).
 *
 * 🔴 RIEN ICI N'AUTORISE NI N'INTERDIT — le serveur reste seul juge. La pré-validation est
 * FAIL-SAFE : elle guide la saisie (borne de la liste déroulante, repère « déjà dans N blocs »)
 * sans jamais remplacer le verdict serveur `App\State\Processor\SharedTrainingBlockStateProcessor::assertBlockValid`
 * (2..10 équipes, garde centrale Σ des séances communes ≤ séances/semaine effectives, ensemble déjà
 * déclaré). La borne AFFICHÉE est un plafond de CONFORT (`min` des séances effectives) ; la garde Σ
 * peut refuser plus finement (le cumul des AUTRES blocs de l'équipe), auquel cas le 422 est affiché
 * tel quel, jamais supposé impossible.
 *
 * ⚠ Ce module n'est PAS un miroir déclaré (régime 2) et ne doit pas se déclarer comme tel : il ne
 * branche AUCUN enum de contrainte partagé pour décider d'un comportement — il compte des séances
 * effectives et des appartenances pour l'AFFICHAGE (patron `matches/lib/diagnostic.ts`). Réutilise
 * `maxCommonSessions`/`effectiveSessionsPerWeek` de `sharedTraining.ts` : la borne « min des séances
 * effectives » est la MÊME donnée que pour le groupe K, lue par sa forme.
 */

/**
 * Les valeurs de la LISTE DÉROULANTE des séances communes du bloc : `1..cap`, où
 * `cap = min(séances/semaine EFFECTIVES des membres)` (override de période prioritaire). Liste
 * VIDE quand la sélection est vide OU qu'un membre a zéro séance effective (aucune valeur posable
 * — rien à offrir, le bouton de création reste inerte). C'est un plafond de CONFORT : le serveur
 * (garde Σ) peut refuser plus bas.
 */
export function blockCommonSessionOptions(teams: Team[], overrideByTeamId: ReadonlyMap<string, TeamPeriodOverride>): number[] {
  const cap = maxCommonSessions(teams, overrideByTeamId);
  if (cap < 1) {
    return [];
  }

  return Array.from({ length: cap }, (_, i) => i + 1);
}

/**
 * Combien de blocs de la PORTÉE COURANTE (hormis celui en cours d'édition) contiennent déjà cette
 * équipe — pour le repère informatif « déjà dans N bloc(s) ». La multi-appartenance est PERMISE
 * (D9) : ce compte n'INTERDIT rien, il rassure que la case reste cochable (contraste voulu avec le
 * verrou un-groupe-par-équipe du groupe K).
 */
export function blockMembershipCount(blocks: SharedTrainingBlock[], teamId: string, excludeBlockId: string | null): number {
  let count = 0;
  for (const b of blocks) {
    if (b.id !== excludeBlockId && b.teamIds.includes(teamId)) {
      count += 1;
    }
  }

  return count;
}
