<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Clock\ClubClock;
use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * P4-16 / P2-4 — l'horloge d'un club sait vivre à une date simulée, et SEULEMENT
 * pour le club qui en a une.
 *
 * `now()` (chemin tenant, memoïsé) :
 *  1. club à `simulated_today` posé + requête tenant-résolue → now() rend CETTE
 *     date (l'heure du jour reste réelle : durées et TTL gardent un sens) ;
 *  2. club SANS horloge → horloge réelle (le cas de tous les vrais clubs — zéro
 *     changement de comportement) ;
 *  3. hors requête (workers, crons, commandes) → horloge réelle, même si des
 *     clubs à horloge simulée existent : le contexte tenant est la SEULE clé.
 *
 * `simulatedTodayFor(Club)` (point d'entrée unique, lit l'ENTITÉ) :
 *  4. club à horloge posée → rend la date, hors de tout contexte de requête ;
 *  5. club sans horloge → null.
 *
 * Le service `now()` est obtenu par le CONTENEUR (ClockInterface), pas construit
 * à la main : c'est la chaîne réelle de décoration qui est testée — en env test,
 * SimulatedClock (non épinglé) délègue à `clock`, que ClubClock décore.
 */
#[Group('integration')]
final class ClubClockTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ClockInterface $clock;

    private ClubClock $clubClock;

    private RequestStack $requestStack;

    public function testAClubLivesOnItsSimulatedDate(): void
    {
        $clubId = $this->club(simulatedToday: new DateTimeImmutable('2026-12-15'));
        $this->pushTenantRequest($clubId);

        $now = $this->clock->now();

        self::assertSame('2026-12-15', $now->format('Y-m-d'), 'la DATE est celle du club à horloge simulée');
        // L'HEURE reste réelle : à minuit près (le test peut franchir une seconde),
        // l'écart avec l'horloge machine doit être négligeable.
        self::assertLessThan(5, abs($now->getTimestamp() - new DateTimeImmutable('2026-12-15 ' . new DateTimeImmutable()->format('H:i:s'))->getTimestamp()), 'seule la date est simulée, pas l\'heure du jour');
    }

    public function testAClubWithoutAClockStaysOnTheRealClock(): void
    {
        $clubId = $this->club(simulatedToday: null);
        $this->pushTenantRequest($clubId);

        self::assertSame(new DateTimeImmutable()->format('Y-m-d'), $this->clock->now()->format('Y-m-d'), 'un club sans horloge (simulated_today NULL) vit à la date réelle');
    }

    public function testOutsideARequestTheClockIsReal(): void
    {
        // Un club à horloge simulée EXISTE, mais aucun contexte de requête : workers,
        // crons et commandes doivent vivre à l'heure réelle — le contexte tenant est la clé.
        $this->club(simulatedToday: new DateTimeImmutable('2026-12-15'));

        self::assertSame(new DateTimeImmutable()->format('Y-m-d'), $this->clock->now()->format('Y-m-d'));
    }

    public function testSimulatedTodayForReadsTheEntityOutsideAnyRequest(): void
    {
        // Point d'entrée unique : pas de requête poussée, on interroge l'entité.
        $club = (new Club)->setName('Horloge')->setSlug('horloge-' . bin2hex(random_bytes(4)))->setTimezone('Europe/Paris')->setLocale('fr');
        // SEC-30 — la date simulée n'est honorée que pour un club démo.
        $club->setIsDemo(true);
        $club->setSimulatedToday(new DateTimeImmutable('2027-03-01'));

        self::assertSame('2027-03-01', $this->clubClock->simulatedTodayFor($club)?->format('Y-m-d'));
    }

    public function testSimulatedTodayForIgnoresAPinOnANonDemoClub(): void
    {
        // SEC-30 — ceinture côté LECTURE : un pin posé sur un club NON démo (cas d'un
        // objet non persisté — en base la contrainte CHECK l'interdit déjà) n'est JAMAIS
        // honoré. Falsification : retirer `&& $club->isDemo()` de ClubClock fait rendre
        // la date ici → rouge.
        $club = (new Club)->setName('Réel pin')->setSlug('reel-pin-' . bin2hex(random_bytes(4)))->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setIsDemo(false);
        $club->setSimulatedToday(new DateTimeImmutable('2027-03-01'));

        self::assertNull($this->clubClock->simulatedTodayFor($club), 'un pin sur un club non démo est ignoré (SEC-30)');
    }

    public function testSimulatedTodayForIsNullForAClubWithoutAClock(): void
    {
        $club = (new Club)->setName('Réel')->setSlug('reel-' . bin2hex(random_bytes(4)))->setTimezone('Europe/Paris')->setLocale('fr');

        self::assertNull($this->clubClock->simulatedTodayFor($club), 'un club sans horloge n\'en a pas');
    }

    public function testDevFlagOffIgnoresThePinEvenWhenOneIsSet(): void
    {
        $clock = $this->clubClockWith(clockAllEnabled: false, environment: 'dev', devPin: new DateTimeImmutable('2026-07-01 10:00'));

        self::assertNull($clock->simulatedTodayFor($this->clocklessClub()), 'drapeau OFF : le pin DevClock n\'ouvre rien');
    }

    public function testDevFlagOnBorrowsTheGlobalPinForAClubWithoutAClock(): void
    {
        $clock = $this->clubClockWith(clockAllEnabled: true, environment: 'dev', devPin: new DateTimeImmutable('2026-07-01 10:00'));

        self::assertSame('2026-07-01', $clock->simulatedTodayFor($this->clocklessClub())?->format('Y-m-d'), 'drapeau ON + pin : la DATE du pin');
    }

    public function testDevFlagOnWithoutAPinIsStillRealTime(): void
    {
        $clock = $this->clubClockWith(clockAllEnabled: true, environment: 'dev', devPin: null);

        self::assertNull($clock->simulatedTodayFor($this->clocklessClub()), 'drapeau ON mais aucun pin : heure réelle');
    }

    public function testTheClubOwnSimulatedTodayPrimesOverTheDevFlag(): void
    {
        $clock = $this->clubClockWith(clockAllEnabled: true, environment: 'dev', devPin: new DateTimeImmutable('2026-07-01 10:00'));
        $club = $this->clocklessClub();
        // SEC-30 — la date simulée n'est honorée que pour un club démo.
        $club->setIsDemo(true);
        $club->setSimulatedToday(new DateTimeImmutable('2027-03-01'));

        self::assertSame('2027-03-01', $clock->simulatedTodayFor($club)?->format('Y-m-d'), 'la date du club prime sur le pin global');
    }

    public function testTheDevFlagIsNeutralisedInProd(): void
    {
        $clock = $this->clubClockWith(clockAllEnabled: true, environment: 'prod', devPin: new DateTimeImmutable('2026-07-01 10:00'));

        self::assertNull($clock->simulatedTodayFor($this->clocklessClub()), 'garde de sûreté : jamais actif en prod, même drapeau posé');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->clock = self::getContainer()->get(ClockInterface::class);
        $this->clubClock = self::getContainer()->get(ClubClock::class);
        $this->requestStack = self::getContainer()->get(RequestStack::class);
    }

    protected function tearDown(): void
    {
        // Ne jamais laisser une requête poussée fuir vers le test suivant.
        while ($this->requestStack->getCurrentRequest() instanceof Request) {
            $this->requestStack->pop();
        }
        parent::tearDown();
    }

    private function clocklessClub(): Club
    {
        return (new Club)->setName('Sans horloge')->setSlug('sans-' . bin2hex(random_bytes(4)))->setTimezone('Europe/Paris')->setLocale('fr');
    }

    private function clubClockWith(bool $clockAllEnabled, string $environment, ?DateTimeImmutable $devPin): ClubClock
    {
        $store = new DevClockStore(new ArrayAdapter);
        $store->set($devPin);

        return new ClubClock(new MockClock, new RequestStack, $this->em, $store, $clockAllEnabled, $environment);
    }

    private function club(?DateTimeImmutable $simulatedToday): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = (new Club)->setName('Demo ' . $suffix)->setSlug('demo-clock-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setIsDemo($simulatedToday instanceof DateTimeImmutable);
        $club->setSimulatedToday($simulatedToday);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        return $club->getId();
    }

    private function pushTenantRequest(string $clubId): void
    {
        $request = new Request;
        // Le MÊME attribut que TenantFilterListener pose après le firewall — la
        // décoration ne lit que lui, jamais un header (spoofable).
        $request->attributes->set('_club_id', $clubId);
        $this->requestStack->push($request);
    }
}
