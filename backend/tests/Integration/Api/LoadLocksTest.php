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
use App\Service\ClubGenerationLock;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * V1bis (planning lifecycle, §7.1) — POST /api/schedules/{id}/load-locks (« Charger » pour une
 * PÉRIODE) pose le POINTEUR `schedule_plan.lock_source_schedule_id` sur la version nommée : ses
 * verrous deviennent les données « en cours » du plan, d'où la prochaine « Régénérer » repart. Il
 * NE génère PAS. Ce test épingle les gardes (manager, tenant, période, terminée, plan non validé,
 * génération en cours), l'effet (le pointeur pris en compte par la maison unique
 * {@see SchedulePlanProvisioner::lockSourceScheduleIdOfPlan}), le re-pointage d'une nouvelle
 * COMPLETED, et la parité `capabilities.canLoadLocks` ⇄ le verdict.
 */
#[Group('phase1')]
final class LoadLocksTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    private string $coachToken;

    public function testManagerLoadsLocksAndThePointerBecomesTheSource(): void
    {
        [$planId, $v1, $v2] = $this->periodPlanWithTwoVersions();

        // Sans pointeur, la source est la DERNIÈRE COMPLETED (v2).
        $this->scopeGucToClub($this->club->getId());
        self::assertSame($v2->getId(), $this->provisioner()->lockSourceScheduleIdOfPlan($planId), 'source par défaut = dernière COMPLETED');

        // « Charger » v1 : le pointeur la désigne, elle devient la source.
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/load-locks", [], [], $this->headers());
        self::assertResponseStatusCodeSame(200);
        self::assertSame($v1->getId(), $this->decode()['id']);

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertSame($v1->getId(), $this->provisioner()->lockSourceScheduleIdOfPlan($planId), 'après Charger, la maison renvoie la version chargée');
    }

    public function testANewCompletedVersionRepointsTheSource(): void
    {
        [$planId, $v1] = $this->periodPlanWithTwoVersions();

        // On charge v1 (pointeur = v1)…
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/load-locks", [], [], $this->headers());
        self::assertResponseStatusCodeSame(200);

        // …puis une nouvelle version COMPLETED naît (ce que fait la complétion d'une génération) :
        // elle DEVIENT la source — exactement ce que le handler appelle via pointLockSource.
        $this->scopeGucToClub($this->club->getId());
        $v3 = $this->periodVersion($planId, ScheduleStatus::COMPLETED);
        $this->em->flush();
        $this->provisioner()->pointLockSource($planId, $v3->getId());

        $this->em->clear();
        $this->scopeGucToClub($this->club->getId());
        self::assertSame($v3->getId(), $this->provisioner()->lockSourceScheduleIdOfPlan($planId), 'une nouvelle COMPLETED repointe la source');
    }

    public function testNonManagerIsForbidden(): void
    {
        [, $v1] = $this->periodPlanWithTwoVersions();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/load-locks", [], [], $this->coachHeaders());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnotherClubsVersionIs404(): void
    {
        // Une version d'un AUTRE club : la RLS/le filtre tenant la masquent → 404 byte-identique
        // à une version inconnue (jamais un oracle d'existence, jamais un 403).
        $otherClubVersionId = $this->otherClubPeriodVersionId();

        $this->client->request('POST', "/api/schedules/{$otherClubVersionId}/load-locks", [], [], $this->headers());
        self::assertResponseStatusCodeSame(404);
    }

    public function testSeasonVersionIsRefused(): void
    {
        // « Charger les verrous » est un geste de PÉRIODE : une version de saison est refusée (409).
        $season = $this->seasonVersion(ScheduleStatus::COMPLETED);
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$season->getId()}/load-locks", [], [], $this->headers());
        self::assertResponseStatusCodeSame(409);
    }

    public function testNonCompletedVersionIsRefused(): void
    {
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $draft = $this->periodVersion($planId, ScheduleStatus::GENERATING);
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$draft->getId()}/load-locks", [], [], $this->headers());
        self::assertResponseStatusCodeSame(409);
    }

    public function testValidatedPlanIsRefused(): void
    {
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $v1 = $this->periodVersion($planId, ScheduleStatus::COMPLETED);
        $this->em->flush();
        // Valider la version = le plan la POINTE → plan validé, lecture seule : rouvrir d'abord.
        $this->scopeGucToClub($this->club->getId());
        self::assertTrue($this->provisioner()->choose($v1));
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/load-locks", [], [], $this->headers());
        self::assertResponseStatusCodeSame(409);
    }

    public function testGenerationInProgressIsRefused(): void
    {
        [, $v1] = $this->periodPlanWithTwoVersions();

        $lock = self::getContainer()->get(ClubGenerationLock::class);
        $token = $lock->acquire($this->club->getId(), 60);
        self::assertIsString($token);

        try {
            $this->client->request('POST', "/api/schedules/{$v1->getId()}/load-locks", [], [], $this->headers());
            self::assertResponseStatusCodeSame(409);
            self::assertSame('generation_in_progress', $this->decode()['code'] ?? null);
        } finally {
            // Redis n'est PAS couvert par le rollback DAMA : on relâche le verrou (DEL ciblé).
            $lock->release($this->club->getId(), $token);
        }
    }

    public function testCanLoadLocksCapabilityMatchesTheVerdict(): void
    {
        [, $v1, $v2] = $this->periodPlanWithTwoVersions();

        // v1 n'est pas la source (v2 l'est par défaut), période terminée, plan non validé, rien
        // en cours → canLoadLocks true (le geste changerait la source, il est offert).
        $this->client->request('GET', "/api/schedules/{$v1->getId()}", [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->decode()['capabilities']['canLoadLocks'], 'v1 n\'est pas la source → geste offert');

        // v2 EST la source (dernière COMPLETED) : le geste serait un no-op → canLoadLocks false.
        $this->client->request('GET', "/api/schedules/{$v2->getId()}", [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->decode()['capabilities']['canLoadLocks'], 'v2 est déjà la source → geste NON offert');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('LL ' . $uid)->setSlug('ll-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $manager = (new User)->setEmail('ll-mgr' . $uid . '@test.com')->setFirstName('M')->setLastName('G');
        $manager->setPasswordHash($hasher->hashPassword($manager, 'Password123!'));
        $this->em->persist($manager);
        $coach = (new User)->setEmail('ll-coach' . $uid . '@test.com')->setFirstName('C')->setLastName('H');
        $coach->setPasswordHash($hasher->hashPassword($coach, 'Password123!'));
        $this->em->persist($coach);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($manager->getId())->setRole('admin')->setIsActive(true));
        // Un membre NON gestionnaire (coach) de CE club : adhésion unique active → le listener
        // tenant résout bien ce club, et assertManager refuse (403).
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($coach->getId())->setRole('coach')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($manager);
        $this->coachToken = $container->get(JWTTokenManagerInterface::class)->create($coach);
    }

    private function provisioner(): SchedulePlanProvisioner
    {
        return self::getContainer()->get(SchedulePlanProvisioner::class);
    }

    /**
     * Un plan de PÉRIODE portant DEUX versions COMPLETED (v1 puis v2) — v2, la dernière, est la
     * source par défaut (aucun pointeur). versionNumber croissant départage l'ex æquo de createdAt.
     *
     * @return array{0: string, 1: Schedule, 2: Schedule}
     */
    private function periodPlanWithTwoVersions(): array
    {
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $v1 = $this->periodVersion($planId, ScheduleStatus::COMPLETED);
        $v2 = $this->periodVersion($planId, ScheduleStatus::COMPLETED);
        $this->em->flush();

        return [$planId, $v1, $v2];
    }

    private function periodVersion(string $planId, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('V')->setStatus($status)->setSchedulePlanId($planId);
        $this->em->persist($schedule);
        // Numérote la version dans son plan (comme la production au POST) — sa propre transaction.
        $this->provisioner()->linkSchedule($schedule);

        return $schedule;
    }

    private function seasonVersion(ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Socle')->setStatus($status);
        // Plan AVANT persist (schedule_plan_id NOT NULL) : résout/pose le plan SEASON puis numérote.
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }

    private function otherClubPeriodVersionId(): string
    {
        $uid = uniqid('', true);
        $otherClub = (new Club)->setName('Other ' . $uid)->setSlug('other-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($otherClub);
        $this->em->flush();

        // season/schedule de l'autre club sont club-scopés (policy RLS WITH CHECK sur app.club_id) :
        // on bascule le GUC tenant sur l'AUTRE club AVANT d'insérer ses lignes — sinon la RLS les
        // rejette (le GUC est encore sur le club du requérant, posé en setUp) — puis on rend la main
        // au club du requérant pour la suite du test.
        $this->scopeGucToClub($otherClub->getId());
        $otherSeason = (new Season)->setClubId($otherClub->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($otherSeason);
        $this->em->flush();

        $planId = $this->createPeriodPlan($otherClub->getId(), $otherSeason->getId());
        $schedule = (new Schedule)->setClubId($otherClub->getId())->setSeasonId($otherSeason->getId())
            ->setName('V')->setStatus(ScheduleStatus::COMPLETED)->setSchedulePlanId($planId);
        $this->em->persist($schedule);
        $this->provisioner()->linkSchedule($schedule);
        $this->em->flush();
        // On rend la main au club du requérant pour la suite du test.
        $this->scopeGucToClub($this->club->getId());

        return $schedule->getId();
    }

    /** @return array<string, mixed> */
    private function decode(): array
    {
        return json_decode($this->client->getResponse()->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }

    /** @return array<string, string> */
    private function coachHeaders(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->coachToken,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }
}
