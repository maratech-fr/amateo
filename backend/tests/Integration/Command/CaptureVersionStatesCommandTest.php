<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Plans\PlanStates;
use App\Service\SchedulePlanProvisioner;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Promesse (lot 5, PR 2, ligne 9 du plan, §6 : la conversion) : `app:plans:capture-version-states`, jouée une fois par
 * la procédure manuelle du lot, site fermé, donne à chaque version VALIDÉE ou CHARGÉE née avant la migration qui crée
 * `schedule_state` une copie égale à l'état vivant de son plan ; une version ni validée ni chargée n'en reçoit pas ;
 * rejouer n'écrit rien de plus. `--check` n'écrit rien et rend 1 dès qu'une version validée ou chargée n'a pas sa
 * copie : une capture interrompue arrête la procédure (le site ne rouvre pas). Une version née APRÈS la migration et
 * sans copie n'est jamais réparée par la commande : `--check` la signale.
 *
 * « Née avant la migration » : `schedule.created_at` antérieur à l'exécution de la migration qui crée `schedule_state`
 * (`doctrine_migration_versions.executed_at`) ; le décor date ses versions d'avant en 2020.
 *
 * Patron de garde de migration de données (charte tests-par-parcours §2) : protège le run réel tant que la commande
 * n'a pas tourné en prod. La base de test ne garde aucune version d'un autre test (transaction annulée) : le
 * `--check` global ne voit que ce décor. Jumeau, cité : `Lot4MigrationChainTest::testTheConversionGivesEveryKeptVersionItsCopy`.
 */
#[Group('phase1')]
#[Group('integration')]
final class CaptureVersionStatesCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private const string BEFORE_THE_MIGRATION = '2020-01-01 00:00:00';

    private EntityManagerInterface $em;

    private string $clubId;

    private string $planId;

    /**
     * Une version validée et une version chargée nées avant la migration reçoivent chacune une copie égale à l'état
     * vivant ; une version ni validée ni chargée n'en reçoit pas ; `--check` rend 0 ; rejouer n'écrit rien de plus.
     */
    public function testTheCaptureCopiesEveryValidatedOrLoadedVersionOnce(): void
    {
        $validated = $this->version('Validée', self::BEFORE_THE_MIGRATION);
        $loaded = $this->version('Chargée', self::BEFORE_THE_MIGRATION);
        $other = $this->version('Ni l\'une ni l\'autre', self::BEFORE_THE_MIGRATION);
        $this->point($validated, $loaded);

        self::assertSame(Command::SUCCESS, $this->capture([])->getStatusCode(), 'la capture réussit');

        $kept = [$validated, $loaded];
        sort($kept);
        self::assertSame($kept, $this->versionsWithACopy(), 'une copie pour la version validée et pour la chargée, aucune pour l\'autre');
        self::assertNotContains($other, $this->versionsWithACopy());
        $this->scopeGucToClub($this->clubId);
        $states = self::getContainer()->get(PlanStates::class);
        foreach ([$validated, $loaded] as $versionId) {
            self::assertTrue($states->of($versionId)->changesTo($states->capture($this->planId))->isEmpty(), 'la copie est l\'état vivant : aucun plan n\'est « en attente » après la conversion');
        }

        self::assertSame(Command::SUCCESS, $this->capture(['--check' => true])->getStatusCode(), '--check rend 0 : rien ne manque');
        $this->capture([]);
        self::assertSame(2, $this->copyCount(), 'rejouer la capture n\'écrit rien de plus');
    }

    /** Une capture interrompue (une copie manque) : `--check` rend 1, sans rien écrire. */
    public function testTheCheckFailsWhenACopyIsMissingAndWritesNothing(): void
    {
        $validated = $this->version('Validée', self::BEFORE_THE_MIGRATION);
        $loaded = $this->version('Chargée', self::BEFORE_THE_MIGRATION);
        $this->point($validated, $loaded);
        $this->capture([]);
        self::assertSame(2, $this->copyCount(), 'témoin : les deux copies sont écrites');
        $this->scopeGucToClub($this->clubId);
        $this->em->getConnection()->executeStatement('DELETE FROM schedule_state WHERE schedule_id = ?', [$loaded]);

        $tester = $this->capture(['--check' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), '--check rend 1 quand une version chargée n\'a pas sa copie : le site ne rouvre pas');
        self::assertSame(1, $this->copyCount(), '--check n\'écrit rien');
    }

    /** Une version validée née après la migration et sans copie n'est jamais réparée : `--check` la signale. */
    public function testAVersionBornAfterTheMigrationIsNeverRepaired(): void
    {
        $validated = $this->version('Validée après la migration', null);
        $this->point($validated, null);

        $this->capture([]);

        self::assertSame([], $this->versionsWithACopy(), 'la capture ne répare pas une version née après la migration');
        self::assertSame(Command::FAILURE, $this->capture(['--check' => true])->getStatusCode(), '--check signale la version validée sans copie');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $club = (new Club)->setName('Conversion ' . $uid)->setSlug('conversion-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->clubId = $club->getId();
        $this->scopeGucToClub($this->clubId);
        $season = (new Season)->setClubId($this->clubId)->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->persist((new Team)->setClubId($this->clubId)->setSeasonId($season->getId())->setName('U15')
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(2));
        $this->em->flush();
        $this->planId = SeasonPlanOf::id(self::getContainer(), $season->getId());
        self::assertSame(0, $this->copyCount(), 'témoin : le club n\'a aucune copie');
    }

    /** Une version terminée du plan de saison, datée avant la migration (ou du jour, `null`). */
    private function version(string $name, ?string $createdAt): string
    {
        $this->scopeGucToClub($this->clubId);
        $season = (string) $this->em->getConnection()->fetchOne('SELECT season_id FROM schedule_plan WHERE id = ?', [$this->planId]);
        $version = (new Schedule)->setClubId($this->clubId)->setSeasonId($season)->setName($name)
            ->setStatus(ScheduleStatus::COMPLETED)->setSchedulePlanId($this->planId);
        $this->em->persist($version);
        $this->em->flush();
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($version);
        $this->em->flush();
        if (null !== $createdAt) {
            $this->em->getConnection()->executeStatement('UPDATE schedule SET created_at = ? WHERE id = ?', [$createdAt, $version->getId()]);
        }

        return $version->getId();
    }

    /** La version validée (pointeur) et la version chargée (source des verrous) du plan. */
    private function point(string $validated, ?string $loaded): void
    {
        $this->scopeGucToClub($this->clubId);
        $this->em->getConnection()->executeStatement(
            'UPDATE schedule_plan SET chosen_schedule_id = ?, lock_source_schedule_id = ? WHERE id = ?',
            [$validated, $loaded, $this->planId],
        );
        $this->em->clear();
    }

    /** @param array<string, mixed> $options */
    private function capture(array $options): CommandTester
    {
        $this->em->clear();
        $this->clearGuc();
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('app:plans:capture-version-states'));
        $tester->execute($options);

        return $tester;
    }

    /** @return list<string> les versions du club qui ont une copie, triées */
    private function versionsWithACopy(): array
    {
        $this->scopeGucToClub($this->clubId);

        return array_map(strval(...), $this->em->getConnection()->fetchFirstColumn('SELECT schedule_id FROM schedule_state WHERE club_id = ? ORDER BY schedule_id', [$this->clubId]));
    }

    private function copyCount(): int
    {
        return \count($this->versionsWithACopy());
    }
}
