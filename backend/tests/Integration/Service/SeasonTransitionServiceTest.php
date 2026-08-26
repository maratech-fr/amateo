<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueTrainingSlot;
use App\Entity\VenueTravelTime;
use App\Entity\VenueUnavailability;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Enum\TeamLinkIntensity;
use App\Enum\TeamLinkType;
use App\Enum\VenueTravelTimeSource;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SeasonAlreadyTransitionedException;
use App\Service\SeasonResolver;
use App\Service\SeasonTransitionService;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Season transition copy NR (spec transition-de-saison §2-3): the ENTRIES of
 * N are copied into N+1 with every cross-reference remapped, permanent
 * constraints only, lineage in parent_*_id, and NOTHING generated copied.
 */
#[Group('phase1')]
#[Group('integration')]
final class SeasonTransitionServiceTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private SeasonTransitionService $service;

    public function testCopiesTheFullEntryGraphWithRemappedReferences(): void
    {
        [$club, $season, $refs] = $this->createClubGraph();

        $target = $this->service->transition($season);

        // Season shell: dates +1 year, draft, gates null, lineage in transitionData.
        self::assertSame($club->getId(), $target->getClubId());
        self::assertSame($season->getStartDate()->modify('+1 year')->format('Y-m-d'), $target->getStartDate()->format('Y-m-d'));
        self::assertSame(SeasonStatus::DRAFT, $target->getStatus());
        self::assertNull($this->chosenPlanVersion($target), 'N+1 starts as an empty espace de travail');
        self::assertSame($season->getId(), $target->getTransitionData()['sourceSeasonId']);
        self::assertSame($target->getId(), $season->getTransitionData()['transitionedTo']);

        $counts = $target->getTransitionData()['counts'];
        self::assertSame(
            ['venues' => 2, 'venueTrainingSlots' => 1, 'coaches' => 2, 'teams' => 2, 'teamCoaches' => 1, 'coachPlayerMemberships' => 1, 'constraints' => 3],
            $counts,
        );

        // Venue lineage + slot remap.
        $newVenues = $this->em->getRepository(Venue::class)->findBy(['seasonId' => $target->getId()], ['name' => 'ASC']);
        self::assertSame([$refs['venueA']->getId(), $refs['venueB']->getId()], [$newVenues[0]->getParentVenueId(), $newVenues[1]->getParentVenueId()]);
        $newSlot = $this->em->getRepository(VenueTrainingSlot::class)->findOneBy(['seasonId' => $target->getId()]);
        self::assertSame($newVenues[0]->getId(), $newSlot?->getVenueId());

        // P1-4 PR B — match access windows follow (city-hall convention renews),
        // remapped to the copied venue; the dated unavailability does NOT.
        $newWindow = $this->em->getRepository(VenueMatchWindow::class)->findOneBy(['seasonId' => $target->getId()]);
        self::assertSame($newVenues[0]->getId(), $newWindow?->getVenueId());
        self::assertSame('14:00', $newWindow?->getStartTime()->format('H:i'));
        self::assertNull($this->em->getRepository(VenueUnavailability::class)->findOneBy(['seasonId' => $target->getId()]));

        // P1-4 PR C — habitude remappée (équipe + gymnase), passerelle remappée
        // des DEUX côtés et toujours normalisée (teamAId < teamBId).
        $newTeamsById = $this->em->getRepository(Team::class)->findBy(['seasonId' => $target->getId()]);
        $newTeamIds = array_map(static fn (Team $t): string => $t->getId(), $newTeamsById);
        $newHabit = $this->em->getRepository(TeamMatchHabit::class)->findOneBy(['seasonId' => $target->getId()]);
        self::assertNotNull($newHabit);
        self::assertContains($newHabit->getTeamId(), $newTeamIds);
        self::assertSame('15:30', $newHabit->getKickoffTime()->format('H:i'));
        self::assertSame($newVenues[0]->getId(), $newHabit->getVenueId());
        $newTeamLink = $this->em->getRepository(TeamLink::class)->findOneBy(['seasonId' => $target->getId()]);
        self::assertNotNull($newTeamLink);
        self::assertContains($newTeamLink->getTeamAId(), $newTeamIds);
        self::assertContains($newTeamLink->getTeamBId(), $newTeamIds);
        self::assertLessThan(0, strcasecmp($newTeamLink->getTeamAId(), $newTeamLink->getTeamBId()));
        // L'intensité côté entraînement suit la recopie (non-défaut : prouve le set).
        self::assertSame(TeamLinkIntensity::MANDATORY, $newTeamLink->getTrainingIntensity());

        // P2-53 RMM-8 — le barème de trajet suit, remappé aux gymnases copiés, minutes ET
        // source MANUAL préservées (falsifie l'oubli de setDriving*).
        $newTravel = $this->em->getRepository(VenueTravelTime::class)->findOneBy(['seasonId' => $target->getId()]);
        self::assertNotNull($newTravel);
        $newVenueIds = [$newVenues[0]->getId(), $newVenues[1]->getId()];
        self::assertContains($newTravel->getVenueAId(), $newVenueIds);
        self::assertContains($newTravel->getVenueBId(), $newVenueIds);
        self::assertLessThan(0, strcasecmp($newTravel->getVenueAId(), $newTravel->getVenueBId()));
        self::assertSame(22, $newTravel->getDrivingMinutes());
        self::assertSame(VenueTravelTimeSource::MANUAL, $newTravel->getDrivingSource());

        // Team: forcedVenueId remapped to the copied venue, name untouched.
        $newTeams = $this->em->getRepository(Team::class)->findBy(['seasonId' => $target->getId()], ['name' => 'ASC']);
        self::assertSame('SM1', $newTeams[0]->getName());
        self::assertSame($newVenues[0]->getId(), $newTeams[0]->getForcedVenueId());
        self::assertSame($refs['teamA']->getId(), $newTeams[0]->getParentTeamId());
        // Club-scoped referentials shared: same category/tier ids.
        self::assertSame($refs['teamA']->getSportCategoryId(), $newTeams[0]->getSportCategoryId());

        // Links remapped on BOTH ends.
        $newLink = $this->em->getRepository(TeamCoach::class)->findOneBy(['seasonId' => $target->getId()]);
        $newCoaches = $this->em->getRepository(Coach::class)->findBy(['seasonId' => $target->getId()], ['firstName' => 'ASC']);
        self::assertSame($newTeams[0]->getId(), $newLink?->getTeamId());
        self::assertSame($newCoaches[0]->getId(), $newLink?->getCoachId());
        $newMembership = $this->em->getRepository(CoachPlayerMembership::class)->findOneBy(['seasonId' => $target->getId()]);
        self::assertSame($newTeams[1]->getId(), $newMembership?->getTeamId());

        // Team tags are NOT copied by the transition — TeamTagSyncListener
        // re-derives the SYSTEM tags for the copied teams on its own, and
        // custom tags are intentionally left out (ephemeral pre-existing
        // behaviour). So the custom tag from N must NOT appear in N+1.
        $customInTarget = $this->em->getRepository(TeamTagAssignment::class)->findOneBy(['seasonId' => $target->getId(), 'tagId' => $refs['tag']->getId()]);
        self::assertNull($customInTarget);
    }

    /**
     * RMM-5 (P2-49) — les créneaux de match partagés (rotation A/B) suivent la saison, gymnase
     * ET membres remappés. Trois gardes : un membre non remappé est EXCLU ; un créneau < 2 après
     * remap n'est pas recopié ; un gymnase non remappé (référence pendante) n'est pas recopié —
     * la rotation EST le créneau, rien où l'ancrer.
     */
    public function testMatchSlotRotationsAreCopiedRemappedWithGuards(): void
    {
        [$club, $season, $refs] = $this->createClubGraph();
        $teamA = $refs['teamA'];
        $venueA = $refs['venueA'];
        $teamB = $this->em->getRepository(Team::class)->findOneBy(['seasonId' => $season->getId(), 'name' => 'U13']);
        self::assertNotNull($teamB);
        $bogusTeam = 'deadbeef-2222-4000-8000-000000000000';
        $bogusVenue = 'deadbeef-3333-4000-8000-000000000000';

        // R1 {teamA, teamB} → recopié (2 membres remappés).
        $this->seedRotation($club, $season, $venueA->getId(), 6, '20:30', [$teamA->getId(), $teamB->getId()]);
        // R2 {teamA, bogusTeam} → bogus exclu → 1 membre < 2 → NON recopié.
        $this->seedRotation($club, $season, $venueA->getId(), 7, '18:00', [$teamA->getId(), $bogusTeam]);
        // R3 {teamA, teamB} sur un gymnase pendant → NON recopié.
        $this->seedRotation($club, $season, $bogusVenue, 6, '20:30', [$teamA->getId(), $teamB->getId()]);
        // R4 {teamA, teamB, bogusTeam} → bogus exclu → 2 membres restent → recopié.
        $this->seedRotation($club, $season, $venueA->getId(), 6, '18:00', [$teamA->getId(), $teamB->getId(), $bogusTeam]);
        $this->em->flush();

        $target = $this->service->transition($season);

        $copied = $this->em->getRepository(MatchSlotRotation::class)->findBy(['seasonId' => $target->getId()], ['dayOfWeek' => 'ASC', 'kickoffTime' => 'ASC']);
        self::assertCount(2, $copied, 'seuls R1 et R4 sont recopiés (R2 tombe < 2, R3 a un gymnase pendant)');

        $newVenue = $this->em->getRepository(Venue::class)->findOneBy(['seasonId' => $target->getId(), 'name' => 'Gym A']);
        $newTeamIds = array_map(
            static fn (Team $t): string => $t->getId(),
            $this->em->getRepository(Team::class)->findBy(['seasonId' => $target->getId()]),
        );
        foreach ($copied as $rotation) {
            self::assertSame($newVenue?->getId(), $rotation->getVenueId(), 'le gymnase est remappé sur la copie N+1');
            $members = $this->em->getRepository(MatchSlotRotationTeam::class)->findBy(['rotationId' => $rotation->getId()], ['position' => 'ASC']);
            self::assertCount(2, $members, 'le membre pendant est exclu, les deux remappés restent');
            foreach ($members as $member) {
                self::assertContains($member->getTeamId(), $newTeamIds, 'chaque membre pointe une équipe recopiée');
                self::assertSame($target->getId(), $member->getSeasonId());
            }
        }
    }

    public function testConstraintsArePermanentOnlyWithRemappedTargets(): void
    {
        [, $season, $refs] = $this->createClubGraph();

        $target = $this->service->transition($season);

        $copied = $this->em->getRepository(Constraint::class)->findBy(['seasonId' => $target->getId()], ['name' => 'ASC']);
        // The dated constraint (calendarEntryId set) is NOT copied.
        self::assertCount(3, $copied);
        self::assertSame(['Coach indispo', 'Salle imposée', 'Salle interdite'], [$copied[0]->getName(), $copied[1]->getName(), $copied[2]->getName()]);

        $newCoach = $this->em->getRepository(Coach::class)->findOneBy(['seasonId' => $target->getId(), 'firstName' => 'Anna']);
        $newVenueB = $this->em->getRepository(Venue::class)->findOneBy(['seasonId' => $target->getId(), 'name' => 'Gym B']);

        // scopeTargetId remapped per scope; config id keys remapped too.
        self::assertSame($newCoach?->getId(), $copied[0]->getScopeTargetId());
        self::assertArrayNotHasKey('coachId', $copied[0]->getConfig(), 'La cible du coach est le scope (SEC-13), jamais le config.');
        self::assertSame($newVenueB?->getId(), $copied[2]->getConfig()['forbiddenVenueId']);
        // D-08 : `forcedVenueId` n'était PAS dans la liste de remap — la contrainte partait
        // en saison N+1 avec l'uuid du gymnase de l'ancienne saison, sans skip ni log.
        self::assertSame($newVenueB?->getId(), $copied[1]->getConfig()['forcedVenueId'], 'forcedVenueId doit être remappé comme les autres clés uuid.');
        // Lineage.
        self::assertSame($refs['coachConstraint']->getId(), $copied[0]->getParentConstraintId());
    }

    public function testDanglingConstraintReferencesAreSkippedNotPropagated(): void
    {
        [$club, $season] = $this->createClubGraph();
        // A constraint whose scope target no longer exists in N (deleted entity)
        // and one whose config id is dangling — both must NOT be copied.
        $ghost = new Constraint;
        $ghost->setClubId($club->getId());
        $ghost->setSeasonId($season->getId());
        $ghost->setName('Cible fantôme');
        $ghost->setScope(ConstraintScope::TEAM);
        $ghost->setScopeTargetId('deadbeef-0000-4000-8000-000000000000');
        $ghost->setFamily(ConstraintFamily::TIME);
        $ghost->setRuleType(ConstraintRuleType::HARD);
        $ghost->setConfig(['maxStartTime' => '19:30']);
        $this->em->persist($ghost);

        $ghostConfig = new Constraint;
        $ghostConfig->setClubId($club->getId());
        $ghostConfig->setSeasonId($season->getId());
        $ghostConfig->setName('Config fantôme');
        $ghostConfig->setScope(ConstraintScope::CLUB);
        $ghostConfig->setFamily(ConstraintFamily::FACILITY);
        $ghostConfig->setRuleType(ConstraintRuleType::PREFERRED);
        $ghostConfig->setConfig(['forbiddenVenueId' => 'deadbeef-1111-4000-8000-000000000000']);
        $this->em->persist($ghostConfig);
        $this->em->flush();

        $target = $this->service->transition($season);

        $copiedNames = array_map(
            static fn (Constraint $c): string => $c->getName(),
            $this->em->getRepository(Constraint::class)->findBy(['seasonId' => $target->getId()]),
        );
        self::assertNotContains('Cible fantôme', $copiedNames);
        self::assertNotContains('Config fantôme', $copiedNames);
        // The valid permanent constraints are still copied.
        self::assertContains('Coach indispo', $copiedNames);
    }

    public function testNothingGeneratedIsCopied(): void
    {
        [, $season] = $this->createClubGraph();

        $target = $this->service->transition($season);

        self::assertCount(0, $this->em->getRepository(Schedule::class)->findBy(['seasonId' => $target->getId()]));
    }

    public function testCanPrepareNextSeasonInJuneFromASettledCurrentSeason(): void
    {
        // Real anticipation flow (spec §1): mid-June, the current season's plan
        // points at a version; the manager prepares next season ahead of the rush.
        // The transition works and N+1 starts as an empty espace de travail, so
        // the cockpit gate makes it build its own plan.
        $club = $this->minimalClub();
        // Season 2025-26 (started Aug 2025) — current on 2026-06-01, and settled.
        $current = $this->createSeason($club, 2025);
        $this->settleSeasonPlan($current);

        $june1 = new DateTimeImmutable('2026-06-01');
        $target = $this->service->transition($current, $june1);

        self::assertSame(SeasonStatus::DRAFT, $target->getStatus());
        self::assertNull($this->chosenPlanVersion($target), 'N+1 must not inherit the pointer');
        // N+1 = the 2026-27 season-year.
        self::assertSame('2026-08-01', $target->getStartDate()->format('Y-m-d'));
        self::assertSame($current->getId(), $target->getTransitionData()['sourceSeasonId']);
    }

    public function testEnginePayloadOfTransitionedSeasonReferencesCopiedEntities(): void
    {
        // Constraint-semantics NR (§7.1): a constraint copied by the transition
        // must be HONOURED when generating N+1 — i.e. the engine payload built
        // for the target season references the COPIED entities, never season-N ids.
        [$club, $season, $refs] = $this->createClubGraph();

        $target = $this->service->transition($season);

        $builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $payload = $builder->buildForClubSeason($club->getId(), $target->getId());

        $newCoach = $this->em->getRepository(Coach::class)->findOneBy(['seasonId' => $target->getId(), 'firstName' => 'Anna']);
        $newTeams = $this->em->getRepository(Team::class)->findBy(['seasonId' => $target->getId()], ['name' => 'ASC']);

        $payloadTeamIds = array_column($payload['teams'], 'id');
        self::assertContains($newTeams[0]->getId(), $payloadTeamIds);
        self::assertNotContains($refs['teamA']->getId(), $payloadTeamIds);

        $coachConstraints = array_values(array_filter(
            $payload['constraints'],
            static fn (array $c): bool => 'Coach indispo' === ($c['name'] ?? null),
        ));
        self::assertNotEmpty($coachConstraints);
        self::assertSame($newCoach?->getId(), $coachConstraints[0]['scopeTargetId']);
        self::assertArrayNotHasKey('coachId', $coachConstraints[0]['config'] ?? [], 'SEC-13 : la cible du coach est le scope.');
    }

    public function testRerunReturnsConflictWithTheExistingSuccessor(): void
    {
        [, $season] = $this->createClubGraph();
        $target = $this->service->transition($season);

        try {
            $this->service->transition($season);
            self::fail('Expected SeasonAlreadyTransitionedException');
        } catch (SeasonAlreadyTransitionedException $e) {
            self::assertSame($target->getId(), $e->getExistingSeasonId());
        }
    }

    public function testNonCurrentSourceIsRefused(): void
    {
        [$club, $season] = $this->createClubGraph();
        // Add a PAST season and try to transition it: refused.
        $past = $this->createSeason($club, SeasonResolver::seasonYear($season->getStartDate()) - 1);
        $this->em->flush();

        $this->expectException(ConflictHttpException::class);
        $this->service->transition($past);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(SeasonTransitionService::class);
    }

    /**
     * One club, one current season, a full entry graph:
     * 2 venues (A with slot, B), 2 coaches (Anna, Bob), 2 teams (SM1 forced on
     * venue A + tagged, U13 with Bob as player), 1 team-coach link, 1
     * membership, 3 constraints (COACH scoped + config.coachId, FACILITY
     * config.forbiddenVenueId, 1 DATED excluded) and 1 generated Schedule.
     *
     * @return array{0: Club, 1: Season, 2: array<string, object>}
     */
    private function createClubGraph(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club transition');
        $club->setSlug('club-transition-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('TRA' . strtoupper(substr(md5($uid), 0, 10)));
        // P1-5 : en règle très loin — ces tests couvrent la copie, pas le gate.
        $club->setPaidSeasonYear(9999);
        $this->em->persist($club);

        $sport = new Sport;
        $sport->setName('Basketball ' . $uid);
        $sport->setSlug('basket-' . $uid);
        $this->em->persist($sport);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('Séniors ' . substr($uid, -4));
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }

        $season = $this->createSeason($club, SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $this->em->flush();

        $venueA = $this->venue($club, $season, 'Gym A');
        $venueB = $this->venue($club, $season, 'Gym B');

        // P2-53 RMM-8 — un barème de trajet A↔B, valeur MANUAL : la recopie N+1 doit le
        // remapper aux gymnases copiés ET préserver sa valeur/source (sinon régression muette).
        $travel = new VenueTravelTime;
        $travel->setClubId($club->getId());
        $travel->setSeasonId($season->getId());
        [$loV, $hiV] = strcasecmp($venueA->getId(), $venueB->getId()) > 0 ? [$venueB->getId(), $venueA->getId()] : [$venueA->getId(), $venueB->getId()];
        $travel->setVenueAId($loV);
        $travel->setVenueBId($hiV);
        $travel->setDrivingMinutes(22);
        $travel->setDrivingSource(VenueTravelTimeSource::MANUAL);
        $this->em->persist($travel);

        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venueA->getId());
        $slot->setDayOfWeek(2);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $this->em->persist($slot);

        // P1-4 PR B — capacité : la fenêtre match se recopie (convention mairie),
        // l'indisponibilité (fait daté one-shot) jamais.
        $window = new VenueMatchWindow;
        $window->setClubId($club->getId());
        $window->setSeasonId($season->getId());
        $window->setVenueId($venueA->getId());
        $window->setDayOfWeek(6);
        $window->setStartTime(new DateTimeImmutable('14:00'));
        $window->setEndTime(new DateTimeImmutable('22:00'));
        $this->em->persist($window);

        $unavailability = new VenueUnavailability;
        $unavailability->setClubId($club->getId());
        $unavailability->setSeasonId($season->getId());
        $unavailability->setVenueId($venueA->getId());
        $unavailability->setStartDate(new DateTimeImmutable('2027-02-04'));
        $unavailability->setEndDate(new DateTimeImmutable('2027-02-28'));
        $unavailability->setLabel('travaux');
        $this->em->persist($unavailability);

        $anna = $this->coach($club, $season, 'Anna');
        $bob = $this->coach($club, $season, 'Bob');

        $teamA = $this->team($club, $season, 'SM1', $category->getId(), (int) $tier->getId(), $venueA->getId());
        $teamB = $this->team($club, $season, 'U13', $category->getId(), (int) $tier->getId(), null);

        // P1-4 PR C — habitude (avec gymnase) + passerelle : recopiées remappées.
        $habit = new TeamMatchHabit;
        $habit->setClubId($club->getId());
        $habit->setSeasonId($season->getId());
        $habit->setTeamId($teamA->getId());
        $habit->setDayOfWeek(6);
        $habit->setKickoffTime(new DateTimeImmutable('15:30'));
        $habit->setVenueId($venueA->getId());
        $this->em->persist($habit);

        $teamLink = new TeamLink;
        $teamLink->setClubId($club->getId());
        $teamLink->setSeasonId($season->getId());
        $ids = [$teamA->getId(), $teamB->getId()];
        sort($ids);
        $teamLink->setTeamAId($ids[0]);
        $teamLink->setTeamBId($ids[1]);
        $teamLink->setLinkType(TeamLinkType::NOT_SIMULTANEOUS);
        // Intensité NON-DÉFAUT : la recopie N+1 doit la transporter (le test rougit si
        // SeasonTransitionService omet setTrainingIntensity — la copie retomberait à PREFERRED).
        $teamLink->setTrainingIntensity(TeamLinkIntensity::MANDATORY);
        $this->em->persist($teamLink);

        $link = new TeamCoach;
        $link->setClubId($club->getId());
        $link->setSeasonId($season->getId());
        $link->setTeamId($teamA->getId());
        $link->setCoachId($anna->getId());
        $link->setRole(TeamCoachRole::MAIN);
        $link->setIsRequired(true);
        $this->em->persist($link);

        $membership = new CoachPlayerMembership;
        $membership->setClubId($club->getId());
        $membership->setSeasonId($season->getId());
        $membership->setCoachId($bob->getId());
        $membership->setTeamId($teamB->getId());
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $tag = new TeamTag;
        $tag->setClubId($club->getId());
        $tag->setName('JEUNE-' . substr($uid, -4));
        $tag->setIsSystem(false);
        $this->em->persist($tag);
        $this->em->flush();

        $assignment = new TeamTagAssignment;
        // BCK-11 : tenant + RLS, une ligne sans club_id est refusée en base.
        $assignment->setClubId($teamA->getClubId());
        $assignment->setSeasonId($season->getId());
        $assignment->setTeamId($teamA->getId());
        $assignment->setTagId($tag->getId());
        $this->em->persist($assignment);

        $coachConstraint = new Constraint;
        $coachConstraint->setClubId($club->getId());
        $coachConstraint->setSeasonId($season->getId());
        $coachConstraint->setName('Coach indispo');
        $coachConstraint->setScope(ConstraintScope::COACH);
        $coachConstraint->setScopeTargetId($anna->getId());
        $coachConstraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $coachConstraint->setRuleType(ConstraintRuleType::HARD);
        // D-08 — le fixture portait `config.coachId`, clé SUPPRIMÉE par SEC-13 (doublon
        // exact du scope, refusée à l'écriture) : le test vérifiait le remap d'une clé qui
        // ne peut plus exister. La cible du coach, c'est le scope.
        $coachConstraint->setConfig(['unavailableDays' => [1]]);
        $this->em->persist($coachConstraint);

        $venueConstraint = new Constraint;
        $venueConstraint->setClubId($club->getId());
        $venueConstraint->setSeasonId($season->getId());
        $venueConstraint->setName('Salle interdite');
        $venueConstraint->setScope(ConstraintScope::TEAM);
        $venueConstraint->setScopeTargetId($teamA->getId());
        $venueConstraint->setFamily(ConstraintFamily::FACILITY);
        $venueConstraint->setRuleType(ConstraintRuleType::PREFERRED);
        $venueConstraint->setConfig(['forbiddenVenueId' => $venueB->getId()]);
        $this->em->persist($venueConstraint);

        // D-08 — la clé que la liste de remap manuscrite OUBLIAIT.
        $forcedConstraint = new Constraint;
        $forcedConstraint->setClubId($club->getId());
        $forcedConstraint->setSeasonId($season->getId());
        $forcedConstraint->setName('Salle imposée');
        $forcedConstraint->setScope(ConstraintScope::TEAM);
        $forcedConstraint->setScopeTargetId($teamA->getId());
        $forcedConstraint->setFamily(ConstraintFamily::FACILITY);
        $forcedConstraint->setRuleType(ConstraintRuleType::HARD);
        $forcedConstraint->setConfig(['forcedVenueId' => $venueB->getId()]);
        $this->em->persist($forcedConstraint);

        $dated = new Constraint;
        $dated->setClubId($club->getId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Fermeture datée');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setScopeTargetId($venueA->getId());
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setConfig(['type' => 'venue_closed', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10']);
        $dated->setCalendarEntryId('99999999-9999-4999-8999-999999999999');
        $this->em->persist($dated);

        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Plan N');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        // lot D : schedule_plan_id NOT NULL — le helper résout le plan SEASON, le POSE,
        // persiste et NUMÉROTE la version avant le flush (pas de persist brut sans plan).
        $this->linkSeededSchedule($schedule);

        $this->em->flush();

        return [$club, $season, [
            'venueA' => $venueA,
            'venueB' => $venueB,
            'teamA' => $teamA,
            'coachConstraint' => $coachConstraint,
            'tag' => $tag,
        ]];
    }

    /**
     * Un créneau de match partagé (rotation A/B) et ses membres ordonnés — seedés directement
     * (les references pendantes sont possibles au niveau entité : aucune FK).
     *
     * @param list<string> $teamIds
     */
    private function seedRotation(Club $club, Season $season, string $venueId, int $day, string $at, array $teamIds): void
    {
        $rotation = new MatchSlotRotation;
        $rotation->setClubId($club->getId());
        $rotation->setSeasonId($season->getId());
        $rotation->setVenueId($venueId);
        $rotation->setDayOfWeek($day);
        $rotation->setKickoffTime(new DateTimeImmutable($at));
        $this->em->persist($rotation);

        foreach (array_values($teamIds) as $position => $teamId) {
            $member = new MatchSlotRotationTeam;
            $member->setClubId($club->getId());
            $member->setSeasonId($season->getId());
            $member->setRotationId($rotation->getId());
            $member->setTeamId($teamId);
            $member->setPosition($position);
            $this->em->persist($member);
        }
    }

    private function minimalClub(): Club
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club juin');
        $club->setSlug('club-juin-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('JUN' . strtoupper(substr(md5($uid), 0, 10)));
        // P1-5 : ces tests couvrent la COPIE, pas le gate de paiement — club
        // réputé en règle très loin (le gate a ses propres tests, ApiTest).
        $club->setPaidSeasonYear(9999);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        return $club;
    }

    private function createSeason(Club $club, int $startYear): Season
    {
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $startYear);
        $season->setStartDate(new DateTimeImmutable($startYear . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($startYear + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        return $season;
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function coach(Club $club, Season $season, string $firstName): Coach
    {
        $coach = new Coach;
        $coach->setClubId($club->getId());
        $coach->setSeasonId($season->getId());
        $coach->setFirstName($firstName);
        $coach->setLastName('Test');
        $this->em->persist($coach);
        $this->em->flush();

        return $coach;
    }

    private function team(Club $club, Season $season, string $name, string $categoryId, int $tierId, ?string $forcedVenueId): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($categoryId);
        $team->setPriorityTierId($tierId);
        $team->setName($name);
        $team->setSessionsPerWeek(2);
        $team->setForcedVenueId($forcedVenueId);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }
}
