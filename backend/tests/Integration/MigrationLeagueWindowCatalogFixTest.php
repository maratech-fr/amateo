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
use DoctrineMigrations\Version20260929130000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR — le CORRECTIF catalogue P4-272 ② porté par la migration : là où le catalogue
 * global est VIDE, `migrations:migrate` seul le charge depuis le JSON fédéral ET pose
 * la copie de chaque club (comme Version20260928140000 l'aurait fait avec un catalogue
 * plein). Anti-résurrection : catalogue PLEIN au départ ⇒ on ne touche à rien — une
 * copie vidée à dessein NE ressuscite pas. On exécute le VRAI SQL de la migration (via
 * getSql(), jamais une copie), sous GUC de club.
 */
#[Group('integration')]
final class MigrationLeagueWindowCatalogFixTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testEmptyCatalogIsLoadedFromTheJsonAndCopiesAreBackfilledIdempotently(): void
    {
        // Catalogue VIDE au départ (la base de test le porte plein depuis l'init — on
        // le vide pour simuler l'environnement que le correctif vise).
        $this->em->createQuery('DELETE FROM ' . ClubLeagueWindow::class . ' w')->execute();
        $this->em->createQuery('DELETE FROM ' . LeagueMatchWindow::class . ' w')->execute();
        self::assertSame(0, $this->catalogCount());

        // Un club préexistant : saisons ACTIVE + DRAFT (copiées) + ARCHIVED (non). Ligue
        // AURA (cataloguée par le JSON chargé).
        $clubA = $this->makeClub('AURA');
        $this->scopeGucToClub($clubA);
        $active = $this->makeSeason($clubA, '2025-2026', SeasonStatus::ACTIVE);
        $draft = $this->makeSeason($clubA, '2026-2027', SeasonStatus::DRAFT);
        $archived = $this->makeSeason($clubA, '2024-2025', SeasonStatus::ARCHIVED);

        $this->runMigration($clubA);

        // Le catalogue fédéral est chargé (le JSON AURA committé compte 21 fenêtres).
        self::assertSame(21, $this->catalogCount(), 'le catalogue vide est chargé depuis le JSON');

        // La copie ACTIVE et la DRAFT reçoivent la ligue effective (AURA) ; l'ARCHIVED non.
        self::assertCount(21, $this->copies($clubA, $active));
        self::assertCount(21, $this->copies($clubA, $draft));
        self::assertCount(0, $this->copies($clubA, $archived));
        foreach ($this->copies($clubA, $active) as $window) {
            self::assertSame('AURA', $window->getLeague());
        }

        // Idempotence : rejouée, le catalogue étant DÉSORMAIS plein, la migration est un
        // no-op propre — ni doublon de catalogue, ni doublon de copie.
        $this->runMigration($clubA);
        self::assertSame(21, $this->catalogCount(), 'rejouée = pas de doublon de catalogue');
        self::assertCount(21, $this->copies($clubA, $active), 'rejouée = pas de doublon de copie');
    }

    public function testFullCatalogIsUntouchedAndAnEmptiedCopyIsNotResurrected(): void
    {
        // Catalogue PLEIN au départ : une fenêtre distinctive suffit à le marquer non vide.
        $this->em->createQuery('DELETE FROM ' . ClubLeagueWindow::class . ' w')->execute();
        $this->em->createQuery('DELETE FROM ' . LeagueMatchWindow::class . ' w')->execute();
        $this->seedGlobalWindow('AURA', 'CatPleine', 'DEPARTEMENTAL', null, 6, '10:00', '11:00');
        $before = $this->catalogCount();
        self::assertGreaterThan(0, $before);

        // Un club dont le gestionnaire a VIDÉ sa copie : on pose une ligne puis on la
        // supprime (l'état « copie volontairement vide » que l'anti-résurrection protège).
        $clubA = $this->makeClub('AURA');
        $this->scopeGucToClub($clubA);
        $season = $this->makeSeason($clubA, '2025-2026', SeasonStatus::ACTIVE);
        $this->seedCopyRow($clubA, $season, 'Provisoire', 6, '14:00', '15:00');
        $this->em->createQuery('DELETE FROM ' . ClubLeagueWindow::class . ' w WHERE w.clubId = :c')->setParameter('c', $clubA)->execute();
        self::assertCount(0, $this->copies($clubA, $season));

        $this->runMigration($clubA);

        // Catalogue INTOUCHÉ (aucune ligne ajoutée) ET copie NON ressuscitée.
        self::assertSame($before, $this->catalogCount(), 'catalogue plein → intouché');
        self::assertCount(0, $this->copies($clubA, $season), 'une copie vidée à dessein ne ressuscite pas');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function runMigration(string $gucClubId): void
    {
        $this->scopeGucToClub($gucClubId);
        $migration = new Version20260929130000($this->em->getConnection(), new NullLogger);
        $migration->up(new Schema);
        $connection = $this->em->getConnection();
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $this->em->clear();
    }

    private function catalogCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM league_match_window');
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

    private function seedCopyRow(string $clubId, string $seasonId, string $category, int $day, string $min, string $max): void
    {
        $window = new ClubLeagueWindow;
        $window->setClubId($clubId);
        $window->setSeasonId($seasonId);
        $window->setLeague('AURA');
        $window->setCategory($category);
        $window->setLevel('DEPARTEMENTAL');
        $window->setGender(null);
        $window->setDayOfWeek($day);
        $window->setKickoffMin(new DateTimeImmutable($min));
        $window->setKickoffMax(new DateTimeImmutable($max));
        $this->em->persist($window);
        $this->em->flush();
    }

    private function makeClub(string $league): string
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Catalog Fix Club');
        $club->setSlug('catalog-fix-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 7)));
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
