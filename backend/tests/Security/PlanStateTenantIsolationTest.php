<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Enum\TeamLevel;
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
        $theirTeam = $this->loadTeam('SM1 de B', level: TeamLevel::REGIONAL, tier: 1, sessions: 2);
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

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
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
