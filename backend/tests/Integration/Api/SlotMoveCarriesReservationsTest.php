<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — P4-44 : DÉPLACER un créneau déplace ses réservations (fondateur 2026-08-07).
 *
 * Une `Reservation` désigne son créneau par le TRIPLET (gymnase, jour, heure), jamais
 * par son id. Corriger un horaire à l'étape Gymnases laissait donc la réservation sur
 * l'ancien triplet — un horaire qui n'existe plus.
 *
 * ⚠ CE QUE CE CAS ÉVITE, et pourquoi il compte plus qu'un INFEASIBLE : sur le SOCLE,
 * le moteur ne se plaint PAS d'un épinglage hors grille — il le place et rend
 * `completed` (mesuré le 2026-08-07 : grille ouverte à 18h30, verrou à 18h00 → séance
 * émise à 18h00, seul diagnostic `unused_slot`). Le gestionnaire distribuait donc un
 * planning envoyant ses équipes devant une porte fermée, sans une alerte.
 */
#[Group('integration')]
final class SlotMoveCarriesReservationsTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private User $user;

    public function testMovingASlotCarriesItsReservation(): void
    {
        [$slotId, $reservationId] = $this->slotWithReservation(dayOfWeek: 2, start: '18:00');

        $this->putSlot($slotId, dayOfWeek: 2, start: '18:30');

        $this->em->clear();
        $reservation = $this->em->getRepository(Reservation::class)->find($reservationId);
        self::assertInstanceOf(Reservation::class, $reservation, 'la réservation SURVIT : le geste est « je corrige l\'horaire », pas « annule ma réservation »');
        self::assertSame('18:30', $reservation->getStartTime()->format('H:i'), 'et elle SUIT le créneau — sinon elle pointe un horaire mort que le moteur place quand même');
        self::assertSame(2, $reservation->getDayOfWeek());
    }

    public function testMovingASlotToAnotherDayCarriesItToo(): void
    {
        [$slotId, $reservationId] = $this->slotWithReservation(dayOfWeek: 2, start: '18:00');

        $this->putSlot($slotId, dayOfWeek: 4, start: '18:00');

        $this->em->clear();
        self::assertSame(4, $this->em->getRepository(Reservation::class)->find($reservationId)?->getDayOfWeek());
    }

    public function testEditingOnlyTheCapacityLeavesTheReservationAlone(): void
    {
        // TÉMOIN : le triplet ne bouge pas → aucune écriture sur les réservations.
        // Sans lui, « la réservation est au bon horaire » passerait aussi si le code
        // réécrivait tout à chaque PUT (et écraserait des données au passage).
        [$slotId, $reservationId] = $this->slotWithReservation(dayOfWeek: 2, start: '18:00');
        $before = $this->em->getRepository(Reservation::class)->find($reservationId);
        self::assertInstanceOf(Reservation::class, $before);
        $versionBefore = $before->getVersion();

        // On modifie la DURÉE : elle ne fait pas partie du triplet (gymnase, jour,
        // heure) qui identifie le créneau — la réservation ne doit donc pas bouger.
        $this->putSlot($slotId, dayOfWeek: 2, start: '18:00', durationMinutes: 120);

        $this->em->clear();
        $after = $this->em->getRepository(Reservation::class)->find($reservationId);
        self::assertInstanceOf(Reservation::class, $after);
        self::assertSame('18:00', $after->getStartTime()->format('H:i'));
        self::assertSame($versionBefore, $after->getVersion(), 'triplet inchangé = la ligne n\'est pas touchée du tout');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Slot move ' . $uid)->setSlug('slotmove-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->user = (new User)->setEmail('sm' . $uid . '@test.com')->setFirstName('S')->setLastName('M');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($this->user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();
    }

    /** @return array{0: string, 1: string} [slotId, reservationId] */
    private function slotWithReservation(int $dayOfWeek, string $start): array
    {
        $venue = (new Venue)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Matéo')->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        $slot = (new VenueTrainingSlot)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueId($venue->getId())->setDayOfWeek($dayOfWeek)
            ->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)->setCapacity(1);
        $this->em->persist($slot);

        $reservation = (new Reservation)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId('11111111-1111-4111-8111-111111111111')->setVenueId($venue->getId())
            ->setDayOfWeek($dayOfWeek)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90);
        $this->em->persist($reservation);
        $this->em->flush();

        return [$slot->getId(), $reservation->getId()];
    }

    private function putSlot(string $slotId, int $dayOfWeek, string $start, int $durationMinutes = 90): void
    {
        $this->client->loginUser($this->user);
        $slot = $this->em->getRepository(VenueTrainingSlot::class)->find($slotId);
        self::assertInstanceOf(VenueTrainingSlot::class, $slot);

        $this->client->request('PUT', '/api/venue_training_slots/' . $slotId, [], [], [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'venueId' => $slot->getVenueId(),
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $start,
            'durationMinutes' => $durationMinutes,
            'capacity' => 1,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
    }
}
