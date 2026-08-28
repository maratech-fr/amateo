<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Geo;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\VenueTravelTimeAutofillService;
use App\Tests\Double\SteppingClock;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * BCK-22 — le budget GLOBAL de l'autofill. Sans budget, un IGN dégradé pouvait tenir
 * la requête ~150 s (30 fenêtres × 5 s) sans rien rendre au gestionnaire. Le budget
 * arrête de dispatcher les fenêtres au-delà du temps imparti ; les paires restantes
 * reviennent `unresolved` avec la raison `budget_exceeded` (« relancez pour continuer »),
 * SANS casser le best-effort ni le cap dur. Le budget est prouvé par une horloge qui
 * avance à chaque lecture (jamais une vraie attente).
 */
#[Group('integration')]
final class VenueTravelTimeAutofillBudgetTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    /** L'horloge saute après la 1re fenêtre : les paires restantes → budget_exceeded. */
    public function testPairsBeyondTheBudgetComeBackUnresolvedWithBudgetReason(): void
    {
        [$clubId, $seasonId] = $this->seedGeolocatedVenues(5); // 5 venues → 10 pairs → 20 jobs → 3 windows.

        // Step 100 s ≫ the 30 s budget: after window 0 (the first 8 jobs = the first
        // 4 pairs) the next clock read is already past the deadline, so windows 1-2
        // are never dispatched.
        $result = $this->autofillWith(new SteppingClock(stepSeconds: 100))->autofill($clubId, $seasonId);

        // The 4 pairs of window 0 are filled; the 6 remaining come back budget_exceeded.
        self::assertSame(4, $result['filled'], 'les paires de la 1re fenêtre sont remplies');
        self::assertCount(6, $result['unresolved'], 'les 6 paires au-delà du budget reviennent non résolues');
        foreach ($result['unresolved'] as $pair) {
            self::assertSame('budget_exceeded', $pair['reason']);
        }
    }

    /** Contrôle : sans dépassement de budget, TOUTES les paires sont remplies. */
    public function testWithinBudgetEveryPairIsFilled(): void
    {
        [$clubId, $seasonId] = $this->seedGeolocatedVenues(5);

        // A clock that never advances → the budget never bites.
        $result = $this->autofillWith(new MockClock)->autofill($clubId, $seasonId);

        self::assertSame(10, $result['filled'], 'les 10 paires sont remplies quand le budget tient');
        self::assertSame([], $result['unresolved']);
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function autofillWith(ClockInterface $clock): VenueTravelTimeAutofillService
    {
        $ign = new IgnRoutingClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => 600]))),
            $clock,
        );

        return new VenueTravelTimeAutofillService($this->em, $ign);
    }

    /**
     * @return array{0: string, 1: string} clubId, seasonId
     */
    private function seedGeolocatedVenues(int $count): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club budget ' . $uid);
        $club->setSlug('club-budget-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('today'));
        $season->setEndDate(new DateTimeImmutable('+300 days'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        for ($i = 0; $i < $count; ++$i) {
            $venue = new Venue;
            $venue->setClubId($club->getId());
            $venue->setSeasonId($season->getId());
            $venue->setName('Gymnase ' . $i);
            $venue->setSource('manual');
            $venue->setLatitude(\sprintf('45.%06d', 700000 + $i));
            $venue->setLongitude(\sprintf('4.%06d', 800000 + $i));
            $this->em->persist($venue);
        }
        $this->em->flush();

        return [$club->getId(), $season->getId()];
    }
}
