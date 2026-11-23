<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Person;
use App\Entity\Place;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\SchedulePlanProvisioner;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use ReflectionProperty;

/**
 * Ce que le lot des personnes et des lieux déplace, posé sur le décor des demandes au moteur : les temps de trajet
 * entre gymnases, les statuts « salarié » et « véhiculé » des coachs, et un club à DEUX saisons. S'emploie AVEC
 * `EngineRequestDecorTrait` (il en lit les identifiants et `erConstraint`).
 *
 * Saison en cours (2025-2026, celle du décor de base) : un troisième gymnase, Est, et trois couples de trajet —
 * Nord–Sud saisi à la main, Nord–Est calculé, Sud–Est sans minutes ; Alix salariée et véhiculée, Bao ni l'un ni
 * l'autre.
 *
 * Saison suivante (2026-2027, brouillon) : construite À LA MAIN avec des identifiants fixes — le geste « Préparer la
 * saison suivante » tire les siens au hasard (`SeasonTransitionService`), une référence captée dessus ne se
 * rejouerait pas. Mêmes trois gymnases, mais Sud y a un identifiant PLUS PETIT que Nord : le couple Nord–Sud est
 * rangé à l'envers d'une saison à l'autre (« plus petit identifiant de gymnase d'abord »). Mêmes couples, minutes
 * différentes ; deux équipes, deux coachs (salarié véhiculé, non véhiculé), une règle de coach.
 *
 * Depuis le lot 3, PR 5, le temps de trajet vit au club entre deux LIEUX (`place_travel_time`). Sud et Est gardent
 * leurs lieux d'une saison à l'autre (le couple Sud–Est, sans minutes, est UNE ligne servie aux deux saisons) ; Nord
 * passe sur un lieu à lui, d'identifiant fixé le plus petit du décor : Nord–Sud de la saison suivante est rangé en base
 * Nord d'abord, et doit partir au moteur Sud d'abord. Les deux références restent celles captées sur le code d'avant.
 *
 * Consommé par `Integration/EngineRequestReferenceTest` (références 9 et 10) et
 * `Integration/Command/EngineRequestsRecordCommandTest`.
 */
trait EngineRequestTravelDecorTrait
{
    private const string ER_VENUE_EST = 'be0e0000-0000-4000-8000-000000000003';
    /** Le plus grand identifiant de lieu du décor (les lieux de Nord et Sud de la saison en cours sont tirés au hasard). */
    private const string ER_PLACE_EST = 'ffffffff-0000-4000-8000-000000000003';
    /** Le plus petit : Nord–Sud de la saison suivante se range ainsi en base à l'inverse de ses gymnases. */
    private const string ER_NEXT_PLACE_NORD = '00000000-0000-4000-8000-000000000022';

    private const string ER_NEXT_SEASON = '5ea50000-0000-4000-8000-000000000002';
    private const string ER_NEXT_VENUE_SUD = 'be0e0000-0000-4000-8000-000000000021';
    private const string ER_NEXT_VENUE_NORD = 'be0e0000-0000-4000-8000-000000000022';
    private const string ER_NEXT_VENUE_EST = 'be0e0000-0000-4000-8000-000000000023';
    private const string ER_NEXT_TEAM_U13 = '7ea00000-0000-4000-8000-000000000021';
    private const string ER_NEXT_TEAM_SM1 = '7ea00000-0000-4000-8000-000000000022';
    private const string ER_NEXT_COACH_A = 'c0ac0000-0000-4000-8000-000000000021';
    private const string ER_NEXT_COACH_B = 'c0ac0000-0000-4000-8000-000000000022';
    private const string ER_NEXT_SOCLE_V1 = '5c4e0000-0000-4000-8000-000000000021';

    /** La saison en cours : le gymnase Est, ses créneaux, les trois couples de trajet, les statuts des coachs. */
    private function seedEngineRequestTravelOfCurrentSeason(): void
    {
        $em = $this->erEm();
        $place = $this->erPlace(self::ER_PLACE_EST);
        $em->persist($place);
        $em->persist(new Venue($place)->setId(self::ER_VENUE_EST)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setName('Gymnase Est')->setCanSplit(false)->setSource('manual'));
        foreach ([[7, 2, '19:30'], [8, 5, '18:00']] as [$n, $day, $time]) {
            $em->persist((new VenueTrainingSlot)->setId(self::erId('a7a1', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
                ->setVenueId(self::ER_VENUE_EST)->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($time))
                ->setDurationMinutes(90)->setCapacity(1));
        }

        foreach ([[self::ER_COACH_A, true, true], [self::ER_COACH_B, false, false]] as [$coachId, $employee, $vehicled]) {
            $coach = $em->getRepository(Coach::class)->find($coachId);
            Assert::assertInstanceOf(Coach::class, $coach);
            $coach->setIsEmployee($employee)->setIsVehicled($vehicled);
        }
        $em->flush();

        $this->erTravelTime(1, $this->erPlaceOf(self::ER_VENUE_NORD), $this->erPlaceOf(self::ER_VENUE_SUD), 12, 30, 'MANUAL');
        $this->erTravelTime(2, $this->erPlaceOf(self::ER_VENUE_NORD), self::ER_PLACE_EST, 8, 20, 'AUTO');
        // Sud–Est vaut pour les deux saisons : la saison suivante reprend ces deux lieux.
        $this->erTravelTime(3, $this->erPlaceOf(self::ER_VENUE_SUD), self::ER_PLACE_EST, null, null, null);
    }

    /**
     * La saison suivante, à la main, et une version brouillon de son socle. Rend la version.
     */
    private function seedEngineRequestNextSeason(): Schedule
    {
        $em = $this->erEm();
        $season = (new Season)->setId(self::ER_NEXT_SEASON)->setClubId(self::ER_CLUB)->setName('2026-2027')
            ->setStartDate(new DateTimeImmutable('2026-08-01'))->setEndDate(new DateTimeImmutable('2027-07-15'))
            ->setStatus(SeasonStatus::DRAFT)->setTransitionData([]);
        $em->persist($season);
        $em->flush();
        $provisioner = static::getContainer()->get(SchedulePlanProvisioner::class);
        $provisioner->ensureSeasonPlan($season);
        $em->flush();

        // Sud et Est restent aux lieux de la saison en cours ; Nord passe sur un lieu à lui (ses minutes diffèrent).
        $nextNordPlace = $this->erPlace(self::ER_NEXT_PLACE_NORD);
        $em->persist($nextNordPlace);
        foreach ([
            [self::ER_NEXT_VENUE_SUD, 'Gymnase Sud', false, $this->erPlaceEntityOf(self::ER_VENUE_SUD)],
            [self::ER_NEXT_VENUE_NORD, 'Gymnase Nord', true, $nextNordPlace],
            [self::ER_NEXT_VENUE_EST, 'Gymnase Est', false, $this->erPlaceEntityOf(self::ER_VENUE_EST)],
        ] as [$venueId, $name, $canSplit, $place]) {
            $em->persist(new Venue($place)->setId($venueId)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_NEXT_SEASON)
                ->setName($name)->setCanSplit($canSplit)->setSource('manual'));
        }
        foreach ([
            [21, self::ER_NEXT_VENUE_SUD, 2, '18:00', 1],
            [22, self::ER_NEXT_VENUE_NORD, 1, '18:00', 2],
            [23, self::ER_NEXT_VENUE_NORD, 3, '19:30', 2],
            [24, self::ER_NEXT_VENUE_EST, 4, '18:00', 1],
        ] as [$n, $venueId, $day, $time, $capacity]) {
            $em->persist((new VenueTrainingSlot)->setId(self::erId('a7a1', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_NEXT_SEASON)
                ->setVenueId($venueId)->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($time))
                ->setDurationMinutes(90)->setCapacity($capacity));
        }
        foreach ([[self::ER_NEXT_TEAM_U13, 'U13 F', 3], [self::ER_NEXT_TEAM_SM1, 'SM1', 1]] as [$teamId, $name, $tier]) {
            $em->persist((new Team)->setId($teamId)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_NEXT_SEASON)
                ->setSportCategoryId(self::ER_CATEGORY)->setPriorityTierId($tier)->setName($name)
                ->setSessionsPerWeek(2)->setIsActive(true));
        }
        $alix = (new Person)->setClubId(self::ER_CLUB)->setFirstName('Alix')->setLastName('Entraîneur');
        $bao = (new Person)->setClubId(self::ER_CLUB)->setFirstName('Bao')->setLastName('Entraîneur');
        $em->persist($alix);
        $em->persist($bao);
        $em->persist(new Coach($alix)->setId(self::ER_NEXT_COACH_A)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_NEXT_SEASON)
            ->setIsEmployee(true)->setIsVehicled(true));
        $em->persist(new Coach($bao)->setId(self::ER_NEXT_COACH_B)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_NEXT_SEASON)
            ->setIsEmployee(false)->setIsVehicled(false));
        $em->flush();
        foreach ([[21, self::ER_NEXT_TEAM_U13, self::ER_NEXT_COACH_A], [22, self::ER_NEXT_TEAM_SM1, self::ER_NEXT_COACH_B]] as [$n, $teamId, $coachId]) {
            $em->persist((new TeamCoach)->setId(self::erId('7c0a', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_NEXT_SEASON)
                ->setTeamId($teamId)->setCoachId($coachId)->setRole(TeamCoachRole::MAIN));
        }
        $em->persist((new Constraint)->setId(self::erId('c057', 21))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_NEXT_SEASON)
            ->setName('Contrainte 21')->setScope(ConstraintScope::COACH)->setScopeTargetId(self::ER_NEXT_COACH_B)
            ->setFamily(ConstraintFamily::COACH_AVAILABILITY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['unavailableDays' => [3]]));

        $em->flush();
        // Les couples du lieu de Nord de cette saison. Nord–Sud est rangé lieu de Nord d'abord en base (le plus petit
        // identifiant de lieu du décor), mais part au moteur Sud d'abord (gymnases 21 < 22) : le rangement de sortie
        // suit les gymnases de la saison demandée, jamais les lieux. Sud–Est est celui de la saison en cours.
        $this->erTravelTime(21, self::ER_NEXT_PLACE_NORD, $this->erPlaceOf(self::ER_VENUE_SUD), 15, 35, 'MANUAL');
        $this->erTravelTime(22, self::ER_NEXT_PLACE_NORD, self::ER_PLACE_EST, 9, 22, 'AUTO');

        $planId = $provisioner->ensureSeasonPlanId(self::ER_NEXT_SEASON);
        Assert::assertIsString($planId);
        $plan = $em->getRepository(SchedulePlan::class)->find($planId);
        Assert::assertInstanceOf(SchedulePlan::class, $plan);
        $schedule = (new Schedule)->setId(self::ER_NEXT_SOCLE_V1)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_NEXT_SEASON)
            ->setName('Version 21')->setStatus(ScheduleStatus::DRAFT)->setSchedulePlanId($planId);
        $em->persist($schedule);
        $provisioner->linkSchedule($schedule);
        $em->flush();

        return $schedule;
    }

    /**
     * Le bloc des temps de trajet d'une demande, `[[gymnase A, gymnase B, voiture, vélo], …]`. Témoin positif : une
     * référence figée sur une demande sans trajets ne prouverait rien de l'ordre des couples.
     *
     * @param array<string, mixed> $body
     *
     * @return list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed}>
     */
    private function travelRows(array $body): array
    {
        Assert::assertIsArray($body['venueTravelTimes'] ?? null, 'la demande porte ses temps de trajet');

        return array_map(static function (mixed $row): array {
            Assert::assertIsArray($row);

            return [$row['venueAId'] ?? null, $row['venueBId'] ?? null, $row['drivingMinutes'] ?? null, $row['walkingMinutes'] ?? null];
        }, array_values($body['venueTravelTimes']));
    }

    /**
     * Un temps de trajet au club entre deux lieux (table `place_travel_time`, lot 3 PR 5), couple rangé « plus petit
     * identifiant de LIEU d'abord » comme en base. Écrit en SQL sur la connexion du décor (RLS du club posée).
     */
    private function erTravelTime(int $n, string $placeA, string $placeB, ?int $driving, ?int $walking, ?string $source): void
    {
        if (strcmp($placeA, $placeB) > 0) {
            [$placeA, $placeB] = [$placeB, $placeA];
        }
        $this->erEm()->getConnection()->executeStatement(
            'INSERT INTO place_travel_time (id, version, created_at, updated_at, club_id, place_a_id, place_b_id, driving_minutes, walking_minutes, driving_source, walking_source)'
            . ' VALUES (?, 1, now(), now(), ?, ?, ?, ?, ?, ?, ?)',
            [self::erId('7a7e', $n), self::ER_CLUB, $placeA, $placeB, $driving, $walking, null === $driving ? null : $source, null === $walking ? null : $source],
        );
    }

    /** Un lieu du club à l'identifiant fixé : le rangement des couples en base ne dépend plus du hasard. */
    private function erPlace(string $id): Place
    {
        $place = (new Place)->setClubId(self::ER_CLUB);
        new ReflectionProperty(Place::class, 'id')->setValue($place, $id);

        return $place;
    }

    private function erPlaceOf(string $venueId): string
    {
        return $this->erPlaceEntityOf($venueId)->getId();
    }

    private function erPlaceEntityOf(string $venueId): Place
    {
        $venue = $this->erEm()->getRepository(Venue::class)->find($venueId);
        Assert::assertInstanceOf(Venue::class, $venue);
        $place = $this->erEm()->find(Place::class, $venue->getPlaceId());
        Assert::assertInstanceOf(Place::class, $place);

        return $place;
    }
}
