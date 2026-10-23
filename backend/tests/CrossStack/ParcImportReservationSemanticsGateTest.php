<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Service\ParcImport\ParcImportExecutor;
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
 * Chantier G PR 2 — NR BLOQUANT (axes §7.1 « constraint semantics » + « generation pipeline ») : une
 * RÉSERVATION saisie par l'IMPORT DE PARC est HONORÉE par le solveur. Le chemin complet est prouvé de
 * bout en bout — texte JSON `parc-v1` → IMPORT RÉEL ({@see ParcImportExecutor}, le métier de cette PR)
 * → VRAI builder ({@see ScheduleConstraintBuilder}) → VRAI moteur `/generate` (contrat 1.5) :
 *
 *  une réservation d'équipe pré-génération est un VERROU HARD (invariant socle, `Reservation.php:12-17`).
 *  Un gymnase a DEUX cases (lundi 18:00 réservée, mercredi 18:00 libre) et l'équipe PRÉFÈRE (souple) le
 *  mercredi. AVANT l'import — SANS réservation — le solveur suit la préférence et pose la séance le
 *  MERCREDI. L'import du parc écrit la réservation du LUNDI ; le builder la transporte en HARD, et le
 *  moteur pose désormais la séance le LUNDI, sur la case réservée, malgré la préférence contraire.
 *
 * TÉMOIN falsifiable (dans les deux sens) : le MÊME décor résolu AVANT l'import pose la séance le
 * mercredi (la préférence). C'est donc bien la réservation IMPORTÉE — et elle seule — qui déplace la
 * séance sur sa case. Si l'import cessait d'écrire la réservation (ou de la poser HARD au socle,
 * `schedulePlanId` null), le second solve retomberait sur le mercredi et le témoin casserait.
 *
 * Pourquoi un gate à MOTEUR réel en plus des gates de réservation existants : `FreeSlotSemanticsGateTest`
 * prouve qu'un créneau LIBRE (sans équipe) RETIRE une place, `SeasonSlotKeepSemanticsGateTest` que le
 * keep (via `socleReferenceAssignments`) garde un créneau de saison. AUCUN ne prouve qu'une réservation
 * d'ÉQUIPE écrite PAR L'IMPORT DE PARC devient un verrou HARD que le moteur honore — la jointure
 * « import de parc → verrou → moteur », sensible (constraint semantics + pipeline de génération), est
 * neuve. Doublon ASSUMÉ du point sensible, angle distinct nommé ici.
 *
 * Étape NOMMÉE du job `blocking-tests` de `.github/workflows/ci.yml` ET ligne de
 * `docs/testing/blocking-tests.md` — gardé par `BlockingTestsListMatchesCiTest`. Groupe `phase1`
 * (`--group phase1`) + `contract` (job engine-semantics). Skip propre si le moteur est absent.
 */
#[Group('phase1')]
#[Group('contract')]
final class ParcImportReservationSemanticsGateTest extends KernelTestCase
{
    use TenantGucTrait;

    private const string ENGINE_URL = 'http://engine:8000/generate';

    private const string SPORT_CATEGORY_ID = '99999999-9999-4999-8999-999999999999';

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    private ParcImportExecutor $executor;

    private ?CacheItemPoolInterface $scheduleCache = null;

    public function testAnImportedReservationLocksTheSessionOntoItsCaseAgainstASoftPreference(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Gymnase Matéo'); // non divisible
        $this->trainingSlot($club, $season, $venue, 1, '18:00'); // case RÉSERVÉE par l'import : lundi 18:00
        $this->trainingSlot($club, $season, $venue, 3, '18:00'); // case PRÉFÉRÉE (souple) : mercredi 18:00
        $team = $this->team($club, $season, 'SM1'); // 1 séance par semaine
        $this->preferredDay($club, $season, $team->getId(), 3); // préférence SOUPLE pour le mercredi
        $this->em->flush();

        // TÉMOIN — AVANT l'import (aucune réservation) : la préférence mercredi gagne.
        $witness = $this->solve($this->build($club, $season));
        self::assertSame(3, $this->dayOfTeam($witness, $team->getId()), 'témoin cassé : sans la réservation importée, l\'équipe doit suivre sa préférence (mercredi)');

        // IMPORT RÉEL du parc : une seule ligne cochée, la réservation lundi 18:00 pour SM1.
        $parc = [
            'format' => 'parc-v1',
            'gymnases' => [],
            'equipes' => [],
            'creneaux' => [],
            'mutualisations' => [],
            'reservations' => [['equipe' => 'SM1', 'gymnase' => 'Gymnase Matéo', 'jour' => 1, 'debut' => '18:00']],
        ];
        $decisions = [['section' => 'reservations', 'index' => 0, 'coche' => true]];
        $this->scopeGucToClub($club->getId());
        $this->executor->import($club, $season->getId(), $parc, $decisions);

        // L'import a posé une réservation HARD du SOCLE (schedulePlanId null) sur la case lundi 18:00.
        $this->scopeGucToClub($club->getId());
        $reservation = $this->reservationOf($team->getId(), $venue->getId(), 1, '18:00');
        self::assertInstanceOf(Reservation::class, $reservation, 'l\'import doit écrire la réservation cochée');
        self::assertNull($reservation->getSchedulePlanId(), 'la réservation importée est un verrou HARD du socle');

        // AVEC la réservation importée : le verrou HARD pose la séance le LUNDI, malgré la préférence mercredi.
        $kept = $this->solve($this->build($club, $season));
        self::assertSame(1, $this->dayOfTeam($kept, $team->getId()), 'la réservation importée doit verrouiller la séance sur sa case (lundi), malgré la préférence mercredi');
        self::assertSame($venue->getId(), $this->venueOfTeam($kept, $team->getId()), 'la séance tombe sur le gymnase réservé');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $this->executor = self::getContainer()->get(ParcImportExecutor::class);
        $pool = self::getContainer()->get('cache.schedule');
        $this->scheduleCache = $pool instanceof CacheItemPoolInterface ? $pool : null;
    }

    /**
     * Le payload réellement émis par le VRAI builder. On PURGE le cache d'entrée (clé par club+saison)
     * avant chaque build : le second build rebâtit le MÊME club+saison après l'import et, sans purge,
     * servirait le premier payload (périmé, sans la réservation).
     *
     * @return array<string, mixed>
     */
    private function build(Club $club, Season $season): array
    {
        $this->scheduleCache?->clear();

        return $this->builder->buildForClubSeason($club->getId(), $season->getId());
    }

    private function reservationOf(string $teamId, string $venueId, int $day, string $start): ?Reservation
    {
        /** @var list<Reservation> $rows */
        $rows = $this->em->getRepository(Reservation::class)->findBy([
            'teamId' => $teamId,
            'venueId' => $venueId,
            'dayOfWeek' => $day,
            'schedulePlanId' => null,
        ]);
        foreach ($rows as $reservation) {
            if ($reservation->getStartTime()->format('H:i') === $start) {
                return $reservation;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $result */
    private function dayOfTeam(array $result, string $teamId): ?int
    {
        foreach ($result['slots'] as $slot) {
            if (($slot['teamId'] ?? null) === $teamId) {
                return (int) $slot['dayOfWeek'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $result */
    private function venueOfTeam(array $result, string $teamId): ?string
    {
        foreach ($result['slots'] as $slot) {
            if (($slot['teamId'] ?? null) === $teamId) {
                return (string) $slot['venueId'];
            }
        }

        return null;
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
            ->setSportCategoryId(self::SPORT_CATEGORY_ID)->setPriorityTierId(1)->setSessionsPerWeek(1);
        $this->em->persist($team);

        return $team;
    }

    private function preferredDay(Club $club, Season $season, string $teamId, int $day): void
    {
        $constraint = (new Constraint)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('préférence de test')->setScope(ConstraintScope::TEAM)->setScopeTargetId($teamId)
            ->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::PREFERRED)->setConfig(['preferredDays' => [$day]]);
        $this->em->persist($constraint);
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Parc Reservation Gate Club');
        $club->setSlug('parc-reservation-gate-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('PRG' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('parc-reservation-gate-' . $uid . '@test.com');
        $user->setFirstName('P');
        $user->setLastName('R');
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
