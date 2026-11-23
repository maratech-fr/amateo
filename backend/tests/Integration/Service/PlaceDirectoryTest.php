<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Place;
use App\Entity\Venue;
use App\Structure\PlaceDirectory;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\Security\PersonPlaceTenantIsolationTest;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La porte de lecture de l'adresse ne rend jamais un lieu qui n'est pas celui du club du gymnase
 * (lot conception 3, PR 4) : un lieu d'un autre club lève une `LogicException`, qu'il soit relu de la
 * base ou porté en mémoire par un gymnase tout juste construit ; sans EntityManager, un gymnase relu
 * de la base lève aussi, jamais une adresse inventée.
 *
 * Second angle, nommé, de l'axe *tenant isolation* : la base garde déjà la frontière (RLS de `place`
 * et clé composée `(place_id, club_id)`, {@see PersonPlaceTenantIsolationTest}) ; ici, la vérification
 * applicative de `PlaceDirectory::of`, que la clé composée rend inatteignable par une écriture. Jumeau
 * sur la fiche de personne : {@see PersonDirectoryTest}.
 */
#[Group('integration')]
final class PlaceDirectoryTest extends KernelTestCase
{
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testWithoutEntityManagerAVenueReadFromTheDatabaseIsRefusedNeverInvented(): void
    {
        $venue = $this->reloadedVenue();

        self::assertSame('1 place du Marché', self::getContainer()->get(PlaceDirectory::class)->of($venue)->getAddress(), 'témoin : la porte du conteneur lit le lieu');
        $this->expectException(LogicException::class);
        new PlaceDirectory()->of($venue);
    }

    public function testAPlaceThatIsNotOfTheVenueClubIsNeverReturned(): void
    {
        $venue = $this->reloadedVenue();
        $venue->setClubId('99999999-9999-4999-8999-999999999999');

        $this->expectException(LogicException::class);
        self::getContainer()->get(PlaceDirectory::class)->of($venue);
    }

    /**
     * Un lieu EN MÉMOIRE (`new Venue($place)`, lu sans requête) d'un autre club que le gymnase n'est
     * jamais rendu non plus. Jumeau du cas relu de la base, {@see testAPlaceThatIsNotOfTheVenueClubIsNeverReturned} :
     * la condition de club est la même, mais le lieu arrive par l'autre branche de `of`.
     */
    public function testAPlaceGivenInMemoryThatIsNotOfTheVenueClubIsNeverReturned(): void
    {
        $place = (new Place)->setClubId('11111111-1111-4111-8111-111111111111')->setAddress('1 place du Marché');

        self::assertSame('1 place du Marché', new PlaceDirectory()->of(new Venue($place)->setClubId('11111111-1111-4111-8111-111111111111'))->getAddress(), 'témoin : même club, le lieu en mémoire se lit sans base');
        $this->expectException(LogicException::class);
        new PlaceDirectory()->of(new Venue($place)->setClubId('99999999-9999-4999-8999-999999999999'));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** Un gymnase écrit en base puis relu par Doctrine : il ne porte plus son lieu en mémoire. */
    private function reloadedVenue(): Venue
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Porte ' . $uid)->setSlug('porte-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());
        // `venue.season_id` est un guid nu (sans clé étrangère).
        $venue = $this->newVenue($club->getId(), '33333333-3333-4333-8333-333333333333', 'Gymnase Nord');
        $venue->placeGivenAtCreation()?->setAddress('1 place du Marché');
        $this->em->flush();
        $venueId = $venue->getId();
        $this->em->clear();

        $venue = $this->em->find(Venue::class, $venueId);
        self::assertInstanceOf(Venue::class, $venue);
        self::assertNull($venue->placeGivenAtCreation(), 'le gymnase relu ne porte plus son lieu en mémoire');

        return $venue;
    }
}
