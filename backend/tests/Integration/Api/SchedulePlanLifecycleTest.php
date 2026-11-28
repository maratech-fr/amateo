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
use App\Enum\CalendarEntryStatus;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\OverlayManager;
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
 * ADR-0002 lot B1 NR — axe structurant §7.1 « planning lifecycle ».
 *
 * Lot B1 est **ADDITIF** : le pointeur du plan (`chosenScheduleId`) est MAINTENU
 * par valider/rouvrir/supprimer, mais **rien ne le lit encore pour décider** — le
 * legacy (baseline, socleValidatedAt, VALIDATED) reste la vérité jusqu'au lot de
 * bascule, qui déplacera TOUS les consommateurs (back + front) d'un coup et
 * supprimera le legacy dans le même commit.
 *
 * Ce test garde donc ce que B1 promet vraiment :
 *  1. valider ⇒ le plan pointe la version (et le legacy est intact) ;
 *  2. rouvrir ⇒ le pointeur est relâché ;
 *  3. aucun pointage automatique — générer ne pointe jamais (inv. 2/17) ;
 *  4. le pointeur ne nomme jamais une version supprimée (inv. 2) ;
 *  5. la numérotation est strictement monotone (une V supprimée ne rend jamais
 *     son numéro) — le déféré de lot A.
 */
#[Group('phase1')]
#[Group('integration')]
final class SchedulePlanLifecycleTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private SchedulePlanProvisioner $provisioner;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    /** inv. 1 : valider = le plan pointe la version. Le legacy reste intact (additif). */
    public function testValidatingPointsThePlan(): void
    {
        [$user, , $season] = $this->seed('LCY1');
        $v1 = $this->version($season, ScheduleStatus::COMPLETED);

        $this->post($user, "/api/schedules/{$v1->getId()}/validate");
        self::assertResponseIsSuccessful();

        // Le pointeur est la SEULE vérité : plus de miroir legacy à tenir à jour,
        // donc plus de risque de divergence entre deux sources.
        self::assertSame($v1->getId(), $this->chosenOfSeason($season), 'valider = pointer');
        $this->em->clear();
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($v1->getId())?->getStatus(), 'aucun statut ne redit « choisi »');
    }

    /** inv. 2 : aucun pointage automatique — seul le gestionnaire pointe. */
    public function testCreatingVersionsNeverPointsThePlanAutomatically(): void
    {
        [, , $season] = $this->seed('LCY3');
        $this->version($season, ScheduleStatus::COMPLETED);
        $this->version($season, ScheduleStatus::COMPLETED);

        self::assertFalse($this->chosenOfSeason($season), 'même COMPLETED, une version ne se pointe jamais toute seule');
    }

    /** inv. 2 : le pointeur ne nomme jamais une version supprimée. */
    public function testDeletingTheChosenVersionReleasesThePointer(): void
    {
        [, , $season] = $this->seed('LCY4');
        $v1 = $this->version($season, ScheduleStatus::COMPLETED);
        $this->provisioner->choose($v1);
        self::assertSame($v1->getId(), $this->chosenOfSeason($season));

        // Chemin de suppression réel (purge des artefacts + release du pointeur).
        self::getContainer()->get(OverlayManager::class)->purgeScheduleArtifacts($v1);
        $this->em->remove($v1);
        $this->em->flush();

        self::assertFalse($this->chosenOfSeason($season), 'le pointeur est libéré, jamais laissé pendant');
    }

    /**
     * Déféré de lot A : numérotation strictement monotone. Un `MAX+1` rendrait le
     * numéro d'une version supprimée — le compteur stocké ne recule jamais.
     */
    public function testVersionNumbersAreMonotonicAcrossDeletion(): void
    {
        [, , $season] = $this->seed('LCY5');
        $v1 = $this->version($season, ScheduleStatus::COMPLETED);
        $v2 = $this->version($season, ScheduleStatus::COMPLETED);
        $v3 = $this->version($season, ScheduleStatus::COMPLETED);
        self::assertSame([1, 2, 3], [$v1->getVersionNumber(), $v2->getVersionNumber(), $v3->getVersionNumber()]);

        // Supprimer la plus haute : un MAX+1 réattribuerait « 3 ».
        self::getContainer()->get(OverlayManager::class)->purgeScheduleArtifacts($v3);
        $this->em->remove($v3);
        $this->em->flush();

        $v4 = $this->version($season, ScheduleStatus::COMPLETED);
        self::assertSame(4, $v4->getVersionNumber(), 'la version suivante est V4, jamais un numéro réutilisé');
    }

    /**
     * inv. 10 : supprimer une indisponibilité supprime SES plans — sinon
     * /api/schedule_plans garde un plan fantôme nommant une période disparue.
     */
    public function testDeletingAPeriodRemovesItsPlan(): void
    {
        [$user, $club, $season] = $this->seed('LCY7');

        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $entry->setTitle('Fermeture');
        $entry->setIsDisruptive(true);
        $entry->setStartDate(new DateTimeImmutable('2025-10-20'));
        $entry->setEndDate(new DateTimeImmutable('2025-10-26'));
        $this->em->persist($entry);
        $this->em->flush();
        // ADR-0002 lot C : le plan naît DU GESTE. En prod le POST /api/calendar_entries
        // le crée ; l'entrée étant fabriquée à la main, on rejoue le geste.
        $planId = self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId());

        // La version d'overlay se raccroche au plan déjà né (depuis C4 : par schedulePlanId,
        // que l'appelant POSE ; linkSchedule ne fait plus que numéroter).
        $overlay = new Schedule;
        $overlay->setClubId($club->getId());
        $overlay->setSeasonId($season->getId());
        $overlay->setName('Overlay');
        $overlay->setStatus(ScheduleStatus::COMPLETED);
        $overlay->setSchedulePlanId($planId);
        $this->em->persist($overlay);
        $this->em->flush();
        $this->provisioner->linkSchedule($overlay);
        $this->em->flush();
        self::assertNotFalse($this->periodPlanId($entry), 'le plan de période existe');

        $this->client->request('DELETE', "/api/calendar_entries/{$entry->getId()}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
        ]);
        self::assertResponseIsSuccessful();

        self::assertFalse($this->periodPlanId($entry), 'le plan de la période supprimée ne survit pas');
    }

    /**
     * Le compteur s'auto-répare : s'il dérive SOUS le MAX réel (dump antérieur au
     * seed, plan recréé à la main), un compteur nu rendrait un numéro déjà pris et
     * chaque génération de ce plan échouerait à jamais (uniq_schedule_plan_version).
     */
    public function testVersionCounterHealsWhenItDriftsBelowTheRealMax(): void
    {
        [, , $season] = $this->seed('LCY6');
        $v1 = $this->version($season, ScheduleStatus::COMPLETED);
        $v2 = $this->version($season, ScheduleStatus::COMPLETED);
        self::assertSame(2, $v2->getVersionNumber());

        // Dérive artificielle : compteur remis sous le MAX réel.
        $this->em->getConnection()->executeStatement(
            'UPDATE schedule_plan SET last_version_number = 0 WHERE season_id = :sid',
            ['sid' => $season->getId()],
        );

        $v3 = $this->version($season, ScheduleStatus::COMPLETED);
        self::assertSame(3, $v3->getVersionNumber(), 'le compteur repart au-dessus du MAX réel, jamais sur un numéro pris');
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

    private function periodPlanId(CalendarEntry $entry): string|false
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT id FROM schedule_plan WHERE calendar_entry_id = :eid',
            ['eid' => $entry->getId()],
        );
    }

    /** Stateless firewall → every call carries its own Bearer token. */
    private function post(User $user, string $uri): void
    {
        $this->client->request('POST', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    /** The season plan's pointer, or false when unpointed/absent. */
    private function chosenOfSeason(Season $season): string|false
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT chosen_schedule_id FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'',
            ['sid' => $season->getId()],
        ) ?: false;
    }

    /** A version created the way production does it: linked to its plan. */
    private function version(Season $season, ScheduleStatus $status): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($season->getClubId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Version');
        $schedule->setStatus($status);
        // C4 : linkSchedule numérote — la version de saison doit d'abord porter son plan SEASON.
        $schedule->setSchedulePlanId($this->provisioner->ensureSeasonPlanId($season->getId()));
        $this->em->persist($schedule);
        $this->em->flush();
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
        $user->setFirstName('L');
        $user->setLastName('C');
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

        // The season owns its SEASON plan from birth (inv. 3) — the real flow
        // provisions it at onboarding; a raw EM persist bypasses that hook.
        $this->provisioner->ensureSeasonPlan($season);
        $this->em->flush();

        return [$user, $club, $season];
    }
}
