<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Coach;
use App\Structure\PersonDirectory;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\Security\PersonPlaceTenantIsolationTest;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La porte de lecture de l'identité ne rend jamais une identité qu'elle n'a pas lue sur la fiche du
 * club du coach (lot conception 3, PR 2b) : sans EntityManager (défaut `new PersonDirectory` de cinq
 * classes, tests unitaires), un coach relu de la base lève une `LogicException` ; une fiche qui n'est
 * pas celle du club du coach lève aussi, jamais rendue.
 *
 * Second angle, nommé, de l'axe *tenant isolation* : la base garde déjà la frontière (RLS de `person`
 * et clé composée, {@see PersonPlaceTenantIsolationTest}) ; ici, la vérification
 * applicative de `PersonDirectory::personOf`, que la clé composée rend inatteignable par une écriture.
 */
#[Group('integration')]
final class PersonDirectoryTest extends KernelTestCase
{
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testWithoutEntityManagerACoachReadFromTheDatabaseIsRefusedNeverInvented(): void
    {
        $coach = $this->reloadedCoach();

        self::assertSame('Paul Martin', self::getContainer()->get(PersonDirectory::class)->of($coach)->fullName(), 'témoin : la porte du conteneur lit la fiche');
        $this->expectException(LogicException::class);
        new PersonDirectory()->of($coach);
    }

    public function testARecordThatIsNotOfTheCoachClubIsNeverReturned(): void
    {
        $coach = $this->reloadedCoach();
        $coach->setClubId('99999999-9999-4999-8999-999999999999');

        $this->expectException(LogicException::class);
        self::getContainer()->get(PersonDirectory::class)->of($coach);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** Un coach écrit en base puis relu par Doctrine : il ne porte plus sa fiche en mémoire. */
    private function reloadedCoach(): Coach
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Porte ' . $uid)->setSlug('porte-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());
        // `coach.season_id` est un guid nu (sans clé étrangère).
        $coachId = $this->newCoach($club->getId(), '33333333-3333-4333-8333-333333333333', 'Paul', 'Martin')->getId();
        $this->em->flush();
        $this->em->clear();

        $coach = $this->em->find(Coach::class, $coachId);
        self::assertInstanceOf(Coach::class, $coach);
        self::assertNull($coach->personGivenAtCreation(), 'le coach relu ne porte plus sa fiche en mémoire');

        return $coach;
    }
}
