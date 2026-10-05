<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Enum\CalendarEntryKind;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\PlanVenueClosures;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * P4-300 — NR BLOQUANT (axes §7.1 « constraint semantics » + « backend↔engine contract ») : une
 * FERMETURE de gymnase du calendrier (`venue_closed`) s'applique AUSSI aux matchs. La promesse
 * entière est prouvée de bout en bout — seed → VRAI builder → VRAI moteur `/place-matches` :
 *
 *  (1) un domicile À PLACER sur un samedi où son UNIQUE gymnase est fermé par le calendrier
 *      n'est posé NULLE PART ce jour-là ; TÉMOIN falsifiable — SANS la fermeture, le MÊME payload
 *      (rebâti par le builder après suppression de la contrainte) place le match dans ce gymnase ;
 *  (2) parité à la source : l'intervalle émis par `PlanVenueClosures::closureIntervals` == les
 *      dates stockées dans le `config` (repli legacy = fenêtre de l'entrée porteuse quand le config
 *      est nu), et la fermeture d'un AUTRE club ne fuit pas (GUC scope le club courant).
 *
 * Étape NOMMÉE du job `blocking-tests` de `.github/workflows/ci.yml` (qui démarre déjà php-fpm +
 * engine) ET ligne de `docs/testing/blocking-tests.md` — gardé par `BlockingTestsListMatchesCiTest`.
 * Groupe `phase1` pour `--group phase1` ; `contract` pour le job engine-semantics. Skip propre si le
 * moteur est absent (mêmes conventions que les autres tests de contrat cross-stack).
 */
#[Group('phase1')]
#[Group('contract')]
final class VenueClosureSemanticsGateTest extends KernelTestCase
{
    use TenantGucTrait;

    private const string ENGINE_URL = 'http://engine:8000/place-matches';
    private const string SATURDAY = '2026-10-03'; // ISO day 6, inside the closure below
    private const string CLOSURE_FROM = '2026-08-31';
    private const string CLOSURE_TO = '2026-10-16';

    private EntityManagerInterface $em;

    private MatchPlacementPayloadBuilder $builder;

    private PlanVenueClosures $planVenueClosures;

    /**
     * (1) + TÉMOIN — le moteur réel ne pose pas un domicile dans un gymnase fermé par le
     * calendrier ce samedi-là ; sans la fermeture, le même payload l'y pose.
     */
    public function testAClosedVenueKeepsTheMatchOffItWhileWithoutItThePlacementLands(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Gymnase Matéo');
        $this->matchWindow($club, $season, $venue, 6, '13:00', '22:30');
        $team = $this->team($club, $season, 'SM1');
        $this->homeFixture($club, $season, $team, self::SATURDAY);
        $carrier = $this->periodEntry($club, $season);
        $closure = $this->venueClosed($club, $season, $venue, $carrier, self::CLOSURE_FROM, self::CLOSURE_TO);
        $this->em->flush();

        // AVEC la fermeture — le gymnase est fermé le 2026-10-03 : le match n'est posé nulle part.
        $withClosure = $this->solve($this->build($club, $season));
        self::assertSame([], $this->placementsAtVenue($withClosure, $venue->getId()), 'un domicile ne doit pas être posé dans un gymnase fermé par le calendrier');
        self::assertCount(1, $withClosure['unplaced'], 'le match doit rester non placé, son seul gymnase étant fermé');
        self::assertSame('venue_unavailable', $withClosure['unplaced'][0]['reason']);

        // TÉMOIN — on retire la fermeture et on REBÂTIT par le vrai builder : le match s'y pose.
        $this->em->remove($closure);
        $this->em->flush();

        $withoutClosure = $this->solve($this->build($club, $season));
        self::assertSame([], $withoutClosure['unplaced'], 'témoin cassé : le match reste non placé même sans fermeture');
        self::assertCount(1, $this->placementsAtVenue($withoutClosure, $venue->getId()), 'sans fermeture, le match se pose bien dans ce gymnase');
    }

    /**
     * (2) parité à la source — l'intervalle émis == les dates du config ; repli legacy = fenêtre
     * de l'entrée porteuse ; une fermeture d'un AUTRE club ne fuit pas.
     */
    public function testEmittedIntervalsMatchTheStoredFactAndDoNotLeakAcrossClubs(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Gymnase Matéo');
        $carrier = $this->periodEntry($club, $season);
        $this->venueClosed($club, $season, $venue, $carrier, self::CLOSURE_FROM, self::CLOSURE_TO, 'Gymnase en travaux');
        // Une seconde fermeture au config NU → repli sur la fenêtre de l'entrée porteuse.
        $legacyVenue = $this->venue($club, $season, 'Gymnase Legacy');
        $this->venueClosed($club, $season, $legacyVenue, $carrier, null, null, 'Fermeture legacy');
        $this->em->flush();

        $intervals = $this->planVenueClosures->closureIntervals($club->getId(), $season->getId());
        $byVenue = [];
        foreach ($intervals as $interval) {
            $byVenue[$interval['venueId']] = $interval;
        }

        self::assertSame([self::CLOSURE_FROM, self::CLOSURE_TO], [$byVenue[$venue->getId()]['startDate'] ?? null, $byVenue[$venue->getId()]['endDate'] ?? null], 'l\'intervalle émis == les dates stockées dans le config');
        self::assertSame('Gymnase en travaux', $byVenue[$venue->getId()]['title'] ?? null);
        self::assertSame(
            [$carrier->getStartDate()->format('Y-m-d'), $carrier->getEndDate()->format('Y-m-d')],
            [$byVenue[$legacyVenue->getId()]['startDate'] ?? null, $byVenue[$legacyVenue->getId()]['endDate'] ?? null],
            'un config nu retombe sur la fenêtre de l\'entrée porteuse (repli legacy)',
        );

        // RLS — la fermeture du club B est invisible dans le périmètre du club A.
        [$clubB, $seasonB] = $this->seed();
        $venueB = $this->venue($clubB, $seasonB, 'Gymnase B');
        $carrierB = $this->periodEntry($clubB, $seasonB);
        $this->venueClosed($clubB, $seasonB, $venueB, $carrierB, self::CLOSURE_FROM, self::CLOSURE_TO);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $venueIdsForA = array_column($this->planVenueClosures->closureIntervals($club->getId(), $season->getId()), 'venueId');
        self::assertNotContains($venueB->getId(), $venueIdsForA, 'la fermeture d\'un autre club ne fuit pas');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
        $this->planVenueClosures = self::getContainer()->get(PlanVenueClosures::class);
    }

    /**
     * Le payload de placement réellement émis par le VRAI builder pour ce club+saison.
     *
     * @return array<string, mixed>
     */
    private function build(Club $club, Season $season): array
    {
        return $this->builder->build($club, $season->getId())['payload'];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>> les placements posés dans ce gymnase
     */
    private function placementsAtVenue(array $result, string $venueId): array
    {
        /** @var list<array<string, mixed>> $placements */
        $placements = $result['placements'];

        return array_values(array_filter($placements, static fn (array $p): bool => ($p['venueId'] ?? null) === $venueId));
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
            self::assertSame('completed', $result['status'], 'le scénario de placement doit rester résoluble');

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

    private function matchWindow(Club $club, Season $season, Venue $venue, int $day, string $start, string $end): void
    {
        $window = new VenueMatchWindow;
        $window->setClubId($club->getId());
        $window->setSeasonId($season->getId());
        $window->setVenueId($venue->getId());
        $window->setDayOfWeek($day);
        $window->setStartTime(new DateTimeImmutable($start));
        $window->setEndTime(new DateTimeImmutable($end));
        $this->em->persist($window);
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)
            ->setSportCategoryId('99999999-9999-4999-8999-999999999999')->setPriorityTierId(1)->setSessionsPerWeek(1);
        $this->em->persist($team);

        return $team;
    }

    private function homeFixture(Club $club, Season $season, Team $team, string $date): void
    {
        $now = new DateTimeImmutable;
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($team->getId());
        // Non-amical (competitionId non null) → candidat TO_PLACE, pas un amical libre.
        $fixture->setCompetitionId('88888888-8888-4888-8888-888888888888');
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire');
        $fixture->setStatus(FixtureStatus::UNPLACED, $now);
        $this->em->persist($fixture);
    }

    private function periodEntry(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Période de reprise');
        $entry->setStartDate(new DateTimeImmutable(self::CLOSURE_FROM));
        $entry->setEndDate(new DateTimeImmutable(self::CLOSURE_TO));
        $this->em->persist($entry);

        return $entry;
    }

    private function venueClosed(Club $club, Season $season, Venue $venue, CalendarEntry $carrier, ?string $from, ?string $to, string $name = 'Gymnase fermé'): Constraint
    {
        $closure = new Constraint;
        $closure->setClubId($club->getId());
        $closure->setSeasonId($season->getId());
        $closure->setFamily(ConstraintFamily::FACILITY);
        $closure->setScope(ConstraintScope::FACILITY);
        $closure->setScopeTargetId($venue->getId());
        $closure->setRuleType(ConstraintRuleType::HARD);
        $closure->setName($name);
        $closure->setCalendarEntryId($carrier->getId());
        $config = ['type' => 'venue_closed'];
        if (null !== $from) {
            $config['startDate'] = $from;
        }
        if (null !== $to) {
            $config['endDate'] = $to;
        }
        $closure->setConfig($config);
        $this->em->persist($closure);

        return $closure;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Venue Closure Gate Club');
        $club->setSlug('venue-closure-gate-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('VCG' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('venue-closure-gate-' . $uid . '@test.com');
        $user->setFirstName('V');
        $user->setLastName('C');
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
