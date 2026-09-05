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
 * P4-173 (axe planning lifecycle) — le plan de période/saison SERT lui-même sa péremption
 * (`SchedulePlanResource::staleness`), pour que le cockpit dise « à régénérer » sans redériver la
 * règle. La péremption est celle de la version POINTÉE ; `null` quand rien n'est pointé ou que la
 * fenêtre est révolue. Anti-N+1 : une seule requête sur `schedule` pour toute la collection
 * (mémoïsation `SchedulePlanStalenessResolver`) — non mesurable ici faute de compteur de requêtes
 * dans la suite (comme `CalendarEntryApiTest` pour `redatable`), la correction sur PLUSIEURS plans
 * atteste le partage de l'ensemble mémoïsé.
 */
#[Group('phase1')]
#[Group('integration')]
final class SchedulePlanStalenessServedTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private JWTTokenManagerInterface $jwt;

    private mixed $hasher;

    public function testChosenVersionMarkedServesStalenessTrue(): void
    {
        [$user, $club, $season] = $this->seedFutureSeason('ST1');
        $baseline = $this->chosenSeasonVersion($club, $season);
        $baseline->setConstraintsChangedSinceGeneration(true);
        $this->em->flush();

        $plan = $this->seasonPlanId($season);
        $data = $this->getJson("/api/schedule_plans/{$plan}", $user, $club);

        self::assertArrayHasKey('staleness', $data, 'le bloc staleness est servi');
        self::assertIsArray($data['staleness'], 'une version pointée non révolue porte le bloc');
        self::assertTrue($data['staleness']['constraintsChanged'], 'la version pointée est marquée « contrainte changée »');
        self::assertFalse($data['staleness']['manuallyEdited']);
        self::assertFalse($data['staleness']['resourcesChanged']);
    }

    public function testV1MarkedButCleanV2ChosenServesAllFalse(): void
    {
        [$user, $club, $season] = $this->seedFutureSeason('ST2');

        // V1 marquée puis pointée, V2 propre puis pointée : c'est la version POINTÉE qui fait foi.
        $v1 = $this->chosenSeasonVersion($club, $season, 'V1');
        $v1->setConstraintsChangedSinceGeneration(true)->setResourcesChangedSinceGeneration(true);
        $this->em->flush();

        $v2 = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('V2')->setStatus(ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($v2); // V2 devient la version pointée (propre)

        $plan = $this->seasonPlanId($season);
        $data = $this->getJson("/api/schedule_plans/{$plan}", $user, $club);

        self::assertIsArray($data['staleness'], 'un plan avec une version pointée porte le bloc');
        self::assertFalse($data['staleness']['constraintsChanged'], 'V2 pointée est propre — la V1 marquée n\'éteint pas le signal seule');
        self::assertFalse($data['staleness']['resourcesChanged']);
        self::assertFalse($data['staleness']['manuallyEdited']);
    }

    public function testNoPointerServesNull(): void
    {
        [$user, $club, $season] = $this->seedFutureSeason('ST3');
        // Une version COMPLETED existe mais le plan ne la POINTE pas (workspace pas validé).
        $draft = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('WIP')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($draft);
        $draft->setConstraintsChangedSinceGeneration(true);
        $this->em->flush();

        $plan = $this->seasonPlanId($season);
        $data = $this->getJson("/api/schedule_plans/{$plan}", $user, $club);

        self::assertArrayHasKey('staleness', $data);
        self::assertNull($data['staleness'], 'sans version pointée, staleness est null');
    }

    public function testPastWindowServesNull(): void
    {
        // Saison entièrement révolue : sa fenêtre est derrière, « à régénérer » y serait un faux
        // appel à l'action — staleness null même sur une version pointée marquée.
        [$user, $club, $season] = $this->seedSeason('ST4', '2024-09-01', '2025-06-30');
        $baseline = $this->chosenSeasonVersion($club, $season);
        $baseline->setConstraintsChangedSinceGeneration(true);
        $this->em->flush();

        $plan = $this->seasonPlanId($season);
        $data = $this->getJson("/api/schedule_plans/{$plan}", $user, $club);

        self::assertArrayHasKey('staleness', $data);
        self::assertNull($data['staleness'], 'une fenêtre révolue ne se dit jamais « à régénérer »');
    }

    public function testCollectionServesStalenessPerPlan(): void
    {
        [$user, $club, $season] = $this->seedFutureSeason('ST5');

        // Socle pointé + marqué « contrainte ».
        $baseline = $this->chosenSeasonVersion($club, $season);
        $baseline->setConstraintsChangedSinceGeneration(true);

        // Un overlay de période, pointé + marqué « données du club » — fenêtre future.
        $entry = $this->futurePeriod($club, $season, 'Gym fermé');
        $overlayPlanId = self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId());
        self::assertIsString($overlayPlanId);
        $overlay = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Overlay')->setStatus(ScheduleStatus::COMPLETED);
        $overlay->setSchedulePlanId($overlayPlanId);
        $this->em->persist($overlay);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($overlay);
        self::assertTrue(self::getContainer()->get(SchedulePlanProvisioner::class)->choose($overlay));
        $overlay->setResourcesChangedSinceGeneration(true);
        $this->em->flush();

        $data = $this->getJson('/api/schedule_plans', $user, $club);
        $byChosen = [];
        foreach ($data['member'] as $plan) {
            self::assertArrayHasKey('staleness', $plan, 'chaque plan de la collection porte staleness');
            $byChosen[$plan['chosenScheduleId'] ?? '∅'] = $plan['staleness'];
        }

        // Chaque plan porte le bloc de SA version pointée — distinctes : le socle est marqué
        // « contrainte » (aucune contrainte créée ici → l'overlay, lui, reste faux là-dessus),
        // l'overlay est marqué « données du club ».
        self::assertNotNull($byChosen[$baseline->getId()] ?? null, 'le socle porte son bloc');
        self::assertTrue($byChosen[$baseline->getId()]['constraintsChanged'], 'le socle est marqué « contrainte changée »');

        self::assertNotNull($byChosen[$overlay->getId()] ?? null, 'l\'overlay porte son bloc');
        self::assertTrue($byChosen[$overlay->getId()]['resourcesChanged'], 'l\'overlay est marqué « données du club »');
        self::assertFalse($byChosen[$overlay->getId()]['constraintsChanged'], 'aucune contrainte créée : ce drapeau reste faux, propre à la version pointée');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $uri, User $user, Club $club): array
    {
        // Kernel de test partagé : les écritures ci-dessus (choose = UPDATE) laissent le plan
        // managé PÉRIMÉ dans l'identity map. En prod la lecture est une requête FRAÎCHE (autre EM) ;
        // on le simule en vidant l'EM avant le GET, sinon le provider relit l'entité périmée.
        $this->em->clear();

        $this->client->request('GET', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
        ]);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** La version que le plan SEASON pointe (créée, liée, pointée). */
    private function chosenSeasonVersion(Club $club, Season $season, string $name = 'Baseline'): Schedule
    {
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)->setStatus(ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($schedule);

        return $schedule;
    }

    private function seasonPlanId(Season $season): string
    {
        $id = self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlanId($season->getId());
        self::assertIsString($id);

        return $id;
    }

    private function futurePeriod(Club $club, Season $season, string $title): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)
            ->setTitle($title)
            ->setStartDate(new DateTimeImmutable('+30 days'))->setEndDate(new DateTimeImmutable('+37 days'));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seedFutureSeason(string $tag): array
    {
        // Fenêtre franchement devant, pour que la règle « révolu → null » ne masque pas le cas.
        return $this->seedSeason($tag, new DateTimeImmutable('-10 days')->format('Y-m-d'), new DateTimeImmutable('+300 days')->format('Y-m-d'));
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seedSeason(string $tag, string $start, string $end): array
    {
        $uid = uniqid('', true);

        $club = (new Club)->setName('Club ' . $tag)->setSlug('club-' . $tag . '-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = (new User)->setEmail('u-' . $tag . '-' . $uid . '@test.com')->setFirstName('S')->setLastName('T');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        $season = (new Season)->setClubId($club->getId())->setName($tag)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$user, $club, $season];
    }
}
