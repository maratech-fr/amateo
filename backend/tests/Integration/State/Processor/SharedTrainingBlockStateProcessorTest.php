<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Processor;

use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\SharedTrainingBlockResource;
use App\Dto\SharedTrainingBlockInput;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\User;
use App\Enum\SchedulePlanType;
use App\Enum\SeasonStatus;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\SoloReservationBudget;
use App\State\Processor\SharedTrainingBlockStateProcessor;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * P2-51 — les 422 métier du processor du bloc de mutualisation (les invariants de FORME — 2..10,
 * doublon DANS le bloc — vivent dans le DTO). On PROUVE ici :
 *  - la garde CENTRALE Σ : pour chaque équipe, Σ des commonSessions de ses blocs (même portée) ≤
 *    ses séances EFFECTIVES — y compris le cas qui piège, un override de période qui RÉDUIT ;
 *  - la multi-appartenance ENTRE blocs est permise tant que Σ tient ;
 *  - un bloc au MÊME ensemble d'équipes dans la même portée est refusé ;
 *  - une équipe inconnue / inactive est refusée.
 * Plus le chemin heureux (le bloc + ses membres sont écrits, teamIds triés en sortie).
 */
#[Group('integration')]
final class SharedTrainingBlockStateProcessorTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private SharedTrainingBlockStateProcessor $processor;

    public function testHappyPathWritesTheBlockAndItsMembers(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 2);
        $t2 = $this->team($club, $season, 2);
        $this->em->flush();

        $result = $this->post($this->input([$t1->getId(), $t2->getId()], 1, null), $club, $season);

        self::assertInstanceOf(SharedTrainingBlockResource::class, $result);
        self::assertSame($this->sorted([$t1->getId(), $t2->getId()]), $result->teamIds);
        self::assertSame(1, $result->commonSessions);
    }

    public function testMultiMembershipIsAllowedWhileTheSumFits(): void
    {
        [$club, $season] = $this->seed();
        $shared = $this->team($club, $season, 2); // 2 séances : deux blocs à 1 tiennent (1+1=2)
        $a = $this->team($club, $season, 2);
        $b = $this->team($club, $season, 2);
        $this->em->flush();

        $this->post($this->input([$shared->getId(), $a->getId()], 1, null), $club, $season);
        // Le même $shared dans un SECOND bloc socle : permis (unicité levée), Σ = 1+1 = 2 ≤ 2.
        $second = $this->post($this->input([$shared->getId(), $b->getId()], 1, null), $club, $season);
        self::assertSame(1, $second->commonSessions);
    }

    public function testTheSumOfCommonSessionsAcrossABlocksTeamIsCapped(): void
    {
        [$club, $season] = $this->seed();
        $shared = $this->team($club, $season, 2); // 2 séances
        $a = $this->team($club, $season, 2);
        $b = $this->team($club, $season, 2);
        $this->em->flush();

        // Premier bloc {shared, a} à 2 séances : Σ(shared) = 2, tient (2 ≤ 2).
        $this->post($this->input([$shared->getId(), $a->getId()], 2, null), $club, $season);

        // Second bloc {shared, b} à 1 séance : Σ(shared) = 2 + 1 = 3 > 2 → refusé.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Le total des séances communes des blocs d\'une équipe (3) dépasse son nombre de séances hebdomadaires (2).');
        $this->post($this->input([$shared->getId(), $b->getId()], 1, null), $club, $season);
    }

    public function testAPeriodOverrideThatReducesSessionsTightensTheGuard(): void
    {
        [$club, $season] = $this->seed();
        // L'équipe a 3 séances de BASE, mais un override de PÉRIODE la ramène à 1.
        $shared = $this->team($club, $season, 3);
        $a = $this->team($club, $season, 3);
        $plan = $this->periodPlan($club, $season);
        $this->overrideSessions($club, $season, $plan, $shared, 1);
        $this->em->flush();

        // Un bloc de PÉRIODE {shared, a} à 1 séance : Σ(shared) = 1 ≤ 1 effectif → tient.
        $this->post($this->input([$shared->getId(), $a->getId()], 1, $plan->getId()), $club, $season);

        // Un SECOND bloc de période à 1 : Σ(shared) = 1 + 1 = 2 > 1 (l'override, pas la base 3)
        // → refusé. C'est le cas qui piège : sans EffectiveTeamSessions, la base 3 laisserait passer.
        $b = $this->team($club, $season, 3);
        $this->em->flush();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Le total des séances communes des blocs d\'une équipe (2) dépasse son nombre de séances hebdomadaires (1).');
        $this->post($this->input([$shared->getId(), $b->getId()], 1, $plan->getId()), $club, $season);
    }

    public function testTheSameTeamSetInTheSameScopeIsRejected(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 3);
        $t2 = $this->team($club, $season, 3);
        $this->em->flush();

        $this->post($this->input([$t1->getId(), $t2->getId()], 1, null), $club, $season);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Un bloc de mutualisation portant exactement ces équipes existe déjà pour cette portée.');
        $this->post($this->input([$t2->getId(), $t1->getId()], 1, null), $club, $season);
    }

    public function testAnUnknownTeamIsRejected(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 2);
        $this->em->flush();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Une équipe du bloc est inconnue de cette saison.');
        $this->post($this->input([$t1->getId(), $this->uuid()], 1, null), $club, $season);
    }

    public function testAnInactiveTeamIsRejected(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 2);
        $t2 = $this->team($club, $season, 2);
        $t2->setIsActive(false);
        $this->em->flush();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Une équipe du bloc est inactive et ne peut pas être mutualisée.');
        $this->post($this->input([$t1->getId(), $t2->getId()], 1, null), $club, $season);
    }

    // ── P2-60 : porte 2 — un bloc ne fait pas déborder des réservations INDIVIDUELLES existantes ──

    public function testACreationThatWouldOverflowExistingIndividualReservationsIsRefused(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 1); // 1 séance
        $t2 = $this->team($club, $season, 1);
        $this->em->flush();

        // t1 a DÉJÀ un créneau individuel (seule sur sa case → non bloc-complète).
        $this->reservation($club, $season, $t1, 2, '18:00', null);
        $this->em->flush();

        // Déclarer {t1,t2}@1 : B(t1)=1 → R(t1)=0, mais individualUsed(t1)=1 > 0 → refusé, t1 nommée.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cette mutualisation ferait passer des créneaux individuels existants au-dessus du résidu autorisé pour');
        $this->post($this->input([$t1->getId(), $t2->getId()], 1, null), $club, $season);
    }

    public function testAModificationThatWouldOverflowIsRefused(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 2); // 2 séances
        $t2 = $this->team($club, $season, 2);
        $this->em->flush();

        // Bloc {t1,t2}@1 : R(t1)=1. t1 pose son unique créneau individuel (autorisé).
        $block = $this->post($this->input([$t1->getId(), $t2->getId()], 1, null), $club, $season);
        $this->reservation($club, $season, $t1, 2, '18:00', null);
        $this->em->flush();

        // PUT le bloc à commonSessions=2 : B(t1)=2 → R(t1)=0, individualUsed(t1)=1 > 0 → refusé.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cette mutualisation ferait passer des créneaux individuels existants au-dessus du résidu autorisé pour');
        $this->put($block->id, $this->input([$t1->getId(), $t2->getId()], 2, null), $club, $season);
    }

    public function testDeletingABlockIsNeverBlockedByTheSoloBudget(): void
    {
        [$club, $season] = $this->seed();
        $t1 = $this->team($club, $season, 1);
        $t2 = $this->team($club, $season, 1);
        $this->em->flush();

        $block = $this->post($this->input([$t1->getId(), $t2->getId()], 1, null), $club, $season);
        // État déjà incohérent en base (créneau individuel de t1 au-delà de R=0, P2-61) : la
        // suppression LIBÈRE du résidu, elle n'est JAMAIS bloquée par la garde solo.
        $this->reservation($club, $season, $t1, 2, '18:00', null);
        $this->em->flush();

        $this->delete($block->id, $club);
        self::assertNull($this->em->getRepository(SharedTrainingBlock::class)->find($block->id), 'le bloc est supprimé sans que la garde solo s\'y oppose');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->processor = new SharedTrainingBlockStateProcessor(
            $this->em,
            $container->get(RequestStack::class),
            $container->get(SeasonResolver::class),
            $container->get(SeasonAccessGuard::class),
            $container->get(ManagementAccessGuard::class),
        );
        // Dépendance #[Required] : câblée à la main puisque le processor est instancié hors conteneur.
        $this->processor->setSoloReservationBudget($container->get(SoloReservationBudget::class));
    }

    private function post(SharedTrainingBlockInput $input, Club $club, Season $season): SharedTrainingBlockResource
    {
        $method = new ReflectionMethod($this->processor, 'processPost');
        $result = $method->invoke($this->processor, $input, $club->getId(), $season->getId());
        self::assertInstanceOf(SharedTrainingBlockResource::class, $result);

        return $result;
    }

    private function put(string $blockId, SharedTrainingBlockInput $input, Club $club, Season $season): SharedTrainingBlockResource
    {
        $method = new ReflectionMethod($this->processor, 'processPut');
        $result = $method->invoke($this->processor, $input, ['id' => $blockId], $club->getId(), $season->getId());
        self::assertInstanceOf(SharedTrainingBlockResource::class, $result);

        return $result;
    }

    private function delete(string $blockId, Club $club): void
    {
        $method = new ReflectionMethod($this->processor, 'processDelete');
        $method->invoke($this->processor, ['id' => $blockId], $club->getId());
    }

    private function reservation(Club $club, Season $season, Team $team, int $dayOfWeek, string $startTime, ?string $planId): void
    {
        $reservation = (new Reservation)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setTeamId($team->getId())
            ->setVenueId($this->uuid())
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new DateTimeImmutable($startTime))
            ->setDurationMinutes(90);
        $this->em->persist($reservation);
    }

    /**
     * @param list<string> $teamIds
     */
    private function input(array $teamIds, int $commonSessions, ?string $planId): SharedTrainingBlockInput
    {
        $input = new SharedTrainingBlockInput;
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

    private function periodPlan(Club $club, Season $season): SchedulePlan
    {
        $plan = (new SchedulePlan)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setType(SchedulePlanType::HOLIDAY)
            ->setName('Vacances')
            ->setStartDate(new DateTimeImmutable('2025-10-20'))
            ->setEndDate(new DateTimeImmutable('2025-10-26'));
        $this->em->persist($plan);

        return $plan;
    }

    private function overrideSessions(Club $club, Season $season, SchedulePlan $plan, Team $team, int $sessions): void
    {
        $override = (new TeamPeriodOverride)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($plan->getId())
            ->setTeamId($team->getId())
            ->setIsActive(true)
            ->setSessionsPerWeek($sessions);
        $this->em->persist($override);
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

        $club = (new Club)->setName('STB Club')->setSlug('stb-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true)->setFfbbClubCode('STB' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = (new User)->setEmail('stb-' . $uid . '@test.com')->setFirstName('S')->setLastName('B');
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
