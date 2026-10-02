<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Clock\ClubClock;
use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Service\ClubDay;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * P4-46 — « quel jour est-on pour ce club ? », les quatre cas de fuseau + l'horloge simulée.
 *
 * ⚑ Le cas guadeloupéen est celui qui a motivé le foyer : le soir du dernier jour d'une
 * deadline, il est déjà demain à Paris — comparer au jour du SERVEUR fermait le lien
 * (`410`) alors que la règle promet « deadline incluse, le jour même est ouvert ». Le jour
 * qui compte est celui que LE CLUB vit.
 *
 * ⚑ P4-16 / P2-4 : un club à horloge simulée vit à SA date, quel que soit son fuseau et
 * l'heure réelle — la lecture passe par {@see ClubClock::simulatedTodayFor()}, le point
 * d'entrée unique. Supprimer ce court-circuit dans ClubDay fait rougir
 * {@see self::testASimulatedClockOverridesTheRealCivilDay}.
 */
#[Group('phase1')]
final class ClubDayTest extends TestCase
{
    /** 01:30 UTC le 16 : déjà le 16 à Paris… encore le 15 en Guadeloupe (UTC−4). */
    public function testAGuadeloupeClubIsStillOnYesterdayWhenParisHasMovedOn(): void
    {
        $service = $this->clubDay(new MockClock('2026-06-16 01:30:00', 'UTC'));

        self::assertSame('2026-06-15', $service->todayYmdFor($this->club('America/Guadeloupe')), 'le soir guadeloupéen appartient encore à SA journée — pas à celle de Paris');
        self::assertSame('2026-06-16', $service->todayYmdFor($this->club('Europe/Paris')));
    }

    /** 20:00 UTC le 15 : encore le 15 à Paris… déjà le 16 à Nouméa (UTC+11). */
    public function testANoumeaClubIsAlreadyOnTomorrow(): void
    {
        $service = $this->clubDay(new MockClock('2026-06-15 20:00:00', 'UTC'));

        self::assertSame('2026-06-16', $service->todayYmdFor($this->club('Pacific/Noumea')));
        self::assertSame('2026-06-15', $service->todayYmdFor($this->club('Europe/Paris')));
    }

    public function testAnEmptyTimezoneFallsBackToParis(): void
    {
        $service = $this->clubDay(new MockClock('2026-06-15 23:30:00', 'UTC'));

        // 23:30 UTC = 01:30 le 16 à Paris : le repli doit être Paris, pas UTC.
        self::assertSame('2026-06-16', $service->todayYmdFor($this->club('')));
    }

    /** Une TZ stockée invalide ne doit JAMAIS exploser sur une donnée — repli silencieux. */
    public function testAnInvalidTimezoneFallsBackToParisWithoutThrowing(): void
    {
        $service = $this->clubDay(new MockClock('2026-06-15 12:00:00', 'UTC'));

        self::assertSame('2026-06-15', $service->todayYmdFor($this->club('Mars/Olympus')));
    }

    /**
     * Horloge simulée : la date du club prime sur le fuseau ET sur l'heure réelle. Le même
     * club guadeloupéen à horloge posée vit à CETTE date, pas à celle que son fuseau
     * imposerait. C'est la non-régression du court-circuit ajouté dans ClubDay.
     */
    public function testASimulatedClockOverridesTheRealCivilDay(): void
    {
        $service = $this->clubDay(new MockClock('2026-06-16 01:30:00', 'UTC'));
        $club = $this->club('America/Guadeloupe')->setSimulatedToday(new DateTimeImmutable('2027-01-20'));

        self::assertSame('2027-01-20', $service->todayYmdFor($club), 'un club à horloge posée vit à SA date, pas au jour réel de son fuseau');
    }

    private function club(string $timezone): Club
    {
        return new Club()->setTimezone($timezone);
    }

    /**
     * ClubDay s'appuie sur un ClubClock RÉEL (le point d'entrée unique). Avec le drapeau
     * `APP_CLUB_CLOCK_ALL` éteint et aucun pin, `simulatedTodayFor` ne lit que
     * `club.simulated_today` : l'EntityManager et le RequestStack ne sont jamais touchés
     * pour ces cas, d'où des doubles inertes.
     */
    private function clubDay(MockClock $clock): ClubDay
    {
        $clubClock = new ClubClock(
            $clock,
            new RequestStack(),
            $this->createMock(EntityManagerInterface::class),
            new DevClockStore(new ArrayAdapter()),
            false,
            'test',
        );

        return new ClubDay($clock, $clubClock);
    }
}
