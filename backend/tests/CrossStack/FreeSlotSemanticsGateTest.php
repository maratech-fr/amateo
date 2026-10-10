<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\SeasonStatus;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Lot 4bis — NR BLOQUANT (axes §7.1 « constraint semantics » + « backend↔engine contract ») : un
 * CRÉNEAU LIBRE réservé RETIRE une place à sa case, exactement comme une capacité en moins. La
 * promesse est prouvée de bout en bout — seed → VRAI builder (`ScheduleConstraintBuilder`) → VRAI
 * moteur `/generate` (contrat 1.4) :
 *
 *  un gymnase NON divisible n'a qu'une case (lundi 18:00, capacité 1) et une équipe (1 séance) n'a
 *  qu'elle où aller. AVEC un créneau libre sur cette case, la capacité tombe à 0 : la case
 *  DISPARAÎT du payload, l'équipe n'y est placée NULLE PART. TÉMOIN falsifiable — SANS le créneau
 *  libre, le MÊME scénario rebâti par le builder place l'équipe sur cette case.
 *
 * Étape NOMMÉE du job `blocking-tests` de `.github/workflows/ci.yml` (qui démarre déjà php-fpm +
 * engine) ET ligne de `docs/testing/blocking-tests.md` — gardé par `BlockingTestsListMatchesCiTest`.
 * Groupe `phase1` pour `--group phase1` ; `contract` pour le job engine-semantics. Skip propre si le
 * moteur est absent (mêmes conventions que les autres tests de contrat cross-stack).
 */
#[Group('phase1')]
#[Group('contract')]
final class FreeSlotSemanticsGateTest extends KernelTestCase
{
    use TenantGucTrait;

    private const string ENGINE_URL = 'http://engine:8000/generate';

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    private ?CacheItemPoolInterface $scheduleCache = null;

    public function testAFreeSlotRemovesTheSeatWhileWithoutItTheTeamIsPlacedThere(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Gymnase Matéo'); // non divisible (canSplit = false)
        $this->trainingSlot($club, $season, $venue, 1, '18:00'); // unique case : lundi 18:00, capacité 1
        $team = $this->team($club, $season, 'SM1'); // 1 séance par semaine
        $free = $this->freeSlot($club, $season, $venue, 1, '18:00', 'Loto du club');
        $this->em->flush();

        // AVEC le créneau libre — la capacité de la case tombe à 0, la case sort du payload :
        // l'équipe n'est placée nulle part.
        $withFree = $this->solve($this->build($club, $season));
        self::assertSame([], $this->placementsOfTeam($withFree, $team->getId()), 'un créneau libre retire la place : l\'équipe ne doit pas y être placée');

        // TÉMOIN — on retire le créneau libre et on REBÂTIT par le vrai builder : l'équipe s'y pose.
        $this->em->remove($free);
        $this->em->flush();

        $withoutFree = $this->solve($this->build($club, $season));
        self::assertCount(1, $this->placementsOfTeam($withoutFree, $team->getId()), 'témoin cassé : sans le créneau libre, l\'équipe doit être placée sur la case');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $pool = self::getContainer()->get('cache.schedule');
        $this->scheduleCache = $pool instanceof CacheItemPoolInterface ? $pool : null;
    }

    /**
     * Le payload de génération réellement émis par le VRAI builder pour ce club+saison. On PURGE le
     * cache d'entrée avant chaque build : il est clé par club+saison, et le témoin rebâtit le MÊME
     * club+saison après avoir retiré le créneau libre — sans purge, le second build servirait le
     * premier payload (périmé).
     *
     * @return array<string, mixed>
     */
    private function build(Club $club, Season $season): array
    {
        $this->scheduleCache?->clear();

        return $this->builder->buildForClubSeason($club->getId(), $season->getId());
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>> les créneaux placés pour cette équipe
     */
    private function placementsOfTeam(array $result, string $teamId): array
    {
        /** @var list<array<string, mixed>> $slots */
        $slots = $result['slots'];

        return array_values(array_filter($slots, static fn (array $s): bool => ($s['teamId'] ?? null) === $teamId));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function solve(array $payload): array
    {
        $client = HttpClient::create(['timeout' => 30]);

        try {
            $response = $client->request('POST', self::ENGINE_URL, ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());
            $result = $response->toArray(false);
            self::assertSame('completed', $result['status'], 'le scénario de génération doit rester résoluble');

            return $result;
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)->setSource('manual');
        $this->em->persist($venue);

        return $venue;
    }

    private function trainingSlot(Club $club, Season $season, Venue $venue, int $day, string $start): void
    {
        $slot = (new VenueTrainingSlot)
            ->setClubId($club->getId())->setSeasonId($season->getId())->setVenueId($venue->getId())
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)->setCapacity(1);
        $this->em->persist($slot);
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)
            ->setSportCategoryId('99999999-9999-4999-8999-999999999999')->setPriorityTierId(1)->setSessionsPerWeek(1);
        $this->em->persist($team);

        return $team;
    }

    private function freeSlot(Club $club, Season $season, Venue $venue, int $day, string $start, string $label): Reservation
    {
        $reservation = (new Reservation)
            ->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId(null)->setLabel($label)
            ->setVenueId($venue->getId())->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90);
        $this->em->persist($reservation);

        return $reservation;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Free Slot Gate Club');
        $club->setSlug('free-slot-gate-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('FSG' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('free-slot-gate-' . $uid . '@test.com');
        $user->setFirstName('F');
        $user->setLastName('S');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

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
