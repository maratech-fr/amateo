<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Controller\ValidateImpactController;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\TenantGucTrait;
use App\Tests\Unit\Controller\ValidateImpactControllerGuardTest;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * BCK-21 — les chemins du contrôleur {@see ValidateImpactController}
 * (GET /api/schedules/{id}/validate-impact). Le prédicat est couvert ailleurs
 * (DeletionImpactParityTest) ; ici on couvre les GARDES du contrôleur :
 *
 *  · LECTURE ouverte au non-gestionnaire — le contrôleur n'a AUCUNE garde management,
 *    donc un `viewer` (rôle non-management) LIT (falsifie l'ajout d'un assertManager) ;
 *  · planning d'un AUTRE club → 404, parce que le scope tenant (RLS + filtre) rend
 *    `find()` null (falsifie le retrait du garde not-found).
 *
 * Le 400 fail-closed (contexte club irrésolu) est INATTEIGNABLE par HTTP — le listener
 * tenant efface le GUC à chaque requête (TenantFilterListener) puis, sans club résolu,
 * RLS masque le planning et le 404 précède le 400. Il est prouvé en unitaire
 * ({@see ValidateImpactControllerGuardTest}).
 */
#[Group('integration')]
final class ValidateImpactApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private SchedulePlanProvisioner $provisioner;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    /** LECTURE ouverte : un `viewer` (rôle non-management) lit l'impact — 200, jamais 403. */
    public function testANonManagerMemberCanReadTheImpact(): void
    {
        [$user, $clubId, $schedule] = $this->seedClubWithSchedule('VIA', 'viewer');

        $this->request($user, $clubId, $schedule->getId());

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(0, $body['orphanedFixtures']);
        self::assertSame(0, $body['declaredOrphanedFixtures']);
    }

    /** Planning d'un AUTRE club → 404 : le scope tenant rend le planning invisible. */
    public function testAScheduleOfAnotherClubIsNotFound(): void
    {
        [, , $scheduleA] = $this->seedClubWithSchedule('VIB', 'admin');
        [$userB, $clubIdB] = $this->seedClubWithSchedule('VIC', 'admin');

        // userB (club B) demande le planning du club A : RLS + filtre → find() null → 404.
        $this->request($userB, $clubIdB, $scheduleA->getId());

        self::assertResponseStatusCodeSame(404);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->provisioner = $container->get(SchedulePlanProvisioner::class);
        $this->hasher = $container->get('security.user_password_hasher');
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    private function request(User $user, string $clubId, string $scheduleId): void
    {
        $this->client->request('GET', "/api/schedules/{$scheduleId}/validate-impact", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $clubId,
        ]);
    }

    /**
     * @return array{0: User, 1: string, 2: Schedule} user, clubId, schedule
     */
    private function seedClubWithSchedule(string $tag, string $role): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . strtolower($tag) . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . strtolower($tag) . '-' . $uid . '@test.com');
        $user->setFirstName('V');
        $user->setLastName('I');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);

        $cu = new ClubUser;
        $cu->setClubId($clubId);
        $cu->setUserId($user->getId());
        $cu->setRole($role);
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($clubId);
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        $this->provisioner->ensureSeasonPlan($season);
        $this->em->flush();

        $schedule = new Schedule;
        $schedule->setClubId($clubId);
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Version');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $schedule->setSchedulePlanId($this->provisioner->ensureSeasonPlanId($season->getId()));
        $this->em->persist($schedule);
        $this->em->flush();
        $this->provisioner->linkSchedule($schedule);
        $this->em->flush();

        // L'EM est partagé avec le contrôleur en WebTestCase : sans ce clear, `find()`
        // rendrait le planning depuis l'identity map SANS repasser par RLS, et le
        // cas cross-club ne prouverait rien. On force la relecture en base.
        $this->em->clear();

        return [$user, $clubId, $schedule];
    }
}
