<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * planning-versions (overlay versions) non-regression: a period's overlay
 * follows the SAME version lifecycle as a season plan — validating one version
 * archives its siblings OF THE SAME PERIOD and makes it the active overlay, while
 * leaving other periods' overlays, season plans and the season baseline/socle
 * untouched; an in-flight sibling blocks validation; reopening does not
 * resurrect archived siblings.
 */
#[Group('phase1')]
#[Group('integration')]
final class OverlayVersionsTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private JWTTokenManagerInterface $jwt;

    public function testValidatingOverlayVersionDeletesSiblingsAndSetsActive(): void
    {
        [$user, $club, $season, $baseline] = $this->seed('OVV1');
        $entry = $this->period($club, $season, 'P1');
        $otherEntry = $this->period($club, $season, 'P2');

        $v1 = $this->overlay($club, $season, $entry, ScheduleStatus::COMPLETED);
        $v2 = $this->overlay($club, $season, $entry, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($v1); // V1 validée (active) au départ — lot D-b : « active » = pointeur du plan
        $otherOverlay = $this->overlay($club, $season, $otherEntry, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($otherOverlay);
        // A SEASON plan version must not be touched by an overlay validation.
        $seasonPlan = $this->seasonPlan($club, $season, ScheduleStatus::COMPLETED);
        $this->em->flush();

        $this->post($user, $club, "/api/schedules/{$v2->getId()}/validate");
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        // The period's plan points at V2, which becomes the active overlay; V1 —
        // the version it no longer points at — is deleted (inv. 1).
        self::assertNull($this->em->getRepository(Schedule::class)->find($v1->getId()), 'the unchosen sibling version is deleted');
        self::assertSame($v2->getId(), $provisioner->chosenOfPeriodPlan($entry->getId()), 'la version validée devient la version active de la période');
        // The OTHER period's overlay is untouched — each period owns its own plan.
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($otherOverlay->getId()));
        self::assertSame($otherOverlay->getId(), $provisioner->chosenOfPeriodPlan($otherEntry->getId()));
        // The season plan is untouched, and its pointer did NOT move.
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($seasonPlan->getId()));
        self::assertSame($baseline->getId(), $this->chosenPlanVersion($season), 'validating an overlay must not move the season plan pointer');
    }

    public function testCreatingAVersionKeepsTheValidatedOverlayActive(): void
    {
        [$user, $club, $season] = $this->seed('OVV4');
        $entry = $this->period($club, $season, 'P');
        $v1 = $this->overlay($club, $season, $entry, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($v1); // V1 validée = active
        $this->em->flush();

        // Creating a new version must NOT un-validate the good V1 (it stays shown
        // while the new draft solves; validating V2 flips the pointer later). lot D-b :
        // « active » = plan.chosenScheduleId, que la création ne touche jamais.
        $this->client->request('POST', '/api/schedules', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['name' => 'V2', 'status' => 'DRAFT', 'schedulePlanId' => self::getContainer()->get(SchedulePlanProvisioner::class)->periodPlanId($entry->getId())], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $v2 = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
        self::assertNotSame($v1->getId(), $v2);

        $this->em->clear();
        self::assertSame($v1->getId(), self::getContainer()->get(SchedulePlanProvisioner::class)->chosenOfPeriodPlan($entry->getId()), 'la version validée reste active pendant que le nouveau brouillon se résout');
    }

    public function testInFlightSiblingOverlayBlocksValidation(): void
    {
        [$user, $club, $season] = $this->seed('OVV2');
        $entry = $this->period($club, $season, 'P');
        $v1 = $this->overlay($club, $season, $entry, ScheduleStatus::COMPLETED);
        $this->overlay($club, $season, $entry, ScheduleStatus::GENERATING); // sibling still solving
        $this->em->flush();

        $this->post($user, $club, "/api/schedules/{$v1->getId()}/validate");
        self::assertResponseStatusCodeSame(409, 'a sibling overlay still generating blocks validation');
    }

    public function testReopeningAnOverlayReleasesItsPeriodPlan(): void
    {
        // Il n'y a plus de sœur « archivée » à ressusciter : valider les supprime.
        // Ce qui reste à garantir, c'est que rouvrir dépointe le plan de la période
        // SANS toucher au plan de la saison (deux plans, deux pointeurs).
        [$user, $club, $season, $baseline] = $this->seed('OVV3');
        $entry = $this->period($club, $season, 'P');
        $chosen = $this->overlay($club, $season, $entry, ScheduleStatus::COMPLETED);
        $this->em->flush();
        $this->choosePlanVersion($chosen);

        $this->post($user, $club, "/api/schedules/{$chosen->getId()}/reopen");
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($chosen->getId())?->getStatus(), 'la version survit : rouvrir lâche le pointeur, pas le travail');
        // Rouvrir dépointe le plan de la période : plus de version active (on ajuste).
        self::assertNull(self::getContainer()->get(SchedulePlanProvisioner::class)->chosenOfPeriodPlan($entry->getId()), 'rouvrir dépointe le plan de la période');
        self::assertSame($baseline->getId(), $this->chosenPlanVersion($season), 'rouvrir un plan secondaire ne touche pas au plan de la saison');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    private function post(User $user, Club $club, string $url): void
    {
        $this->client->request('POST', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
        ]);
    }

    private function overlay(Club $club, Season $season, CalendarEntry $entry, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setName('Overlay ' . $status->value)
            ->setStatus($status);
        // lot D : schedule_plan_id NOT NULL — la version ne doit pas être persistée avant
        // d'avoir son plan. linkSeededSchedule pose le plan (né du geste) PUIS persiste.
        // Prod lie de même chaque overlay ; sans plan, la validation (periodEntryIdOf) lèverait.
        $this->linkSeededSchedule($schedule, $entry->getId());

        return $schedule;
    }

    private function seasonPlan(Club $club, Season $season, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setName('Plan saison')
            ->setStatus($status);
        // lot D : linkSeededSchedule pose le plan SEASON puis persiste (pas de persist/flush avant).
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }

    private function period(Club $club, Season $season, string $title): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle($title);
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);
        // ADR-0002 lot C: une période a TOUJOURS son plan — en prod le geste (POST
        // /api/calendar_entries) le crée. L'entrée est fabriquée à la main ici, on
        // rejoue donc le geste (flush d'abord: provisionPeriodPlan relit la ligne).
        $this->em->flush();
        self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId());

        return $entry;
    }

    /**
     * @return array{0: User, 1: Club, 2: Season, 3: Schedule}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);
        $container = self::getContainer();
        $hasher = $container->get('security.user_password_hasher');

        $club = (new Club)->setName('Club ' . $tag)->setSlug('club-' . $tag . '-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = (new User)->setEmail('u-' . $tag . '-' . $uid . '@test.com')->setFirstName('O')->setLastName('V');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        // Le socle : la version que le plan SEASON pointe. Les plans secondaires
        // (overlays de période) ne sont autorisés qu'au-dessus d'un socle pointé (inv. 13).
        $baseline = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Baseline')->setStatus(ScheduleStatus::COMPLETED);
        // lot D : choosePlanVersion lie (plan AVANT persist) puis pointe — pas de persist/flush avant.
        $this->choosePlanVersion($baseline);

        return [$user, $club, $season, $baseline];
    }
}
