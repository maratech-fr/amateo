<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * ADR-0002 — le modèle de LECTURE du plan (additif, avant la bascule).
 *
 * `/api/me` expose le plan SEASON — LE calendrier de base : son id, son nom, sa
 * version choisie, et s'il porte une version terminée (futur déblocage cockpit,
 * inv. 8/16). LECTURE SEULE : rien ne bascule, les champs legacy restent exposés
 * et restent la vérité jusqu'au lot de bascule (qui, lui, déplacera les lecteurs,
 * supprimera le legacy et rendra le nom éditable sur le plan).
 */
#[Group('phase1')]
#[Group('integration')]
final class SchedulePlanReadModelTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private SchedulePlanProvisioner $provisioner;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    /** `/api/me` expose le plan SEASON : espace de travail tant que rien n'est choisi. */
    public function testMeExposesTheSeasonPlanAsAWorkspaceUntilAVersionIsChosen(): void
    {
        [$user, , $season] = $this->seed('RDM1');

        $plan = $this->me($user)['seasonPlan'];
        self::assertNotNull($plan, 'la saison porte son plan SEASON dès sa naissance');
        self::assertSame('Planning de la saison 2025-2026', $plan['name']);
        self::assertNull($plan['chosenScheduleId'], 'aucune version choisie = espace de travail');
        self::assertFalse($plan['hasFinishedVersion'], 'aucune version encore');
        // P4-266 : l'empreinte de structure n'est PLUS exposée sur /api/me — elle se lit PAR PLAN
        // via GET /api/schedule_plans/{id}/structure-hash (divergence couverte par
        // SchedulePlanStructureHashTest). /me ne porte donc plus `currentStructureHash`.
        self::assertArrayNotHasKey('currentStructureHash', $plan, 'le hash courant ne doit plus être exposé sur /api/me (P4-266)');

        // Une version terminée débloque le cockpit (inv. 8/16) sans rien pointer.
        $v1 = $this->version($season, ScheduleStatus::COMPLETED);
        $plan = $this->me($user)['seasonPlan'];
        self::assertTrue($plan['hasFinishedVersion']);
        self::assertNull($plan['chosenScheduleId'], 'une génération ne pointe jamais toute seule');

        // Valider pointe — via la VRAIE route, pour que les transitions de statut
        // du cycle de vie s'appliquent réellement (cf. le test suivant).
        $this->validate($user, $v1);
        self::assertSame($v1->getId(), $this->me($user)['seasonPlan']['chosenScheduleId']);
    }

    /**
     * Le déblocage ne doit JAMAIS s'inverser à la validation (inv. 8/16, axe
     * structurant §7.1). Valider POINTE une version et SUPPRIME ses sœurs : si
     * « terminé » se lisait mal, le cockpit se re-verrouillerait pile au moment
     * où le gestionnaire valide. C'est ce qu'un miroir de statut avait déjà
     * failli provoquer ; le pointeur laisse la version choisie en COMPLETED,
     * donc « terminée », et le déblocage tient.
     */
    public function testTheUnlockSurvivesValidation(): void
    {
        [$user, , $season] = $this->seed('RDM2');
        $v1 = $this->version($season, ScheduleStatus::COMPLETED);
        $v2 = $this->version($season, ScheduleStatus::COMPLETED);
        self::assertTrue($this->me($user)['seasonPlan']['hasFinishedVersion']);

        $this->validate($user, $v2);

        $this->em->clear();
        // La sœur non choisie disparaît ; la choisie reste une version terminée.
        self::assertNull($this->em->getRepository(Schedule::class)->find($v1->getId()));
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($v2->getId())?->getStatus());

        $plan = $this->me($user)['seasonPlan'];
        self::assertTrue($plan['hasFinishedVersion'], 'valider ne doit jamais re-verrouiller le cockpit');
        self::assertSame($v2->getId(), $plan['chosenScheduleId']);
    }

    /** Une saison sans plan SEASON (donnée antérieure au lot A) → seasonPlan null, pas d'erreur. */
    public function testSeasonWithoutAPlanExposesNull(): void
    {
        [$user, , $season] = $this->seed('RDM3');
        $this->em->getConnection()->executeStatement(
            'DELETE FROM schedule_plan WHERE season_id = :sid',
            ['sid' => $season->getId()],
        );

        $me = $this->me($user);
        self::assertNull($me['seasonPlan'], 'aucun plan → null, jamais une erreur');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->provisioner = $container->get(SchedulePlanProvisioner::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    private function validate(User $user, Schedule $schedule): void
    {
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/validate", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
        ]);
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function me(User $user): array
    {
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user)]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function version(Season $season, ScheduleStatus $status): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($season->getClubId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Version');
        $schedule->setStatus($status);
        // D : schedule_plan_id est NOT NULL — la version de saison porte son plan SEASON avant tout flush.
        $schedule->setSchedulePlanId($this->provisioner->ensureSeasonPlanId($season->getId()));
        $this->em->persist($schedule);
        $this->em->flush();
        // linkSchedule numérote la version déjà persistée.
        $this->provisioner->linkSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    /** @return array{0: User, 1: Club, 2: Season} */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('R');
        $user->setLastName('M');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        $this->provisioner->ensureSeasonPlan($season);
        $this->em->flush();

        return [$user, $club, $season];
    }
}
