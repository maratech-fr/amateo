<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plans;

use App\Entity\CalendarEntry;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\TeamLevel;
use App\Plans\PlanStates;
use App\Tests\LoadsVersionDecorTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse (lot conception 5, PR 3, lignes 13, 15, 16 et 21 du plan) : « Charger » une version remet l'état de
 * travail de SON plan d'après la copie du froid de cette version, et rien d'autre. Il remet le froid des équipes et
 * des gymnases existants (rang, catégorie, niveau, « divisible ») sans créer ni supprimer d'équipe, de gymnase, de
 * coach ou de catégorie ; il refuse sans rien écrire si une équipe engagée changerait de niveau ; une référence
 * disparue depuis (équipe, gymnase, coach, catégorie) ne revient pas et ne laisse rien « en attente » ; il ne touche
 * aucun autre plan. Une règle qu'il retire emporte ses lignes de période, une règle qu'il ramène en reçoit une au
 * défaut ; un coach principal qu'il remet redevient destinataire de la collecte ouverte. Une retouche de planning met
 * la copie de la version chargée à jour pour ses seules lignes.
 *
 * Décor : {@see LoadsVersionDecorTrait} — les versions portent la copie prise par le vrai `PlanStates::capture`,
 * le geste est la route `POST /api/schedules/{id}/load`. Lecture en SQL sous le contexte du club.
 *
 * Angle jumeau, cité : `features/charger-une-version-ramene-son-etat.feature` (le geste de bout en bout, sur un
 * petit club généré : ce qui revient, ce qui ne bouge pas). Ce test prouve les cas nombreux que le plan lui confie.
 * Bloquant (step `Blocking suite` de `ci.yml`, `docs/testing/blocking-tests.md`).
 */
#[Group('phase1')]
#[Group('integration')]
final class LoadVersionTest extends WebTestCase
{
    use LoadsVersionDecorTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * Ligne 13 : rang, catégorie et niveau d'une équipe existante, « divisible » d'un gymnase reviennent à la version ;
     * le nom ne bouge pas, aucune équipe ni aucun gymnase ne naît, l'étoile passe sur la version chargée, aucune
     * version ne naît. Une équipe engagée dont le niveau ne change pas ne bloque rien (sans ce témoin, une garde qui
     * refuserait tout resterait inaperçue).
     */
    public function testLoadingPutsBackTheColdOfExistingTeamsAndVenues(): void
    {
        $sm1 = $this->loadTeam('SM1', 'Seniors', tier: 1, level: TeamLevel::DEPARTEMENTAL);
        $u15 = $this->loadTeam('U15', 'U15', tier: 3, level: TeamLevel::REGIONAL);
        $this->engageTeam($u15);
        $nord = $this->loadVenue('Gymnase Nord', canSplit: false);
        $v1 = $this->loadVersionOf($this->soclePlanId);

        $this->em->getConnection()->executeStatement(
            'UPDATE team SET priority_tier_id = 2, sport_category_id = ?, level = ?, name = ? WHERE id = ?',
            [$this->categoryIds['U17'], TeamLevel::REGIONAL->value, 'SM1 renommée', $sm1->getId()],
        );
        $this->em->getConnection()->executeStatement('UPDATE venue SET can_split = true WHERE id = ?', [$nord->getId()]);
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);
        $versions = (int) $this->loadScalar('SELECT COUNT(*) FROM schedule WHERE schedule_plan_id = ?', [$this->soclePlanId]);

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger la version 1');
        self::assertSame(
            [1, $this->categoryIds['Seniors'], TeamLevel::DEPARTEMENTAL->value, 'SM1 renommée'],
            array_values((array) $this->em->getConnection()->fetchAssociative('SELECT priority_tier_id, sport_category_id, level, name FROM team WHERE id = ?', [$sm1->getId()])),
            'rang, catégorie et niveau reviennent à la version 1 ; le nom, libre, ne bouge pas',
        );
        self::assertFalse((bool) $this->loadScalar('SELECT can_split FROM venue WHERE id = ?', [$nord->getId()]), '« divisible » revient à la version 1');
        self::assertSame(TeamLevel::REGIONAL->value, $this->loadScalar('SELECT level FROM team WHERE id = ?', [$u15->getId()]), 'témoin : l\'équipe engagée garde son niveau, que la version ne change pas');
        self::assertSame(2, (int) $this->loadScalar('SELECT COUNT(*) FROM team WHERE season_id = ?', [$this->seasonId]), 'aucune équipe ne naît ni ne part');
        self::assertSame(1, (int) $this->loadScalar('SELECT COUNT(*) FROM venue WHERE season_id = ?', [$this->seasonId]), 'aucun gymnase ne naît ni ne part');
        self::assertSame(3, (int) $this->loadScalar('SELECT COUNT(*) FROM sport_category WHERE club_id = ?', [$this->clubId]), 'aucune catégorie ne naît ni ne part');
        self::assertSame($versions, (int) $this->loadScalar('SELECT COUNT(*) FROM schedule WHERE schedule_plan_id = ?', [$this->soclePlanId]), 'charger ne fait naître aucune version');
        self::assertSame($v1->getId(), $this->loadedOf($this->soclePlanId), 'l\'étoile passe sur la version chargée');
    }

    /**
     * Ligne 13 : une version qui rendrait à une équipe engagée un autre niveau ne se charge pas, et rien n'est écrit —
     * ni le niveau, ni un autre réglage, ni l'étoile. Le refus nomme l'équipe ; la capacité affichée dit le même motif.
     */
    public function testLoadingIsRefusedWithoutWritingWhenAnEngagedTeamWouldChangeLevel(): void
    {
        $sm1 = $this->loadTeam('SM1', level: TeamLevel::DEPARTEMENTAL, sessions: 2);
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $this->em->getConnection()->executeStatement('UPDATE team SET level = ? WHERE id = ?', [TeamLevel::REGIONAL->value, $sm1->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE plan_team SET sessions_per_week = 3 WHERE schedule_plan_id = ? AND team_id = ?', [$this->soclePlanId, $sm1->getId()]);
        $this->engageTeam($sm1);
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);

        $this->client->request('GET', '/api/schedules/' . $v1->getId(), [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->managerToken, 'HTTP_ACCEPT' => 'application/ld+json']);
        $capabilities = $this->loadResponse()['capabilities'] ?? [];
        $this->loadVersion($v1->getId());

        self::assertSame(409, $this->client->getResponse()->getStatusCode(), 'une version qui changerait le niveau d\'une équipe engagée ne se charge pas');
        $refusal = $this->loadResponse();
        self::assertSame('load_blocked', $refusal['code'] ?? null);
        self::assertStringContainsString('SM1', (string) ($refusal['error'] ?? ''), 'le refus nomme l\'équipe');
        self::assertIsArray($capabilities);
        self::assertFalse($capabilities['canLoad'] ?? null, 'la capacité annonce le refus');
        self::assertSame($refusal['error'] ?? null, $capabilities['loadBlockedReason'] ?? null, 'capacité affichée = motif du refus, mot pour mot');
        self::assertSame(TeamLevel::REGIONAL->value, $this->loadScalar('SELECT level FROM team WHERE id = ?', [$sm1->getId()]), 'le niveau sous lequel elle est inscrite ne bouge pas');
        self::assertSame(3, (int) $this->loadScalar('SELECT sessions_per_week FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$this->soclePlanId, $sm1->getId()]), 'aucun autre réglage n\'est écrit');
        self::assertSame($v2->getId(), $this->loadedOf($this->soclePlanId), 'l\'étoile ne bouge pas');
    }

    /**
     * Ligne 13 : le gymnase imposé d'une équipe, supprimé depuis la version, ne revient pas (ni le gymnase, ni le champ
     * de l'équipe) et ne laisse rien « en attente » : l'état de travail ne diffère pas de la copie, références mortes
     * ôtées.
     */
    public function testAForcedVenueDeletedSinceDoesNotComeBackAndLeavesNothingPending(): void
    {
        $sud = $this->loadVenue('Gymnase Sud');
        $this->loadVenue('Gymnase Nord');
        $u15 = $this->loadTeam('U15', 'U15');
        $this->em->getConnection()->executeStatement('UPDATE team SET forced_venue_id = ? WHERE id = ?', [$sud->getId(), $u15->getId()]);
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $this->em->getConnection()->executeStatement('UPDATE team SET forced_venue_id = NULL WHERE id = ?', [$u15->getId()]);
        $this->deleteVenue($sud->getId());
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger une version dont le gymnase imposé a disparu');
        self::assertNull($this->loadScalar('SELECT forced_venue_id FROM team WHERE id = ?', [$u15->getId()]), 'le champ ne désigne pas un gymnase disparu');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM venue WHERE id = ?', [$sud->getId()]), 'le gymnase supprimé ne revient pas');
        $this->assertNothingPendingAgainst($v1->getId());
    }

    /** Ligne 13 : la catégorie d'une équipe, supprimée depuis la version, ne revient pas et ne laisse rien « en attente ». */
    public function testACategoryDeletedSinceIsNotPutBackAndLeavesNothingPending(): void
    {
        $sm1 = $this->loadTeam('SM1', 'U17');
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $this->em->getConnection()->executeStatement('UPDATE team SET sport_category_id = ? WHERE id = ?', [$this->categoryIds['Seniors'], $sm1->getId()]);
        $this->em->getConnection()->executeStatement('DELETE FROM sport_category WHERE id = ?', [$this->categoryIds['U17']]);
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger une version dont la catégorie a disparu');
        self::assertSame($this->categoryIds['Seniors'], $this->loadScalar('SELECT sport_category_id FROM team WHERE id = ?', [$sm1->getId()]), 'l\'équipe ne pointe pas une catégorie disparue');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM sport_category WHERE id = ?', [$this->categoryIds['U17']]), 'la catégorie supprimée ne revient pas');
        $this->assertNothingPendingAgainst($v1->getId());
    }

    /**
     * Ligne 15 : charger le socle laisse intactes les lignes de règle et d'équipe et la grille d'un plan de période
     * commencé, même quand la règle et les séances du socle changent (la grille de période reprend
     * `RegenerateFromVersionTest::testLoadVersionKeepsPeriodTrainingSlots`).
     */
    public function testLoadingTheSocleLeavesTheLinesOfAStartedPeriod(): void
    {
        $sm1 = $this->loadTeam('SM1', sessions: 2);
        $rule = $this->loadTeamRule($sm1, '19:00');
        $started = $this->loadPeriodPlan('Fermeture de novembre', '2026-11-02', '2026-12-13');
        $nord = $this->loadVenue('Gymnase Nord');
        $this->scopeGucToClub($this->clubId);
        $this->em->persist((new VenueTrainingSlot)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setSchedulePlanId($started)
            ->setVenueId($nord->getId())->setDayOfWeek(3)->setStartTime(new DateTimeImmutable('17:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE plan_team SET sessions_per_week = 1, max_sessions_per_day = 1 WHERE schedule_plan_id = ? AND team_id = ?', [$started, $sm1->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE plan_constraint SET applies = false WHERE schedule_plan_id = ? AND constraint_id = ?', [$started, $rule->getId()]);
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $this->em->getConnection()->executeStatement('UPDATE "constraint" SET config = ? WHERE id = ?', ['{"minStartTime":"20:00"}', $rule->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE plan_team SET sessions_per_week = 3 WHERE schedule_plan_id = ? AND team_id = ?', [$this->soclePlanId, $sm1->getId()]);
        $before = $this->periodLines($started);
        self::assertStringNotContainsString('aucun créneau', $before, 'témoin : la période commencée a sa grille');
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger le socle');
        self::assertSame(2, (int) $this->loadScalar('SELECT sessions_per_week FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$this->soclePlanId, $sm1->getId()]), 'témoin : le socle a bien été chargé');
        self::assertSame($before, $this->periodLines($started), 'les lignes d\'équipe et de règle et la grille de la période commencée ne bougent pas');
    }

    /** Ligne 15 : une règle désactivée dans le plan de Noël le reste après « charger la version 1, puis revenir ». */
    public function testARuleDisabledInTheChristmasPlanStaysDisabledAfterLoadingVersionOneThenComingBack(): void
    {
        $sm1 = $this->loadTeam('SM1');
        $rule = $this->loadTeamRule($sm1, '19:00');
        $noel = $this->loadPeriodPlan('Noël', '2026-12-21', '2027-01-03');
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $this->em->getConnection()->executeStatement('UPDATE "constraint" SET config = ? WHERE id = ?', ['{"minStartTime":"20:00"}', $rule->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE plan_constraint SET applies = false WHERE schedule_plan_id = ? AND constraint_id = ?', [$noel, $rule->getId()]);
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);

        $this->loadVersion($v1->getId());
        $this->assertLoadAccepted('charger la version 1');
        self::assertSame('{"minStartTime":"19:00"}', $this->loadScalar('SELECT config::text FROM "constraint" WHERE id = ?', [$rule->getId()]), 'témoin : la règle du socle est revenue à la version 1');
        $this->loadVersion($v2->getId());
        $this->assertLoadAccepted('revenir à la version 2');

        self::assertFalse((bool) $this->loadScalar('SELECT applies FROM plan_constraint WHERE schedule_plan_id = ? AND constraint_id = ?', [$noel, $rule->getId()]), 'la règle reste désactivée dans le plan de Noël');
    }

    /**
     * Ligne 15 : charger une version de période remet le plan de cette période et laisse le socle — ses règles, ses
     * séances par équipe et la règle de trajet de la saison.
     */
    public function testLoadingAPeriodLeavesTheSocle(): void
    {
        $sm1 = $this->loadTeam('SM1', sessions: 2);
        $rule = $this->loadTeamRule($sm1, '19:00');
        $noel = $this->loadPeriodPlan('Noël', '2026-12-21', '2027-01-03');
        $this->em->getConnection()->executeStatement('UPDATE plan_team SET sessions_per_week = 1 WHERE schedule_plan_id = ? AND team_id = ?', [$noel, $sm1->getId()]);
        $p1 = $this->loadVersionOf($noel);
        $this->em->getConnection()->executeStatement('UPDATE plan_team SET sessions_per_week = 2 WHERE schedule_plan_id = ? AND team_id = ?', [$noel, $sm1->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE "constraint" SET config = ? WHERE id = ?', ['{"minStartTime":"20:00"}', $rule->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE plan_team SET sessions_per_week = 3 WHERE schedule_plan_id = ? AND team_id = ?', [$this->soclePlanId, $sm1->getId()]);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO venue_travel_rule_setting (id, created_at, updated_at, club_id, season_id, intensity, tolerance_minutes, default_minutes) VALUES (gen_random_uuid(), now(), now(), ?, ?, ?, 10, 30)',
            [$this->clubId, $this->seasonId, 'HARD'],
        );
        $p2 = $this->loadVersionOf($noel);
        $this->makeLoaded($p2);
        $socle = $this->socleRows($sm1->getId(), $rule->getId());

        $this->loadVersion($p1->getId());

        $this->assertLoadAccepted('charger la version 1 des vacances');
        self::assertSame(1, (int) $this->loadScalar('SELECT sessions_per_week FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$noel, $sm1->getId()]), 'le plan des vacances revient à sa version 1');
        self::assertSame($socle, $this->socleRows($sm1->getId(), $rule->getId()), 'le socle ne bouge pas : règle, séances par équipe, règle de trajet');
        self::assertSame($p1->getId(), $this->loadedOf($noel), 'l\'étoile des vacances passe sur la version chargée');
    }

    /**
     * Une règle créée après la version, retirée par « Charger », perd ses lignes dans chaque plan de période, coche du
     * gestionnaire comprise : aucune ligne ne survit à sa règle (sinon la composition de la période lève). Reprend la
     * promesse de l'ancien `StructureRestorerTest::testARuleRemovedByLoadingLosesItsLinesInEveryPeriodPlan`.
     */
    public function testARuleCreatedSinceLeavesWithItsLinesInEveryPeriodPlan(): void
    {
        $sm1 = $this->loadTeam('SM1');
        $noel = $this->loadPeriodPlan('Noël', '2026-12-21', '2027-01-03');
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $rule = $this->loadTeamRule($sm1, '19:00');
        $this->em->getConnection()->executeStatement('UPDATE plan_constraint SET applies = false WHERE schedule_plan_id = ? AND constraint_id = ?', [$noel, $rule->getId()]);
        self::assertSame(1, (int) $this->loadScalar('SELECT COUNT(*) FROM plan_constraint WHERE constraint_id = ?', [$rule->getId()]), 'témoin : la règle a sa ligne (décochée) dans Noël');
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger une version antérieure à la règle');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM "constraint" WHERE id = ?', [$rule->getId()]), 'la règle absente de la version 1 est retirée');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM plan_constraint WHERE constraint_id = ?', [$rule->getId()]), 'ses lignes partent avec elle, dans chaque plan');
    }

    /**
     * Une règle de la version, supprimée depuis, ramenée par « Charger », retrouve sa ligne dans chaque plan de période,
     * au défaut ; aucune au plan de saison (plan, §3.4 : PR 3 seulement). Reprend la promesse de l'ancien
     * `StructureRestorerTest::testARuleBroughtBackByLoadingGetsItsLineInEveryPeriodPlan`.
     */
    public function testARuleBroughtBackByLoadingGetsItsLineInEveryPeriodPlan(): void
    {
        $sm1 = $this->loadTeam('SM1');
        $rule = $this->loadTeamRule($sm1, '19:00');
        $noel = $this->loadPeriodPlan('Noël', '2026-12-21', '2027-01-03');
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $this->em->getConnection()->executeStatement('DELETE FROM plan_constraint WHERE constraint_id = ?', [$rule->getId()]);
        $this->em->getConnection()->executeStatement('DELETE FROM "constraint" WHERE id = ?', [$rule->getId()]);
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger une version qui porte une règle supprimée depuis');
        self::assertSame(1, (int) $this->loadScalar('SELECT COUNT(*) FROM "constraint" WHERE id = ?', [$rule->getId()]), 'témoin : la règle revient avec la version 1');
        self::assertSame(1, (int) $this->loadScalar('SELECT COUNT(*) FROM plan_constraint WHERE constraint_id = ? AND schedule_plan_id = ?', [$rule->getId(), $noel]), 'ramenée, elle a sa ligne dans Noël');
        self::assertSame(1, (int) $this->loadScalar('SELECT COUNT(*) FROM plan_constraint WHERE constraint_id = ?', [$rule->getId()]), 'une seule ligne : aucune au plan de saison');
    }

    /**
     * Un coach principal remis par « Charger » redevient le destinataire de la collecte de doléances ouverte : son jeton
     * porte l'équipe, celui du coach remplacé ne la porte plus (la règle de toute écriture d'un lien de coach). Reprend
     * la promesse de l'ancien `StructureRestorerTest::testLoadingRecreatesTheRecipientsTokenAndKeepsTheMark`.
     */
    public function testAMainCoachPutBackByLoadingBecomesTheRecipientOfTheOpenCollection(): void
    {
        $u11 = $this->loadTeam('U11');
        $julie = $this->newCoach($this->clubId, $this->seasonId, 'Julie', 'Petit');
        $maxime = $this->newCoach($this->clubId, $this->seasonId, 'Maxime', 'Durand');
        $this->em->flush();
        $mainCoach = 'INSERT INTO team_coach (id, created_at, updated_at, club_id, season_id, team_id, coach_id, role) VALUES (gen_random_uuid(), now(), now(), ?, ?, ?, ?, \'MAIN\')';
        $this->em->getConnection()->executeStatement($mainCoach, [$this->clubId, $this->seasonId, $u11->getId(), $julie->getId()]);
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $this->em->getConnection()->executeStatement('DELETE FROM team_coach WHERE coach_id = ?', [$julie->getId()]);
        $this->em->getConnection()->executeStatement($mainCoach, [$this->clubId, $this->seasonId, $u11->getId(), $maxime->getId()]);
        $this->scopeGucToClub($this->clubId);
        $entry = (new CalendarEntry)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setKind(CalendarEntryKind::PERIOD)
            ->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Pâques')
            ->setStartDate(new DateTimeImmutable('2027-04-05'))->setEndDate(new DateTimeImmutable('2027-04-18'));
        $this->em->persist($entry);
        $campaign = (new CoachWishCampaign)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setCalendarEntryId($entry->getId())
            ->setDeadline(new DateTimeImmutable('2030-01-01'))->setWeeks(['2027-04-05'])->setTeamIds([$u11->getId()]);
        $this->em->persist($campaign);
        $this->em->persist((new CoachWishToken)->setCampaignId($campaign->getId())->setCoachId($maxime->getId())->setClubId($this->clubId)->setTeamIds([$u11->getId()]));
        $this->em->flush();
        $campaignId = $campaign->getId();
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger la version où Julie est coach principale');
        $tokenTeams = 'SELECT team_ids::text FROM coach_wish_token WHERE campaign_id = ? AND coach_id = ?';
        self::assertSame([$u11->getId()], json_decode((string) $this->loadScalar($tokenTeams, [$campaignId, $julie->getId()]), true), 'Julie, remise coach principale, reçoit le jeton de l\'équipe');
        self::assertSame([], json_decode((string) $this->loadScalar($tokenTeams, [$campaignId, $maxime->getId()]), true), 'le jeton de Maxime ne porte plus l\'équipe');
    }

    /**
     * Ligne 16 : une équipe supprimée depuis la version ne revient pas, ni ses lignes ; de même, une ligne dont le
     * gymnase ou le coach a disparu est ignorée (filtre du §3.4) — créneau, règle de coach, coach principal.
     */
    public function testATeamVenueOrCoachDeletedSinceDoesNotComeBackNorItsLines(): void
    {
        $sm1 = $this->loadTeam('SM1');
        $u15 = $this->loadTeam('U15', 'U15');
        $sud = $this->loadVenue('Gymnase Sud');
        $this->scopeGucToClub($this->clubId);
        $this->em->persist((new VenueTrainingSlot)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setSchedulePlanId($this->soclePlanId)
            ->setVenueId($sud->getId())->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1));
        $coach = $this->newCoach($this->clubId, $this->seasonId, 'Camille', 'Partie');
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            'INSERT INTO team_coach (id, created_at, updated_at, club_id, season_id, team_id, coach_id, role) VALUES (gen_random_uuid(), now(), now(), ?, ?, ?, ?, ?)',
            [$this->clubId, $this->seasonId, $sm1->getId(), $coach->getId(), 'MAIN'],
        );
        $this->loadTeamRule($u15, '18:30');
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $this->deleteTeam($u15->getId());
        $this->deleteVenue($sud->getId());
        $this->em->getConnection()->executeStatement('DELETE FROM team_coach WHERE coach_id = ?', [$coach->getId()]);
        $this->em->getConnection()->executeStatement('DELETE FROM coach WHERE id = ?', [$coach->getId()]);
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger une version qui précède des suppressions');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM team WHERE id = ?', [$u15->getId()]), 'l\'équipe supprimée ne revient pas');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM plan_team WHERE team_id = ?', [$u15->getId()]), 'ni sa ligne d\'équipe');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM "constraint" WHERE scope_target_id = ?', [$u15->getId()]), 'ni sa règle');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM venue_training_slot WHERE venue_id = ?', [$sud->getId()]), 'un créneau d\'un gymnase disparu ne revient pas');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM team_coach WHERE coach_id = ?', [$coach->getId()]), 'le lien d\'un coach disparu ne revient pas');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM coach WHERE id = ?', [$coach->getId()]), 'ni le coach');
        self::assertSame(1, (int) $this->loadScalar('SELECT COUNT(*) FROM team WHERE id = ?', [$sm1->getId()]), 'témoin : l\'équipe restante est là');
    }

    /** Ligne 16 : une équipe créée après la version garde sa ligne et ses séances. */
    public function testATeamCreatedSinceKeepsItsLine(): void
    {
        $this->loadTeam('SM1');
        $v1 = $this->loadVersionOf($this->soclePlanId);
        $u17 = $this->loadTeam('U17', 'U17', sessions: 3);
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($v2);

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger une version antérieure à une équipe');
        self::assertSame(1, (int) $this->loadScalar('SELECT COUNT(*) FROM team WHERE id = ?', [$u17->getId()]), 'l\'équipe créée depuis reste');
        self::assertSame(3, (int) $this->loadScalar('SELECT sessions_per_week FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$this->soclePlanId, $u17->getId()]), 'et garde sa ligne, ses séances comprises');
    }

    /** Ligne 21 : un groupe créé depuis le planning entre dans la copie de la version chargée, avec ses équipes. */
    public function testAGroupCreatedFromThePlanningEntersTheCopyOfTheLoadedVersion(): void
    {
        [$version, $source, $joiner] = $this->placedSeason();
        $states = self::getContainer()->get(PlanStates::class);
        self::assertArrayNotHasKey('SharedTrainingBlock', array_filter($states->of($version->getId())->toArray()), 'témoin : la copie n\'a aucun groupe avant le geste');

        $this->client->request('POST', '/api/schedule-slots/' . $source->getId() . '/mutualize', [], [], $this->jsonHeaders(), json_encode([
            'teamIds' => [$source->getTeamId(), $joiner->getTeamId()], 'label' => 'Groupe U11', 'replacedSlotIds' => [$joiner->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200, 'témoin : le geste de mutualisation aboutit');

        $this->scopeGucToClub($this->clubId);
        $this->em->clear();
        $block = $this->em->getRepository(SharedTrainingBlock::class)->findOneBy(['schedulePlanId' => $this->soclePlanId]);
        self::assertInstanceOf(SharedTrainingBlock::class, $block);
        $copy = $states->of($version->getId())->toArray();
        self::assertArrayHasKey($block->getId(), $copy['SharedTrainingBlock'] ?? [], 'le groupe entre dans la copie de la version chargée');
        self::assertCount(2, $copy['SharedTrainingBlockTeam'] ?? [], 'avec ses deux équipes');
        self::assertTrue($states->of($version->getId())->changesTo($states->capture($this->soclePlanId))->isEmpty(), 'la copie suit exactement les lignes touchées');
    }

    /** Ligne 21 : un groupe dissous depuis le planning sort de la copie, et « Charger » ne le ramène pas. */
    public function testAGroupDissolvedFromThePlanningLeavesTheCopyAndLoadingDoesNotBringItBack(): void
    {
        // U11 A et U11 C forment le groupe du mardi 18 h ; U11 B, placée le mercredi, le rejoint.
        [$version, $placedA, $joiner, $placedC] = $this->placedSeason(withThirdTeam: true);
        $group = $this->groupAtCase($version->getId(), [$placedA->getTeamId(), $placedC->getTeamId()]);
        $states = self::getContainer()->get(PlanStates::class);
        $states->store($version, $states->capture($this->soclePlanId));
        self::assertArrayHasKey($group['block'], $states->of($version->getId())->toArray()['SharedTrainingBlock'] ?? [], 'témoin : le groupe est dans la copie avant le geste');

        $this->client->request('POST', '/api/schedule-slots/mutualize-at-case', [], [], $this->jsonHeaders(), json_encode([
            'sourceSlotId' => $joiner->getId(), 'targetSlotId' => $group['slot'],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200, 'témoin : l\'équipe rejoint le groupe, qui se dissout dans un nouveau');

        self::assertArrayNotHasKey($group['block'], $states->of($version->getId())->toArray()['SharedTrainingBlock'] ?? [], 'le groupe dissous sort de la copie');
        $later = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($later);
        $this->loadVersion($version->getId());
        $this->assertLoadAccepted('recharger la version retouchée');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM shared_training_block WHERE id = ?', [$group['block']]), '« Charger » ne ramène pas le groupe dissous');
    }

    /** Ligne 21 : une réservation retirée sort de la copie de la version chargée. */
    public function testARemovedReservationLeavesTheCopyOfTheLoadedVersion(): void
    {
        $sm1 = $this->loadTeam('SM1');
        $nord = $this->loadVenue('Gymnase Nord');
        $this->scopeGucToClub($this->clubId);
        $reservation = (new Reservation)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setSchedulePlanId($this->soclePlanId)
            ->setTeamId($sm1->getId())->setVenueId($nord->getId())->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90);
        $this->em->persist($reservation);
        $this->em->flush();
        $version = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($version);
        $states = self::getContainer()->get(PlanStates::class);
        self::assertArrayHasKey($reservation->getId(), $states->of($version->getId())->toArray()['Reservation'] ?? [], 'témoin : la réservation est dans la copie');

        $this->client->request('DELETE', '/api/reservations/' . $reservation->getId(), [], [], $this->jsonHeaders());
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 204], 'témoin : le retrait aboutit');

        self::assertArrayNotHasKey($reservation->getId(), $states->of($version->getId())->toArray()['Reservation'] ?? [], 'la réservation retirée sort de la copie');
    }

    /**
     * Ligne 21 : une réservation saisie au Wizard et jamais générée n'entre pas dans la copie de la version chargée ;
     * « Charger » cette version la retire donc de l'état de travail (le dialogue prévient : décision 3).
     */
    public function testAReservationEnteredInTheWizardAndNeverGeneratedDoesNotEnterTheCopy(): void
    {
        $sm1 = $this->loadTeam('SM1');
        $nord = $this->loadVenue('Gymnase Nord');
        $version = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($version);

        $this->client->request('POST', '/api/reservations', [], [], $this->jsonHeaders(), json_encode([
            'teamId' => $sm1->getId(), 'venueId' => $nord->getId(), 'dayOfWeek' => 3, 'startTime' => '18:00', 'durationMinutes' => 90, 'schedulePlanId' => null,
        ], \JSON_THROW_ON_ERROR));
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 201], 'témoin : la saisie aboutit — ' . $this->client->getResponse()->getContent());
        $reservationId = (string) ($this->loadResponse()['id'] ?? '');

        $states = self::getContainer()->get(PlanStates::class);
        self::assertSame([], $states->of($version->getId())->toArray()['Reservation'] ?? [], 'une réservation jamais générée n\'entre pas dans la copie');
        $this->loadVersion($version->getId());
        $this->assertLoadAccepted('recharger la version');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM reservation WHERE id = ?', [$reservationId]), '« Charger » remet l\'état de la version : la saisie non générée est perdue');
    }

    /**
     * Abus (cascade) : retirer un groupe absent de la copie ne supprime aucune séance d'une autre version — la place
     * d'une séance appartient à sa version. Charger la version 1 (sans groupe) depuis la version 2 (un groupe et ses
     * séances liées) laisse les séances de la version 2 à leur place ; elles perdent seulement leur lien de groupe.
     */
    public function testRemovingAGroupAbsentFromTheCopyLeavesTheSessionsOfAnotherVersion(): void
    {
        [$v1, $placedA, $placedB] = $this->placedSeason();
        $v2 = $this->loadVersionOf($this->soclePlanId);
        $group = $this->groupAtCase($v2->getId(), [$placedA->getTeamId(), $placedB->getTeamId()]);
        $states = self::getContainer()->get(PlanStates::class);
        $states->store($v2, $states->capture($this->soclePlanId));
        $this->makeLoaded($v2);
        $sessionsOfV2 = 'SELECT COUNT(*) FROM schedule_slot_template WHERE schedule_id = ?';
        self::assertSame(2, (int) $this->loadScalar($sessionsOfV2 . ' AND shared_training_block_id = ?', [$v2->getId(), $group['block']]), 'témoin : la version 2 porte les deux séances du groupe');

        $this->loadVersion($v1->getId());

        $this->assertLoadAccepted('charger la version 1, sans groupe');
        self::assertSame(0, (int) $this->loadScalar('SELECT COUNT(*) FROM shared_training_block WHERE id = ?', [$group['block']]), 'témoin : le groupe absent de la version 1 est retiré');
        self::assertSame(2, (int) $this->loadScalar($sessionsOfV2, [$v2->getId()]), 'les séances de la version 2 restent');
        self::assertSame(2, (int) $this->loadScalar($sessionsOfV2 . ' AND shared_training_block_id IS NULL AND day_of_week = 2 AND lock_level = ?', [$v2->getId(), LockLevel::HARD->value]), 'à leur place et verrouillées, sans lien de groupe');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->seedLoadClub();
    }

    /** Après le chargement, l'état de travail ne diffère pas de la copie de la version, références mortes ôtées. */
    private function assertNothingPendingAgainst(string $versionId): void
    {
        $this->scopeGucToClub($this->clubId);
        $this->em->clear();
        $states = self::getContainer()->get(PlanStates::class);
        $changes = $states->withoutDeadReferences($this->soclePlanId, $states->of($versionId))->changesTo($states->capture($this->soclePlanId));
        self::assertTrue($changes->isEmpty(), 'rien n\'est « en attente » après le chargement : ' . json_encode($changes->lines()));
    }

    /** Les lignes d'équipe et de règle d'un plan, et sa grille, en texte trié. */
    private function periodLines(string $planId): string
    {
        return (string) $this->loadScalar(
            'SELECT (SELECT string_agg(team_id::text || \' \' || participates || \' \' || sessions_per_week || \' \' || max_sessions_per_day, \' ; \' ORDER BY team_id) FROM plan_team WHERE schedule_plan_id = :p)'
            . ' || \' | \' || (SELECT string_agg(constraint_id::text || \' \' || applies, \' ; \' ORDER BY constraint_id) FROM plan_constraint WHERE schedule_plan_id = :p)'
            . ' || \' | \' || COALESCE((SELECT string_agg(id::text || \' \' || venue_id || \' \' || day_of_week || \' \' || start_time, \' ; \' ORDER BY id) FROM venue_training_slot WHERE schedule_plan_id = :p), \'aucun créneau\')',
            ['p' => $planId],
        );
    }

    /** Les lignes du socle qu'une version de période ne doit pas toucher, en texte. */
    private function socleRows(string $teamId, string $ruleId): string
    {
        return (string) $this->loadScalar(
            'SELECT (SELECT config::text FROM "constraint" WHERE id = :r) || \' | \' || (SELECT sessions_per_week FROM plan_team WHERE schedule_plan_id = :s AND team_id = :t)'
            . ' || \' | \' || COALESCE((SELECT intensity || \' \' || tolerance_minutes || \' \' || default_minutes FROM venue_travel_rule_setting WHERE season_id = :season), \'aucune\')',
            ['r' => $ruleId, 's' => $this->soclePlanId, 't' => $teamId, 'season' => $this->seasonId],
        );
    }

    private function deleteTeam(string $teamId): void
    {
        $connection = $this->em->getConnection();
        $this->scopeGucToClub($this->clubId);
        $connection->executeStatement('DELETE FROM plan_constraint WHERE constraint_id IN (SELECT id FROM "constraint" WHERE scope_target_id = ?)', [$teamId]);
        $connection->executeStatement('DELETE FROM "constraint" WHERE scope_target_id = ?', [$teamId]);
        $connection->executeStatement('DELETE FROM plan_team WHERE team_id = ?', [$teamId]);
        $connection->executeStatement('DELETE FROM team_coach WHERE team_id = ?', [$teamId]);
        $connection->executeStatement('DELETE FROM team WHERE id = ?', [$teamId]);
    }

    private function deleteVenue(string $venueId): void
    {
        $connection = $this->em->getConnection();
        $this->scopeGucToClub($this->clubId);
        $connection->executeStatement('DELETE FROM venue_training_slot WHERE venue_id = ?', [$venueId]);
        $connection->executeStatement('DELETE FROM plan_venue WHERE venue_id = ?', [$venueId]);
        $connection->executeStatement('DELETE FROM venue WHERE id = ?', [$venueId]);
    }

    /**
     * Le socle d'un planning retouchable : un gymnase et sa fenêtre du mardi 18 h, deux (ou trois) équipes placées
     * dans la version chargée, la copie de la version prise.
     *
     * @return array{0: Schedule, 1: ScheduleSlotTemplate, 2: ScheduleSlotTemplate, 3?: ScheduleSlotTemplate}
     */
    private function placedSeason(bool $withThirdTeam = false): array
    {
        $venue = $this->loadVenue('Gymnase Centre');
        $this->scopeGucToClub($this->clubId);
        $this->em->persist((new VenueTrainingSlot)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setSchedulePlanId($this->soclePlanId)
            ->setVenueId($venue->getId())->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(3));
        $this->em->flush();
        $teams = [$this->loadTeam('U11 A'), $this->loadTeam('U11 B')];
        if ($withThirdTeam) {
            $teams[] = $this->loadTeam('U11 C');
        }
        $version = $this->loadVersionOf($this->soclePlanId);
        $this->makeLoaded($version);
        $slots = [];
        foreach ($teams as $index => $team) {
            $slots[] = $this->placeSlot($version->getId(), $team, $venue->getId(), 0 === $index ? 2 : 3);
        }

        return [$version, ...$slots];
    }

    private function placeSlot(string $versionId, Team $team, string $venueId, int $day): ScheduleSlotTemplate
    {
        $this->scopeGucToClub($this->clubId);
        $slot = (new ScheduleSlotTemplate)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setScheduleId($versionId)
            ->setTeamId($team->getId())->setVenueId($venueId)->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot;
    }

    /**
     * Un groupe déclaré (bloc + équipes) dont les séances sont posées, liées et verrouillées, au mardi 18 h —
     * ce que laisse le geste de mutualisation.
     *
     * @param list<string> $teamIds
     *
     * @return array{block: string, slot: string}
     */
    private function groupAtCase(string $versionId, array $teamIds): array
    {
        $this->scopeGucToClub($this->clubId);
        $block = (new SharedTrainingBlock)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setSchedulePlanId($this->soclePlanId)->setCommonSessions(1);
        $this->em->persist($block);
        $this->em->flush();
        $venueId = (string) $this->em->getConnection()->fetchOne('SELECT id FROM venue WHERE season_id = ? AND name = ?', [$this->seasonId, 'Gymnase Centre']);
        $slotId = '';
        foreach ($teamIds as $teamId) {
            $this->em->persist((new SharedTrainingBlockTeam)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setSchedulePlanId($this->soclePlanId)->setBlockId($block->getId())->setTeamId($teamId));
            $this->em->getConnection()->executeStatement('DELETE FROM schedule_slot_template WHERE schedule_id = ? AND team_id = ?', [$versionId, $teamId]);
            $slot = (new ScheduleSlotTemplate)->setClubId($this->clubId)->setSeasonId($this->seasonId)->setScheduleId($versionId)
                ->setTeamId($teamId)->setVenueId($venueId)->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)
                ->setLockLevel(LockLevel::HARD)->setLockOrigin(LockOrigin::MANUAL)->setSharedTrainingBlockId($block->getId());
            $this->em->persist($slot);
            $this->em->flush();
            $slotId = '' === $slotId ? $slot->getId() : $slotId;
        }

        return ['block' => $block->getId(), 'slot' => $slotId];
    }

    /** @return array<string, string> */
    private function jsonHeaders(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->managerToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    }
}
