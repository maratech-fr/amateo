<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\LeagueMatchWindow;
use App\Entity\Season;
use App\Enum\SeasonStatus;
use App\Service\ClubLeagueWindowSeeder;
use App\Service\ClubProvisioner;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P4-272 ① — la COPIE club de l'enveloppe ligue est posée à partir de la ligue
 * EFFECTIVE (patron `LeagueMatchWindowRepository::effectiveLeague`) : club
 * catalogué → sa ligue, club non catalogué → défaut fédérale AURA. Idempotente.
 * Et la naissance d'un club en pose une (`ClubProvisioner::seedWorkspace`).
 */
#[Group('integration')]
final class ClubLeagueWindowSeederTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ClubLeagueWindowSeeder $seeder;

    public function testCataloguedClubCopiesItsOwnLeague(): void
    {
        [$club, $season] = $this->clubSeason('GEST');

        $count = $this->seeder->seedForSeason($club->getId(), $season->getId(), 'GEST');
        $this->em->flush();

        self::assertSame(1, $count);
        $copy = $this->copies($club, $season);
        self::assertCount(1, $copy);
        self::assertSame('GEST', $copy[0]->getLeague());
        self::assertSame('18:00', $copy[0]->getKickoffMin()->format('H:i'));
        self::assertSame('20:00', $copy[0]->getKickoffMax()->format('H:i'));
    }

    public function testUncataloguedClubFallsBackToAura(): void
    {
        [$club, $season] = $this->clubSeason(null);

        // Ligue inconnue (jamais cataloguée) → repli AURA.
        $count = $this->seeder->seedForSeason($club->getId(), $season->getId(), 'ZZZ');
        $this->em->flush();

        self::assertSame(2, $count);
        $copy = $this->copies($club, $season);
        self::assertCount(2, $copy);
        foreach ($copy as $window) {
            self::assertSame('AURA', $window->getLeague());
        }
        self::assertSame(['Seniors', 'U13'], array_map(static fn (ClubLeagueWindow $w): string => $w->getCategory(), $copy));
    }

    public function testSeedingIsIdempotent(): void
    {
        [$club, $season] = $this->clubSeason(null);

        self::assertSame(2, $this->seeder->seedForSeason($club->getId(), $season->getId(), null));
        $this->em->flush();
        // Un second passage ne redouble pas la copie.
        self::assertSame(0, $this->seeder->seedForSeason($club->getId(), $season->getId(), null));
        $this->em->flush();

        self::assertCount(2, $this->copies($club, $season));
    }

    public function testClubProvisioningPlantsTheCopy(): void
    {
        $provisioner = self::getContainer()->get(ClubProvisioner::class);
        $club = $provisioner->createClub('Club Provision Ligue', 'ZZZ999');
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $provisioner->seedWorkspace($club);
        $this->em->flush();

        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $club->getId()]);
        self::assertNotNull($season);
        // ZZZ999 → ligue non résolue → repli AURA (2 fenêtres cataloguées).
        self::assertCount(2, $this->copies($club, $season));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seeder = self::getContainer()->get(ClubLeagueWindowSeeder::class);

        // Catalogue GLOBAL : deux fenêtres AURA (référence des copies).
        $this->globalWindow('AURA', 'Seniors', 'REGIONAL', null, 6, '14:00', '16:00');
        $this->globalWindow('AURA', 'U13', 'DEPARTEMENTAL', 'M', 7, '10:00', '11:30');
        // Une autre ligue cataloguée, pour distinguer « club catalogué ».
        $this->globalWindow('GEST', 'Seniors', 'REGIONAL', null, 6, '18:00', '20:00');
        $this->em->flush();
    }

    /**
     * @return list<ClubLeagueWindow>
     */
    private function copies(Club $club, Season $season): array
    {
        return $this->em->getRepository(ClubLeagueWindow::class)->findBy(
            ['clubId' => $club->getId(), 'seasonId' => $season->getId()],
            ['category' => 'ASC'],
        );
    }

    private function globalWindow(string $league, string $category, string $level, ?string $gender, int $day, string $min, string $max): void
    {
        $window = new LeagueMatchWindow;
        $window->setLeague($league)->setCategory($category)->setLevel($level)->setGender($gender)
            ->setDayOfWeek($day)->setKickoffMin(new DateTimeImmutable($min))->setKickoffMax(new DateTimeImmutable($max));
        $this->em->persist($window);
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function clubSeason(?string $league): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Seeder Club');
        $club->setSlug('seeder-club-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('SDR' . strtoupper(substr(md5($uid), 0, 8)));
        $club->setLeague($league);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }
}
