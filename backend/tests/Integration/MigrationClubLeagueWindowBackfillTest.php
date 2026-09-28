<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\LeagueMatchWindow;
use App\Entity\Season;
use App\Enum\SeasonStatus;
use App\Repository\ClubLeagueWindowRepository;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260928140000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR — le BACKFILL P4-272 ① porté par la migration : après `migrations:migrate`
 * seul (sans commande), un club PRÉEXISTANT a sa copie de fenêtres de ligue =
 * ligue EFFECTIVE. On exécute le VRAI SQL de la migration (via getSql(), jamais une
 * copie), sous GUC de club (la RLS borne l'INSERT au club de test), et on le rejoue
 * pour prouver l'idempotence. La saison ACTIVE et la DRAFT sont copiées, l'ARCHIVED
 * ne l'est pas ; un club à ligue non cataloguée retombe sur AURA.
 */
#[Group('integration')]
final class MigrationClubLeagueWindowBackfillTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testBackfillCopiesTheEffectiveLeagueForExistingClubsAndIsIdempotent(): void
    {
        // Catalogue global : une ligue distinctive « BFILLTEST » (1 fenêtre) + une AURA
        // distinctive — bornes reconnaissables pour ne dépendre d'aucun seed committé.
        $this->seedGlobalWindow('BFILLTEST', 'CatCataloguee', 'REGIONAL', null, 6, '15:00', '16:30');
        $this->seedGlobalWindow('AURA', 'CatAura', 'DEPARTEMENTAL', 'M', 7, '09:00', '10:00');

        // Club A, ligue cataloguée : saisons ACTIVE + DRAFT (copiées) + ARCHIVED (non).
        $clubA = $this->makeClub('BFILLTEST');
        $this->scopeGucToClub($clubA);
        $active = $this->makeSeason($clubA, '2025-2026', SeasonStatus::ACTIVE);
        $draft = $this->makeSeason($clubA, '2026-2027', SeasonStatus::DRAFT);
        $archived = $this->makeSeason($clubA, '2024-2025', SeasonStatus::ARCHIVED);

        $this->runMigration();
        $this->runMigration(); // rejouée : idempotente, aucun doublon

        // ACTIVE : copie = la fenêtre de la ligue cataloguée du club, à l'identique.
        $activeCopy = $this->copies($clubA, $active);
        self::assertCount(1, $activeCopy);
        self::assertSame('BFILLTEST', $activeCopy[0]->getLeague());
        self::assertSame('CatCataloguee', $activeCopy[0]->getCategory());
        self::assertSame('15:00', $activeCopy[0]->getKickoffMin()->format('H:i'));
        self::assertSame('16:30', $activeCopy[0]->getKickoffMax()->format('H:i'));

        // DRAFT : copiée aussi (saison suivante déjà là).
        self::assertCount(1, $this->copies($clubA, $draft));
        // ARCHIVED : le passé reste consultatif, jamais copié.
        self::assertCount(0, $this->copies($clubA, $archived));

        // Club B, ligue NON cataloguée → repli AURA.
        $clubB = $this->makeClub('ZZZUNCAT');
        $this->scopeGucToClub($clubB);
        $seasonB = $this->makeSeason($clubB, '2025-2026', SeasonStatus::ACTIVE);

        $this->runMigration();

        $copyB = $this->copies($clubB, $seasonB);
        self::assertNotEmpty($copyB);
        foreach ($copyB as $window) {
            self::assertSame('AURA', $window->getLeague(), 'ligue non cataloguée → copie AURA');
        }
        self::assertContains('CatAura', array_map(static fn (ClubLeagueWindow $w): string => $w->getCategory(), $copyB));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function runMigration(): void
    {
        $migration = new Version20260928140000($this->em->getConnection(), new NullLogger);
        $migration->up(new Schema);
        $connection = $this->em->getConnection();
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $this->em->clear();
    }

    /**
     * @return list<ClubLeagueWindow>
     */
    private function copies(string $clubId, string $seasonId): array
    {
        /** @var ClubLeagueWindowRepository $repository */
        $repository = $this->em->getRepository(ClubLeagueWindow::class);

        return $repository->findForClubSeason($clubId, $seasonId);
    }

    private function seedGlobalWindow(string $league, string $category, string $level, ?string $gender, int $day, string $min, string $max): void
    {
        $window = new LeagueMatchWindow;
        $window->setLeague($league)->setCategory($category)->setLevel($level)->setGender($gender)
            ->setDayOfWeek($day)->setKickoffMin(new DateTimeImmutable($min))->setKickoffMax(new DateTimeImmutable($max));
        $this->em->persist($window);
        $this->em->flush();
    }

    private function makeClub(string $league): string
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Backfill Club');
        $club->setSlug('backfill-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('BFL' . strtoupper(substr(md5($uid), 0, 8)));
        $club->setLeague($league);
        $this->em->persist($club);
        $this->em->flush();

        return $club->getId();
    }

    private function makeSeason(string $clubId, string $name, SeasonStatus $status): string
    {
        $startYear = (int) substr($name, 0, 4);
        $season = new Season;
        $season->setClubId($clubId);
        $season->setName($name);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus($status);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return $season->getId();
    }
}
