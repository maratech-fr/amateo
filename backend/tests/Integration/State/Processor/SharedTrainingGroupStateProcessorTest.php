<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\SharedTrainingGroupResource;
use App\Dto\SharedTrainingGroupInput;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\EffectiveTeamSessions;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\State\Processor\SharedTrainingGroupStateProcessor;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * P2-27 — les 422 métier du processor de mutualisation (les invariants de FORME — 2..10, doublon
 * dans le groupe — vivent dans le DTO). On PROUVE ici les trois que le processor porte :
 *  - K > le plus petit sessionsPerWeek effectif des membres ;
 *  - une équipe déjà dans un AUTRE groupe DU MÊME PLAN ;
 *  - une équipe inconnue du club+saison.
 * Plus le chemin heureux (le groupe + ses membres sont écrits, teamIds triés en sortie).
 */
#[Group('integration')]
final class SharedTrainingGroupStateProcessorTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private SharedTrainingGroupStateProcessor $processor;

    public function testHappyPathWritesTheGroupAndItsMembers(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 2);
        $t2 = $this->team($club, $season, 2);
        $this->em->flush();

        $result = $this->post($this->input([$t1->getId(), $t2->getId()], 1, null), $club, $season);

        self::assertInstanceOf(SharedTrainingGroupResource::class, $result);
        self::assertSame($this->sorted([$t1->getId(), $t2->getId()]), $result->teamIds);
        self::assertSame(1, $result->commonSessions);
    }

    public function testCommonSessionsAboveTheSmallestSessionsPerWeekIsRejected(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 3);
        $t2 = $this->team($club, $season, 2); // le plus petit effectif = 2
        $this->em->flush();

        $this->expectException(ValidationException::class);
        $this->post($this->input([$t1->getId(), $t2->getId()], 3, null), $club, $season);
    }

    public function testATeamAlreadyInAnotherGroupOfTheSamePlanIsRejected(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 2);
        $t2 = $this->team($club, $season, 2);
        $t3 = $this->team($club, $season, 2);
        $this->em->flush();

        // Premier groupe socle {t1, t2} : accepté.
        $this->post($this->input([$t1->getId(), $t2->getId()], 1, null), $club, $season);

        // Second groupe socle réutilisant t2 : refusé (même plan = socle NULL).
        $this->expectException(ValidationException::class);
        $this->post($this->input([$t2->getId(), $t3->getId()], 1, null), $club, $season);
    }

    public function testAnUnknownTeamIsRejected(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 2);
        $this->em->flush();

        $this->expectException(ValidationException::class);
        $this->post($this->input([$t1->getId(), $this->uuid()], 1, null), $club, $season);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->processor = new SharedTrainingGroupStateProcessor(
            $this->em,
            $container->get(RequestStack::class),
            $container->get(SeasonResolver::class),
            $container->get(SeasonAccessGuard::class),
            $container->get(ManagementAccessGuard::class),
        );
        // Dépendance #[Required] : câblée à la main puisque le processor est instancié hors conteneur.
        $this->processor->setEffectiveTeamSessions($container->get(EffectiveTeamSessions::class));
    }

    private function post(SharedTrainingGroupInput $input, Club $club, Season $season): SharedTrainingGroupResource
    {
        $method = new ReflectionMethod($this->processor, 'processPost');
        $result = $method->invoke($this->processor, $input, $club->getId(), $season->getId());
        self::assertInstanceOf(SharedTrainingGroupResource::class, $result);

        return $result;
    }

    /**
     * @param list<string> $teamIds
     */
    private function input(array $teamIds, int $commonSessions, ?string $planId): SharedTrainingGroupInput
    {
        $input = new SharedTrainingGroupInput;
        $input->teamIds = $teamIds;
        $input->commonSessions = $commonSessions;
        $input->schedulePlanId = $planId;

        return $input;
    }

    private function team(Club $club, Season $season, int $sessionsPerWeek): Team
    {
        $team = (new Team)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSportCategoryId($this->uuid())
            ->setPriorityTierId(3)
            ->setName('T' . substr($this->uuid(), 0, 6))
            ->setSessionsPerWeek($sessionsPerWeek)
            ->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = (new Club)->setName('STG Club')->setSlug('stg-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true)->setFfbbClubCode('STG' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = (new User)->setEmail('stg-' . $uid . '@test.com')->setFirstName('S')->setLastName('T');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = (new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true);
        $this->em->persist($cu);

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }
}
