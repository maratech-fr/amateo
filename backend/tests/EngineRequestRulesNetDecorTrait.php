<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Constraint;
use App\Entity\MatchConstraint;
use App\Entity\TeamLink;
use App\Entity\VenueTravelRuleSetting;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\TeamLinkIntensity;
use App\Enum\TeamLinkType;
use App\Enum\VenueTravelRuleIntensity;
use App\Plans\PlanComposition;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/**
 * Le filet du lot 9 (les règles), posé sur le décor des demandes au moteur (références 23 à 25), capté sur le code
 * d'avant le lot :
 *
 *  23. le socle d'un club qui règle ses règles hors des défauts : trajet entre gymnases « Obligatoire », battement
 *      10 minutes, temps par défaut 15 minutes, avec un temps de trajet saisi (Nord–Sud) ; une passerelle U15 M → SM1
 *      obligatoire ; une contrainte permanente ÉTEINTE (« SM1 pas avant 20:30 », case « active » décochée). La règle
 *      éteinte part au moteur avec `isActive` faux ;
 *  24. le même club, dans le plan de vacances réglé de la référence 12, où la règle éteinte est COCHÉE : elle part
 *      au moteur, toujours avec `isActive` faux (aujourd'hui, une règle éteinte l'est dans tous les plans) ;
 *  25. le placement des matchs quand les règles de match ont des bornes ouvertes : une règle de club sans heure de
 *      fin, une sans heure de début, une deuxième équipe interdite d'un gymnase, une interdiction « souhaitée » que le
 *      placement ignore, trois indisponibilités de coach (sans fin, sans début, toute la journée).
 *
 * Les lignes ajoutées par 23 et 24 sont les MÊMES, écrites en SQL dans le schéma d'avant le lot 4 par
 * `tests/Fixtures/lot-9/complements.sql`, sur la section de base de `tests/Fixtures/lot-4/decor-avant.sql` :
 * `Integration/Migration/Lot4MigrationChainTest` compare aux mêmes fichiers. Un identifiant changé ici se change là.
 *
 * Chaque cas lit ses témoins en base AVANT la composition : un témoin absent fait échouer le test.
 *
 * S'emploie AVEC `EngineRequestDecorTrait` et `EngineRequestPlanSettingsDecorTrait`. Consommé par
 * `Integration/EngineRequestReferenceTest`.
 */
trait EngineRequestRulesNetDecorTrait
{
    /** La contrainte permanente éteinte : SM1 pas avant 20:30. */
    private const string ER_RULE_OFF = 'c0570000-0000-4000-8000-000000000023';
    /** La passerelle obligatoire U15 M → SM1. */
    private const string ER_LINK_MANDATORY = '11c00000-0000-4000-8000-000000000023';

    /** Références 23 et 24 : trajet réglé et sa matrice, passerelle obligatoire, contrainte permanente éteinte. */
    private function seedEngineRequestRulesNet(): void
    {
        $em = $this->erEm();
        $em->persist((new VenueTravelRuleSetting)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setIntensity(VenueTravelRuleIntensity::MANDATORY)->setToleranceMinutes(10)->setDefaultMinutes(15));
        $em->persist((new TeamLink)->setId(self::ER_LINK_MANDATORY)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setTeamAId(self::ER_TEAM_U15)->setTeamBId(self::ER_TEAM_SM1)->setLinkType(TeamLinkType::BACK_TO_BACK)
            ->setTrainingIntensity(TeamLinkIntensity::MANDATORY));
        $this->erConstraint(23, ConstraintScope::TEAM, self::ER_TEAM_SM1, ConstraintFamily::TIME, ConstraintRuleType::HARD, ['minStartTime' => '20:30']);
        $em->flush();
        $off = $em->find(Constraint::class, self::ER_RULE_OFF);
        Assert::assertInstanceOf(Constraint::class, $off);
        $off->setIsActive(false);
        $em->flush();
        // Comme une saisie : la porte pose la ligne de la règle dans chaque plan de période, au défaut de sa nature.
        static::getContainer()->get(PlanComposition::class)->ensureLines(self::ER_SEASON);
        $this->erEngineRequestTravelTime(23, 14, 35);
    }

    /** Référence 24 : la règle éteinte cochée dans le plan de vacances (une règle d'équipe n'y est pas cochée par défaut). */
    private function checkTheOffRuleIn(string $planId): void
    {
        static::getContainer()->get(PlanComposition::class)->constraintsOf($planId)[self::ER_RULE_OFF]->setApplies(true);
        $this->erEm()->flush();
    }

    /** Témoins des références 23 et 24, lus en base avant la composition. `$holidayPlanId` : la coche de la 24. */
    private function assertRulesNetWitnesses(?string $holidayPlanId): void
    {
        $this->scopeGucToClub(self::ER_CLUB);
        $connection = $this->erEm()->getConnection();
        foreach ([
            'SELECT COUNT(*) FROM venue_travel_rule_setting WHERE season_id = :s AND intensity = \'MANDATORY\' AND tolerance_minutes = 10 AND default_minutes = 15' => 1,
            'SELECT COUNT(*) FROM place_travel_time WHERE club_id = (SELECT club_id FROM season WHERE id = :s)' => 1,
            'SELECT COUNT(*) FROM team_link WHERE season_id = :s AND training_intensity = \'MANDATORY\'' => 1,
            'SELECT COUNT(*) FROM "constraint" WHERE season_id = :s AND calendar_entry_id IS NULL AND NOT is_active' => 1,
        ] as $sql => $expected) {
            Assert::assertSame($expected, (int) $connection->fetchOne($sql, ['s' => self::ER_SEASON]), 'témoin 23-24 : ' . $sql);
        }
        if (null !== $holidayPlanId) {
            Assert::assertTrue((bool) $connection->fetchOne(
                'SELECT applies FROM plan_constraint WHERE schedule_plan_id = :p AND constraint_id = :c',
                ['p' => $holidayPlanId, 'c' => self::ER_RULE_OFF],
            ), 'témoin 24 : la règle éteinte est cochée dans le plan de vacances');
        }
    }

    /**
     * Référence 25 : les règles de match aux bornes ouvertes, ajoutées aux matchs du décor (`seedEngineRequestMatches`).
     */
    private function seedEngineRequestOpenBoundMatchRules(): void
    {
        $em = $this->erEm();
        foreach ([
            // [n, portée, cible, cran, jours, début, fin, gymnase]
            [25, ConstraintScope::CLUB, null, ConstraintRuleType::HARD, [7], '10:00', null, null],
            [26, ConstraintScope::CLUB, null, ConstraintRuleType::PREFERRED, [6], null, '20:00', null],
            [27, ConstraintScope::TEAM, self::ER_TEAM_SM1, ConstraintRuleType::HARD, [], null, null, self::ER_VENUE_NORD],
            [28, ConstraintScope::TEAM, self::ER_TEAM_U13, ConstraintRuleType::PREFERRED, [], null, null, self::ER_VENUE_SUD],
            [29, ConstraintScope::COACH, self::ER_COACH_B, ConstraintRuleType::PREFERRED, [6], '18:00', null, null],
            [30, ConstraintScope::COACH, self::ER_COACH_A, ConstraintRuleType::PREFERRED, [6], null, '11:00', null],
            [31, ConstraintScope::COACH, self::ER_COACH_B, ConstraintRuleType::PREFERRED, [7], null, null, null],
        ] as [$n, $scope, $target, $ruleType, $days, $min, $max, $venue]) {
            $em->persist((new MatchConstraint)->setId(self::erId('acc0', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
                ->setScope($scope)->setScopeTargetId($target)->setRuleType($ruleType)->setDaysOfWeek($days)
                ->setKickoffMin(null === $min ? null : new DateTimeImmutable($min))->setKickoffMax(null === $max ? null : new DateTimeImmutable($max))
                ->setVenueId($venue));
        }
        $em->flush();
    }

    /** Témoins de la référence 25 : chaque borne ouverte est en base, et l'interdiction « souhaitée » aussi. */
    private function assertOpenBoundMatchRulesWitnesses(): void
    {
        $this->scopeGucToClub(self::ER_CLUB);
        $connection = $this->erEm()->getConnection();
        foreach ([
            'SELECT COUNT(*) FROM match_constraint WHERE season_id = :s AND scope = \'CLUB\' AND kickoff_min IS NOT NULL AND kickoff_max IS NULL' => 1,
            'SELECT COUNT(*) FROM match_constraint WHERE season_id = :s AND scope = \'CLUB\' AND kickoff_min IS NULL AND kickoff_max IS NOT NULL' => 1,
            'SELECT COUNT(DISTINCT scope_target_id) FROM match_constraint WHERE season_id = :s AND scope = \'TEAM\' AND rule_type = \'HARD\' AND venue_id IS NOT NULL' => 2,
            'SELECT COUNT(*) FROM match_constraint WHERE season_id = :s AND scope = \'TEAM\' AND rule_type = \'PREFERRED\'' => 1,
            'SELECT COUNT(*) FROM match_constraint WHERE season_id = :s AND scope = \'COACH\' AND kickoff_min IS NOT NULL AND kickoff_max IS NULL' => 1,
            'SELECT COUNT(*) FROM match_constraint WHERE season_id = :s AND scope = \'COACH\' AND kickoff_min IS NULL AND kickoff_max IS NOT NULL' => 1,
            'SELECT COUNT(*) FROM match_constraint WHERE season_id = :s AND scope = \'COACH\' AND kickoff_min IS NULL AND kickoff_max IS NULL' => 1,
        ] as $sql => $expected) {
            Assert::assertSame($expected, (int) $connection->fetchOne($sql, ['s' => self::ER_SEASON]), 'témoin 25 : ' . $sql);
        }
    }

    /** Le temps de trajet Nord–Sud, au club entre les deux lieux (`place_travel_time`), couple rangé « plus petit lieu d'abord ». */
    private function erEngineRequestTravelTime(int $n, int $driving, int $walking): void
    {
        $connection = $this->erEm()->getConnection();
        $places = array_map(strval(...), $connection->fetchFirstColumn(
            'SELECT place_id::text FROM venue WHERE id IN (:nord, :sud) ORDER BY place_id',
            ['nord' => self::ER_VENUE_NORD, 'sud' => self::ER_VENUE_SUD],
        ));
        Assert::assertCount(2, $places, 'Nord et Sud ont chacun leur lieu');
        $connection->executeStatement(
            'INSERT INTO place_travel_time (id, version, created_at, updated_at, club_id, place_a_id, place_b_id, driving_minutes, walking_minutes, driving_source, walking_source)'
            . ' VALUES (?, 1, now(), now(), ?, ?, ?, ?, ?, \'MANUAL\', \'MANUAL\')',
            [self::erId('7a7e', $n), self::ER_CLUB, $places[0], $places[1], $driving, $walking],
        );
    }
}
