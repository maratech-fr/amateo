<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ResetClubSeasonCommand;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Person;
use App\Entity\Season;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La remise à zéro de support (`app:clubs:reset-season`) balaie les fiches de personne que plus aucun
 * rôle ne désigne, comme la remise à zéro de l'écran (lot conception 3, PR 2b, décision 1 A) ; la fiche
 * d'une personne qui entraîne encore dans une autre saison reste.
 *
 * Jumeaux cités : `supprimer-emporte-ce-qui-en-depend.feature` (« le club n'a plus aucune fiche de
 * personne », le geste de l'écran, `ResetSeasonController`) et
 * {@see PurgeSeasonsCommandTest::testThePersonRecordOfACoachOnlyInThePurgedSeasonGoesAndASharedOneStays}
 * (la purge de rétention). Ce test garde le troisième appelant du balayage, sans autre test.
 */
#[Group('integration')]
final class ResetClubSeasonCommandTest extends KernelTestCase
{
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testTheSupportResetSweepsRecordsLeftWithoutRoleAndKeepsOneStillCoaching(): void
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Remise ' . $uid)->setSlug('remise-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $previous = $this->season($club, $year - 1);
        $current = $this->season($club, $year);
        $this->em->flush();

        $onlyNow = $this->newCoach($club->getId(), $current->getId(), 'Seulement', 'Maintenant');
        $before = $this->newCoach($club->getId(), $previous->getId(), 'Toujours', 'Là');
        $roleless = (new Person)->setClubId($club->getId())->setFirstName('Sans')->setLastName('Rôle');
        $this->em->persist($roleless);
        $this->em->flush();
        $this->em->persist(new Coach($this->personOf($before))->setClubId($club->getId())->setSeasonId($current->getId()));
        $this->em->flush();
        [$onlyNowPerson, $beforePerson, $rolelessId, $beforeId] = [$onlyNow->getPersonId(), $before->getPersonId(), $roleless->getId(), $before->getId()];

        $tester = new CommandTester(self::getContainer()->get(ResetClubSeasonCommand::class));
        $tester->execute(['--club' => $club->getId()]);
        $tester->assertCommandIsSuccessful();
        $this->scopeGucToClub($club->getId());

        self::assertNull($this->em->getRepository(Person::class)->find($onlyNowPerson), 'la fiche du coach de la seule saison remise à zéro part');
        self::assertNull($this->em->getRepository(Person::class)->find($rolelessId), 'une fiche déjà sans rôle part');
        self::assertNotNull($this->em->getRepository(Person::class)->find($beforePerson), 'la fiche d\'une personne qui entraîne dans une autre saison reste');
        self::assertSame($beforePerson, $this->em->getRepository(Coach::class)->find($beforeId)?->getPersonId(), 'son rôle de l\'autre saison la pointe toujours');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function season(Club $club, int $startYear): Season
    {
        $season = (new Season)->setClubId($club->getId())->setName((string) $startYear)
            ->setStartDate(new DateTimeImmutable($startYear . '-08-01'))->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($season);

        return $season;
    }
}
