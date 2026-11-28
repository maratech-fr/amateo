<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Schedule;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Plans\PlanStates;
use App\Plans\SoclePlan;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Tests\EngineRequestDecorTrait;
use App\Tests\EngineRequestGroupRulesDecorTrait;
use App\Tests\EngineRequestMatchesNetDecorTrait;
use App\Tests\EngineRequestPlanSettingsDecorTrait;
use App\Tests\EngineRequestRulesNetDecorTrait;
use App\Tests\EngineRequestRunnerTrait;
use App\Tests\EngineRequestSocleNetDecorTrait;
use App\Tests\EngineRequestTravelDecorTrait;
use App\Tests\Integration\Migration\Lot4MigrationChainTest;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Promesse : la demande que le moteur reçoit ne change pas quand on réorganise le code qui la compose.
 * Des demandes réelles — le corps HTTP que le moteur simulé reçoit, sur le rail réel (handler de
 * génération ou de placement, `EngineClient` réel, `MockHttpClient`) — sont comparées à l'octet
 * (assertSame sur le JSON décodé, ordre des listes et des clés compris) aux fichiers de référence
 * de `tests/Fixtures/engine-requests/` :
 *
 *   1. socle, première génération d'un brouillon (aucune version chargée) ;
 *   2. socle régénéré depuis la version chargée, qui porte un verrou ;
 *   3. période générée sur place, avec un verrou sur la version et « garder ses créneaux de saison » ;
 *   4. période comblée (épingles de la version chargée de la période, référence du socle) ;
 *   5. placement des matchs, club entier ;
 *   6. placement des matchs sur une fenêtre (une semaine) ;
 *   7. socle aux règles de groupe sous leurs trois formes, et une équipe sans habitude au jour de
 *      match enregistré (`EngineRequestGroupRulesDecorTrait`) ;
 *   8. période générée sur place, avec une règle de groupe datée ;
 *   9. socle de la saison SUIVANTE d'un club à deux saisons, aux temps de trajet, un couple rangé à l'envers
 *      d'une saison à l'autre (`EngineRequestTravelDecorTrait`) ;
 *  10. période générée sur place, aux temps de trajet de la saison en cours ;
 *  11 à 15. ce que le lot 4 range dans le plan (`EngineRequestPlanSettingsDecorTrait`) : le socle complet,
 *      un plan de vacances et un plan de fermeture réglés, le verdict d'un déplacement dans le socle et
 *      dans une période (`/validate-assignments`) ;
 *  16 à 19. le filet des matchs du lot 7 (`EngineRequestMatchesNetDecorTrait`) : deux équipes dans une
 *      division, les amicaux, les stades du suivi fédéral, les coupes. Chaque cas vérifie d'abord ses
 *      témoins de décor en base : un témoin absent fait échouer le test ;
 *  20 et 21. le filet du lot 5 (`EngineRequestSocleNetDecorTrait`) : le socle de la saison suivante née par
 *      le vrai geste depuis un socle complet en vigueur, et le placement quand une équipe créée après la
 *      validation porte un match. Témoins de décor lus en base, comme 16 à 19 ;
 *  23 à 25. le filet du lot 9 (`EngineRequestRulesNetDecorTrait`) : le socle au trajet obligatoire réglé, à la
 *      passerelle obligatoire et à la contrainte permanente éteinte ; le même club dans un plan de vacances où la
 *      règle éteinte est cochée ; le placement aux règles de match à bornes ouvertes. Témoins lus en base. Le numéro
 *      22 est réservé (lot 5, PR 6).
 *
 * Jumeau de conversion : {@see Lot4MigrationChainTest} compare aux MÊMES fichiers les demandes composées
 * sur ce décor écrit en SQL dans le schéma d'avant le lot 4, une fois toutes les migrations jouées.
 *
 * Bloquant (step `Blocking suite` de `ci.yml`, `docs/testing/blocking-tests.md`) : le « Lot Gate »
 * d'une branche de lot ne joue que la suite bloquante. Le groupe `phase1` est requis : le step lance
 * `phpunit --group phase1 <fichiers>`, un fichier listé sans ce groupe n'y jouerait aucun test.
 *
 * Aux cas de génération (1 à 4, 7 à 13, 20, 23, 24), la demande se recoupe avec ce qui est écrit en base : l'empreinte
 * `snapshotHash` vaut `fingerprint(snapshotData, règle de trajet de la copie)`, les clés de la greffe `payloadGraft` sont
 * exactement celles attendues, et le corps reçu vaut `engineInput()` relu en base. Aux cas 1 à 3, 7 à 13, 20, 23 et 24,
 * `snapshotHash` vaut aussi `structureHashOfPlan(plan)` (le signal « structure modifiée » ne ment pas
 * sur une version fraîche). Au cas 4 (comblement), la relation constatée est AUSSI l'égalité, mais par
 * un autre chemin : les épingles du comblement (tout le placé de la version source, en HARD) sont
 * injectées AVANT l'empreinte, et `structureHashOfPlan` recompose la période SANS version ; c'est la
 * complétion qui les y ramène — la version comblée devient la source des verrous du plan
 * (`pointLockSource`) et ses épingles, réimportées en HARD, sont greffées à la structure du plan.
 * Relation figée telle quelle : si elle change, c'est un changement de sens à nommer.
 *
 * Le moteur simulé renvoie, comme le vrai, les verrous HARD reçus et rien d'autre : la version
 * fraîche les porte, ce que lit `structureHashOfPlan` ensuite.
 *
 * Décor : `EngineRequestDecorTrait`, identifiants écrits en dur ; `assertEveryUuidIsFixed` prouve
 * qu'aucun identifiant aléatoire n'entre dans une demande. Le payload du socle est servi 4 h par le
 * cache Redis (`cache.schedule`, hors transaction de test) : il est purgé avant chaque composition, et
 * `testAPrimedCacheNeverReachesTheReference` prouve que la purge porte.
 *
 * Capture : `ENGINE_REQUEST_CAPTURE=1` RÉÉCRIT les fichiers de référence au lieu de les comparer. Les
 * références se capturent une fois, sur un code dont l'ordre des demandes est stable
 * (`EngineRequestOrderIsStableTest` vert) ; elles ne se recapturent ensuite que pour un changement
 * VOULU de la demande, nommé dans la PR. Sans fichier, le test est rouge et dit comment capturer.
 *
 * Jumeau : `EngineRequestOrderIsStableTest` garde l'ORDRE des listes face à l'ordre physique des
 * lignes ; celui-ci fige le CONTENU des demandes de référence.
 */
#[Group('phase1')]
#[Group('integration')]
final class EngineRequestReferenceTest extends KernelTestCase
{
    use EngineRequestDecorTrait;
    use EngineRequestGroupRulesDecorTrait;
    use EngineRequestMatchesNetDecorTrait;
    use EngineRequestPlanSettingsDecorTrait;
    use EngineRequestRulesNetDecorTrait;
    use EngineRequestRunnerTrait;
    use EngineRequestSocleNetDecorTrait;
    use EngineRequestTravelDecorTrait;

    public function testSocleFirstGenerationOfADraft(): void
    {
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::DRAFT);

        $run = $this->runGeneration(self::ER_SOCLE_V1);

        $this->assertReference('socle-1-premiere-generation-brouillon', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], null);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    public function testSocleRegeneratedFromTheLoadedVersionWithALock(): void
    {
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED, [
            [1, self::ER_TEAM_U13, self::ER_VENUE_NORD, 1, '18:00', LockLevel::HARD],
            [2, self::ER_TEAM_U15, self::ER_VENUE_NORD, 3, '18:00', LockLevel::NONE],
            [3, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
        ]);
        $this->loadSocle(self::ER_SOCLE_V1);
        $this->seedSocleVersion(self::ER_SOCLE_V2, ScheduleStatus::PENDING);

        $run = $this->runGeneration(self::ER_SOCLE_V2, sourceScheduleId: self::ER_SOCLE_V1);

        $this->assertReference('socle-2-regeneration-version-chargee-verrou', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], ['previousAssignments']);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    public function testPeriodGeneratedInPlaceWithALockAndKeepSeasonSlots(): void
    {
        $this->seedSocleInForce();
        $this->seedEngineRequestPeriod();
        $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::DRAFT, [
            [11, self::ER_TEAM_U15, self::ER_VENUE_NORD, 1, '19:30', LockLevel::HARD],
        ]);

        $run = $this->runGeneration(self::ER_PERIOD_P1);

        $this->assertReference('periode-3-generation-sur-place-verrou-garder-creneaux', $run['body']);
        // Aucune version sœur terminée : pas de `previousAssignments`, la seule greffe est la
        // référence « garder ses créneaux » (U13).
        $this->assertGenerationCoherence($run['body'], $run['schedule'], ['socleReferenceAssignments']);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    public function testPeriodFill(): void
    {
        $this->seedSocleInForce();
        $planId = $this->seedEngineRequestPeriod();
        $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::COMPLETED, [
            [11, self::ER_TEAM_U15, self::ER_VENUE_NORD, 1, '19:30', LockLevel::HARD],
            [12, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
        ]);
        self::getContainer()->get(SchedulePlanProvisioner::class)->pointLockSource($planId, self::ER_PERIOD_P1);
        $this->seedPeriodVersion(self::ER_PERIOD_P2, ScheduleStatus::PENDING);

        $run = $this->runGeneration(self::ER_PERIOD_P2, fillSourceScheduleId: self::ER_PERIOD_P1);

        $this->assertReference('periode-4-comblement', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], ['socleReferenceAssignments']);
        // Relation constatée et figée (docblock de la classe) : égalité, par la source des verrous.
        self::assertSame(
            $this->structureHashOfPlanOf($run['schedule']),
            $run['schedule']->getSnapshotHash(),
            'comblement terminé : snapshotHash = structureHashOfPlan (la version comblée est devenue la source des verrous du plan)',
        );
    }

    public function testPlacementOfTheWholeClub(): void
    {
        $this->seedSocleInForce();
        $this->seedEngineRequestMatches();

        $body = $this->runPlacement(null);

        $this->assertReference('placement-5-club-entier', $body);
    }

    public function testPlacementOnAWindow(): void
    {
        $this->seedSocleInForce();
        $this->seedEngineRequestMatches();

        $body = $this->runPlacement(['from' => '2026-01-12', 'to' => '2026-01-18']);

        $this->assertReference('placement-6-fenetre', $body);
    }

    /**
     * Les groupes que le lot des déclarations communes déplace : trois règles de club (deux groupes
     * croisés, une exclusion seule, l'ancienne forme à un groupe) éclatées par équipe, et le jour de
     * match enregistré d'une équipe sans habitude (5 enregistré, 6 émis).
     */
    public function testSocleWithGroupRulesAndARecordedMatchDay(): void
    {
        $this->seedEngineRequestTeamGroups();
        $this->seedEngineRequestSeasonGroupRules();
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::DRAFT);

        $run = $this->runGeneration(self::ER_SOCLE_V1);

        self::assertSame([
            self::ER_RULE_TWO_TAGS => [self::ER_TEAM_U15],
            self::ER_RULE_EXCLUDE => [self::ER_TEAM_SM1],
            self::ER_RULE_LEGACY_TAG => [self::ER_TEAM_U13],
        ], $this->groupRuleRows($run['body'], [self::ER_RULE_TWO_TAGS, self::ER_RULE_EXCLUDE, self::ER_RULE_LEGACY_TAG]), 'chaque règle de groupe vise ses seules équipes');
        $this->assertReference('socle-7-regles-de-groupe-et-jour-de-match', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], null);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    /** Un plan de période généré sur place, avec une règle de groupe datée de la fermeture. */
    public function testPeriodWithAGroupRule(): void
    {
        $this->seedEngineRequestTeamGroups();
        $this->seedEngineRequestSeasonGroupRules();
        $this->seedSocleInForce();
        $this->seedEngineRequestPeriod();
        $this->seedEngineRequestPeriodGroupRule();
        $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::DRAFT);

        $run = $this->runGeneration(self::ER_PERIOD_P1);

        self::assertSame(
            [self::ER_RULE_PERIOD_GROUP => [self::ER_TEAM_U13]],
            $this->groupRuleRows($run['body'], [self::ER_RULE_PERIOD_GROUP]),
            'la règle de groupe datée vise ses seules équipes',
        );
        $this->assertReference('periode-8-regle-de-groupe', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], ['socleReferenceAssignments']);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    /**
     * Le socle de la saison SUIVANTE d'un club à deux saisons, aux temps de trajet saisis : seuls les couples de cette
     * saison partent, rangés selon les identifiants de ses gymnases — Nord–Sud y est rangé Sud d'abord, à l'envers de la
     * saison en cours —, avec un couple sans minutes ; les coachs portent leurs statuts salarié et véhiculé.
     */
    public function testNextSeasonSocleWithTravelTimesStoredTheOtherWayRound(): void
    {
        $this->seedEngineRequestTravelOfCurrentSeason();
        $version = $this->seedEngineRequestNextSeason();

        $run = $this->runGeneration($version->getId());

        self::assertSame(self::ER_NEXT_SEASON, $run['body']['seasonId'] ?? null, 'la demande est celle de la saison suivante');
        self::assertSame([
            [self::ER_NEXT_VENUE_SUD, self::ER_NEXT_VENUE_NORD, 15, 35],
            [self::ER_NEXT_VENUE_SUD, self::ER_NEXT_VENUE_EST, null, null],
            [self::ER_NEXT_VENUE_NORD, self::ER_NEXT_VENUE_EST, 9, 22],
        ], $this->travelRows($run['body']), 'les trois couples de la saison suivante, et aucun de la saison en cours');
        self::assertSame(
            [[self::ER_NEXT_COACH_A, true, true], [self::ER_NEXT_COACH_B, false, false]],
            array_map(static fn (array $c): array => [$c['id'] ?? null, $c['isEmployee'] ?? null, $c['isVehicled'] ?? null], \is_array($run['body']['coaches'] ?? null) ? $run['body']['coaches'] : []),
            'un coach salarié véhiculé, un non véhiculé',
        );
        $this->assertReference('socle-9-trajets-saison-suivante', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], null);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    /** Un plan de période généré sur place, aux temps de trajet de la saison en cours. */
    public function testPeriodWithTravelTimes(): void
    {
        $this->seedEngineRequestTravelOfCurrentSeason();
        $this->seedSocleInForce();
        $this->seedEngineRequestPeriod();
        $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::DRAFT);

        $run = $this->runGeneration(self::ER_PERIOD_P1);

        self::assertSame([
            [self::ER_VENUE_NORD, self::ER_VENUE_SUD, 12, 30],
            [self::ER_VENUE_NORD, self::ER_VENUE_EST, 8, 20],
            [self::ER_VENUE_SUD, self::ER_VENUE_EST, null, null],
        ], $this->travelRows($run['body']), 'les trois couples de la saison, triés par gymnases');
        $this->assertReference('periode-10-trajets', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], ['socleReferenceAssignments']);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    /** Le socle dont chaque famille ancrée au plan a sa ligne, et une équipe ses séances et son plafond propres. */
    public function testCompleteSocle(): void
    {
        $this->seedEngineRequestCompleteSocle();
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::DRAFT);

        $run = $this->runGeneration(self::ER_SOCLE_V1);

        self::assertSame([[self::ER_BLOCK_SOCLE, [self::ER_TEAM_U15, self::ER_TEAM_SM1]]], $this->blockRows($run['body']), 'le groupe mutualisé du socle part au moteur');
        $this->assertReference('socle-11-socle-complet', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], null);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    /** La semaine-enfant d'un plan de vacances réglé (équipes, règles, gymnases), une équipe créée après sa naissance. */
    public function testHolidayPlanWithItsSettings(): void
    {
        $this->seedSocleInForce();
        $this->seedEngineRequestHolidaySettings();
        $this->erVersion(self::ER_HOLIDAY_V1, self::ER_HOLIDAY_WEEK, ScheduleStatus::DRAFT, []);

        $run = $this->runGeneration(self::ER_HOLIDAY_V1);

        self::assertSame(
            [self::ER_TEAM_U15, self::ER_TEAM_SM1, self::ER_TEAM_U17_HOLIDAY],
            array_column(\is_array($run['body']['teams'] ?? null) ? $run['body']['teams'] : [], 'id'),
            'U13 écartée ; U17, créée après la naissance, participe',
        );
        $this->assertReference('periode-12-vacances-reglages', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], null);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    /** Un plan de fermeture réglé : groupe hérité, règle décochée, grille ressaisie, contrainte et équipe créées après. */
    public function testClosurePlanWithItsSettings(): void
    {
        $this->seedSocleInForce();
        $this->seedEngineRequestClosureSettings();
        $this->erVersion(self::ER_CLOSURE_V1, self::ER_CLOSURE_SETTINGS, ScheduleStatus::DRAFT, []);

        $run = $this->runGeneration(self::ER_CLOSURE_V1);

        self::assertSame([[self::ER_BLOCK_CLOSURE, [self::ER_TEAM_U15, self::ER_TEAM_SM1]]], $this->blockRows($run['body']), 'la fermeture porte le groupe hérité du socle');
        self::assertContains(self::ER_TEAM_U17_CLOSURE, array_column(\is_array($run['body']['teams'] ?? null) ? $run['body']['teams'] : [], 'id'), 'U17, créée après la naissance, participe');
        $this->assertReference('periode-13-fermeture-reglages', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], null);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    /** Le verdict d'un déplacement dans le socle : U15 du mercredi 18:00 au lundi 19:30, au Nord. */
    public function testValidationOfAMoveInTheSocle(): void
    {
        $this->seedSocleInForce();

        $body = $this->runMoveValidation(self::erId('5107', 2), [1, '19:30', self::ER_VENUE_NORD]);

        $this->assertReference('validation-14-deplacement-socle', $body);
    }

    /** Le verdict d'un déplacement dans un plan de fermeture : SM1 du jeudi au lundi 18:00, au Nord. */
    public function testValidationOfAMoveInAPeriod(): void
    {
        $this->seedSocleInForce();
        $planId = $this->seedEngineRequestPeriod();
        $this->seedPeriodVersion(self::ER_PERIOD_P1, ScheduleStatus::COMPLETED, [
            [11, self::ER_TEAM_U15, self::ER_VENUE_NORD, 1, '19:30', LockLevel::HARD],
            [12, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
        ]);
        self::getContainer()->get(SchedulePlanProvisioner::class)->pointLockSource($planId, self::ER_PERIOD_P1);

        $body = $this->runMoveValidation(self::erId('5107', 12), [1, '18:00', self::ER_VENUE_NORD]);

        $this->assertReference('validation-15-deplacement-periode', $body);
    }

    /** Deux équipes dans la même division : chacune son domicile à placer, une seule résout la fenêtre de ligue du samedi. */
    public function testPlacementWithTwoTeamsInOneDivision(): void
    {
        $this->seedSocleInForce();
        $this->seedEngineRequestMatches();
        $this->seedMatchesNetTwoTeamsOneDivision();
        $this->assertMatchesNetTwoTeamsOneDivisionWitnesses();

        $body = $this->runPlacement(null);

        $days = $this->leagueWindowDaysByTeam($body);
        self::assertSame([6], $days[self::ER_TEAM_U13] ?? null, 'témoin 16 : U13 F résout la fenêtre de ligue du samedi');
        self::assertSame([], $days[self::ER_NET_TEAM_U13_F2] ?? null, 'témoin 16 : U13 F2 ne résout aucune fenêtre');
        $this->assertReference('placement-16-deux-equipes-une-division', $body);
    }

    /**
     * Un match d'une compétition amicale n'est jamais confié au moteur (lot 7, PR 4a, Q6) : posé, c'est une ancre ;
     * non posé, il est absent — pour un amical de « Amicaux » comme pour « Amical PNF ». La référence a changé à la
     * PR 4a : le domicile non placé de « Amical PNF » sort de la demande, celui posé par le système devient une ancre.
     */
    public function testPlacementWithFriendlies(): void
    {
        $this->seedSocleInForce();
        $this->seedEngineRequestMatches();
        $this->seedMatchesNetFriendlies();
        $this->assertMatchesNetFriendliesWitnesses();

        $this->assertReference('placement-17-amicaux', $this->runPlacement(null));
    }

    /** Les stades du suivi fédéral : saisi, validé, avec ou sans gymnase, domicile et extérieur, et un domicile importé posé. */
    public function testPlacementWithTheFederalTrackingStages(): void
    {
        $this->seedSocleInForce();
        $this->seedEngineRequestMatches();
        $this->seedMatchesNetFederalTracking();
        $this->assertMatchesNetFederalTrackingWitnesses();

        $this->assertReference('placement-18-suivi-federal', $this->runPlacement(null));
    }

    /**
     * Les coupes, pour une équipe à fenêtre de ligue le samedi seulement et pour une équipe sans aucune fenêtre. Un
     * domicile de coupe n'est jamais confié au moteur (lot 7, PR 4b, Q7) : non placé, il est absent ; posé par le
     * système, c'est une ancre ; posé à la main, il l'était déjà. La référence a changé à la PR 4b : les trois domiciles
     * de coupe non placés sortent, celui posé par le système devient une ancre, et l'occupation d'entraînement du
     * mercredi 11 mars part avec eux (plus aucun match à placer ce jour-là). « Coupe Territoriale U13 », rangée
     * championnat, garde son domicile à placer : la nature décide, jamais le nom.
     */
    public function testPlacementWithCups(): void
    {
        $this->seedSocleInForce();
        $this->seedEngineRequestMatches();
        $this->seedMatchesNetCups();
        $this->assertMatchesNetCupsWitnesses();

        $body = $this->runPlacement(null);

        $days = $this->leagueWindowDaysByTeam($body);
        self::assertSame([6], $days[self::ER_TEAM_U13] ?? null, 'témoin 19 : U13 F a une fenêtre de ligue le samedi seulement');
        self::assertSame([], $days[self::ER_TEAM_U15] ?? null, 'témoin 19 : U15 M n\'a aucune fenêtre de ligue');
        $this->assertReference('placement-19-coupes', $body);
    }

    /**
     * Le socle de la saison suivante, née par le vrai geste depuis un socle complet en vigueur : aucune
     * mutualisation ne suit la saison, les trajets si.
     */
    public function testNextSeasonSocleAfterTheTransitionOfACompleteSocle(): void
    {
        $version = $this->seedEngineRequestTransitionedSocle();

        $run = $this->runGeneration($version->getId());

        self::assertSame(self::ER_TRANSITION_SEASON, $run['body']['seasonId'] ?? null, 'la demande est celle de la saison née de la bascule');
        self::assertSame([], $this->blockRows($run['body']), 'aucun groupe mutualisé ne suit la saison');
        self::assertNotSame([], $this->travelRows($run['body']), 'les temps de trajet suivent la saison');
        $this->assertReference('socle-20-saison-suivante-socle-complet', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], null);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    /** Le placement des matchs quand une équipe créée après la validation du socle, avec son coach, porte un domicile. */
    public function testPlacementWithATeamCreatedAfterTheValidation(): void
    {
        $this->seedSocleInForce();
        $this->seedEngineRequestMatches();
        $this->seedEngineRequestTeamCreatedAfterValidation();
        $this->assertTeamCreatedAfterValidationWitnesses();

        $body = $this->runPlacement(null);

        self::assertContains(self::ER_TEAM_U17_AFTER_VALIDATION, array_column(\is_array($body['teams'] ?? null) ? $body['teams'] : [], 'id'), 'témoin 21 : U17 F, créée après la validation, part au moteur');
        $this->assertReference('placement-21-equipe-creee-apres-validation', $body);
    }

    /**
     * Le socle d'un club au trajet obligatoire réglé (battement 10, temps par défaut 15), à la passerelle obligatoire et
     * à la contrainte permanente éteinte : la règle éteinte part au moteur, `isActive` faux.
     */
    public function testSocleWithARegulatedTravelRuleAMandatoryLinkAndARuleSwitchedOff(): void
    {
        $this->seedEngineRequestRulesNet();
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::DRAFT);
        $this->assertRulesNetWitnesses(null);

        $run = $this->runGeneration(self::ER_SOCLE_V1);

        $this->assertRulesNetRequest($run['body']);
        $this->assertReference('socle-23-trajet-passerelle-regle-eteinte', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], null);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    /**
     * Le même club, dans le plan de vacances réglé de la référence 12, où la règle éteinte dans la saison est cochée :
     * elle part au moteur, `isActive` faux, comme au socle.
     */
    public function testHolidayPlanWhereTheRuleSwitchedOffIsChecked(): void
    {
        $this->seedSocleInForce();
        $planId = $this->seedEngineRequestHolidaySettings();
        $this->seedEngineRequestRulesNet();
        $this->checkTheOffRuleIn($planId);
        $this->erVersion(self::ER_HOLIDAY_V1, self::ER_HOLIDAY_WEEK, ScheduleStatus::DRAFT, []);
        $this->assertRulesNetWitnesses($planId);

        $run = $this->runGeneration(self::ER_HOLIDAY_V1);

        $this->assertRulesNetRequest($run['body']);
        $this->assertReference('periode-24-vacances-regle-eteinte-cochee', $run['body']);
        $this->assertGenerationCoherence($run['body'], $run['schedule'], null);
        $this->assertSnapshotMatchesPlanStructure($run['schedule']);
    }

    /** Le placement des matchs quand les règles de match ont des bornes ouvertes (sans début, sans fin, ni l'un ni l'autre). */
    public function testPlacementWithOpenBoundMatchRules(): void
    {
        $this->seedSocleInForce();
        $this->seedEngineRequestMatches();
        $this->seedEngineRequestOpenBoundMatchRules();
        $this->assertOpenBoundMatchRulesWitnesses();

        $body = $this->runPlacement(null);

        self::assertContains(['ruleType' => 'HARD', 'daysOfWeek' => [7], 'kickoffMin' => '10:00', 'kickoffMax' => null], \is_array($body['clubRules'] ?? null) ? $body['clubRules'] : [], 'témoin 25 : la règle de club sans heure de fin part, bornes nulles comprises');
        self::assertContains(['coachId' => self::ER_COACH_B, 'daysOfWeek' => [7], 'kickoffMin' => null, 'kickoffMax' => null], \is_array($body['coachUnavailabilities'] ?? null) ? $body['coachUnavailabilities'] : [], 'témoin 25 : l\'indisponibilité de toute la journée part');
        $forbidden = array_column(\is_array($body['teams'] ?? null) ? $body['teams'] : [], 'forbiddenVenueIds', 'id');
        self::assertSame([self::ER_VENUE_NORD], $forbidden[self::ER_TEAM_SM1] ?? null, 'témoin 25 : SM1 est interdite du Nord');
        self::assertSame([], $forbidden[self::ER_TEAM_U13] ?? null, 'témoin 25 : une interdiction « souhaitée » n\'interdit rien');
        $this->assertReference('placement-25-regles-de-match-bornes-ouvertes', $body);
    }

    /**
     * Falsification de la purge du cache : une valeur DIFFÉRENTE amorcée sous la clé exacte du
     * payload de socle est (a) servie par le constructeur si l'on ne purge pas — le piège est réel —
     * et (b) n'atteint jamais le moteur sur le chemin du test, qui purge avant de composer.
     */
    public function testAPrimedCacheNeverReachesTheReference(): void
    {
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::DRAFT);
        $primed = ['amorce' => 'valeur qui n\'est pas la demande'];

        $this->primeSocleCache($primed);
        self::assertSame(
            $primed,
            self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForPlan(self::getContainer()->get(SoclePlan::class)->idOf(self::ER_SEASON)),
            'sans purge, le constructeur sert la valeur amorcée : la purge est nécessaire',
        );

        $this->primeSocleCache($primed);
        $run = $this->runGeneration(self::ER_SOCLE_V1);

        self::assertNotSame($primed, $run['body'], 'la valeur amorcée ne doit jamais atteindre le moteur');
        $this->assertReference('socle-1-premiere-generation-brouillon', $run['body']);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->pinEngineRequestClock();
        $this->seedEngineRequestBase();
    }

    protected function tearDown(): void
    {
        $this->unpinEngineRequestClock();
        parent::tearDown();
    }

    /** Le socle en vigueur des cas de période et de placement : V1 terminée, pointée et chargée, un verrou. */
    private function seedSocleInForce(): void
    {
        $this->seedSocleVersion(self::ER_SOCLE_V1, ScheduleStatus::COMPLETED, [
            [1, self::ER_TEAM_U13, self::ER_VENUE_NORD, 1, '18:00', LockLevel::HARD],
            [2, self::ER_TEAM_U15, self::ER_VENUE_NORD, 3, '18:00', LockLevel::NONE],
            [3, self::ER_TEAM_SM1, self::ER_VENUE_NORD, 4, '18:00', LockLevel::NONE],
        ]);
        $this->chooseAndLoadSocle(self::ER_SOCLE_V1);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>|null    $graftKeys
     */
    private function assertGenerationCoherence(array $body, Schedule $schedule, ?array $graftKeys): void
    {
        // Lot 5 : l'empreinte est le point d'entrée unique, la demande figée et la règle de trajet de la copie de la version.
        $travelRule = self::getContainer()->get(PlanStates::class)->travelRuleOfVersion($schedule->getId());
        self::assertSame(
            ScheduleConstraintBuilder::fingerprint($schedule->getSnapshotData(), $travelRule),
            $schedule->getSnapshotHash(),
            'snapshotHash = fingerprint(snapshotData, règle de trajet de la copie de la version)',
        );
        $graft = $schedule->getPayloadGraft();
        self::assertSame($graftKeys, null === $graft ? null : array_keys($graft), 'clés de la greffe payloadGraft');
        self::assertSame($schedule->engineInput(), $body, 'le corps reçu par le moteur = engineInput() relu en base');
    }

    private function assertSnapshotMatchesPlanStructure(Schedule $schedule): void
    {
        self::assertSame($this->structureHashOfPlanOf($schedule), $schedule->getSnapshotHash(), 'version fraîche : snapshotHash = structureHashOfPlan(plan)');
    }

    private function structureHashOfPlanOf(Schedule $schedule): ?string
    {
        $this->purgeEngineRequestCache();

        return self::getContainer()->get(SchedulePlanProvisioner::class)->structureHashOfPlan($schedule->getSchedulePlanId());
    }

    /** @param array<string, mixed> $value */
    private function primeSocleCache(array $value): void
    {
        $this->purgeEngineRequestCache();
        $pool = self::getContainer()->get('cache.schedule');
        self::assertInstanceOf(TagAwareCacheInterface::class, $pool);
        $pool->get(ScheduleConstraintBuilder::cacheKey(self::ER_CLUB, self::ER_SEASON, null), static function (ItemInterface $item) use ($value): array {
            $item->tag(ScheduleConstraintBuilder::cacheTag(self::ER_CLUB));
            $item->expiresAfter(600);

            return $value;
        });
    }

    /**
     * Témoins des références 23 et 24 dans la demande : le trajet réglé, la passerelle obligatoire, la règle éteinte.
     *
     * @param array<string, mixed> $body
     */
    private function assertRulesNetRequest(array $body): void
    {
        $rules = \is_array($body['implicitRules'] ?? null) ? $body['implicitRules'] : [];
        self::assertSame(['intensity' => 'MANDATORY', 'defaultMinutes' => 15, 'toleranceMinutes' => 10], $rules['travelTime'] ?? null, 'le trajet part obligatoire, temps par défaut 15, battement 10');
        $links = array_column(\is_array($body['teamLinks'] ?? null) ? $body['teamLinks'] : [], 'intensity', 'id');
        self::assertSame('MANDATORY', $links[self::ER_LINK_MANDATORY] ?? null, 'la passerelle part obligatoire');
        $off = array_values(array_filter(
            \is_array($body['constraints'] ?? null) ? $body['constraints'] : [],
            static fn (mixed $row): bool => \is_array($row) && self::ER_RULE_OFF === ($row['id'] ?? null),
        ));
        self::assertCount(1, $off, 'la règle éteinte part au moteur');
        self::assertFalse($off[0]['isActive'] ?? null, 'la règle éteinte part avec isActive faux');
    }

    /**
     * Les groupes mutualisés de la demande : [id, équipes membres].
     *
     * @param array<string, mixed> $body
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function blockRows(array $body): array
    {
        return array_map(
            static fn (array $block): array => [$block['id'] ?? null, $block['teamIds'] ?? null],
            array_values(array_filter(\is_array($body['sharedBlocks'] ?? null) ? $body['sharedBlocks'] : [], \is_array(...))),
        );
    }
}
