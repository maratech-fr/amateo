<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\Fixture;
use App\Entity\ImplicitRuleSetting;
use App\Entity\Person;
use App\Entity\PlanTeam;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\EntityCascadeDeleter;
use App\Service\SchedulePlanProvisioner;
use App\Service\StructureRestorer;
use App\Service\StructureSnapshotter;
use App\Structure\PersonDirectory;
use App\Structure\PlaceDirectory;
use App\Tests\ComposesPlanTeamsTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * planning-versions D3 (§7.1 planning lifecycle + tenant isolation): restore the
 * club structure to a version's captured photo. Round-trip fidelity, original
 * ids preserved (graph stays consistent), and the calendar / other versions are
 * never touched.
 */
#[Group('phase1')]
#[Group('integration')]
final class StructureRestorerTest extends KernelTestCase
{
    use ComposesPlanTeamsTrait;
    use CreatesPeopleAndPlacesTrait;
    use ProvisionsPeriodPlanTrait;

    use TenantGucTrait;

    private EntityManagerInterface $em;

    private StructureSnapshotter $snapshotter;

    private StructureRestorer $restorer;

    public function testRestoreReplacesCurrentStructureWithThePhotoKeepingIds(): void
    {
        [$club, $season] = $this->seedClubSeason();
        // State A: 1 team + 1 venue + 1 permanent constraint. Capture it on V1.
        $teamA = $this->persistTeam($club, $season, 'SM1');
        $venueA = $this->newVenue($club->getId(), $season->getId(), 'Gymnase A');
        $constraintA = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('SM1 mardi')->setScope(ConstraintScope::TEAM)->setScopeTargetId($teamA->getId())
            ->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['days' => [2]]);
        $this->em->persist($venueA);
        $this->em->persist($constraintA);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $teamAId = $teamA->getId();

        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        // State B (current): delete SM1, add two other teams — "the structure changed".
        $this->em->remove($teamA);
        $this->em->remove($constraintA);
        $this->em->flush();
        $this->persistTeam($club, $season, 'SM2');
        $this->persistTeam($club, $season, 'SM3');
        $this->em->flush();
        self::assertCount(2, $this->em->getRepository(Team::class)->findBy(['seasonId' => $season->getId()]));

        // Restore V1's conditions.
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        // Back to state A: exactly SM1 (with its ORIGINAL id), the two added teams gone.
        $teams = $this->em->getRepository(Team::class)->findBy(['seasonId' => $season->getId()]);
        self::assertCount(1, $teams);
        self::assertSame('SM1', $teams[0]->getName());
        self::assertSame($teamAId, $teams[0]->getId(), 'the restored row keeps its original id so the graph stays consistent');
        // The permanent constraint came back and still targets the same team id.
        $constraints = $this->em->getRepository(Constraint::class)->findBy(['seasonId' => $season->getId()]);
        self::assertCount(1, $constraints);
        self::assertSame($teamAId, $constraints[0]->getScopeTargetId());
    }

    public function testRestoreLeavesTheCalendarAndOtherVersionsUntouched(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $this->persistTeam($club, $season, 'SM1');
        // A venue that IS part of the photo — its dated closure must survive.
        $venue = $this->newVenue($club->getId(), $season->getId(), 'Gym');
        $this->em->persist($venue);
        $this->em->flush();
        $v1 = $this->makeSchedule($club, $season);
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable('2026-02-01'))->setEndDate(new DateTimeImmutable('2026-02-15'));
        $this->em->persist($entry);
        // A dated closure on a venue present in the photo — a legit calendar row
        // that must survive the structure restore (only DANGLING refs are purged).
        $dated = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Fermeture')->setScope(ConstraintScope::FACILITY)->setScopeTargetId($venue->getId())
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig([])
            ->setCalendarEntryId($entry->getId());
        $this->em->persist($dated);
        $v2 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        // Calendar entry, its dated constraint, and BOTH version rows survive.
        self::assertNotNull($this->em->getRepository(CalendarEntry::class)->find($entry->getId()));
        self::assertNotNull($this->em->getRepository(Constraint::class)->find($dated->getId()), 'a dated constraint is calendar, not structure');
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($v1->getId()));
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($v2->getId()));
    }

    public function testRestorePurgesDatedRefsToEntitiesAbsentFromThePhoto(): void
    {
        [$club, $season] = $this->seedClubSeason();
        // Photo A: no team. Capture on V1.
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        // Later: a team is created + a dated constraint targets it.
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setTitle('P')
            ->setStartDate(new DateTimeImmutable('2026-02-01'))->setEndDate(new DateTimeImmutable('2026-02-15'));
        $this->em->persist($entry);
        $newTeam = $this->persistTeam($club, $season, 'PostSnap');
        $this->em->flush();
        $ghost = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Ghost')->setScope(ConstraintScope::TEAM)->setScopeTargetId($newTeam->getId())
            ->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::HARD)->setConfig([])
            ->setCalendarEntryId($entry->getId());
        $this->em->persist($ghost);
        $this->em->flush();
        $ghostId = $ghost->getId();

        // Restore V1 (team gone) → the dated constraint that targeted it is a
        // ghost and gets purged; the calendar entry itself stays.
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();
        self::assertNull($this->em->getRepository(Constraint::class)->find($ghostId), 'a dated constraint targeting a now-absent team is purged');
        self::assertNotNull($this->em->getRepository(CalendarEntry::class)->find($entry->getId()));
    }

    public function testRestorePurgesConstraintPeriodOverrideWhosePermanentConstraintIsGone(): void
    {
        [$club, $season] = $this->seedClubSeason();
        // Photo A: no permanent constraint. Capture on V1.
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        // Later: a permanent constraint, a closure period, and an override disabling it.
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)->setTitle('Fermeture')
            ->setStartDate(new DateTimeImmutable('2026-02-01'))->setEndDate(new DateTimeImmutable('2026-02-15'));
        $this->em->persist($entry);
        $permanent = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Perm')->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::TIME)->setRuleType(ConstraintRuleType::HARD)->setConfig([]);
        $this->em->persist($permanent);
        $this->em->flush();
        $override = (new ConstraintPeriodOverride)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSchedulePlanId($this->planIdOf($entry))->setConstraintId($permanent->getId())->setIsActive(false);
        $this->em->persist($override);
        $this->em->flush();
        $overrideId = $override->getId();

        // Restore V1 (permanent constraint gone) → the override that referenced it is a
        // ghost and gets purged; the calendar entry itself stays.
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();
        self::assertNull($this->em->getRepository(ConstraintPeriodOverride::class)->find($overrideId), 'an override whose permanent constraint is now absent is purged');
        self::assertNotNull($this->em->getRepository(CalendarEntry::class)->find($entry->getId()));
    }

    /**
     * P2-52 (RMM-10) — L'EXPLORATION NE DÉTRUIT PLUS. Le restore ne DÉPOINTE plus un match dont
     * le gymnase est absent de la photo : le pointeur PENDOUILLE, transitoire et assumé. Le
     * scénario fondateur, joué : charger une version qui ignore le gymnase LAISSE le match
     * intact, et recharger une version qui LE contient rend le pointeur valide de nouveau —
     * l'essai ne coûte rien. (La VALIDATION, elle, est la gâchette du dépointage — pas ce test.).
     */
    public function testRestoreNoLongerUnplacesAMatchWhoseVenueIsAbsentFromThePhoto(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'SM1');
        $this->em->flush();

        // Photo « sans gymnase » (V1) : capturée AVANT que le gymnase existe.
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        // Puis : un gymnase et un match domicile DÉCLARÉ posé dessus, avec son heure.
        $venue = $this->newVenue($club->getId(), $season->getId(), 'Gymnase X');
        $this->em->persist($venue);
        $this->em->flush();
        $venueId = $venue->getId();
        $fixture = (new Fixture)->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId($team->getId())
            ->setMatchDate(new DateTimeImmutable('2026-01-10'))->setHomeAway(FixtureHomeAway::HOME)->setOpponentLabel('Adversaire')
            ->setStatus(FixtureStatus::SUBMITTED, new DateTimeImmutable)->setVenueId($venueId)->setKickoffTime(new DateTimeImmutable('15:30'));
        $this->em->persist($fixture);
        // Photo « avec gymnase » (V2), pour la deuxième moitié du scénario.
        $v2 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $fixtureId = $fixture->getId();
        $this->snapshotter->store($v2, $this->snapshotter->serialize($club->getId(), $season->getId()));

        // ── Charger V1 (sans le gymnase) : le gymnase est balayé, le match SURVIT INTACT.
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();
        self::assertNull($this->em->getRepository(Venue::class)->find($venueId), 'le gymnase absent de la photo est balayé');
        $afterV1 = $this->em->getRepository(Fixture::class)->find($fixtureId);
        self::assertNotNull($afterV1);
        self::assertSame($venueId, $afterV1->getVenueId(), 'le pointeur pendouille — le restore ne dépointe plus');
        self::assertSame(FixtureStatus::SUBMITTED, $afterV1->getStatus(), 'le statut ne change pas : l\'exploration ne détruit rien');
        self::assertNull($afterV1->getUnplacedReason(), 'aucune raison venue_lost : ce n\'est pas la gâchette');
        self::assertNotNull($afterV1->getKickoffTime(), 'l\'heure est conservée');

        // ── Recharger V2 (avec le gymnase) : le pointeur redevient valide, tout est intact.
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v2));
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Venue::class)->find($venueId), 'le gymnase revient avec son id d\'origine');
        $afterV2 = $this->em->getRepository(Fixture::class)->find($fixtureId);
        self::assertNotNull($afterV2);
        self::assertSame($venueId, $afterV2->getVenueId(), 'le match retrouve un pointeur valide');
        self::assertSame(FixtureStatus::SUBMITTED, $afterV2->getStatus());
        self::assertNull($afterV2->getUnplacedReason());
    }

    public function testLoadingRecreatesTheRecipientsTokenAndKeepsTheMark(): void
    {
        // « Charger » supprime les coachs : la clé étrangère emporte les jetons. La règle les recrée.
        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'U11M1');
        $maxime = $this->newCoach($club->getId(), $season->getId(), 'Maxime', 'Durand');
        $julie = $this->newCoach($club->getId(), $season->getId(), 'Julie', 'Petit');
        $this->em->persist($maxime);
        $this->em->persist($julie);
        $this->em->flush();
        $this->em->persist((new TeamCoach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setCoachId($maxime->getId())->setRole(TeamCoachRole::MAIN)->setCreatedAt(new DateTimeImmutable('2025-09-01')));
        $this->em->persist((new TeamCoach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setCoachId($julie->getId())->setRole(TeamCoachRole::MAIN)->setCreatedAt(new DateTimeImmutable('2025-09-02'))->setWishRecipient(true));
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Pâques')
            ->setStartDate(new DateTimeImmutable('2026-04-06'))->setEndDate(new DateTimeImmutable('2026-04-19'));
        $this->em->persist($entry);
        $campaign = (new CoachWishCampaign)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setCalendarEntryId($entry->getId())->setDeadline(new DateTimeImmutable('2030-01-01'))
            ->setWeeks(['2026-04-06'])->setTeamIds([$team->getId()]);
        $this->em->persist($campaign);
        $this->em->persist((new CoachWishToken)->setCampaignId($campaign->getId())->setCoachId($julie->getId())->setClubId($club->getId())->setTeamIds([$team->getId()]));
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        $link = $this->em->getRepository(TeamCoach::class)->findOneBy(['coachId' => $julie->getId()]);
        self::assertTrue($link?->isWishRecipient(), 'le choix du gestionnaire revient avec la photo');
        $token = $this->em->getRepository(CoachWishToken::class)->findOneBy(['campaignId' => $campaign->getId(), 'coachId' => $julie->getId()]);
        self::assertSame([$team->getId()], $token?->getTeamIds(), 'le destinataire a de nouveau un jeton dans la collecte ouverte');
        self::assertNull($this->em->getRepository(CoachWishToken::class)->findOneBy(['campaignId' => $campaign->getId(), 'coachId' => $maxime->getId()]));
    }

    /**
     * Charger une version où Karim n'existe pas efface sa ligne d'absence, comme sa suppression :
     * celle d'Anna et les deux doléances restent. Filets voisins : suppression directe
     * (`Security/RgpdExportTest::testClubExportCarriesNoStaffLineOfADeletedCoach`), bornes club et
     * saison (`Security/RgpdExportTest::testLoading*`).
     */
    public function testLoadingErasesTheStaffLineOfACoachAbsentFromThePhoto(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'U11M1');
        $partner = $this->persistTeam($club, $season, 'U13M1');
        $anna = $this->persistCoach($club, $season, 'Anna');
        $entry = $this->persistHoliday($club, $season);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        $karim = $this->persistCoach($club, $season, 'Karim');
        $this->em->flush();
        $shared = $this->persistWish($club, $season, $entry, $team, [
            ['coachId' => $anna->getId(), 'days' => [1]],
            ['coachId' => $karim->getId(), 'days' => [4]],
        ]);
        $karimOnly = $this->persistWish($club, $season, $entry, $partner, [['coachId' => $karim->getId(), 'days' => [2]]]);
        $this->em->flush();

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertNull($this->em->getRepository(Coach::class)->find($karim->getId()), 'la photo ne porte pas Karim');
        self::assertSame([['coachId' => $anna->getId(), 'days' => [1]]], $this->staffLinesOf($shared), 'la ligne de Karim part, celle d\'Anna reste à l\'identique');
        self::assertSame([], $this->staffLinesOf($karimOnly), 'la doléance survit à sa dernière ligne, avec une liste vide');
    }

    /**
     * « Charger » supprime TOUS les coachs avant de réinsérer la photo : un coach présent dans la
     * photo n'est pas un coach retiré, sa ligne reste.
     */
    public function testLoadingKeepsTheStaffLineOfACoachPresentInThePhoto(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'U11M1');
        $karim = $this->persistCoach($club, $season, 'Karim');
        $entry = $this->persistHoliday($club, $season);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));
        $wish = $this->persistWish($club, $season, $entry, $team, [['coachId' => $karim->getId(), 'days' => [4]]]);
        $this->em->flush();

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertSame([['coachId' => $karim->getId(), 'days' => [4]]], $this->staffLinesOf($wish));
    }

    /** Un coach retiré par un chargement puis ramené par un autre revient SANS sa ligne d'absence : elle ne ressuscite pas. */
    public function testACoachBroughtBackByALaterLoadReturnsWithoutHisStaffLine(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'U11M1');
        $entry = $this->persistHoliday($club, $season);
        $withoutKarim = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($withoutKarim, $this->snapshotter->serialize($club->getId(), $season->getId()));
        $karim = $this->persistCoach($club, $season, 'Karim');
        $withKarim = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($withKarim, $this->snapshotter->serialize($club->getId(), $season->getId()));
        $wish = $this->persistWish($club, $season, $entry, $team, [['coachId' => $karim->getId(), 'days' => [4]]]);
        $this->em->flush();

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($withoutKarim));
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($withKarim));
        $this->em->clear();

        self::assertNotNull($this->em->getRepository(Coach::class)->find($karim->getId()), 'la seconde version ramène Karim');
        self::assertSame([], $this->staffLinesOf($wish), 'sa ligne effacée ne revient pas avec lui');
    }

    /**
     * Lot 4, PR 3a (ligne 15 du plan, a) : « Charger » une version du socle rend à chaque équipe les séances et le plafond
     * par jour de la photo, sur sa ligne du plan de saison — la valeur saisie depuis s'en va.
     * (Remplace le cas d'avant le lot où le plafond vivait sur l'équipe et une photo sans le champ donnait 1 : les photos
     * sont converties par la migration, `Lot4MigrationChainTest::testTheConversionMovesThePhotoTeamSettingsOntoTheSoclePlan`.).
     */
    public function testLoadingGivesEachTeamBackTheSessionsAndDailyCapOfThePhoto(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $seasonPlan = $this->seasonPlanIdOf($season);
        $team = $this->persistTeam($club, $season, 'SM1');
        $this->em->flush();
        $this->setSocleTeam($season->getId(), $team->getId(), 2, 2);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        $this->teamLine($seasonPlan, $team->getId())->setSessionsPerWeek(3)->setMaxSessionsPerDay(3);
        $this->em->flush();
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertSame(['participates' => true, 'sessionsPerWeek' => 2, 'maxSessionsPerDay' => 2, 'source' => null], $this->lineRow($seasonPlan, $team->getId()), 'les séances et le plafond de la photo reviennent sur la ligne du socle');
    }

    /**
     * Lot 4, PR 3a : une photo restée à l'ancien format (séances sur les lignes `Team`, aucune ligne `PlanTeam`) est
     * refusée, et rien de la structure n'est effacé : la conversion des photos a échoué, la charger laisserait le socle
     * sans ses séances.
     */
    public function testAPhotoWhoseTeamsStillCarryTheirSessionsIsRefused(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'SM1');
        $this->em->flush();
        $this->setSocleTeam($season->getId(), $team->getId(), 2);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $photo = $this->snapshotter->serialize($club->getId(), $season->getId());
        self::assertNotSame([], $photo['PlanTeam'] ?? [], 'témoin : la photo d\'aujourd\'hui porte les lignes du socle');
        unset($photo['PlanTeam']);
        $photo['Team'][0]['sessionsPerWeek'] = 2;
        $photo['Team'][0]['maxSessionsPerDay'] = 1;
        $this->snapshotter->store($v1, $photo);

        try {
            $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
            self::fail('une photo à l\'ancien format doit être refusée');
        } catch (ConflictHttpException) {
        }
        $this->em->clear();

        self::assertNotNull($this->em->getRepository(Team::class)->find($team->getId()), 'les équipes ne sont pas effacées');
        self::assertSame(2, $this->lineRow($this->seasonPlanIdOf($season), $team->getId())['sessionsPerWeek'] ?? null, 'la ligne du socle est intacte');
    }

    /**
     * Lot 4, PR 3a (ligne 15, b) : la ligne du socle réinsérée depuis la photo n'est pas doublée par la porte qui pose
     * les lignes manquantes — une ligne par plan et par équipe, après le chargement comme avant.
     */
    public function testTheReinsertedSocleLineIsNotDoubled(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'SM1');
        $this->em->flush();
        $this->setSocleTeam($season->getId(), $team->getId(), 2);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));
        $holiday = $this->persistHoliday($club, $season);
        $this->planIdOf($holiday);
        $this->layTeamLines($season->getId());
        self::assertSame(2, $this->lineCountOf($team->getId()), 'témoin : une ligne au socle, une dans les vacances');

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertSame(2, $this->lineCountOf($team->getId()), 'toujours une ligne par plan : celle du socle n\'est pas doublée');
    }

    /**
     * Lot 4, PR 3a (ligne 15, c) : une équipe supprimée depuis la photo, ramenée par « Charger », retrouve sa ligne dans
     * chaque plan de période, qui participe, aux valeurs de son socle ramené.
     */
    public function testATeamBroughtBackByLoadingGetsItsLineInEveryPeriodPlan(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'U13 F');
        $this->em->flush();
        $teamId = $team->getId();
        $this->setSocleTeam($season->getId(), $teamId, 3, 2);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));
        $holiday = $this->persistHoliday($club, $season);
        $holidayPlan = $this->planIdOf($holiday);
        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfTeam($team);
        $this->em->remove($team);
        $this->em->flush();
        self::assertSame(0, $this->lineCountOf($teamId), 'témoin : supprimée, elle n\'a plus aucune ligne');

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertSame(['participates' => true, 'sessionsPerWeek' => 3, 'maxSessionsPerDay' => 2, 'source' => null], $this->lineRow($holidayPlan, $teamId), 'ramenée, elle a sa ligne dans les vacances, aux valeurs de son socle');
        self::assertSame(2, $this->lineCountOf($teamId), 'une ligne au socle, une dans les vacances');
    }

    /**
     * Lot 4, PR 3a (ligne 15, d) : une équipe créée après la photo, retirée par « Charger », perd ses lignes dans chaque
     * plan, période comprise — aucune ligne ne survit à son équipe.
     */
    public function testATeamRemovedByLoadingLosesItsLinesInEveryPlan(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));
        $holiday = $this->persistHoliday($club, $season);
        $holidayPlan = $this->planIdOf($holiday);
        $team = $this->persistTeam($club, $season, 'Créée depuis');
        $this->em->flush();
        $teamId = $team->getId();
        $this->setSocleTeam($season->getId(), $teamId, 2);
        $this->teamLine($holidayPlan, $teamId)->setParticipating(false);
        $this->em->flush();
        self::assertSame(2, $this->lineCountOf($teamId), 'témoin : une ligne au socle, une (réglée) dans les vacances');

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertNull($this->em->getRepository(Team::class)->find($teamId), 'la photo ne porte pas l\'équipe');
        self::assertSame(0, $this->lineCountOf($teamId), 'ses lignes partent avec elle, celle des vacances comprise');
    }

    /**
     * Charger une version dont la photo a été prise quand les étiquettes s'enregistraient encore (la photo porte la clé
     * `TeamTagAssignment`) réussit après la suppression des deux tables : la clé, que plus rien ne sait relire, est ignorée,
     * et le reste de la photo revient. Les deux photos de production d'avant le lot conception portent cette clé.
     */
    public function testLoadingAnOlderPhotoThatStillCarriesStoredTagsSucceedsOnceTheTablesAreGone(): void
    {
        // Témoin : le cas ne prouve quelque chose qu'une fois l'entité et les deux tables supprimées ; tant qu'elles
        // existent, la clé est encore connue du restaurateur et le chargement réussirait pour une autre raison.
        self::assertFalse(class_exists('App\Entity\TeamTagAssignment'), 'l\'entité des rattachements d\'étiquettes est supprimée');
        self::assertFalse(class_exists('App\Entity\TeamTag'), 'l\'entité des étiquettes est supprimée');
        foreach (['team_tag', 'team_tag_assignment'] as $table) {
            self::assertNull($this->em->getConnection()->fetchOne('SELECT to_regclass(?)', ['public.' . $table]), \sprintf('la table %s n\'existe plus', $table));
        }

        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'U13 F');
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $teamId = $team->getId();
        $photo = $this->snapshotter->serialize($club->getId(), $season->getId());
        $photo['TeamTagAssignment'] = [[
            'id' => '7a900000-0000-4000-8000-000000000001',
            'version' => 1,
            'createdAt' => '2026-09-01T10:00:00+00:00',
            'updatedAt' => '2026-09-01T10:00:00+00:00',
            'clubId' => $club->getId(),
            'seasonId' => $season->getId(),
            'teamId' => $teamId,
            'tagId' => '7a900000-0000-4000-8000-000000000002',
        ]];
        $this->snapshotter->store($v1, $photo);

        $this->em->remove($team);
        $this->em->flush();
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertSame(['U13 F'], array_map(
            static fn (Team $t): string => $t->getName(),
            $this->em->getRepository(Team::class)->findBy(['seasonId' => $season->getId()]),
        ), 'l\'équipe de la photo revient, la clé des étiquettes enregistrées est ignorée');
        self::assertNotNull($this->em->getRepository(Team::class)->find($teamId), 'avec son identifiant d\'origine');
    }

    /**
     * Décision 1 du lot 3 (A) : supprimer un coach garde sa fiche au club ; « Charger » une version où il
     * entraînait le ramène sur cette fiche, même identifiant, nom et téléphone compris, avec ses liens.
     */
    public function testLoadingBringsBackADeletedCoachOnHisRecord(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'U13M1');
        $paul = $this->newCoach($club->getId(), $season->getId(), 'Paul', 'Martin');
        $this->personOf($paul)->setPhone('0601020304');
        $this->em->flush();
        $link = $this->persistLink($club, $season, $team, $paul);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));
        [$paulId, $personId] = [$paul->getId(), $paul->getPersonId()];

        $this->em->remove($link);
        $this->em->remove($paul);
        $this->em->flush();
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        $back = $this->em->getRepository(Coach::class)->find($paulId);
        self::assertInstanceOf(Coach::class, $back, 'le coach supprimé revient avec son identifiant');
        self::assertSame($personId, $back->getPersonId(), 'il revient sur sa fiche, jamais sur une copie');
        $identity = self::getContainer()->get(PersonDirectory::class)->of($back);
        self::assertSame(['Paul Martin', '0601020304'], [$identity->fullName(), $identity->phone], 'nom et téléphone reviennent avec lui');
        self::assertNotNull($this->em->getRepository(TeamCoach::class)->findOneBy(['coachId' => $paulId, 'teamId' => $team->getId()]), 'et son lien d\'équipe');
    }

    /** Un coach ajouté après la version quitte la saison au chargement ; sa fiche reste au club (décision 1, A). */
    public function testLoadingRemovesACoachAddedSinceAndKeepsHisRecord(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));
        $karim = $this->newCoach($club->getId(), $season->getId(), 'Karim', 'Benali');
        $this->em->flush();
        [$karimId, $personId] = [$karim->getId(), $karim->getPersonId()];

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertNull($this->em->getRepository(Coach::class)->find($karimId), 'le coach ajouté depuis quitte la saison');
        self::assertNotNull($this->em->getRepository(Person::class)->find($personId), 'sa fiche reste au club jusqu\'à la purge de la saison');
    }

    /**
     * Une ligne de photo dont la personne n'existe plus (effacée) n'est pas réinsérée, ni ses liens
     * d'équipe, ni ses règles ; la recherche de la fiche est bornée par le club : une ligne qui
     * pointerait la fiche d'un autre club n'est pas réinsérée non plus (abus). Le reste revient.
     */
    public function testLoadingNeverBringsBackACoachWhosePersonIsGoneNorHisLinksNorHisRules(): void
    {
        $uid = uniqid('', true);
        $otherClub = (new Club)->setName('Autre ' . $uid)->setSlug('autre-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($otherClub);
        $this->em->flush();
        $this->scopeGucToClub($otherClub->getId());
        $foreign = $this->newCoach($otherClub->getId(), '44444444-4444-4444-8444-444444444444', 'Inès', 'Durand');
        $this->em->flush();
        $foreignPersonId = $foreign->getPersonId();

        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'U13M1');
        $paul = $this->newCoach($club->getId(), $season->getId(), 'Paul', 'Martin');
        $anna = $this->newCoach($club->getId(), $season->getId(), 'Anna', 'Petit');
        $this->em->flush();
        $paulLink = $this->persistLink($club, $season, $team, $paul);
        $this->persistLink($club, $season, $team, $anna);
        $paulRule = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Paul Martin · indispo lundi')->setScope(ConstraintScope::COACH)->setScopeTargetId($paul->getId())
            ->setFamily(ConstraintFamily::COACH_AVAILABILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['unavailableDays' => [1]]);
        $this->em->persist($paulRule);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $photo = $this->snapshotter->serialize($club->getId(), $season->getId());
        $forgedId = '55555555-5555-4555-8555-555555555555';
        $forged = $photo['Coach'][0];
        $forged['id'] = $forgedId;
        $forged['personId'] = $foreignPersonId;
        $photo['Coach'][] = $forged;
        $this->snapshotter->store($v1, $photo);
        [$paulId, $annaId, $paulPerson] = [$paul->getId(), $anna->getId(), $this->personOf($paul)];

        $this->em->remove($paulRule);
        $this->em->remove($paulLink);
        $this->em->remove($paul);
        $this->em->flush();
        $this->em->remove($paulPerson);
        $this->em->flush();
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertNull($this->em->getRepository(Coach::class)->find($paulId), 'une personne disparue ne revient pas');
        self::assertNull($this->em->getRepository(TeamCoach::class)->findOneBy(['coachId' => $paulId]), 'ni ses liens d\'équipe');
        self::assertSame([], $this->em->getRepository(Constraint::class)->findBy(['seasonId' => $season->getId(), 'scopeTargetId' => $paulId]), 'ni ses règles');
        self::assertNull($this->em->getRepository(Coach::class)->find($forgedId), 'une ligne qui pointe la fiche d\'un autre club n\'est pas réinsérée');
        self::assertNotNull($this->em->getRepository(Coach::class)->find($annaId), 'témoin : le reste de la photo revient');
        self::assertNotNull($this->em->getRepository(TeamCoach::class)->findOneBy(['coachId' => $annaId]), 'témoin : avec ses liens');
    }

    /**
     * Lot 3, PR 4 : supprimer un gymnase garde son lieu au club (même règle que la fiche d'un coach) ;
     * « Charger » une version où il servait le ramène sur ce lieu, même identifiant, adresse et position
     * comprises — l'adresse n'est plus dans la photo, elle est relue sur le lieu.
     */
    public function testLoadingBringsBackADeletedVenueWithItsAddress(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $nord = $this->newVenue($club->getId(), $season->getId(), 'Gymnase Nord');
        $this->placeOf($nord)->setAddress('12 rue du Nord, 69100 Villeurbanne')->setLatitude('45.7700000')->setLongitude('4.8800000');
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));
        [$nordId, $placeId] = [$nord->getId(), $nord->getPlaceId()];

        $this->em->remove($nord);
        $this->em->flush();
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        $back = $this->em->getRepository(Venue::class)->find($nordId);
        self::assertInstanceOf(Venue::class, $back, 'le gymnase supprimé revient avec son identifiant');
        self::assertSame($placeId, $back->getPlaceId(), 'il revient sur son lieu, jamais sur une copie');
        $place = self::getContainer()->get(PlaceDirectory::class)->of($back);
        self::assertSame(
            ['12 rue du Nord, 69100 Villeurbanne', '45.7700000', '4.8800000'],
            [$place->getAddress(), $place->getLatitude(), $place->getLongitude()],
            'adresse et position reviennent avec lui',
        );
    }

    /**
     * Lot 3, PR 4 (ambiguïté 2) : « Charger » une version dont le lieu d'un gymnase a été balayé depuis
     * ramène le gymnase quand même, même identifiant, avec ses créneaux, sur un lieu NEUF du club, sans
     * adresse ni position (elles sont parties avec le lieu) : jamais un gymnase perdu. Une ligne de
     * photo qui désigne le lieu d'un autre club (abus) revient de même sur un lieu neuf du club, jamais
     * sur le lieu étranger.
     */
    public function testLoadingAVenueWhosePlaceIsGoneBringsItBackWithItsSlotsOnANewBlankPlace(): void
    {
        $uid = uniqid('', true);
        $otherClub = (new Club)->setName('Autre ' . $uid)->setSlug('autre-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($otherClub);
        $this->em->flush();
        $this->scopeGucToClub($otherClub->getId());
        $foreign = $this->newVenue($otherClub->getId(), '44444444-4444-4444-8444-444444444444', 'Salle étrangère');
        $this->em->flush();
        $foreignPlaceId = $foreign->getPlaceId();

        [$club, $season] = $this->seedClubSeason();
        $nord = $this->newVenue($club->getId(), $season->getId(), 'Gymnase Nord');
        $sud = $this->newVenue($club->getId(), $season->getId(), 'Gymnase Sud');
        $this->em->flush();
        $this->placeOf($nord)->setAddress('12 rue du Nord, 69100 Villeurbanne')->setLatitude('45.7700000')->setLongitude('4.8800000');
        $slot = (new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $season->getId()))->setVenueId($nord->getId())
            ->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1);
        $this->em->persist($slot);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $photo = $this->snapshotter->serialize($club->getId(), $season->getId());
        foreach ($photo['Venue'] as $i => $row) {
            if ($sud->getId() === $row['id']) {
                $photo['Venue'][$i]['placeId'] = $foreignPlaceId;
            }
        }
        $this->snapshotter->store($v1, $photo);
        [$nordId, $sudId, $slotId, $oldPlace] = [$nord->getId(), $sud->getId(), $slot->getId(), $this->placeOf($nord)];

        $this->em->remove($slot);
        $this->em->remove($nord);
        $this->em->flush();
        $this->em->remove($oldPlace);
        $this->em->flush();
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        $back = $this->em->getRepository(Venue::class)->find($nordId);
        self::assertInstanceOf(Venue::class, $back, 'le gymnase dont le lieu est parti revient, même identifiant');
        self::assertNotSame($oldPlace->getId(), $back->getPlaceId(), 'sur un lieu neuf, le lieu balayé ne renaît pas');
        $place = self::getContainer()->get(PlaceDirectory::class)->of($back);
        self::assertSame([$club->getId(), null, null, null], [$place->getClubId(), $place->getAddress(), $place->getLatitude(), $place->getLongitude()], 'un lieu du club, sans adresse ni position');
        self::assertNotNull($this->em->getRepository(VenueTrainingSlot::class)->find($slotId), 'avec ses créneaux');

        $forged = $this->em->getRepository(Venue::class)->find($sudId);
        self::assertInstanceOf(Venue::class, $forged, 'la ligne qui désignait le lieu d\'un autre club revient aussi');
        self::assertNotSame($foreignPlaceId, $forged->getPlaceId(), 'jamais sur le lieu de l\'autre club');
        self::assertSame($club->getId(), self::getContainer()->get(PlaceDirectory::class)->of($forged)->getClubId(), 'sur un lieu neuf du club');
    }

    /**
     * Une photo dont les liens d'équipe et de joueur et les règles de portée coach citent un coach
     * absent de ses lignes `Coach` (photo nettoyée par la migration Version20261122140000, qui retire
     * la ligne d'un coach disparu sans toucher ses liens) ne réinsère aucun de ces liens orphelins
     * au « Charger » : aucune clé étrangère ne les refuserait. Jumeau, cité :
     * `Migration/PersonConversionMigrationTest::testPhotosDropTheLinksAndRulesOfACoachThatNoLongerExists`
     * (la photo nettoyée à la source) ; ici le filet du chargement, pour toute photo.
     */
    public function testLoadingNeverReinsertsLinksOrRulesOfACoachAbsentFromThePhoto(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $team = $this->persistTeam($club, $season, 'U13M1');
        $paul = $this->newCoach($club->getId(), $season->getId(), 'Paul', 'Martin');
        $anna = $this->newCoach($club->getId(), $season->getId(), 'Anna', 'Petit');
        $this->em->flush();
        $paulLink = $this->persistLink($club, $season, $team, $paul);
        $this->persistLink($club, $season, $team, $anna);
        $paulPlayer = (new CoachPlayerMembership)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setCoachId($paul->getId())->setTeamId($team->getId());
        $paulRule = (new Constraint)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Paul Martin · indispo lundi')->setScope(ConstraintScope::COACH)->setScopeTargetId($paul->getId())
            ->setFamily(ConstraintFamily::COACH_AVAILABILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['unavailableDays' => [1]]);
        $this->em->persist($paulPlayer);
        $this->em->persist($paulRule);
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        [$paulId, $annaId] = [$paul->getId(), $anna->getId()];
        $photo = $this->snapshotter->serialize($club->getId(), $season->getId());
        $photo['Coach'] = array_values(array_filter($photo['Coach'], static fn (array $row): bool => $paulId !== $row['id']));
        self::assertNotEmpty(array_filter($photo['TeamCoach'], static fn (array $row): bool => $paulId === $row['coachId']), 'témoin : la photo garde le lien du coach absent');
        $this->snapshotter->store($v1, $photo);

        $this->em->remove($paulRule);
        $this->em->remove($paulPlayer);
        $this->em->remove($paulLink);
        $this->em->remove($paul);
        $this->em->flush();
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertNull($this->em->getRepository(TeamCoach::class)->findOneBy(['coachId' => $paulId]), 'aucun lien d\'équipe vers un coach absent de la photo');
        self::assertNull($this->em->getRepository(CoachPlayerMembership::class)->findOneBy(['coachId' => $paulId]), 'aucun lien de joueur vers un coach absent de la photo');
        self::assertSame([], $this->em->getRepository(Constraint::class)->findBy(['seasonId' => $season->getId(), 'scopeTargetId' => $paulId]), 'aucune règle de portée coach vers un coach absent de la photo');
        self::assertNotNull($this->em->getRepository(TeamCoach::class)->findOneBy(['coachId' => $annaId]), 'témoin : le lien d\'un coach de la photo revient');
    }

    /**
     * Lot 4, PR 2 (a) : « Charger » une version du socle ramène sa grille et ses réservations, et elles portent le
     * plan de saison — la grille et les réservations modifiées depuis la photo s'en vont.
     */
    public function testLoadingBringsTheSocleGridAndReservationsBackOnTheSeasonPlan(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $seasonPlan = $this->seasonPlanIdOf($season);
        $team = $this->persistTeam($club, $season, 'SM1');
        $venue = $this->newVenue($club->getId(), $season->getId(), 'Gymnase Nord');
        $this->em->persist($venue);
        $this->em->flush();
        $photographed = $this->persistSlot($club, $season, $venue, $seasonPlan, 2, '18:00');
        $reservation = $this->persistReservation($club, $season, $team, $venue, $seasonPlan, 2, '18:00');
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        $this->em->remove($photographed);
        $this->em->remove($reservation);
        $this->persistSlot($club, $season, $venue, $seasonPlan, 4, '20:00');
        $this->em->flush();

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertSame([$photographed->getId()], $this->idsOf(VenueTrainingSlot::class, $season, $seasonPlan), 'la grille de la photo revient sur le plan de saison, le créneau ajouté depuis s\'en va');
        self::assertSame([$reservation->getId()], $this->idsOf(Reservation::class, $season, $seasonPlan), 'la réservation de la photo revient sur le plan de saison');
    }

    /**
     * Lot 4, PR 2 (b) : une photo prise avant le lot (ses lignes de socle à plan nul), convertie comme la migration
     * la convertit (plan nul → plan de saison), est exactement la photo que le code prend aujourd'hui, et se charge.
     */
    public function testAPhotoConvertedByTheMigrationLoads(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $seasonPlan = $this->seasonPlanIdOf($season);
        $team = $this->persistTeam($club, $season, 'SM1');
        $venue = $this->newVenue($club->getId(), $season->getId(), 'Gymnase Nord');
        $this->em->persist($venue);
        $this->em->flush();
        $slot = $this->persistSlot($club, $season, $venue, $seasonPlan, 2, '18:00');
        $reservation = $this->persistReservation($club, $season, $team, $venue, $seasonPlan, 2, '18:00');
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();

        $today = $this->snapshotter->serialize($club->getId(), $season->getId());
        self::assertNotSame([], $today['VenueTrainingSlot'] ?? [], 'la photo du socle porte sa grille');
        self::assertNotSame([], $today['Reservation'] ?? [], 'la photo du socle porte ses réservations');
        $beforeTheLot = $this->withPlanOnSocleRows($today, null);
        $converted = $this->withPlanOnSocleRows($beforeTheLot, $seasonPlan);
        self::assertSame($today, $converted, 'la photo convertie est celle que le code prend aujourd\'hui');
        $this->snapshotter->store($v1, $converted);

        $this->em->remove($slot);
        $this->em->remove($reservation);
        $this->em->flush();
        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertSame([$slot->getId()], $this->idsOf(VenueTrainingSlot::class, $season, $seasonPlan), 'la grille de la photo convertie revient');
        self::assertSame([$reservation->getId()], $this->idsOf(Reservation::class, $season, $seasonPlan), 'la réservation de la photo convertie revient');
    }

    /**
     * Lot 4, PR 2 (c) : « Charger » une version du socle ne change aucun réglage d'un plan de période — sa grille, ses
     * réservations, ses réglages d'équipes et ses règles de bien-être restent ligne pour ligne.
     * Angle jumeau, cité : `RegenerateFromVersionTest::testLoadVersionKeepsPeriodTrainingSlots` (le créneau de
     * période survit, par la route) ; ici toutes les familles du plan, ligne pour ligne.
     */
    public function testLoadingTheSocleChangesNoSettingOfAPeriodPlan(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $seasonPlan = $this->seasonPlanIdOf($season);
        $team = $this->persistTeam($club, $season, 'SM1');
        $venue = $this->newVenue($club->getId(), $season->getId(), 'Gymnase Nord');
        $this->em->persist($venue);
        $this->em->flush();
        $this->persistSlot($club, $season, $venue, $seasonPlan, 2, '18:00');
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $this->snapshotter->store($v1, $this->snapshotter->serialize($club->getId(), $season->getId()));

        $holiday = $this->persistHoliday($club, $season);
        $this->em->flush();
        $periodPlan = $this->planIdOf($holiday);
        $this->persistSlot($club, $season, $venue, $periodPlan, 3, '10:00');
        $this->persistReservation($club, $season, $team, $venue, $periodPlan, 3, '10:00');
        $this->layTeamLines($season->getId());
        $this->teamLine($periodPlan, $team->getId())->setSessionsPerWeek(1);
        $this->em->flush();
        $before = $this->periodPlanRows($periodPlan);
        self::assertNotSame([], $before[VenueTrainingSlot::class], 'témoin : le plan de période a sa grille');
        self::assertNotSame([], $before[PlanTeam::class], 'témoin : le plan de période a un réglage d\'équipe');

        $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
        $this->em->clear();

        self::assertSame($before, $this->periodPlanRows($periodPlan), 'aucun réglage du plan de période n\'a changé');
    }

    /**
     * Lot 4, PR 2 (d) : une photo restée à l'ancien format (une ligne de socle à plan nul) est refusée, et rien de la
     * structure n'est effacé : la conversion des photos a échoué, la charger écrirait une ligne sans plan.
     */
    public function testAPhotoInTheOldFormatIsRefused(): void
    {
        [$club, $season] = $this->seedClubSeason();
        $seasonPlan = $this->seasonPlanIdOf($season);
        $team = $this->persistTeam($club, $season, 'SM1');
        $venue = $this->newVenue($club->getId(), $season->getId(), 'Gymnase Nord');
        $this->em->persist($venue);
        $this->em->flush();
        $slot = $this->persistSlot($club, $season, $venue, $seasonPlan, 2, '18:00');
        $v1 = $this->makeSchedule($club, $season);
        $this->em->flush();
        $photo = $this->snapshotter->serialize($club->getId(), $season->getId());
        $photo['VenueTrainingSlot'] = [['schedulePlanId' => null] + $this->serializedSlot($photo, $slot->getId())];
        $this->snapshotter->store($v1, $photo);

        try {
            $this->restorer->apply($club->getId(), $season->getId(), $this->restorer->readSnapshot($v1));
            self::fail('une photo à l\'ancien format doit être refusée');
        } catch (ConflictHttpException) {
        }
        $this->em->clear();

        self::assertSame([$slot->getId()], $this->idsOf(VenueTrainingSlot::class, $season, $seasonPlan), 'la grille du socle n\'est pas effacée');
        self::assertNotNull($this->em->getRepository(Team::class)->find($team->getId()), 'les équipes ne sont pas effacées');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->snapshotter = self::getContainer()->get(StructureSnapshotter::class);
        $this->restorer = self::getContainer()->get(StructureRestorer::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function seedClubSeason(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Rest ' . $uid)->setSlug('rest-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $season->getId());

        return [$club, $season];
    }

    private function persistTeam(Club $club, Season $season, string $name): Team
    {
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSportCategoryId('33333333-3333-3333-3333-333333333333')->setPriorityTierId(1)
            ->setName($name)->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }

    private function persistCoach(Club $club, Season $season, string $firstName): Coach
    {
        $coach = $this->newCoach($club->getId(), $season->getId(), $firstName, 'Test');
        $this->em->persist($coach);

        return $coach;
    }

    private function persistLink(Club $club, Season $season, Team $team, Coach $coach): TeamCoach
    {
        $link = (new TeamCoach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN);
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }

    private function persistHoliday(Club $club, Season $season): CalendarEntry
    {
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Pâques')
            ->setStartDate(new DateTimeImmutable('2026-04-06'))->setEndDate(new DateTimeImmutable('2026-04-19'));
        $this->em->persist($entry);

        return $entry;
    }

    /** @param list<array{coachId: string, days: list<int>}> $lines */
    private function persistWish(Club $club, Season $season, CalendarEntry $entry, Team $team, array $lines): string
    {
        $wish = (new CoachWish)->setClubId($club->getId())->setSeasonId($season->getId())->setCalendarEntryId($entry->getId())
            ->setWeekStart(new DateTimeImmutable('2026-04-06'))->setTeamId($team->getId())->setSlotsWanted(1)
            ->setStaffUnavailability($lines);
        $this->em->persist($wish);

        return $wish->getId();
    }

    /** @return array<mixed> les lignes d'absence relues en base ; la doléance doit exister */
    private function staffLinesOf(string $wishId): array
    {
        $wish = $this->em->getRepository(CoachWish::class)->find($wishId);
        self::assertInstanceOf(CoachWish::class, $wish, 'la doléance survit au chargement');

        return $wish->getStaffUnavailability();
    }

    private function persistSlot(Club $club, Season $season, Venue $venue, string $planId, int $day, string $time): VenueTrainingSlot
    {
        $slot = (new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())->setVenueId($venue->getId())
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($time))->setDurationMinutes(90)->setCapacity(1)
            ->setSchedulePlanId($planId);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }

    private function persistReservation(Club $club, Season $season, Team $team, Venue $venue, string $planId, int $day, string $time): Reservation
    {
        $reservation = (new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId($team->getId())
            ->setVenueId($venue->getId())->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($time))->setDurationMinutes(90)
            ->setSchedulePlanId($planId);
        $this->em->persist($reservation);
        $this->em->flush();

        return $reservation;
    }

    /**
     * @param class-string<VenueTrainingSlot|Reservation> $entityClass
     *
     * @return list<string>
     */
    private function idsOf(string $entityClass, Season $season, string $planId): array
    {
        $ids = array_map(
            static fn (VenueTrainingSlot|Reservation $row): string => $row->getId(),
            $this->em->getRepository($entityClass)->findBy(['seasonId' => $season->getId(), 'schedulePlanId' => $planId]),
        );
        sort($ids);

        return $ids;
    }

    /**
     * La photo dont chaque ligne de socle (grille, réservation) porte `$planId`.
     *
     * @param array<string, list<array<string, mixed>>> $photo
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function withPlanOnSocleRows(array $photo, ?string $planId): array
    {
        foreach (['VenueTrainingSlot', 'Reservation'] as $family) {
            foreach ($photo[$family] ?? [] as $i => $row) {
                $photo[$family][$i]['schedulePlanId'] = $planId;
            }
        }

        return $photo;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $photo
     *
     * @return array<string, mixed>
     */
    private function serializedSlot(array $photo, string $slotId): array
    {
        foreach ($photo['VenueTrainingSlot'] ?? [] as $row) {
            if ($slotId === ($row['id'] ?? null)) {
                return $row;
            }
        }
        self::fail('la photo du socle porte le créneau');
    }

    /**
     * Toutes les lignes que porte un plan de période, champ par champ, par famille.
     *
     * @return array<class-string, list<array<string, mixed>>>
     */
    private function periodPlanRows(string $planId): array
    {
        $connection = $this->em->getConnection();
        $rows = [];
        foreach ([VenueTrainingSlot::class, Reservation::class, PlanTeam::class, ImplicitRuleSetting::class] as $class) {
            $table = $this->em->getClassMetadata($class)->getTableName();
            $rows[$class] = $connection->fetchAllAssociative(\sprintf('SELECT * FROM %s WHERE schedule_plan_id = ? ORDER BY id', $table), [$planId]);
        }

        return $rows;
    }

    private function makeSchedule(Club $club, Season $season): Schedule
    {
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('V')->setStatus(ScheduleStatus::COMPLETED)
            ->setSchedulePlanId($this->seasonPlanIdOf($season));
        $this->em->persist($schedule);
        // Numéroter : plusieurs versions du MÊME plan SEASON ne peuvent pas rester à
        // version_number 0 (uniq_schedule_plan_version) — linkSchedule les met à V1, V2, …
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);

        return $schedule;
    }
}
