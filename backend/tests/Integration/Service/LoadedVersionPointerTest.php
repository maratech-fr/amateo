<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\User;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Plans\PlanStates;
use App\Service\SchedulePlanProvisioner;
use App\Tests\EngineRequestDecorTrait;
use App\Tests\EngineRequestRunnerTrait;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse : la version chargée (l'étoile) du socle vit, comme celle d'une période, sur son plan — un seul
 * pointeur, `schedule_plan.lock_source_schedule_id` (lot 4, PR 2 ; la colonne de la saison disparaît). Elle suit
 * « Charger » et chaque génération terminée, et c'est elle, lue sur le plan, qui décide de la seule version
 * modifiable (409 `not_loaded_version` ailleurs).
 *
 * Angles jumeaux, cités : `Integration/Plans/LoadVersionTest::testLoadingPutsBackTheColdOfExistingTeamsAndVenues`
 * (l'étoile suit « Charger », par la route) et `Security/LoadedVersionIsTheOnlyEditableTest` (les neuf guichets
 * refusent une version non chargée, bloquant) ; ici, l'endroit unique où l'étoile du socle est rangée et relue.
 */
#[Group('phase1')]
#[Group('integration')]
final class LoadedVersionPointerTest extends WebTestCase
{
    use EngineRequestDecorTrait;
    use EngineRequestRunnerTrait;

    private KernelBrowser $client;

    public function testLoadingASocleVersionPointsItsSeasonPlan(): void
    {
        $older = $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED);
        $this->seedSocleVersion(self::ER_SOCLE_V2, ScheduleStatus::COMPLETED);
        $this->provisioner()->pointLockSource($this->erSeasonPlanId(), self::ER_SOCLE_V2);
        $this->erEm()->flush();
        $states = self::getContainer()->get(PlanStates::class);
        $states->store($older, $states->capture($this->erSeasonPlanId()));

        $this->client->request('POST', '/api/schedules/' . self::ER_SOCLE_V1 . '/load', [], [], $this->headers());

        self::assertResponseStatusCodeSame(200, (string) $this->client->getResponse()->getContent());
        $this->assertTheSocleStarIs(self::ER_SOCLE_V1);
    }

    public function testACompletedSocleGenerationPointsItsSeasonPlan(): void
    {
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED);
        $this->provisioner()->pointLockSource($this->erSeasonPlanId(), self::ER_SOCLE_V1);
        $this->seedSocleVersion(self::ER_SOCLE_V2, ScheduleStatus::PENDING);
        $this->erEm()->flush();

        $this->runGeneration(self::ER_SOCLE_V2, self::ER_SOCLE_V1);

        $this->assertTheSocleStarIs(self::ER_SOCLE_V2);
    }

    public function testOnlyTheVersionTheSeasonPlanPointsCanBeEdited(): void
    {
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED, [[1, self::ER_TEAM_U13, self::ER_VENUE_NORD, 1, '18:00', LockLevel::NONE]]);
        $this->seedSocleVersion(self::ER_SOCLE_V2, ScheduleStatus::COMPLETED, [[2, self::ER_TEAM_U13, self::ER_VENUE_NORD, 1, '18:00', LockLevel::NONE]]);
        $this->provisioner()->pointLockSource($this->erSeasonPlanId(), self::ER_SOCLE_V1);
        $this->erEm()->flush();

        $this->lock(self::erId('5107', 2));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_loaded_version', $this->decode()['code'] ?? null, 'la version que le plan ne pointe pas se consulte seulement');

        $this->lock(self::erId('5107', 1));
        self::assertNotContains($this->decode()['code'] ?? null, ['not_loaded_version', 'chosen_version'], 'la version pointée par le plan se modifie : ' . $this->client->getResponse()->getContent());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->pinEngineRequestClock();
        $this->seedEngineRequestBase();
    }

    protected function tearDown(): void
    {
        $this->unpinEngineRequestClock();
        parent::tearDown();
    }

    private function assertTheSocleStarIs(string $scheduleId): void
    {
        $connection = $this->erEm()->getConnection();
        self::assertSame(
            $scheduleId,
            $connection->fetchOne('SELECT lock_source_schedule_id FROM schedule_plan WHERE id = ?', [$this->erSeasonPlanId()]),
            'l\'étoile du socle est rangée sur son plan de saison',
        );
        self::assertSame($scheduleId, $this->provisioner()->lockSourceScheduleIdOfPlan($this->erSeasonPlanId()), 'et relue sur lui');
        self::assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = \'public\' AND table_name = \'season\' AND column_name = \'live_context_schedule_id\''),
            'la saison ne porte plus de pointeur : un seul, sur le plan',
        );
    }

    private function lock(string $slotId): void
    {
        $this->client->request('POST', '/api/schedule-slots/' . $slotId . '/manual-edit/lock', [], [], $this->headers(), json_encode(['lockLevel' => 'HARD'], \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $user = $this->erEm()->find(User::class, self::ER_USER);
        self::assertInstanceOf(User::class, $user);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    private function provisioner(): SchedulePlanProvisioner
    {
        return self::getContainer()->get(SchedulePlanProvisioner::class);
    }
}
