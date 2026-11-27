<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Schedule;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Enum\TeamCoachRole;
use App\Enum\TeamLevel;
use App\Plans\InForce;
use App\Service\SchedulePlanProvisioner;
use App\Tests\LoadsVersionDecorTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR bloquant — axe *tenant isolation*, test d'abus (lot conception 5, PR 3, ligne 17 du plan) : « Charger » n'écrit
 * jamais hors du club de la version. Une copie d'état qui cite, par identifiant, l'équipe, la règle ou la ligne de
 * plan d'un autre club (copie corrompue, ou fabriquée en base) n'écrit rien chez lui, et ne fait naître chez le club
 * qui charge aucune ligne qui désigne une équipe d'un autre club.
 *
 * La copie est réécrite en SQL avec les identifiants de l'autre club, puis la version est chargée par le geste réel
 * (`POST /api/schedules/{id}/load`). Lecture de l'autre club sous SON contexte. Témoin : le chargement aboutit et
 * remet chez le club qui charge son propre réglage (sans quoi « rien n'est écrit chez l'autre » serait vrai d'un
 * chargement qui n'écrit rien du tout). La PR 5 ajoute un cas : la porte ne rend jamais l'état d'un autre club.
 * Bloquant (step `Blocking suite` de `ci.yml`, `docs/testing/blocking-tests.md`).
 */
#[Group('phase1')]
#[Group('integration')]
final class PlanStateTenantIsolationTest extends WebTestCase
{
    use LoadsVersionDecorTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testACopyCitingAnotherClubsRowsWritesNothingThere(): void
    {
        // Le club B, sa saison, son équipe, sa règle, sa ligne d'équipe.
        $this->seedLoadClub('club-b');
        $clubB = $this->clubId;
        $theirTeam = $this->loadTeam('SM1 de B', tier: 1, level: TeamLevel::REGIONAL, sessions: 2);
        $theirRule = $this->loadTeamRule($theirTeam, '19:00');
        $theirPlan = $this->soclePlanId;
        $theirLine = (string) $this->loadScalar('SELECT id FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$theirPlan, $theirTeam->getId()]);
        $theirState = $this->clubRows($theirTeam->getId(), $theirRule->getId(), $theirPlan);

        // Le club A, sa version 1 ; puis la copie de la version 1 réécrite pour citer les lignes de B.
        $this->seedLoadClub('club-a');
        $ours = $this->loadTeam('SM1 de A', sessions: 2);
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $this->em->getConnection()->executeStatement('UPDATE plan_team SET sessions_per_week = 3 WHERE schedule_plan_id = ? AND team_id = ?', [$this->soclePlanId, $ours->getId()]);
        $this->makeLoaded($this->loadVersionOf($this->soclePlanId));
        $copy = json_decode((string) $this->loadScalar('SELECT data::text FROM schedule_state WHERE schedule_id = ?', [$v1->getId()]), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($copy);
        $copy['Team'][$theirTeam->getId()] = ['forcedVenueId' => null, 'gender' => null, 'level' => TeamLevel::DEPARTEMENTAL->value, 'priorityTierId' => 4, 'sportCategoryId' => $this->categoryIds['U15']];
        $copy['Constraint'][$theirRule->getId()] = ['config' => ['minStartTime' => '21:00'], 'family' => 'TIME', 'isActive' => false, 'ruleType' => 'HARD', 'scope' => 'TEAM', 'scopeTargetId' => $theirTeam->getId()];
        $copy['PlanTeam'][$theirLine] = ['maxSessionsPerDay' => 1, 'participates' => false, 'sessionsPerWeek' => 5, 'teamId' => $theirTeam->getId()];
        $copy['PlanTeam']['00000000-0000-4000-8000-00000000b0b0'] = ['maxSessionsPerDay' => 1, 'participates' => true, 'sessionsPerWeek' => 4, 'teamId' => $theirTeam->getId()];
        $this->em->getConnection()->executeStatement('UPDATE schedule_state SET data = ? WHERE schedule_id = ?', [json_encode($copy, \JSON_THROW_ON_ERROR), $v1->getId()]);

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger la version de A');
        self::assertSame(2, (int) $this->loadScalar('SELECT sessions_per_week FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$this->soclePlanId, $ours->getId()]), 'témoin : le réglage de A revient à sa version 1');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM plan_team WHERE team_id = ?', [$theirTeam->getId()]), 'aucune ligne de A ne désigne l\'équipe de B');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM "constraint" WHERE id = ? OR scope_target_id = ?', [$theirRule->getId(), $theirTeam->getId()]), 'aucune règle de A ne naît avec l\'identifiant ou la cible de B');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM team WHERE id = ?', [$theirTeam->getId()]), 'aucune équipe de A ne naît avec l\'identifiant de B');

        $this->clubId = $clubB;
        self::assertSame($theirState, $this->clubRows($theirTeam->getId(), $theirRule->getId(), $theirPlan), 'rien n\'est écrit chez B : son équipe, sa règle, sa ligne d\'équipe sont intactes');
    }

    /**
     * Promesse (lot 5, PR 5, ligne 37 du plan) : la porte de l'en vigueur ne rend jamais l'état d'un autre club. Le
     * club A a validé son socle ; la copie de sa version en vigueur est réécrite en SQL pour citer l'équipe et le lien
     * de coach du club B. Sous le contexte de A, la porte ne rend aucune équipe ni aucun lien de B, et la saison de B ne
     * lui rend ni version en vigueur ni ligne. Témoin : la porte de A lit bien sa copie (le rang d'une équipe de A,
     * changé depuis sans valider, revient à celui de la copie), sans quoi « rien de B » serait vrai d'une porte qui
     * n'ouvre pas la copie.
     */
    public function testTheDoorNeverServesAnotherClubsState(): void
    {
        // Le club B, sa saison validée, son équipe et son coach principal.
        $this->seedLoadClub('porte-b');
        $clubB = $this->clubId;
        $seasonB = $this->seasonId;
        $theirTeam = $this->loadTeam('SM1 de B', tier: 2);
        $theirCoach = $this->newCoach($clubB, $seasonB, 'Bea', 'Autreclub');
        $this->em->flush();
        $this->em->persist((new TeamCoach)->setClubId($clubB)->setSeasonId($seasonB)->setTeamId($theirTeam->getId())->setCoachId($theirCoach->getId())->setRole(TeamCoachRole::MAIN));
        $this->em->flush();
        $theirLink = (string) $this->loadScalar('SELECT id FROM team_coach WHERE team_id = ?', [$theirTeam->getId()]);
        $this->makeInForce($this->loadVersionOf($this->soclePlanId));

        // Le club A, sa saison validée (copie prise à la validation), puis son équipe changée de rang sans valider.
        $this->seedLoadClub('porte-a');
        $ours = $this->loadTeam('SM1 de A', tier: 1);
        $inForce = $this->loadVersionOf($this->soclePlanId);
        $this->makeInForce($inForce);
        $this->em->getConnection()->executeStatement('UPDATE team SET priority_tier_id = 4 WHERE id = ?', [$ours->getId()]);
        $copy = json_decode((string) $this->loadScalar('SELECT data::text FROM schedule_state WHERE schedule_id = ?', [$inForce->getId()]), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($copy);
        $copy['Team'][$theirTeam->getId()] = ['forcedVenueId' => null, 'gender' => null, 'level' => TeamLevel::DEPARTEMENTAL->value, 'priorityTierId' => 2, 'sportCategoryId' => $this->categoryIds['Seniors']];
        $copy['TeamCoach'][$theirLink] = ['coachId' => $theirCoach->getId(), 'role' => TeamCoachRole::MAIN->value, 'teamId' => $theirTeam->getId()];
        $this->em->getConnection()->executeStatement('UPDATE schedule_state SET data = ? WHERE schedule_id = ?', [json_encode($copy, \JSON_THROW_ON_ERROR), $inForce->getId()]);

        $this->scopeGucToClub($this->clubId);
        $this->em->clear();
        $door = self::getContainer()->get(InForce::class);
        $teams = [];
        foreach ($door->rows($this->seasonId, Team::class) as $team) {
            $teams[$team->getId()] = $team->getPriorityTierId();
        }
        $links = array_map(static fn (TeamCoach $link): string => $link->getId() . '|' . $link->getTeamId(), $door->rows($this->seasonId, TeamCoach::class));

        self::assertSame(1, $teams[$ours->getId()] ?? null, 'témoin : la porte de A lit sa copie (rang 1 validé, pas le 4 posé depuis)');
        self::assertArrayNotHasKey($theirTeam->getId(), $teams, 'la porte de A ne rend jamais l\'équipe de B, même citée par sa copie');
        self::assertSame([], array_values(array_filter($links, static fn (string $link): bool => str_contains($link, $theirTeam->getId()) || str_starts_with($link, $theirLink))), 'la porte de A ne rend jamais le lien de coach de B');
        self::assertNull($door->socleVersionId($seasonB), 'sous le contexte de A, la saison de B n\'a pas de version en vigueur');
        self::assertSame([], $door->rows($seasonB, Team::class), 'sous le contexte de A, la saison de B ne rend aucune équipe');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** La validation : le plan de saison pointe la version (en vigueur) et la charge. */
    private function makeInForce(Schedule $version): void
    {
        $this->scopeGucToClub($this->clubId);
        self::assertTrue(self::getContainer()->get(SchedulePlanProvisioner::class)->choose($version), 'la version doit pouvoir être pointée');
        $this->makeLoaded($version);
        $this->em->flush();
    }

    /** L'équipe, la règle et la ligne d'équipe d'un club, lues sous son contexte, en texte. */
    private function clubRows(string $teamId, string $ruleId, string $planId): string
    {
        return (string) $this->loadScalar(
            'SELECT (SELECT level || \' \' || priority_tier_id || \' \' || sport_category_id FROM team WHERE id = :t)'
            . ' || \' | \' || (SELECT config::text || \' \' || is_active || \' \' || rule_type FROM "constraint" WHERE id = :r)'
            . ' || \' | \' || (SELECT string_agg(sessions_per_week || \' \' || participates, \' ; \') FROM plan_team WHERE team_id = :t)'
            . ' || \' | \' || (SELECT COUNT(*) FROM plan_team WHERE schedule_plan_id = :p)',
            ['t' => $teamId, 'r' => $ruleId, 'p' => $planId],
        );
    }
}
