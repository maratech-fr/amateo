<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Service\SchedulePlanProvisioner;
use App\Service\StructureSnapshotter;
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
 * planning-versions (§7.1): POST /api/schedules/{id}/regenerate-from ("Charger
 * cette version") restores a version's structure photo and re-points the season's
 * loaded context (★) to it — WITHOUT solving (no new version). Refused for an
 * overlay and for a version with no photo (pre-D2).
 */
#[Group('phase1')]
final class RegenerateFromVersionTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $token;

    public function testLoadVersionRestoresStructureAndRepointsLiveContextWithoutSolving(): void
    {
        $this->persistTeam('SM1');
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->em->flush();
        self::getContainer()->get(StructureSnapshotter::class)->store($v1, self::getContainer()->get(StructureSnapshotter::class)->serialize($this->club->getId(), $this->season->getId()));

        // A later version V2 is the current loaded context; the structure diverges (add a team).
        $v2 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->season->setLiveContextScheduleId($v2->getId());
        $this->persistTeam('SM2');
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        // Structure restored to V1 (only SM1) — and NO new version was created.
        self::assertCount(1, $this->em->getRepository(Team::class)->findBy(['seasonId' => $this->season->getId()]));
        // Les versions de saison = celles rattachées au plan SEASON (plus de colonne
        // calendarEntryId depuis C4 : « saison ? » se lit sur le plan pointé).
        $seasonPlanId = self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlanId($this->season->getId());
        $schedules = $this->em->getRepository(Schedule::class)->findBy(['seasonId' => $this->season->getId(), 'schedulePlanId' => $seasonPlanId]);
        self::assertCount(2, $schedules, 'no new version — only V1 and V2 remain');
        // The ★ moved to V1 (the loaded context).
        self::assertSame($v1->getId(), $this->em->getRepository(Season::class)->find($this->season->getId())?->getLiveContextScheduleId());
    }

    public function testLoadVersionKeepsPeriodTrainingSlots(): void
    {
        $this->persistTeam('SM1');
        // A venue captured in the snapshot so it survives the restore (else the
        // period slot on it would be purged as dangling — the sibling guard).
        $venue = (new Venue)->setId('11111111-1111-4111-8111-111111111111')
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('Gym')->setCanSplit(false)->setSource('manual');
        $this->em->persist($venue);
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->em->flush();
        self::getContainer()->get(StructureSnapshotter::class)->store($v1, self::getContainer()->get(StructureSnapshotter::class)->serialize($this->club->getId(), $this->season->getId()));

        // A period's own training slot (schedulePlanId set) is calendar/overlay, not
        // base structure — a base-version restore must not wipe it.
        $periodPlanId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $periodSlot = (new VenueTrainingSlot)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueId($venue->getId())
            ->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('20:00'))->setDurationMinutes(90)->setCapacity(1)
            ->setSchedulePlanId($periodPlanId);
        $this->em->persist($periodSlot);
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $periodPlanId]), 'the period slot survives a base-version restore');
    }

    public function testLoadVersionPurgesDanglingTeamOverride(): void
    {
        $this->persistTeam('SM1');
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->em->flush();
        self::getContainer()->get(StructureSnapshotter::class)->store($v1, self::getContainer()->get(StructureSnapshotter::class)->serialize($this->club->getId(), $this->season->getId()));

        // A team added AFTER the snapshot, with a period override on it.
        $sm2 = $this->persistTeam('SM2');
        $override = (new TeamPeriodOverride)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->periodPlanId())
            ->setTeamId($sm2->getId())->setIsActive(false);
        $this->em->persist($override);
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(200);

        // Restore removed SM2 (not in the snapshot) → its override is a ghost → purged.
        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(TeamPeriodOverride::class)->findBy(['teamId' => $sm2->getId()]), 'a dangling period override is purged on restore');
    }

    public function testLoadVersionIsRefusedWhenItWouldRemoveAnEngagedTeam(): void
    {
        // `wipeStructure` supprime les Team en DQL de MASSE : il ne passe pas par le
        // processor, donc pas par la garde du périmètre engagé. Sans ce contrôle,
        // « une équipe qui joue est intouchable » serait vrai par l'API et faux ici —
        // donc faux, et contournable en un clic. Ses matchs, absents de la photo comme
        // du wipe, survivraient en nommant un team_id mort (aucune FK ne l'arrête).
        $this->persistTeam('SM1');
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->em->flush();
        $snapshotter = self::getContainer()->get(StructureSnapshotter::class);
        $snapshotter->store($v1, $snapshotter->serialize($this->club->getId(), $this->season->getId()));

        // Une équipe née APRÈS la photo, puis engagée en compétition.
        $sm2 = $this->persistTeam('SM2');
        $fixture = (new Fixture)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($sm2->getId())
            ->setMatchDate(new DateTimeImmutable('2026-10-04'))
            ->setHomeAway(FixtureHomeAway::HOME)
            ->setOpponentLabel('AS Voisins')
            ->setStatus(FixtureStatus::PLACED);
        $this->em->persist($fixture);
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());

        self::assertResponseStatusCodeSame(409, 'charger une photo qui ignore une équipe engagée doit être refusé');
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Team::class)->find($sm2->getId()), 'l\'équipe engagée survit');
        self::assertNotNull($this->em->getRepository(Fixture::class)->find($fixture->getId()), 'et son match aussi');
    }

    public function testLoadVersionStillWorksWhenTheEngagedTeamIsInThePhoto(): void
    {
        // Le pendant du test ci-dessus, et le SEUL qui attrape une garde trop stricte :
        // si `$inPhoto` se remplit mal (mauvaise clé de famille, par ex.), la garde
        // refuse TOUT — y compris ce cas parfaitement légitime — et le test précédent,
        // lui, resterait vert. L'équipe engagée est ici DANS la photo : rien ne se perd.
        $sm1 = $this->persistTeam('SM1');
        $fixture = (new Fixture)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($sm1->getId())
            ->setMatchDate(new DateTimeImmutable('2026-10-04'))
            ->setHomeAway(FixtureHomeAway::HOME)
            ->setOpponentLabel('AS Voisins')
            ->setStatus(FixtureStatus::PLACED);
        $this->em->persist($fixture);
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->em->flush();
        $snapshotter = self::getContainer()->get(StructureSnapshotter::class);
        $snapshotter->store($v1, $snapshotter->serialize($this->club->getId(), $this->season->getId()));

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());

        self::assertResponseStatusCodeSame(200, 'une équipe engagée présente dans la photo ne bloque rien');
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Team::class)->find($sm1->getId()));
    }

    public function testLoadVersionIsRefusedWhenThePhotoWouldRewriteAnEngagedTeamLevel(): void
    {
        // Le gel du niveau existe pour rendre IMPOSSIBLE la divergence photo↔base. Le
        // restore l'atteint par l'autre bout : `level` est un champ mappé, donc la photo
        // le porte et `hydrate()` le réinsère tel quel. Une photo antérieure à la
        // correction du niveau le rétablirait — et le processor refuserait ensuite (409)
        // toute correction : le club resterait avec un niveau faux, sans issue.
        $sm1 = $this->persistTeam('SM1');
        $sm1->setLevel(TeamLevel::DEPARTEMENTAL);
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->em->flush();
        $snapshotter = self::getContainer()->get(StructureSnapshotter::class);
        $snapshotter->store($v1, $snapshotter->serialize($this->club->getId(), $this->season->getId()));

        // Le niveau est corrigé APRÈS la photo (encore permis : pas de match), puis
        // l'équipe est engagée — elle est désormais inscrite REGIONAL à la fédération.
        $sm1->setLevel(TeamLevel::REGIONAL);
        $fixture = (new Fixture)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($sm1->getId())
            ->setMatchDate(new DateTimeImmutable('2026-10-04'))
            ->setHomeAway(FixtureHomeAway::HOME)
            ->setOpponentLabel('AS Voisins')
            ->setStatus(FixtureStatus::PLACED);
        $this->em->persist($fixture);
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());

        self::assertResponseStatusCodeSame(409, 'une photo qui rétablirait un autre niveau sur une équipe engagée doit être refusée');
        $this->em->clear();
        self::assertSame(
            TeamLevel::REGIONAL,
            $this->em->getRepository(Team::class)->find($sm1->getId())?->getLevel(),
            'le niveau sous lequel elle est inscrite ne bouge pas',
        );
    }

    public function testLoadVersionLeavesAMatchWhoseVenueDisappearedUntouched(): void
    {
        // P2-52 (RMM-10) — L'EXPLORATION NE DÉTRUIT PLUS. `Fixture` n'est ni dans le wipe ni
        // dans la photo : le match survit au restore. Si son gymnase, lui, n'est pas dans la
        // photo, il est supprimé — et le match nomme alors un venue_id transitoirement mort.
        // On le LAISSE tel quel : pointeur pendouillant assumé (recharger une version qui porte
        // ce gymnase le rend valide de nouveau). Le restore n'est plus la gâchette du dépointage
        // — la VALIDATION l'est. « Charger cette version » est un ESSAI, il ne doit rien casser.
        $sm1 = $this->persistTeam('SM1');
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->em->flush();
        $snapshotter = self::getContainer()->get(StructureSnapshotter::class);
        $snapshotter->store($v1, $snapshotter->serialize($this->club->getId(), $this->season->getId()));

        // Un gymnase né APRÈS la photo, et un match posé dedans.
        $venue = (new Venue)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('Halle B')->setCanSplit(false)->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $venueId = $venue->getId();
        $fixture = (new Fixture)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($sm1->getId())
            ->setMatchDate(new DateTimeImmutable('2026-10-04'))
            ->setHomeAway(FixtureHomeAway::HOME)
            ->setOpponentLabel('AS Voisins')
            ->setStatus(FixtureStatus::PLACED)
            ->setVenueId($venueId);
        $this->em->persist($fixture);
        $this->em->flush();

        // SM1 est dans la photo (elle n'est pas engagée ici : le match la rendrait
        // engagée, mais elle EST dans la photo — la garde laisse donc passer).
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(200);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Fixture::class)->find($fixture->getId());
        self::assertNotNull($reloaded, 'le match survit à l\'exploration');
        self::assertSame($venueId, $reloaded->getVenueId(), 'le pointeur pendouille — le restore ne dépointe plus');
        self::assertSame(FixtureStatus::PLACED, $reloaded->getStatus(), 'et son statut ne change pas : l\'essai ne détruit rien');
        self::assertNull($reloaded->getUnplacedReason(), 'aucune raison venue_lost : ce n\'est pas la gâchette');
    }

    public function testRegenerateFromTheChosenVersionIsRefused(): void
    {
        // The version the plan points at is read-only: replaying its conditions
        // would rebuild the season's calendar in place. Reopen it first.
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->em->flush();
        $this->choosePlanVersion($v1);

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(409);
    }

    public function testRegenerateFromBlockedWhileASiblingIsGenerating(): void
    {
        $this->persistTeam('SM1');
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->makeSchedule(ScheduleStatus::GENERATING, null); // a sibling mid-solve
        $this->em->flush();
        self::getContainer()->get(StructureSnapshotter::class)->store($v1, self::getContainer()->get(StructureSnapshotter::class)->serialize($this->club->getId(), $this->season->getId()));

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(409);
        // Nothing wiped while a sibling solves.
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Team::class)->findBy(['seasonId' => $this->season->getId()]));
    }

    public function testRegenerateFromAVersionWithoutPhotoIsRefused(): void
    {
        $v1 = $this->makeSchedule(ScheduleStatus::COMPLETED, null); // never snapshotted
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$v1->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(409);
    }

    public function testRegenerateFromAnOverlayIsRefused(): void
    {
        // ADR-0002 C4 : « overlay ? » = plan.type !== SEASON. Il faut donc une VRAIE
        // période (et son plan né du geste), plus un calendarEntryId bidon : sans plan,
        // une version n'est ni socle ni overlay — elle n'existe pas.
        $entry = new CalendarEntry;
        $entry->setClubId($this->club->getId());
        $entry->setSeasonId($this->season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gymnase fermé');
        $entry->setStartDate(new DateTimeImmutable('2026-03-02'));
        $entry->setEndDate(new DateTimeImmutable('2026-03-08'));
        $this->em->persist($entry);
        $this->em->flush();

        $overlay = $this->makeSchedule(ScheduleStatus::COMPLETED, $entry->getId());
        $this->em->flush();

        $this->client->request('POST', "/api/schedules/{$overlay->getId()}/regenerate-from", [], [], $this->headers());
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * The Schedule API exposes `hasStructurePhoto` so the client offers "Charger
     * cette version" ONLY when the restore can succeed — decoupling the button
     * from generatedTeamCount (which every generated plan carries) fixes the
     * pre-D2 "flash error" (the button showed then 409'd on a photo-less plan).
     */
    public function testScheduleApiExposesHasStructurePhoto(): void
    {
        $this->persistTeam('SM1');
        $withPhoto = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $withoutPhoto = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->em->flush();
        self::getContainer()->get(StructureSnapshotter::class)->store($withPhoto, self::getContainer()->get(StructureSnapshotter::class)->serialize($this->club->getId(), $this->season->getId()));

        $this->client->request('GET', "/api/schedules/{$withPhoto->getId()}", [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->decode()['hasStructurePhoto'], 'a snapshotted version carries a restorable photo');

        $this->client->request('GET', "/api/schedules/{$withoutPhoto->getId()}", [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->decode()['hasStructurePhoto'], 'a photo-less version must not offer the restore');
    }

    /**
     * The Schedule API exposes `isLiveContext` (★) — true only for the version the
     * season points at as its loaded context, so the star tracks the loaded
     * context regardless of which version is being viewed.
     */
    public function testScheduleApiExposesIsLiveContext(): void
    {
        $live = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $other = $this->makeSchedule(ScheduleStatus::COMPLETED, null);
        $this->season->setLiveContextScheduleId($live->getId());
        $this->em->flush();

        $this->client->request('GET', "/api/schedules/{$live->getId()}", [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->decode()['isLiveContext'], 'the season-pointed version carries the ★');

        $this->client->request('GET', "/api/schedules/{$other->getId()}", [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->decode()['isLiveContext'], 'a non-pointed version is not the loaded context');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('Regen ' . $uid)->setSlug('regen-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('regen' . $uid . '@test.com')->setFirstName('R')->setLastName('G');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($user);
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
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }

    private function persistTeam(string $name): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('33333333-3333-3333-3333-333333333333')->setPriorityTierId(1)
            ->setName($name)->setSessionsPerWeek(1)->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }

    private function makeSchedule(ScheduleStatus $status, ?string $calendarEntryId): Schedule
    {
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName('V')->setStatus($status);
        // Prod links every version at creation ; sans ça, depuis C4 le site « socle ? »
        // du /regenerate-from lèverait sur une version sans plan (saison ou overlay).
        // lot D : pas de persist/flush ici — linkSeededSchedule pose le plan AVANT de
        // persister (schedule_plan_id NOT NULL) ; les appelants flushent ensuite.
        $this->linkSeededSchedule($schedule, $calendarEntryId);

        return $schedule;
    }

    /**
     * P4-34 — l'ancre d'un `*PeriodOverride` doit EXISTER (FK + garde 422 depuis
     * cette PR) : un uuid décoratif ne passe plus. On provisionne un vrai plan de
     * période, comme en production.
     */
    private function periodPlanId(): string
    {
        $plan = (new SchedulePlan)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setType(SchedulePlanType::HOLIDAY)->setName('Période de test')
            ->setStartDate(new DateTimeImmutable('2026-02-15'))->setEndDate(new DateTimeImmutable('2026-02-28'));
        $this->em->persist($plan);
        $this->em->flush();

        return $plan->getId();
    }
}
