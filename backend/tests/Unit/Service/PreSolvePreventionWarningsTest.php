<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\TeamCoach;
use App\Enum\TeamCoachRole;
use App\Service\PreSolvePreventionWarnings;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\CrossStack\SharedBlockPreSolveMirrorParityTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * P4-57 / ALIGN-09 — les préventions AVANT génération : des AVERTISSEMENTS ({@see detect}) et,
 * depuis ALIGN-09, des BLOQUEURS ({@see detectBlockers}).
 *
 * Chaque cas est monté en DEUX temps : le montage fautif doit avertir/bloquer, ET le montage sain
 * doit se taire. Un warning qui ne sait pas se taire est pire que pas de warning — le gestionnaire
 * apprend à ignorer le bandeau, et le jour où il dit vrai personne ne le lit.
 *
 * Le payload employé ici est celui qui part au moteur (`ScheduleConstraintBuilder`) : mêmes
 * clés, mêmes formes — y compris le mélange assumé v1/v2 (le lien équipe⇄coach voyage en
 * `type: TEAM_COACH` avec `metadata.coachId`, pas en contrainte v2).
 */
#[Group('phase1')]
final class PreSolvePreventionWarningsTest extends TestCase
{
    private const string ASSISTANT_WITHOUT_MAIN = 'U15 a un adjoint (Emma D.) mais aucun coach principal : nommez un coach principal, ou retirez l\'adjoint si l\'équipe est en autonomie.';

    private PreSolvePreventionWarnings $warnings;

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function forcedDayClosers(): iterable
    {
        yield 'indisponibilité' => [['scope' => 'COACH', 'scopeTargetId' => 'anna', 'family' => 'COACH_AVAILABILITY', 'ruleType' => 'HARD', 'config' => ['unavailableDays' => [4]]]];
        yield 'fenêtre horaire' => [['scope' => 'TEAM', 'scopeTargetId' => 'u15', 'family' => 'TIME', 'ruleType' => 'HARD', 'config' => ['minStartTime' => '20:00']]];
    }

    /** @return iterable<string, array{list<int>, int, list<int>, list<int>, string}> */
    public static function blockHeads(): iterable
    {
        yield 'aucun créneau' => [[2], 1, [], [2], 'Groupe U9F1, U9F2 : aucun créneau où au moins un coach du groupe n\'est disponible pour une séance commune — la génération échouera.'];
        yield 'seulement 1 créneau' => [[2, 3], 2, [], [3], 'Groupe U9F1, U9F2 : seulement 1 créneau où un coach du groupe est disponible, pour 2 séances communes — la génération échouera.'];
        yield 'seulement 2 créneaux' => [[2, 3, 4], 3, [], [4], 'Groupe U9F1, U9F2 : seulement 2 créneaux où un coach du groupe est disponible, pour 3 séances communes — la génération échouera.'];
        yield '1 case réservée et aucun' => [[2, 3], 2, [2], [3], 'Groupe U9F1, U9F2 : 1 case réservée et aucun créneau où un coach du groupe est disponible, pour 2 séances communes — la génération échouera.'];
        yield '2 cases réservées et aucun' => [[2, 3, 4], 3, [2, 3], [4], 'Groupe U9F1, U9F2 : 2 cases réservées et aucun créneau où un coach du groupe est disponible, pour 3 séances communes — la génération échouera.'];
        yield '1 case réservée et seulement 1' => [[2, 3, 4], 3, [2], [4], 'Groupe U9F1, U9F2 : 1 case réservée et seulement 1 créneau où un coach du groupe est disponible, pour 3 séances communes — la génération échouera.'];
    }

    /** @return iterable<string, array{string, int, array<string, list<int>>, string}> */
    public static function tooFewDays(): iterable
    {
        yield 'un seul jour' => ['U15', 2, ['allowedDays' => [1]], 'U15 demande 2 séances, mais ses règles n\'ouvrent qu\'un seul jour.'];
        yield 'deux jours' => ['SM2', 3, ['allowedDays' => [1, 2]], 'SM2 demande 3 séances, mais ses règles n\'ouvrent que 2 jours.'];
        yield 'aucun, autorisé moins interdit' => ['U11', 1, ['allowedDays' => [1], 'forbiddenDays' => [1]], 'U11 demande 1 séance, mais ses règles n\'ouvrent aucun jour.'];
        yield 'aucun, tout interdit' => ['U11', 1, ['forbiddenDays' => [1, 2, 3, 4, 5, 6, 7]], 'U11 demande 1 séance, mais ses règles n\'ouvrent aucun jour.'];
    }

    /** @return iterable<string, array{int, list<int>, list<int>, string}> */
    public static function missingSessions(): iterable
    {
        yield '0 réservée' => [1, [], [5], 'U15 n\'autorise que des jours où aucun créneau ne lui est ouvert : elle ne pourra pas être placée.'];
        yield '1 réservée, 1 manque' => [2, [1], [5], 'U15 n\'autorise que des jours où aucun créneau ne lui est ouvert : 1 séance est réservée, 1 séance manquera.'];
        yield '1 réservée, 2 manquent' => [3, [1], [5, 6], 'U15 n\'autorise que des jours où aucun créneau ne lui est ouvert : 1 séance est réservée, 2 séances manqueront.'];
        yield '2 réservées, 1 manque' => [3, [1, 2], [5], 'U15 n\'autorise que des jours où aucun créneau ne lui est ouvert : 2 séances sont réservées, 1 séance manquera.'];
    }

    public function testAVenueWithoutAnySlotIsFlagged(): void
    {
        $found = $this->warnings->detect($this->payload(venues: [
            $this->venue('v1', 'Matéo', [1]),
            $this->venue('v2', 'Debarros', []),
        ]));

        self::assertContains(
            'Le gymnase Debarros n\'a aucun créneau : il ne servira pas à la génération. Ajoutez ses disponibilités, ou retirez-le.',
            $found,
        );
    }

    public function testAVenueThatHasSlotsIsSilent(): void
    {
        $found = $this->warnings->detect($this->payload(venues: [$this->venue('v1', 'Matéo', [1])]));

        self::assertSame([], array_filter($found, static fn (string $w): bool => str_contains($w, 'aucun créneau : il ne servira')));
    }

    /** Le gymnase IMPOSÉ de l'équipe n'offre rien : le placement est impossible, pas improbable. */
    public function testATeamForcedIntoAnEmptyVenueIsFlagged(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1]), $this->venue('v2', 'Debarros', [])],
            teams: [$this->team('t1', 'U13 F1', forcedVenueId: 'v2')],
        ));

        self::assertContains('U13 F1 est imposée dans un gymnase qui n\'a aucun créneau : elle ne pourra pas être placée.', $found);
    }

    /** Ses jours autorisés ne croisent aucun créneau ouvert : même certitude, autre cause. */
    public function testATeamWhoseAllowedDaysMatchNoOpenSlotIsFlagged(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2])],
            teams: [$this->team('t1', 'SM4')],
            constraints: [$this->dayRule('TEAM', 't1', ['allowedDays' => [5]])],
        ));

        self::assertContains('SM4 n\'autorise que des jours où aucun créneau ne lui est ouvert : elle ne pourra pas être placée.', $found);
    }

    /**
     * ⚠ Le cas qui empêche le faux positif : les jours autorisés CROISENT un créneau. Sans
     * cette garde, la règle « intersection vide » se serait confondue avec « restriction
     * quelconque » et aurait crié sur la moitié des clubs.
     */
    public function testATeamWhoseAllowedDaysMeetAnOpenSlotIsSilent(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2])],
            teams: [$this->team('t1', 'SM4')],
            constraints: [$this->dayRule('TEAM', 't1', ['allowedDays' => [2, 5]])],
        ));

        self::assertSame([], array_filter($found, static fn (string $w): bool => str_contains($w, 'ne pourra pas être placée')));
    }

    public function testACoachWithMoreSessionsThanAvailableDaysIsFlagged(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2])],
            teams: [$this->team('t1', 'SM1', sessions: 2), $this->team('t2', 'SM2', sessions: 2)],
            coaches: [['id' => 'c1', 'firstName' => 'Alex', 'lastName' => 'Martin']],
            constraints: [$this->teamCoach('t1', 'c1'), $this->teamCoach('t2', 'c1')],
        ));

        // 4 séances pour 2 jours ouverts.
        self::assertContains(
            'Alex Martin est le seul coach de 4 séances par semaine mais n\'a que 2 jours disponibles : plusieurs séances tomberont le même jour.',
            $found,
        );
    }

    /** Sa charge tient dans ses jours : silence. */
    public function testACoachWithinHisAvailableDaysIsSilent(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2, 3])],
            teams: [$this->team('t1', 'SM1', sessions: 2)],
            coaches: [['id' => 'c1', 'firstName' => 'Alex', 'lastName' => 'Martin']],
            constraints: [$this->teamCoach('t1', 'c1')],
        ));

        self::assertSame([], $this->overloadWarnings($found));
    }

    /** Un co-entraîneur principal peut tenir la séance : Anna n'en est seule que sur 2, pas sur 5. */
    public function testSessionsSharedWithAnotherMainCoachAreNotCounted(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2, 3])],
            teams: [$this->team('t1', 'SM1', sessions: 3), $this->team('t2', 'SM2', sessions: 2)],
            coaches: [$this->coach('anna', 'Anna', 'Martin'), $this->coach('ben', 'Ben', 'Durand')],
            constraints: [
                $this->teamCoach('t1', 'anna'),
                $this->teamCoach('t1', 'ben'),
                $this->teamCoach('t2', 'anna'),
            ],
        ));

        self::assertSame([], $this->overloadWarnings($found));
    }

    /** Un adjoint n'est pas requis à la séance : même seul sur l'équipe, il ne cumule aucune charge. */
    public function testAnAssistantCarriesNoLoad(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'SM1', sessions: 3)],
            coaches: [$this->coach('anna', 'Anna', 'Martin')],
            constraints: [$this->teamCoach('t1', 'anna', 'ASSISTANT')],
        ));

        self::assertSame([], $this->overloadWarnings($found));
    }

    /** Une plage qui commence à la fin du créneau (19:30 pour 18:00-19:30) ne ferme pas le jour : `unavailableDays` seul ne dit pas tout. */
    public function testAnUnavailabilityStartingAfterTheSlotDoesNotCloseTheDay(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venueWithStarts('v1', 'Matéo', [[1, '18:00:00'], [2, '18:00:00'], [3, '18:00:00']])],
            teams: [$this->team('t1', 'SM1', sessions: 3)],
            coaches: [$this->coach('anna', 'Anna', 'Martin')],
            constraints: [
                $this->teamCoach('t1', 'anna'),
                $this->coachUnavailableFrom('anna', [3], '19:30'),
            ],
        ));

        self::assertSame([], $this->overloadWarnings($found));
    }

    /** Une plage qui coupe l'unique créneau du jour (dès 19:00 pour 18:00-19:30) lui retire ce jour, comme à la pose. */
    public function testAnUnavailabilityCuttingTheOnlySlotClosesTheDayForTheOverload(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venueWithStarts('v1', 'Matéo', [[1, '18:00:00'], [2, '18:00:00'], [3, '18:00:00']])],
            teams: [$this->team('t1', 'SM1', sessions: 3)],
            coaches: [$this->coach('anna', 'Anna', 'Martin')],
            constraints: [
                $this->teamCoach('t1', 'anna'),
                $this->coachUnavailableFrom('anna', [3], '19:00'),
            ],
        ));

        self::assertSame(
            ['Anna Martin est le seul coach de 3 séances par semaine mais n\'a que 2 jours disponibles : plusieurs séances tomberont le même jour.'],
            $this->overloadWarnings($found),
        );
    }

    /** Une liste blanche de jours ferme les autres, ce que la seule lecture de `unavailableDays` ne voyait pas. */
    public function testAnAvailableDaysWhitelistClosesTheOtherDaysAndSaysOneDay(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2, 3])],
            teams: [$this->team('t1', 'SM1', sessions: 2)],
            coaches: [$this->coach('anna', 'Anna', 'Martin')],
            constraints: [
                $this->teamCoach('t1', 'anna'),
                ['scope' => 'COACH', 'scopeTargetId' => 'anna', 'family' => 'COACH_AVAILABILITY', 'ruleType' => 'HARD', 'config' => ['availableDays' => [1]]],
            ],
        ));

        self::assertContains(
            'Anna Martin est le seul coach de 2 séances par semaine mais n\'a qu\'un jour disponible : plusieurs séances tomberont le même jour.',
            $found,
        );
    }

    /** Aucun jour ne lui reste : la phrase le dit tel quel, sans promettre des séances qui ne tomberont nulle part. */
    public function testACoachWithNoDayLeftIsToldSo(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2])],
            teams: [$this->team('t1', 'SM1', sessions: 2)],
            coaches: [$this->coach('anna', 'Anna', 'Martin')],
            constraints: [
                $this->teamCoach('t1', 'anna'),
                $this->coachUnavailable('anna', [1, 2]),
            ],
        ));

        self::assertContains('Anna Martin est le seul coach de 2 séances par semaine mais n\'a aucun jour disponible.', $found);
    }

    /** Même phrase au singulier : une seule séance, aucun jour. */
    public function testACoachWithOneSessionAndNoDayLeftIsToldSo(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2])],
            teams: [$this->team('t1', 'SM1', sessions: 1)],
            coaches: [$this->coach('anna', 'Anna', 'Martin')],
            constraints: [
                $this->teamCoach('t1', 'anna'),
                $this->coachUnavailable('anna', [1, 2]),
            ],
        ));

        self::assertContains('Anna Martin est le seul coach de 1 séance par semaine mais n\'a aucun jour disponible.', $found);
    }

    /** Aucun salarié ne peut venir sur aucun créneau du jeudi : le gestionnaire le lit avant de générer. */
    public function testADayWithoutAnyAvailableEmployeeIsFlagged(): void
    {
        $found = $this->warnings->detect($this->employeesPayload(
            employees: [$this->employee('anna', 'Anna', 'Martin'), $this->employee('ben', 'Ben', 'Durand')],
            unavailable: [$this->coachUnavailable('anna', [4]), $this->coachUnavailable('ben', [4])],
        ));

        self::assertSame(
            ['Aucun salarié n\'est disponible sur les créneaux du jeudi : Anna Martin et Ben Durand sont indisponibles ce jour-là.'],
            $this->employeeWarnings($found),
        );
    }

    /** Trois salariés se lisent comme une phrase : virgule, puis « et » avant le dernier. */
    public function testThreeUnavailableEmployeesReadAsASentence(): void
    {
        $found = $this->warnings->detect($this->employeesPayload(
            employees: [$this->employee('a', 'Anna', 'Martin'), $this->employee('b', 'Ben', 'Durand'), $this->employee('c', 'Chloé', 'Petit')],
            unavailable: [$this->coachUnavailable('a', [4]), $this->coachUnavailable('b', [4]), $this->coachUnavailable('c', [4])],
        ));

        self::assertSame(
            ['Aucun salarié n\'est disponible sur les créneaux du jeudi : Anna Martin, Ben Durand et Chloé Petit sont indisponibles ce jour-là.'],
            $this->employeeWarnings($found),
        );
    }

    /** Témoin : un salarié est libre mais rien n'est réservé ce jour-là, le Récap n'a rien à dire. */
    public function testADayWithAFreeEmployeeIsSilent(): void
    {
        $found = $this->warnings->detect($this->employeesPayload(
            employees: [$this->employee('anna', 'Anna', 'Martin'), $this->employee('ben', 'Ben', 'Durand')],
            unavailable: [$this->coachUnavailable('anna', [4])],
        ));

        self::assertSame([], $this->employeeWarnings($found));
    }

    /** Sous le seuil de la règle (2 salariés), rien à dire : la règle elle-même ne s'applique pas. */
    public function testASingleEmployeeIsSilent(): void
    {
        $found = $this->warnings->detect($this->employeesPayload(
            employees: [$this->employee('anna', 'Anna', 'Martin')],
            unavailable: [$this->coachUnavailable('anna', [4])],
        ));

        self::assertSame([], $this->employeeWarnings($found));
    }

    /** Ben reste libre sur le seul créneau de 20:30, celui de 18:00 est fermé à tous : le jour n'est pas impossible. */
    public function testASlotLeftOpenToAnEmployeeKeepsTheDaySilent(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venueWithStarts('v1', 'Matéo', [[4, '18:00:00'], [4, '20:30:00']])],
            coaches: [$this->employee('anna', 'Anna', 'Martin'), $this->employee('ben', 'Ben', 'Durand')],
            constraints: [$this->coachUnavailableFrom('anna', [4], '17:00'), $this->coachUnavailableFrom('ben', [4], '17:00', '20:00')],
        ));

        self::assertSame([], $this->employeeWarnings($found));
    }

    /** Leur plage dès 19:00 coupe l'unique créneau 18:00-19:30 du jeudi : aucun salarié ne peut le tenir, comme à la pose. */
    public function testAnUnavailabilityCuttingTheSlotLeavesTheDayWithoutEmployee(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venueWithStarts('v1', 'Matéo', [[4, '18:00:00']])],
            coaches: [$this->employee('anna', 'Anna', 'Martin'), $this->employee('ben', 'Ben', 'Durand')],
            constraints: [$this->coachUnavailableFrom('anna', [4], '19:00'), $this->coachUnavailableFrom('ben', [4], '19:00')],
        ));

        self::assertSame(
            ['Aucun salarié n\'est disponible sur les créneaux du jeudi : Anna Martin et Ben Durand sont indisponibles ce jour-là.'],
            $this->employeeWarnings($found),
        );
    }

    /** TÉMOIN sans marge : dès 19:30, Ben tient encore 18:00-19:30, le jeudi n'est pas impossible. */
    public function testAnUnavailabilityStartingAtTheEndOfTheSlotKeepsTheDaySilent(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venueWithStarts('v1', 'Matéo', [[4, '18:00:00']])],
            coaches: [$this->employee('anna', 'Anna', 'Martin'), $this->employee('ben', 'Ben', 'Durand')],
            constraints: [$this->coachUnavailableFrom('anna', [4], '19:00'), $this->coachUnavailableFrom('ben', [4], '19:30')],
        ));

        self::assertSame([], $this->employeeWarnings($found));
    }

    /** Le samedi n'est pas un jour ouvré, et un jour sans créneau ne compte pas : même règle que le moteur. */
    public function testAWeekendDayAndADayWithoutSlotAreSilent(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 6])],
            coaches: [$this->employee('anna', 'Anna', 'Martin'), $this->employee('ben', 'Ben', 'Durand')],
            constraints: [$this->coachUnavailable('anna', [4, 6]), $this->coachUnavailable('ben', [4, 6])],
        ));

        self::assertSame([], $this->employeeWarnings($found));
    }

    /** Deux gymnases : U15 ne peut pas être aux deux, la phrase donne les deux créneaux et leurs salles. */
    public function testATeamReservedTwiceOnOverlappingCasesInTwoVenuesIsFlagged(): void
    {
        $found = $this->warnings->detect($this->reservationsPayload([
            $this->reservation('t1', 'v1', 1, '18:00:00'),
            $this->reservation('t1', 'v2', 1, '19:00:00'),
        ]));

        self::assertContains(
            'U15 est réservée deux fois le lundi sur des créneaux qui se chevauchent : 18:00-19:30 à Gymnase A et 19:00-20:30 à Gymnase B.',
            $found,
        );
    }

    /** Même gymnase : la salle n'est dite qu'une fois. */
    public function testATeamReservedTwiceOnOverlappingCasesInOneVenueNamesItOnce(): void
    {
        $found = $this->warnings->detect($this->reservationsPayload([
            $this->reservation('t1', 'v1', 1, '18:00:00'),
            $this->reservation('t1', 'v1', 1, '19:00:00'),
        ]));

        self::assertContains(
            'U15 est réservée deux fois le lundi sur des créneaux qui se chevauchent : 18:00-19:30 et 19:00-20:30 à Gymnase A.',
            $found,
        );
    }

    /** Fin = début : deux séances qui s'enchaînent ne se chevauchent pas. */
    public function testBackToBackReservationsAreSilent(): void
    {
        $found = $this->warnings->detect($this->reservationsPayload([
            $this->reservation('t1', 'v1', 1, '18:00:00'),
            $this->reservation('t1', 'v2', 1, '19:30:00'),
        ]));

        self::assertSame([], $this->overlapWarnings($found));
    }

    /** Deux équipes sur des créneaux qui se chevauchent : aucun doublon, c'est le quotidien d'un club. */
    public function testOverlappingReservationsOfTwoTeamsAreSilent(): void
    {
        $found = $this->warnings->detect($this->reservationsPayload([
            $this->reservation('t1', 'v1', 1, '18:00:00'),
            $this->reservation('t2', 'v2', 1, '19:00:00'),
        ]));

        self::assertSame([], $this->overlapWarnings($found));
    }

    /** Témoin : les mêmes heures un autre jour ne se chevauchent pas. */
    public function testSameHoursOnAnotherDayAreSilent(): void
    {
        $found = $this->warnings->detect($this->reservationsPayload([
            $this->reservation('t1', 'v1', 1, '18:00:00'),
            $this->reservation('t1', 'v2', 2, '19:00:00'),
        ]));

        self::assertSame([], $this->overlapWarnings($found));
    }

    /** Une séance NON verrouillée n'est pas une réservation : le solveur est libre de la reposer. */
    public function testAnUnlockedSlotIsNotAReservation(): void
    {
        $found = $this->warnings->detect($this->reservationsPayload([
            $this->reservation('t1', 'v1', 1, '18:00:00'),
            $this->reservation('t1', 'v2', 1, '19:00:00', 'NONE'),
        ]));

        self::assertSame([], $this->overlapWarnings($found));
    }

    /** Ses indisponibilités RÉDUISENT ses jours — sinon le compte serait faux dans le sens rassurant. */
    public function testACoachUnavailabilityShrinksHisAvailableDays(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2, 3])],
            teams: [$this->team('t1', 'SM1', sessions: 3)],
            coaches: [['id' => 'c1', 'firstName' => 'Alex', 'lastName' => 'Martin']],
            constraints: [
                $this->teamCoach('t1', 'c1'),
                $this->coachUnavailable('c1', [3]),
            ],
        ));

        self::assertContains(
            'Alex Martin est le seul coach de 3 séances par semaine mais n\'a que 2 jours disponibles : plusieurs séances tomberont le même jour.',
            $found,
        );
    }

    public function testAConstraintTargetingAnAbsentTeamIsFlagged(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'SM1')],
            constraints: [$this->v2Constraint('TEAM', 'disparue', ['allowedDays' => [1]])],
        ));

        self::assertContains('Une contrainte vise une équipe absente de cette période : elle ne s\'appliquera pas.', $found);
    }

    public function testAConstraintOnAPresentTeamIsSilent(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'SM1')],
            constraints: [$this->v2Constraint('TEAM', 't1', ['allowedDays' => [1]])],
        ));

        self::assertSame([], array_filter($found, static fn (string $w): bool => str_contains($w, 'absente de cette période')));
    }

    /**
     * ALIGN-09 BLOQUEUR — « au moins une séance l'un de ces jours » (forcedDays) dont AUCUN jour
     * imposé ne porte de créneau candidat : INFEASIBLE certain, donc ça BLOQUE.
     */
    public function testForcedDaysWithNoCandidateSlotBlocks(): void
    {
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2])],
            teams: [$this->team('t1', 'U11 A')],
            constraints: [$this->dayRule('TEAM', 't1', ['forcedDays' => [7]])],
        ));

        self::assertContains(
            'U11 A : au moins une séance exigée le(s) dimanche, mais aucun créneau sur aucun de ces jours — ouvrez un créneau (étape Gymnases) ou retirez la règle (étape Contraintes).',
            $found,
        );
    }

    /** UN seul jour imposé qui croise un créneau suffit (le constat porte sur l'UNION) : silence. */
    public function testForcedDaysWithACandidateSlotDoesNotBlock(): void
    {
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'U11 A')],
            constraints: [$this->dayRule('TEAM', 't1', ['forcedDays' => [1, 7]])],
        ));

        self::assertSame([], $found);
    }

    /**
     * Subtilité : un jour imposé zéroté par le complément d'une whitelist `allowedDays` compte
     * comme SANS candidat (le moteur interdit le complément — constraints.py). Le créneau existe
     * pourtant ce jour-là.
     */
    public function testForcedDayZeroedByAllowedDaysWhitelistBlocks(): void
    {
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2])],
            teams: [$this->team('t1', 'U11 A')],
            constraints: [
                // « au moins une le lundi » MAIS whitelist « uniquement le mardi » → lundi interdit.
                $this->dayRule('TEAM', 't1', ['forcedDays' => [1]]),
                $this->dayRule('TEAM', 't1', ['allowedDays' => [2]]),
            ],
        ));

        self::assertContains(
            'U11 A : au moins une séance exigée le(s) lundi, mais aucun créneau sur aucun de ces jours — ouvrez un créneau (étape Gymnases) ou retirez la règle (étape Contraintes).',
            $found,
        );
    }

    /** Un forcedDays NON obligatoire (placebo côté moteur) ne bloque JAMAIS. */
    public function testAPreferredForcedDaysNeverBlocks(): void
    {
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2])],
            teams: [$this->team('t1', 'U11 A')],
            constraints: [$this->dayRule('TEAM', 't1', ['forcedDays' => [7]], 'PREFERRED')],
        ));

        self::assertSame([], $found);
    }

    /**
     * ALIGN-15 (a) LE CAS DU FINDING — un gymnase imposé par une CONTRAINTE FACILITY (pas par le
     * champ d'équipe) sans créneau le jour exigé, alors qu'un AUTRE gymnase en a un ce jour-là. Le
     * bloqueur DOIT tomber : avant le correctif il retombait sur `$allDays` et laissait passer.
     */
    public function testForcedVenueFromConstraintWithoutSlotOnForcedDayBlocks(): void
    {
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1]), $this->venue('v2', 'Debarros', [2])],
            teams: [$this->team('t1', 'U11 A')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [1]]),
                $this->facilityRule('t1', ['forcedVenueId' => 'v2']),
            ],
        ));

        self::assertContains(
            'U11 A : au moins une séance exigée le(s) lundi, mais aucun créneau sur aucun de ces jours — ouvrez un créneau (étape Gymnases) ou retirez la règle (étape Contraintes).',
            $found,
        );
    }

    /** (b) ANTI-SUR-BLOCAGE — le gymnase imposé A un créneau le jour exigé : aucun bloqueur. */
    public function testForcedVenueFromConstraintWithSlotOnForcedDayDoesNotBlock(): void
    {
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1]), $this->venue('v2', 'Debarros', [2])],
            teams: [$this->team('t1', 'U11 A')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [2]]),
                $this->facilityRule('t1', ['forcedVenueId' => 'v2']),
            ],
        ));

        self::assertSame([], $found);
    }

    /**
     * (c) PRÉCÉDENCE — le champ d'équipe l'emporte sur la contrainte FACILITY ({@see targeting.py}
     * `_forced_venue_id`). Le champ pointe v1 (créneau le lundi), la contrainte v2 (pas de créneau
     * lundi) : si le champ gagne, aucun bloqueur ; si la contrainte gagnait, un bloqueur.
     */
    public function testTeamFieldForcedVenueWinsOverConstraint(): void
    {
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1]), $this->venue('v2', 'Debarros', [2])],
            teams: [$this->team('t1', 'U11 A', forcedVenueId: 'v1')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [1]]),
                $this->facilityRule('t1', ['forcedVenueId' => 'v2']),
            ],
        ));

        self::assertSame([], $found);
    }

    /**
     * (d) LAST-WINS — deux contraintes FACILITY se remplacent (last-wins, {@see parsing.py}
     * `_set_venue_rule`). La première impose v1 (créneau lundi), la seconde v2 (pas de créneau
     * lundi) : la SECONDE gagne, donc un bloqueur. Un « premier gagne » ne bloquerait pas.
     */
    public function testLastWinsBetweenTwoForcedVenueConstraints(): void
    {
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1]), $this->venue('v2', 'Debarros', [2])],
            teams: [$this->team('t1', 'U11 A')],
            constraints: [
                $this->facilityRule('t1', ['forcedVenueId' => 'v1']),
                $this->facilityRule('t1', ['forcedVenueId' => 'v2']),
                $this->dayRule('TEAM', 't1', ['forcedDays' => [1]]),
            ],
        ));

        self::assertContains(
            'U11 A : au moins une séance exigée le(s) lundi, mais aucun créneau sur aucun de ces jours — ouvrez un créneau (étape Gymnases) ou retirez la règle (étape Contraintes).',
            $found,
        );
    }

    /**
     * (e) `preferredVenueId` HARD alimente aussi le gate — le moteur le verse dans `forced_venues`
     * ({@see parsing.py} branche preferredVenueId HARD). Un helper qui ne lirait que
     * `forcedVenueId` manquerait ce cas.
     */
    public function testPreferredVenueIdHardFeedsTheGate(): void
    {
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1]), $this->venue('v2', 'Debarros', [2])],
            teams: [$this->team('t1', 'U11 A')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [1]]),
                $this->facilityRule('t1', ['preferredVenueId' => 'v2']),
            ],
        ));

        self::assertContains(
            'U11 A : au moins une séance exigée le(s) lundi, mais aucun créneau sur aucun de ces jours — ouvrez un créneau (étape Gymnases) ou retirez la règle (étape Contraintes).',
            $found,
        );
    }

    /**
     * (f) LE MIROIR — `teamsNoSlotCanHost` (avertissement) voit lui aussi la contrainte FACILITY :
     * l'équipe est imposée par contrainte dans un gymnase SANS aucun créneau, alors qu'un autre en
     * a. Avant le correctif il retombait sur `$allDays` (non vide) et se taisait.
     */
    public function testMirrorTeamsNoSlotCanHostSeesFacilityConstraint(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1]), $this->venue('v2', 'Debarros', [])],
            teams: [$this->team('t1', 'U13 F1')],
            constraints: [$this->facilityRule('t1', ['forcedVenueId' => 'v2'])],
        ));

        self::assertContains('U13 F1 est imposée dans un gymnase qui n\'a aucun créneau : elle ne pourra pas être placée.', $found);
    }

    /**
     * ALIGN-09 AVERTISSEMENT de FUSION — deux règles « au moins une séance » sur la même équipe se
     * combinent en une seule exigence (l'union des jours). Risque de malentendu → on avertit.
     */
    public function testTwoForcedDayRulesOnSameTeamWarnOfMerge(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 3])],
            teams: [$this->team('t1', 'SM1')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [1]]),
                $this->dayRule('TEAM', 't1', ['forcedDays' => [3]]),
            ],
        ));

        self::assertContains(
            'Deux règles « au moins une séance » sur SM1 se combinent : une seule séance sur l\'ensemble lundi, mercredi suffira.',
            $found,
        );
    }

    /** Une seule règle « au moins une séance » ne déclenche PAS l'avertissement de fusion. */
    public function testASingleForcedDayRuleDoesNotWarnOfMerge(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'SM1')],
            constraints: [$this->dayRule('TEAM', 't1', ['forcedDays' => [1]])],
        ));

        self::assertSame([], array_filter($found, static fn (string $w): bool => str_contains($w, 'se combinent')));
    }

    // ── ALIGN-19 — indispo d'un coach UNIQUEMENT adjoint : ne ferme aucune séance, ferme le relais ──

    public function testAdjointOnlyCoachUnavailabilityIsFlaggedAsIndicative(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'U13 F1')],
            coaches: [$this->coach('c1', 'Anna', 'B')],
            constraints: [
                $this->teamCoach('t1', 'c1', 'ASSISTANT'), // adjointe, jamais principale
                $this->coachUnavailable('c1', [3]),
            ],
        ));

        self::assertContains('L\'indisponibilité de Anna B (adjoint uniquement) ne ferme aucune séance : seul un entraîneur principal indisponible en empêche une. Elle ferme seulement le relais d\'arrivée tardive que cet adjoint aurait pu assurer.', $found);
    }

    public function testSeveralAdjointsUnavailabilitiesNameTheRelayInThePlural(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'U13 F1')],
            coaches: [$this->coach('c1', 'Anna', 'B'), $this->coach('c2', 'Benoît', 'R')],
            constraints: [
                $this->teamCoach('t1', 'c1', 'ASSISTANT'),
                $this->teamCoach('t1', 'c2', 'ASSISTANT'),
                $this->coachUnavailable('c1', [3]),
                $this->coachUnavailable('c2', [4]),
            ],
        ));

        self::assertContains('Les indisponibilités de Anna B, Benoît R (adjoints uniquement) ne ferment aucune séance : seul un entraîneur principal indisponible en empêche une. Elles ferment seulement le relais d\'arrivée tardive que ces adjoints auraient pu assurer.', $found);
    }

    public function testAMainCoachUnavailabilityIsNotFlaggedAsIndicative(): void
    {
        // Même indisponibilité, mais le coach est PRINCIPAL : elle ferme bien → pas de phrase « adjoint ».
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'U13 F1')],
            coaches: [$this->coach('c1', 'Anna', 'B')],
            constraints: [
                $this->teamCoach('t1', 'c1', 'MAIN'),
                $this->coachUnavailable('c1', [3]),
            ],
        ));

        self::assertSame([], array_filter($found, static fn (string $w): bool => str_contains($w, 'adjoint uniquement')));
    }

    // ── ALIGN-16 — jour imposé VIDÉ par une autre règle HARD (créneau existe mais fermé) ──────

    public function testForcedDayEmptiedByMainCoachFullDayUnavailabilityIsFlagged(): void
    {
        // t1 doit s'entraîner le mercredi (3), le gymnase A un créneau mercredi — MAIS son
        // entraîneur PRINCIPAL est indisponible tout le mercredi : la génération échouera.
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [3])],
            teams: [$this->team('t1', 'U13 F1')],
            coaches: [$this->coach('c1', 'Anna', 'B')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [3]]),
                $this->teamCoach('t1', 'c1', 'MAIN'),
                $this->coachUnavailable('c1', [3]),
            ],
        ));

        self::assertNotSame([], array_filter($found, static fn (string $w): bool => str_contains($w, 'U13 F1') && str_contains($w, 'aucun créneau')));
    }

    /**
     * NR (axe constraint semantics) — JUMEAU de {@see testForcedDayEmptiedByMainCoachFullDayUnavailabilityIsFlagged}
     * dont le lien TEAM_COACH est SÉRIALISÉ PAR LE VRAI builder ({@see ScheduleConstraintBuilder::serializeTeamCoachConstraints}),
     * pas par le helper {@see self::teamCoach()} qui écrit 'MAIN' en dur. Le jumeau reste vert quel que soit le bug
     * (chaîne à la main) ; celui-ci attrape la divergence réelle : le builder émettait `metadata.role` en
     * OBJET enum {@see TeamCoachRole}, et `'MAIN' !== $role` ({@see PreSolvePreventionWarnings::mainCoachFullDayUnavailableDays},
     * ligne ~881) est alors TOUJOURS vrai → l'entraîneur principal est ignoré → le jour imposé fermé par
     * son indisponibilité n'est plus détecté → la prévention est muette. La promesse : une prévention qui
     * filtre sur le rôle MAIN se déclenche réellement sur ce que reçoit le solveur.
     */
    public function testMainCoachClosureFiresFromBuilderSerializedTeamCoach(): void
    {
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [3])],
            teams: [$this->team('t1', 'U13 F1')],
            coaches: [$this->coach('c1', 'Anna', 'B')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [3]]),
                $this->builderTeamCoach('t1', 'c1', TeamCoachRole::MAIN),
                $this->coachUnavailable('c1', [3]),
            ],
        ));

        self::assertNotSame(
            [],
            array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'U13 F1') && str_contains($w, 'aucun créneau'))),
            'le rôle MAIN sérialisé par le builder doit fermer le jour imposé — en objet enum la prévention reste muette',
        );
    }

    public function testForcedDayEmptiedByTimeWindowIsFlagged(): void
    {
        // Créneau mercredi 18:00, mais une fenêtre HARD « pas avant 20:00 » ne laisse aucun créneau.
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [3])],
            teams: [$this->team('t1', 'U13 F1')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [3]]),
                $this->timeRule('t1', ['minStartTime' => '20:00']),
            ],
        ));

        self::assertNotSame([], array_filter($found, static fn (string $w): bool => str_contains($w, 'U13 F1') && str_contains($w, 'aucun créneau')));
    }

    public function testForcedDayWithAReachableSlotIsSilentAboutClosure(): void
    {
        // Le créneau mercredi est atteignable (coach disponible, aucune fenêtre) : rien à prévenir.
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [3])],
            teams: [$this->team('t1', 'U13 F1')],
            coaches: [$this->coach('c1', 'Anna', 'B')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [3]]),
                $this->teamCoach('t1', 'c1', 'MAIN'),
                $this->coachUnavailable('c1', [1]), // indisponible lundi, PAS mercredi
            ],
        ));

        self::assertSame([], array_filter($found, static fn (string $w): bool => str_contains($w, 'une autre règle')));
    }

    /**
     * NR (P4-319, axe constraint semantics) — « UN COACH SUFFIT » appliqué au jour imposé
     * fermé : une équipe à DEUX entraîneurs principaux dont l'UN est disponible le jour imposé
     * ne doit PAS voir ce jour fermé. {@see PreSolvePreventionWarnings::mainCoachFullDayUnavailableDays}
     * prend l'INTERSECTION des indisponibilités plein-jour, pas l'union (même règle que le moteur
     * `closures.py` / `availability.py`).
     *
     * PREUVE DE CHUTE : remettre l'UNION (l'ancienne doctrine mono-coach) → ce cas rougit (un
     * avertissement « aucun créneau » fantôme apparaît), tandis que le témoin ci-dessous reste vert.
     */
    public function testForcedDayStaysOpenWhenOneOfTwoMainCoachesCoversIt(): void
    {
        // t1 doit s'entraîner le mercredi (3), un créneau mercredi existe. c1 est indisponible le
        // mercredi, c2 est disponible le mercredi (indispo lundi) : un principal couvre le jour.
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [3])],
            teams: [$this->team('t1', 'U13 F1')],
            coaches: [$this->coach('c1', 'Anna'), $this->coach('c2', 'Bea')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [3]]),
                $this->teamCoach('t1', 'c1', 'MAIN'),
                $this->teamCoach('t1', 'c2', 'MAIN'),
                $this->coachUnavailable('c1', [3]),
                $this->coachUnavailable('c2', [1]),
            ],
        ));

        self::assertSame(
            [],
            array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'U13 F1') && str_contains($w, 'une autre règle'))),
            'un second entraîneur principal disponible le mercredi garde le jour imposé ouvert',
        );
    }

    /** Témoin : les DEUX principaux indisponibles le mercredi → le jour imposé est bien fermé. */
    public function testForcedDayClosedWhenBothMainCoachesAreUnavailable(): void
    {
        $found = $this->warnings->detectBlockers($this->payload(
            venues: [$this->venue('v1', 'Matéo', [3])],
            teams: [$this->team('t1', 'U13 F1')],
            coaches: [$this->coach('c1', 'Anna'), $this->coach('c2', 'Bea')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [3]]),
                $this->teamCoach('t1', 'c1', 'MAIN'),
                $this->teamCoach('t1', 'c2', 'MAIN'),
                $this->coachUnavailable('c1', [3]),
                $this->coachUnavailable('c2', [3]),
            ],
        ));

        self::assertNotSame(
            [],
            array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'U13 F1') && str_contains($w, 'aucun créneau'))),
            'les deux principaux indisponibles le mercredi : aucun ne couvre le jour imposé',
        );
    }

    // ── Récap aligné sur la pose : le jour imposé se ferme au chevauchement, pas à la journée ─────

    /** Jour imposé lundi, unique créneau 18:00-19:30, principal parti dès 19:00 : le Récap prévient avant de générer, et le texte dit la vraie règle. */
    public function testForcedDayClosedByAnUnavailabilityCuttingItsOnlySlotIsFlagged(): void
    {
        self::assertCount(1, $this->closedForcedDayWarnings($this->forcedMondayWarnings(
            [$this->coachUnavailableFrom('c1', [1], '19:00')],
        )));
    }

    /** TÉMOIN sans marge : dès 19:30 le créneau tient, silence (la lecture à la journée criait à tort). */
    public function testForcedDayStaysOpenWhenTheUnavailabilityStartsAtTheEndOfTheSlot(): void
    {
        self::assertSame([], $this->anyClosedForcedDayWarning($this->forcedMondayWarnings(
            [$this->coachUnavailableFrom('c1', [1], '19:30')],
        )));
    }

    /** Un créneau du soir reste tenable : le jour n'est fermé que si TOUS ses créneaux le sont. */
    public function testForcedDayStaysOpenWhenAnotherSlotOfTheDayIsLeft(): void
    {
        self::assertSame([], $this->anyClosedForcedDayWarning($this->forcedMondayWarnings(
            [$this->coachUnavailableFrom('c1', [1], '17:00', '19:00')],
            [[1, '18:00:00'], [1, '20:30:00']],
        )));
    }

    /** Une liste blanche de jours ferme les autres, comme à la pose : la lecture de `unavailableDays` seule ne le voyait pas. */
    public function testForcedDayOutsideTheMainCoachWhitelistIsFlagged(): void
    {
        self::assertCount(1, $this->closedForcedDayWarnings($this->forcedMondayWarnings(
            [['scope' => 'COACH', 'scopeTargetId' => 'c1', 'family' => 'COACH_AVAILABILITY', 'ruleType' => 'HARD', 'config' => ['availableDays' => [2]]]],
        )));
    }

    /** Toute l'union du groupe part dès 19:00 : aucune case servable, le Récap avertit et cite la contrainte, comme à la pose. */
    public function testSharedBlockWhoseUnionLeavesDuringTheSlotIsWarned(): void
    {
        $found = $this->warnings->detectBlockers($this->overlapBlockPayload('19:00'));

        $blockWarnings = array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'coach du groupe')));
        self::assertCount(1, $blockWarnings);
        self::assertStringContainsString('Contrainte en cause : « Indispo cA »', $blockWarnings[0]);
    }

    /** TÉMOIN sans marge : dès 19:30, le groupe garde sa case, silence. */
    public function testSharedBlockIsSilentWhenTheUnavailabilityStartsAtTheEndOfTheSlot(): void
    {
        $found = $this->warnings->detectBlockers($this->overlapBlockPayload('19:30'));

        self::assertSame([], array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'coach du groupe'))));
    }

    /** Un payload vide n'invente rien : zéro équipe, zéro gymnase, zéro bruit. */
    public function testAnEmptyPayloadSaysNothing(): void
    {
        self::assertSame([], $this->warnings->detect([]));
        self::assertSame([], $this->warnings->detectBlockers([]));
    }

    // ── Lot 3B PR B' — « groupe sans case commune servable » (miroir « un coach suffit ») ───────

    public function testSharedBlockWithoutCommonServableCaseIsWarnedAndNamesTeams(): void
    {
        // Gymnase ouvert mardi (2) seul ; les deux coachs requis du bloc sont indisponibles mardi →
        // aucune case n'a de coach de l'union → le bloc est inservable, on AVERTIT en nommant le groupe.
        $found = $this->warnings->detectBlockers($this->blockPayload(
            venueDays: [2],
            coachA: $this->coachAvailabilityRule('avA', 'cA', unavailableDays: [2], name: 'Coach A · indispo mardi'),
            coachB: $this->coachAvailabilityRule('avB', 'cB', unavailableDays: [2], name: 'Coach B · indispo mardi'),
        ));

        $blockWarnings = array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'coach du groupe')));
        self::assertCount(1, $blockWarnings, 'un bloc inservable doit produire exactement un avertissement');
        self::assertStringContainsString('Groupe U9F1, U9F2', $blockWarnings[0]);
        self::assertStringContainsString('Coach A · indispo mardi', $blockWarnings[0]);
    }

    public function testServableSharedBlockIsSilent(): void
    {
        // Mêmes équipes et gymnase, mais les coachs sont disponibles le mardi (aucune indispo) → le
        // bloc est servable → aucun avertissement de bloc (le miroir sait se taire).
        $found = $this->warnings->detectBlockers($this->blockPayload(
            venueDays: [2],
            coachA: $this->coachAvailabilityRule('avA', 'cA', unavailableDays: []),
            coachB: $this->coachAvailabilityRule('avB', 'cB', unavailableDays: []),
        ));

        self::assertSame([], array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'coach du groupe'))));
    }

    public function testBlockWarningComposesSeasonInheritedOriginWhenKnown(): void
    {
        // La contrainte citée est HÉRITÉE de la saison (origins[id] = true) → le texte le dit et
        // propose de la désactiver pour la période.
        $found = $this->warnings->detectBlockers(
            $this->blockPayload(
                venueDays: [2],
                coachA: $this->coachAvailabilityRule('avA', 'cA', unavailableDays: [2], name: 'Coach A · indispo mardi'),
                coachB: $this->coachAvailabilityRule('avB', 'cB', unavailableDays: [2], name: 'Coach B · indispo mardi'),
            ),
            ['avA' => true, 'avB' => true],
        );

        $blockWarnings = array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'coach du groupe')));
        self::assertNotEmpty($blockWarnings);
        self::assertStringContainsString('héritée du planning de saison — désactivable pour cette période', $blockWarnings[0]);
    }

    public function testBlockWarningComposesPeriodPostedOriginWhenDated(): void
    {
        // La contrainte citée est POSÉE pour la période (origins[id] = false) → « posée pour cette période ».
        $found = $this->warnings->detectBlockers(
            $this->blockPayload(
                venueDays: [2],
                coachA: $this->coachAvailabilityRule('avA', 'cA', unavailableDays: [2], name: 'Coach A · indispo vacances'),
                coachB: $this->coachAvailabilityRule('avB', 'cB', unavailableDays: [2], name: 'Coach B · indispo vacances'),
            ),
            ['avA' => false, 'avB' => false],
        );

        $blockWarnings = array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'coach du groupe')));
        self::assertNotEmpty($blockWarnings);
        self::assertStringContainsString('posée pour cette période', $blockWarnings[0]);
        self::assertStringNotContainsString('héritée du planning de saison', $blockWarnings[0]);
    }

    public function testASingleTeamMultiCoachIsNeverAccusedAsABlock(): void
    {
        // Pas de sharedBlocks du tout → aucun avertissement de bloc, quoi qu'il arrive aux coachs.
        $payload = $this->blockPayload(
            venueDays: [2],
            coachA: $this->coachAvailabilityRule('avA', 'cA', unavailableDays: [2]),
            coachB: $this->coachAvailabilityRule('avB', 'cB', unavailableDays: [2]),
        );
        unset($payload['sharedBlocks']);

        $found = $this->warnings->detectBlockers($payload);

        self::assertSame([], array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'coach du groupe'))));
    }

    // ── Groupe réservé : la réservation gagne, le Récap ne crie pas à l'échec (miroir de la parité
    // CrossStack/SharedBlockPreSolveMirrorParityTest et de engine/tests/semantic/test_coach_unavailability_overlaps_the_session.py) ──

    /** A et B réservés sur leur case, l'union part à 19:00 : la génération tient, aucun « échouera ». */
    public function testAFullyReservedBlockCutDuringTheSlotIsSilent(): void
    {
        $payload = [...$this->overlapBlockPayload('19:00'), 'slotTemplates' => $this->pinsOf(['t1', 't2'], 2)];

        self::assertSame([], $this->groupWarnings($this->warnings->detectBlockers($payload)));
    }

    /** A et B réservés sur leur case, l'union absente au début : le planning sortira avec une alerte, pas un échec. */
    public function testAFullyReservedBlockAbsentAtItsStartIsSilent(): void
    {
        $payload = [...$this->absentOnTuesdayBlock([2]), 'slotTemplates' => $this->pinsOf(['t1', 't2'], 2)];

        self::assertSame([], $this->groupWarnings($this->warnings->detectBlockers($payload)));
    }

    /** 2 séances communes : la case réservée en vaut une, le mercredi à coach donne l'autre. Silence. */
    public function testATwoSessionBlockWithOneReservedCaseAndOneServableIsSilent(): void
    {
        $payload = [
            ...$this->absentOnTuesdayBlock([2, 3]),
            'sharedBlocks' => [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 2]],
            'slotTemplates' => $this->pinsOf(['t1', 't2'], 2),
        ];

        self::assertSame([], $this->groupWarnings($this->warnings->detectBlockers($payload)));
    }

    /** TÉMOIN : t2 reste à poser sur une case sans coach, c'est une pose libre, le moteur échouera. Le Récap le dit. */
    public function testAPartiallyReservedBlockIsStillWarned(): void
    {
        $payload = [...$this->absentOnTuesdayBlock([2]), 'slotTemplates' => $this->pinsOf(['t1'], 2)];

        $found = $this->groupWarnings($this->warnings->detectBlockers($payload));

        self::assertCount(1, $found);
        self::assertStringContainsString('la génération échouera', $found[0]);
    }

    /** Plus de cases réservées que de séances communes : ces réservations se contredisent, texte exact, accord au pluriel. */
    public function testAnOverpinnedBlockIsWarnedWithItsCounts(): void
    {
        $oneSession = [...$this->servableBlock([2, 3]), 'slotTemplates' => [...$this->pinsOf(['t1', 't2'], 2), ...$this->pinsOf(['t1', 't2'], 3)]];
        self::assertContains(
            'Groupe U9F1, U9F2 : réservé sur 2 cases pour 1 séance commune — la génération échouera. Retirez une réservation ou ajustez les séances communes dans l\'assistant.',
            $this->warnings->detectBlockers($oneSession),
        );

        $twoSessions = [
            ...$this->servableBlock([2, 3, 4]),
            'sharedBlocks' => [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 2]],
            'slotTemplates' => [...$this->pinsOf(['t1', 't2'], 2), ...$this->pinsOf(['t1', 't2'], 3), ...$this->pinsOf(['t1', 't2'], 4)],
        ];
        self::assertContains(
            'Groupe U9F1, U9F2 : réservé sur 3 cases pour 2 séances communes — la génération échouera. Retirez une réservation ou ajustez les séances communes dans l\'assistant.',
            $this->warnings->detectBlockers($twoSessions),
        );
    }

    /** Deux groupes imbriqués réunis par une seule case réservée : une case ne porte qu'une séance commune, texte exact. */
    public function testNestedBlocksOnASingleReservedCaseAreWarned(): void
    {
        $payload = [
            ...$this->servableBlock([2]),
            'teams' => [$this->team('t1', 'U9F1'), $this->team('t2', 'U9F2'), $this->team('t3', 'U9F3')],
            'sharedBlocks' => [
                ['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1],
                ['id' => 'c', 'teamIds' => ['t1', 't2', 't3'], 'commonSessions' => 1],
            ],
            'slotTemplates' => $this->pinsOf(['t1', 't2', 't3'], 2),
        ];

        self::assertContains(
            'Groupes U9F1, U9F2 et U9F1, U9F2, U9F3 : une seule case réservée les réunit, pour 2 séances communes à placer — la génération échouera. Retirez une réservation ou ajustez les séances communes dans l\'assistant.',
            $this->warnings->detectBlockers($payload),
        );
    }

    /** 2 séances, mardi réservé, mercredi sans coach : le Récap cite l'indisponibilité du mercredi, jamais celle de la case réservée, qui n'y est pour rien. */
    public function testAWarnedBlockCitesOnlyTheRulesClosingItsUnreservedCases(): void
    {
        $payload = [
            ...$this->absentOnTuesdayBlock([2, 3]),
            'constraints' => [
                $this->teamCoach('t1', 'cA'),
                $this->teamCoach('t2', 'cB'),
                $this->coachAvailabilityRule('avA', 'cA', unavailableDays: [2], name: 'Indispo mardi A'),
                $this->coachAvailabilityRule('avB', 'cB', unavailableDays: [2], name: 'Indispo mardi B'),
                $this->coachAvailabilityRule('wedA', 'cA', unavailableDays: [3], name: 'Indispo mercredi A'),
                $this->coachAvailabilityRule('wedB', 'cB', unavailableDays: [3], name: 'Indispo mercredi B'),
            ],
            'sharedBlocks' => [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 2]],
            'slotTemplates' => $this->pinsOf(['t1', 't2'], 2),
        ];

        $found = $this->groupWarnings($this->warnings->detectBlockers($payload));

        self::assertCount(1, $found);
        self::assertStringContainsString('Indispo mercredi A', $found[0]);
        self::assertStringNotContainsString('Indispo mardi', $found[0]);
    }

    // ── Jour imposé fermé par une autre règle : le moteur crédite le jour par le verrou ──────────

    /** Le verrou sur un jour imposé sert l'exigence, y compris un jour imposé que le Récap ne compte pas candidat (mercredi interdit). */
    public function testAForcedDayClosedByAnotherRuleIsSilentWhenALockSitsOnAForcedDay(): void
    {
        foreach ([[[1], [], 1], [[1, 3], [3], 3]] as [$forced, $forbidden, $lockDay]) {
            $found = $this->warnings->detectBlockers([
                ...$this->payload(
                    venues: [$this->venueWithStarts('v1', 'Matéo', [[1, '18:00:00'], [3, '18:00:00']])],
                    teams: [$this->team('t1', 'U13 F1')],
                    coaches: [$this->coach('c1', 'Anna', 'B')],
                    constraints: [
                        $this->dayRule('TEAM', 't1', ['forcedDays' => $forced, 'forbiddenDays' => $forbidden]),
                        $this->teamCoach('t1', 'c1', 'MAIN'),
                        $this->coachUnavailable('c1', [1]),
                    ],
                ),
                'slotTemplates' => [$this->reservation('t1', 'v1', $lockDay, '18:00')],
            ]);

            self::assertSame([], $this->anyClosedForcedDayWarning($found), 'verrou le jour ' . $lockDay);
        }
    }

    /** TÉMOIN : un verrou un autre jour ne sert pas l'exigence du lundi, l'échec reste annoncé. */
    public function testAForcedDayClosedByAnotherRuleStaysWarnedWhenTheLockSitsElsewhere(): void
    {
        $found = $this->warnings->detectBlockers([
            ...$this->payload(
                venues: [$this->venueWithStarts('v1', 'Matéo', [[1, '18:00:00'], [2, '18:00:00']])],
                teams: [$this->team('t1', 'U13 F1', 2)],
                coaches: [$this->coach('c1', 'Anna', 'B')],
                constraints: [
                    $this->dayRule('TEAM', 't1', ['forcedDays' => [1]]),
                    $this->teamCoach('t1', 'c1', 'MAIN'),
                    $this->coachUnavailable('c1', [1]),
                ],
            ),
            'slotTemplates' => [$this->reservation('t1', 'v1', 2, '18:00')],
        ]);

        self::assertCount(1, $this->closedForcedDayWarnings($found));
    }

    // ── Finitions : on ne peut pas être adjoint sans coach ──

    /** Un adjoint sans coach principal : le Récap le nomme avant de générer, et ne bloque rien. */
    public function testATeamWithAnAssistantButNoMainCoachIsFlaggedWithoutBlocking(): void
    {
        $payload = $this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'U15')],
            coaches: [$this->coach('c1', 'Emma', 'Dupont')],
            constraints: [$this->teamCoach('t1', 'c1', 'ASSISTANT')],
        );

        self::assertContains(self::ASSISTANT_WITHOUT_MAIN, $this->warnings->detect($payload));
        self::assertSame([], $this->warnings->detectBlockers($payload));
    }

    /** Même constat quand le lien vient du vrai sérialiseur, celui qui part au moteur. */
    public function testAnAssistantWithoutMainCoachFiresFromBuilderSerializedLink(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'U15')],
            coaches: [$this->coach('c1', 'Emma', 'Dupont')],
            constraints: [$this->builderTeamCoach('t1', 'c1', TeamCoachRole::ASSISTANT)],
        ));

        self::assertContains(self::ASSISTANT_WITHOUT_MAIN, $found);
    }

    /** Plusieurs adjoints sans principal : tous nommés, au pluriel. */
    public function testSeveralAssistantsWithoutMainCoachAreAllNamed(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'U15')],
            coaches: [$this->coach('c1', 'Emma', 'Dupont'), $this->coach('c2', 'Paul', 'Roux')],
            constraints: [$this->teamCoach('t1', 'c1', 'ASSISTANT'), $this->teamCoach('t1', 'c2', 'ASSISTANT')],
        ));

        self::assertContains('U15 a des adjoints (Emma D., Paul R.) mais aucun coach principal : nommez un coach principal, ou retirez les adjoints si l\'équipe est en autonomie.', $found);
    }

    /** Un principal à côté de l'adjoint : l'équipe est en règle, silence. */
    public function testAMainCoachBesideTheAssistantIsSilent(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'U15')],
            coaches: [$this->coach('c1', 'Emma', 'Dupont'), $this->coach('c2', 'Karim', 'Martin')],
            constraints: [$this->teamCoach('t1', 'c1', 'ASSISTANT'), $this->teamCoach('t1', 'c2', 'MAIN')],
        ));

        self::assertSame([], $this->assistantWithoutMainWarnings($found));
    }

    /** Un lien sans rôle vaut principal, comme pour le moteur : silence. */
    public function testALinkWithoutRoleCountsAsMain(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'U15')],
            coaches: [$this->coach('c1', 'Emma', 'Dupont'), $this->coach('c2', 'Karim', 'Martin')],
            constraints: [
                $this->teamCoach('t1', 'c1', 'ASSISTANT'),
                ['type' => 'TEAM_COACH', 'teamId' => 't1', 'metadata' => ['coachId' => 'c2']],
            ],
        ));

        self::assertSame([], $this->assistantWithoutMainWarnings($found));
    }

    /** Une équipe sans aucun coach est en autonomie : silence. */
    public function testATeamWithoutAnyCoachIsSilent(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'U15')],
        ));

        self::assertSame([], $this->assistantWithoutMainWarnings($found));
    }

    /** En période, le payload ne porte que les équipes engagées : une équipe en pause ne déclenche rien. */
    public function testATeamAbsentFromThePeriodPayloadIsSilent(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t2', 'U17')],
            coaches: [$this->coach('c1', 'Emma', 'Dupont')],
            constraints: [$this->teamCoach('t1', 'c1', 'ASSISTANT')],
        ));

        self::assertSame([], $this->assistantWithoutMainWarnings($found));
    }

    // ── Récap et déplacements justes : quand c'est sûr ça bloque, quand ça peut tenir ça alerte ───

    /**
     * Jour imposé vidé, équipe SEULE : le moteur échoue ({@see SharedBlockPreSolveMirrorParityTest}), donc
     * blocage, jamais une simple alerte.
     *
     * @param array<string, mixed> $closing
     */
    #[DataProvider('forcedDayClosers')]
    public function testAForcedDayEmptiedForATeamAloneBlocksAndIsNoLongerAWarning(array $closing): void
    {
        $payload = $this->forcedThursdayPayload($closing);

        $phrase = 'U15 : au moins une séance exigée le(s) jeudi, mais une autre règle (indisponibilité de l\'entraîneur principal sur tous les créneaux de ce jour, ou fenêtre horaire) n\'y laisse aucun créneau — la génération échouera. Levez la règle qui ferme ces jours, ou retirez l\'exigence (étape Contraintes).';
        self::assertContains($phrase, $this->warnings->detectBlockers($payload));
        self::assertNotContains($phrase, $this->warnings->detect($payload));
    }

    /** Membre de groupe : un coach du groupe peut relayer, le moteur produit le planning, donc une alerte et jamais un blocage. */
    public function testAForcedDayEmptiedForAGroupMemberIsAWarningThatSaysWhatItDependsOn(): void
    {
        $payload = [
            ...$this->forcedThursdayPayload($this->coachUnavailable('anna', [4])),
            'teams' => [$this->team('u15', 'U15'), $this->team('u13', 'U13')],
            'sharedBlocks' => [['id' => 'g', 'teamIds' => ['u15', 'u13'], 'commonSessions' => 1]],
        ];

        self::assertContains(
            'U15 : au moins une séance exigée le(s) jeudi, mais une autre règle (indisponibilité de l\'entraîneur principal sur tous les créneaux de ce jour) n\'y laisse aucun créneau — la séance ne tiendra que si un coach de son groupe est disponible.',
            $this->warnings->detect($payload),
        );
        self::assertSame([], $this->warnings->detectBlockers($payload));
    }

    /** Fenêtre horaire sur un membre de groupe : aucun coach ne la lève, le moteur échoue, donc blocage et jamais la phrase du relais. */
    public function testAForcedDayEmptiedByAWindowForAGroupMemberBlocks(): void
    {
        $payload = [
            ...$this->forcedThursdayPayload(['scope' => 'TEAM', 'scopeTargetId' => 'u15', 'family' => 'TIME', 'ruleType' => 'HARD', 'config' => ['minStartTime' => '20:00']]),
            'teams' => [$this->team('u15', 'U15'), $this->team('u13', 'U13')],
            'sharedBlocks' => [['id' => 'g', 'teamIds' => ['u15', 'u13'], 'commonSessions' => 1]],
        ];

        self::assertStringContainsString('la génération échouera', implode(' ', $this->warnings->detectBlockers($payload)));
        self::assertSame([], array_values(array_filter($this->warnings->detect($payload), static fn (string $w): bool => str_contains($w, 'un coach de son groupe'))));
    }

    /** Deux jours imposés, jeudi fermé par une fenêtre, vendredi par l'indisponibilité : le vendredi peut se relayer, alerte qui ne nomme que lui. */
    public function testAForcedDayMixingAWindowAndAnAbsenceStaysAWarningOnTheRelayableDay(): void
    {
        $payload = $this->payload(
            venues: [$this->venueWithStarts('v1', 'Matéo', [[4, '18:00:00'], [5, '20:30:00']])],
            teams: [$this->team('u15', 'U15'), $this->team('u13', 'U13')],
            coaches: [$this->coach('anna', 'Anna', 'Martin')],
            constraints: [
                $this->dayRule('TEAM', 'u15', ['forcedDays' => [4, 5]]),
                $this->teamCoach('u15', 'anna'),
                $this->coachUnavailable('anna', [5]),
                ['scope' => 'TEAM', 'scopeTargetId' => 'u15', 'family' => 'TIME', 'ruleType' => 'HARD', 'config' => ['minStartTime' => '20:00']],
            ],
        );
        $payload['sharedBlocks'] = [['id' => 'g', 'teamIds' => ['u15', 'u13'], 'commonSessions' => 1]];

        self::assertContains(
            'U15 : au moins une séance exigée le(s) vendredi, mais une autre règle (indisponibilité de l\'entraîneur principal sur tous les créneaux de ce jour) n\'y laisse aucun créneau — la séance ne tiendra que si un coach de son groupe est disponible.',
            $this->warnings->detect($payload),
        );
        self::assertSame([], $this->warnings->detectBlockers($payload));
    }

    /** Vendredi imposé, unique case prise par un groupe réservé entier : la réservation gagne, c'est dit, jamais bloqué. Une place libre ailleurs le vendredi : silence. */
    public function testAForcedDayTakenByAReservedGroupWarnsWithoutBlocking(): void
    {
        $build = fn (array $days): array => [
            ...$this->payload(
                venues: [$this->venueWithStarts('v1', 'Matéo', $days)],
                teams: [$this->team('t', 'SA'), $this->team('a', 'A'), $this->team('b', 'B')],
                constraints: [$this->dayRule('TEAM', 't', ['forcedDays' => [5]])],
            ),
            'sharedBlocks' => [['id' => 'g', 'teamIds' => ['a', 'b'], 'commonSessions' => 1]],
            'slotTemplates' => [$this->reservation('a', 'v1', 5, '18:00'), $this->reservation('b', 'v1', 5, '18:00')],
        ];
        $phrase = 'SA : au moins une séance exigée le(s) vendredi, mais les réservations y occupent tous les créneaux : la réservation gagne, cette séance manquera.';

        self::assertContains($phrase, $this->warnings->detect($build([[5, '18:00:00']])));
        self::assertSame([], $this->warnings->detectBlockers($build([[5, '18:00:00']])));
        self::assertNotContains($phrase, $this->warnings->detect($build([[5, '18:00:00'], [5, '19:30:00']])));
    }

    /** Les quatre formes du groupe inservable, tous nombres dits en français : plus jamais « créneau(x) » ni « séance(s) ». */
    #[DataProvider('blockHeads')]
    public function testAnUnservableBlockSaysItsCountsInPlainFrench(array $venueDays, int $commonSessions, array $pinnedDays, array $absentDays, string $head): void
    {
        $payload = [
            ...$this->blockPayload(
                venueDays: $venueDays,
                coachA: $this->coachAvailabilityRule('avA', 'cA', unavailableDays: $absentDays),
                coachB: $this->coachAvailabilityRule('avB', 'cB', unavailableDays: $absentDays),
            ),
            'sharedBlocks' => [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => $commonSessions]],
            'slotTemplates' => array_merge(...array_map(fn (int $day): array => $this->pinsOf(['t1', 't2'], $day), $pinnedDays), ...[[]]),
        ];

        $found = $this->groupWarnings($this->warnings->detectBlockers($payload));

        self::assertCount(1, $found);
        self::assertStringStartsWith($head, $found[0]);
        self::assertStringNotContainsString('(x)', $found[0]);
        self::assertStringNotContainsString('(s)', $found[0]);
    }

    /** Les règles n'ouvrent pas assez de jours pour la demande : la vraie cause est dite, une équipe n'est jamais accusée à tort. */
    #[DataProvider('tooFewDays')]
    public function testTeamRulesOpeningTooFewDaysSayHowMany(string $name, int $sessions, array $config, string $expected): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2, 3, 4, 5])],
            teams: [$this->team('t1', $name, $sessions)],
            constraints: [$this->dayRule('TEAM', 't1', $config)],
        ));

        self::assertContains($expected, $found);
    }

    /** Une équipe déjà servie par ses réservations ne demande plus rien : réservée lundi et mardi, sa règle du lundi se tait. */
    public function testReservationsAreSubtractedFromWhatTheRulesMustOpen(): void
    {
        $silent = $this->warnings->detect([
            ...$this->payload(
                venues: [$this->venue('v1', 'Matéo', [1, 2])],
                teams: [$this->team('t1', 'U15', 2)],
                constraints: [$this->dayRule('TEAM', 't1', ['allowedDays' => [1]])],
            ),
            'slotTemplates' => [$this->reservation('t1', 'v1', 1, '18:00'), $this->reservation('t1', 'v1', 2, '18:00')],
        ]);
        self::assertSame([], array_values(array_filter($silent, static fn (string $w): bool => str_contains($w, 'demande'))));

        $stillShort = $this->warnings->detect([
            ...$this->payload(
                venues: [$this->venue('v1', 'Matéo', [1, 2, 3])],
                teams: [$this->team('t1', 'U15', 3)],
                constraints: [$this->dayRule('TEAM', 't1', ['allowedDays' => [1]])],
            ),
            'slotTemplates' => [$this->reservation('t1', 'v1', 1, '18:00')],
        ]);
        self::assertContains('U15 : il lui reste 2 séances à placer, mais ses règles n\'ouvrent qu\'un seul jour.', $stillShort);
    }

    /** Deux règles « jours autorisés » s'ajoutent : lundi + mardi ouvrent 2 jours, rien à dire pour 2 séances. */
    public function testTwoAllowedDayRulesAreAUnion(): void
    {
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 2])],
            teams: [$this->team('t1', 'U15', 2)],
            constraints: [$this->dayRule('TEAM', 't1', ['allowedDays' => [1]]), $this->dayRule('TEAM', 't1', ['allowedDays' => [2]])],
        ));

        self::assertSame([], array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'U15'))));
    }

    /** Une préférence ne ferme rien pour le moteur (jumeau `ConstraintKeysAreHonouredByEngineTest`) : le Récap ne dit donc rien. */
    public function testAPreferredDayRuleIsIgnored(): void
    {
        $found = $this->warnings->detect([
            ...$this->payload(
                venues: [$this->venue('v1', 'Matéo', [1, 2])],
                teams: [$this->team('t1', 'U15', 2)],
                constraints: [$this->dayRule('TEAM', 't1', ['allowedDays' => [5]], 'PREFERRED')],
            ),
            'slotTemplates' => [$this->reservation('t1', 'v1', 1, '18:00')],
        ]);

        self::assertSame([], array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'U15'))));
    }

    /** Assez de jours mais aucun créneau dessus : la séance qui manquera se compte, réservations déduites, au bon nombre. */
    #[DataProvider('missingSessions')]
    public function testADayRuleWithoutSlotCountsWhatIsMissing(int $sessions, array $reservedDays, array $allowedDays, string $expected): void
    {
        $found = $this->warnings->detect([
            ...$this->payload(
                venues: [$this->venue('v1', 'Matéo', [1, 2, 3])],
                teams: [$this->team('t1', 'U15', $sessions)],
                constraints: [$this->dayRule('TEAM', 't1', ['allowedDays' => $allowedDays])],
            ),
            'slotTemplates' => array_map(fn (int $day): array => $this->reservation('t1', 'v1', $day, '18:00'), $reservedDays),
        ]);

        self::assertContains($expected, $found);
    }

    /** Une réservation hors de la règle de jour est maintenue, et le Récap le dit, une ligne par réservation. */
    public function testAReservationOutsideTheDayRuleIsNamedReservationByReservation(): void
    {
        $found = $this->warnings->detect([
            ...$this->payload(
                venues: [$this->venue('v1', 'Matéo', [1, 2, 3])],
                teams: [$this->team('t1', 'U15', 3)],
                constraints: [$this->dayRule('TEAM', 't1', ['allowedDays' => [3], 'forbiddenDays' => [2]])],
            ),
            'slotTemplates' => [$this->reservation('t1', 'v1', 1, '18:00'), $this->reservation('t1', 'v1', 2, '18:00'), $this->reservation('t1', 'v1', 3, '18:00')],
        ]);

        self::assertContains('U15 est réservée lundi, hors de sa règle : la réservation gagne.', $found);
        self::assertContains('U15 est réservée mardi, hors de sa règle : la réservation gagne.', $found);
        self::assertNotContains('U15 est réservée mercredi, hors de sa règle : la réservation gagne.', $found);
    }

    protected function setUp(): void
    {
        $this->warnings = new PreSolvePreventionWarnings;
    }

    /**
     * Décor du jeudi imposé : U15, Anna seule principale, créneaux lundi et jeudi, la règle donnée.
     *
     * @param array<string, mixed> $closing
     *
     * @return array<string, mixed>
     */
    private function forcedThursdayPayload(array $closing): array
    {
        return $this->payload(
            venues: [$this->venue('v1', 'Matéo', [1, 4])],
            teams: [$this->team('u15', 'U15')],
            coaches: [$this->coach('anna', 'Anna', 'Martin'), $this->coach('ben', 'Ben', 'Durand')],
            constraints: [
                $this->dayRule('TEAM', 'u15', ['forcedDays' => [4]]),
                $this->teamCoach('u15', 'anna'),
                $this->teamCoach('u13', 'ben'),
                $closing,
            ],
        );
    }

    /**
     * Le bloc {t1,t2}, gymnase ouvert les jours donnés, cA et cB indisponibles le mardi.
     *
     * @param list<int> $venueDays
     *
     * @return array<string, mixed>
     */
    private function absentOnTuesdayBlock(array $venueDays): array
    {
        return $this->blockPayload(
            venueDays: $venueDays,
            coachA: $this->coachAvailabilityRule('avA', 'cA', unavailableDays: [2], name: 'Indispo mardi A'),
            coachB: $this->coachAvailabilityRule('avB', 'cB', unavailableDays: [2], name: 'Indispo mardi B'),
        );
    }

    /**
     * Le bloc {t1,t2}, gymnase ouvert les jours donnés, coachs présents partout.
     *
     * @param list<int> $venueDays
     *
     * @return array<string, mixed>
     */
    private function servableBlock(array $venueDays): array
    {
        return $this->blockPayload(
            venueDays: $venueDays,
            coachA: $this->coachAvailabilityRule('avA', 'cA', unavailableDays: [6]),
            coachB: $this->coachAvailabilityRule('avB', 'cB', unavailableDays: [6]),
        );
    }

    /**
     * Les réservations HARD des équipes sur v1, ce jour à 18:00.
     *
     * @param list<string> $teamIds
     *
     * @return list<array<string, mixed>>
     */
    private function pinsOf(array $teamIds, int $day): array
    {
        return array_map(fn (string $teamId): array => $this->reservation($teamId, 'v1', $day, '18:00'), $teamIds);
    }

    /**
     * Tout ce que le Récap dit d'un groupe, quel que soit le libellé : un silence se prouve sans dépendre du texte.
     *
     * @param list<string> $found
     *
     * @return list<string>
     */
    private function groupWarnings(array $found): array
    {
        return array_values(array_filter($found, static fn (string $w): bool => str_starts_with($w, 'Groupe')));
    }

    /**
     * U13 F1 imposée le lundi, c1 son seul principal, la grille et les indisponibilités données.
     *
     * @param list<array<string, mixed>>     $unavailable
     * @param list<array{0: int, 1: string}> $cases
     *
     * @return list<string>
     */
    private function forcedMondayWarnings(array $unavailable, array $cases = [[1, '18:00:00']]): array
    {
        return $this->warnings->detectBlockers($this->payload(
            venues: [$this->venueWithStarts('v1', 'Matéo', $cases)],
            teams: [$this->team('t1', 'U13 F1')],
            coaches: [$this->coach('c1', 'Anna', 'B')],
            constraints: [
                $this->dayRule('TEAM', 't1', ['forcedDays' => [1]]),
                $this->teamCoach('t1', 'c1', 'MAIN'),
                ...$unavailable,
            ],
        ));
    }

    /**
     * L'avertissement du jour imposé fermé, reconnu à son texte exact.
     *
     * @param list<string> $found
     *
     * @return list<string>
     */
    private function closedForcedDayWarnings(array $found): array
    {
        return array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'U13 F1')
            && str_contains($w, 'une autre règle (indisponibilité de l\'entraîneur principal sur tous les créneaux de ce jour, ou fenêtre horaire) n\'y laisse aucun créneau')));
    }

    /**
     * Le même avertissement, quel que soit son libellé : un silence se prouve sans dépendre du texte.
     *
     * @param list<string> $found
     *
     * @return list<string>
     */
    private function anyClosedForcedDayWarning(array $found): array
    {
        return array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'U13 F1') && str_contains($w, 'une autre règle')));
    }

    /**
     * Le bloc {t1,t2}, unique case 18:00-19:30, ses deux coachs indisponibles à partir de `$from`.
     *
     * @return array<string, mixed>
     */
    private function overlapBlockPayload(string $from): array
    {
        $rule = fn (string $coachId): array => [
            ...$this->coachUnavailableFrom($coachId, [2], $from),
            'id' => 'av' . $coachId,
            'name' => 'Indispo ' . $coachId,
        ];

        return [
            ...$this->blockPayload([2], $rule('cA'), $rule('cB')),
            'venues' => [$this->venueWithStarts('v1', 'V1', [[2, '18:00:00']])],
        ];
    }

    /**
     * Payload avec un bloc {t1,t2} (1 séance commune), un gymnase ouvert les ``venueDays``, et les
     * liens coach cA (t1) / cB (t2) + leurs règles de disponibilité.
     *
     * @param list<int>            $venueDays
     * @param array<string, mixed> $coachA
     * @param array<string, mixed> $coachB
     *
     * @return array<string, mixed>
     */
    private function blockPayload(array $venueDays, array $coachA, array $coachB): array
    {
        return [
            'venues' => [$this->venue('v1', 'V1', $venueDays)],
            'teams' => [$this->team('t1', 'U9F1'), $this->team('t2', 'U9F2')],
            'coaches' => [],
            'constraints' => [
                $this->teamCoach('t1', 'cA'),
                $this->teamCoach('t2', 'cB'),
                $coachA,
                $coachB,
            ],
            'sharedBlocks' => [['id' => 'b', 'teamIds' => ['t1', 't2'], 'commonSessions' => 1]],
        ];
    }

    /**
     * Une règle COACH_AVAILABILITY v2 nommée, avec id (pour l'origine). @return array<string, mixed>.
     *
     * @param list<int> $unavailableDays
     */
    private function coachAvailabilityRule(string $id, string $coachId, array $unavailableDays, string $name = 'dispo'): array
    {
        return [
            'id' => $id,
            'scope' => 'COACH',
            'scopeTargetId' => $coachId,
            'family' => 'COACH_AVAILABILITY',
            'ruleType' => 'HARD',
            'name' => $name,
            'config' => ['unavailableDays' => $unavailableDays],
            'isActive' => true,
        ];
    }

    /**
     * @param list<array<string, mixed>> $venues
     * @param list<array<string, mixed>> $teams
     * @param list<array<string, mixed>> $coaches
     * @param list<array<string, mixed>> $constraints
     *
     * @return array<string, mixed>
     */
    private function payload(array $venues = [], array $teams = [], array $coaches = [], array $constraints = []): array
    {
        return ['venues' => $venues, 'teams' => $teams, 'coaches' => $coaches, 'constraints' => $constraints];
    }

    /**
     * @param list<int> $days
     *
     * @return array<string, mixed>
     */
    private function venue(string $id, string $name, array $days): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'trainingSlots' => array_map(
                static fn (int $day): array => ['dayOfWeek' => $day, 'startTime' => '18:00', 'capacity' => 1],
                $days,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function team(string $id, string $name, int $sessions = 1, ?string $forcedVenueId = null): array
    {
        return ['id' => $id, 'name' => $name, 'sessionsPerWeek' => $sessions, 'forcedVenueId' => $forcedVenueId];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function v2Constraint(string $scope, string $targetId, array $config): array
    {
        return ['scope' => $scope, 'scopeTargetId' => $targetId, 'config' => $config];
    }

    /** Forme v1 du lien équipe⇄coach, telle que le builder l'émet. @return array<string, mixed> */
    private function teamCoach(string $teamId, string $coachId, string $role = 'MAIN'): array
    {
        return ['type' => 'TEAM_COACH', 'teamId' => $teamId, 'metadata' => ['coachId' => $coachId, 'role' => $role]];
    }

    /**
     * Le lien équipe⇄coach TEL QUE LE VRAI BUILDER LE SÉRIALISE — on invoque la méthode privée
     * {@see ScheduleConstraintBuilder::serializeTeamCoachConstraints} (pure, sans dépendance : instance
     * sans constructeur) plutôt que de recopier la forme à la main. C'est ce qui attrape l'objet enum
     * que le helper {@see self::teamCoach()} masque par sa chaîne littérale.
     *
     * @return array<string, mixed>
     */
    private function builderTeamCoach(string $teamId, string $coachId, TeamCoachRole $role): array
    {
        $teamCoach = (new TeamCoach)->setTeamId($teamId)->setCoachId($coachId)->setRole($role);

        $builder = new ReflectionClass(ScheduleConstraintBuilder::class)->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($builder, 'serializeTeamCoachConstraints');
        $method->setAccessible(true);

        /** @var array<int, array<string, mixed>> $serialized */
        $serialized = $method->invoke($builder, [$teamCoach]);

        return $serialized[0];
    }

    /**
     * @param list<string> $found
     *
     * @return list<string>
     */
    private function assistantWithoutMainWarnings(array $found): array
    {
        return array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'aucun coach principal')));
    }

    /**
     * @param list<string> $found
     *
     * @return list<string>
     */
    private function overloadWarnings(array $found): array
    {
        return array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'tomberont le même jour')));
    }

    /**
     * @param list<string> $found
     *
     * @return list<string>
     */
    private function employeeWarnings(array $found): array
    {
        return array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'Aucun salarié')));
    }

    /**
     * @param list<string> $found
     *
     * @return list<string>
     */
    private function overlapWarnings(array $found): array
    {
        return array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'se chevauchent')));
    }

    /**
     * Un gymnase ouvert du lundi au vendredi à 18:00, et les salariés donnés.
     *
     * @param list<array<string, mixed>> $employees
     * @param list<array<string, mixed>> $unavailable
     *
     * @return array<string, mixed>
     */
    private function employeesPayload(array $employees, array $unavailable): array
    {
        return $this->payload(venues: [$this->venue('v1', 'Matéo', [1, 2, 3, 4, 5])], coaches: $employees, constraints: $unavailable);
    }

    /** Un salarié, tel que le payload le porte. @return array<string, mixed> */
    private function employee(string $id, string $firstName, string $lastName): array
    {
        return [...$this->coach($id, $firstName, $lastName), 'isEmployee' => true];
    }

    /**
     * Une indisponibilité bornée par sa fenêtre horaire, comme l'onglet Dispo coach l'écrit.
     *
     * @param list<int> $days
     *
     * @return array<string, mixed>
     */
    private function coachUnavailableFrom(string $coachId, array $days, string $from, ?string $until = null): array
    {
        return [
            'scope' => 'COACH',
            'scopeTargetId' => $coachId,
            'family' => 'COACH_AVAILABILITY',
            'ruleType' => 'HARD',
            'config' => ['unavailableDays' => $days, 'fromTime' => $from, ...(null === $until ? [] : ['untilTime' => $until])],
        ];
    }

    /**
     * @param list<array{0: int, 1: string}> $cases
     *
     * @return array<string, mixed>
     */
    private function venueWithStarts(string $id, string $name, array $cases): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'trainingSlots' => array_map(
                static fn (array $case): array => ['dayOfWeek' => $case[0], 'startTime' => $case[1], 'durationMinutes' => 90, 'capacity' => 1],
                $cases,
            ),
        ];
    }

    /**
     * Deux gymnases, U15 et U13, et les séances figées données (`slotTemplates` du builder).
     *
     * @param list<array<string, mixed>> $reservations
     *
     * @return array<string, mixed>
     */
    private function reservationsPayload(array $reservations): array
    {
        return [
            ...$this->payload(
                venues: [$this->venue('v1', 'Gymnase A', [1, 2]), $this->venue('v2', 'Gymnase B', [1, 2])],
                teams: [$this->team('t1', 'U15'), $this->team('t2', 'U13')],
            ),
            'slotTemplates' => $reservations,
        ];
    }

    /** Une réservation, forme de `ScheduleConstraintBuilder::serializeReservation`. @return array<string, mixed> */
    private function reservation(string $teamId, string $venueId, int $day, string $start, string $lockLevel = 'HARD'): array
    {
        return ['id' => $teamId . $venueId . $start, 'teamId' => $teamId, 'venueId' => $venueId, 'coachId' => null, 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => 90, 'lockLevel' => $lockLevel];
    }

    /** Un entraîneur, tel que le payload le porte. @return array<string, mixed> */
    private function coach(string $id, string $firstName = 'Jean', string $lastName = 'Dupont'): array
    {
        return ['id' => $id, 'firstName' => $firstName, 'lastName' => $lastName, 'isActive' => true];
    }

    /**
     * Une indisponibilité coach plein-jour (scope COACH, `unavailableDays`). @return array<string, mixed>.
     *
     * @param list<int> $days
     */
    private function coachUnavailable(string $coachId, array $days): array
    {
        return ['scope' => 'COACH', 'scopeTargetId' => $coachId, 'family' => 'COACH_AVAILABILITY', 'ruleType' => 'HARD', 'config' => ['unavailableDays' => $days]];
    }

    /**
     * Une fenêtre horaire HARD (famille TIME, scope TEAM). @return array<string, mixed>.
     *
     * @param array<string, mixed> $config
     */
    private function timeRule(string $teamId, array $config): array
    {
        return ['scope' => 'TEAM', 'scopeTargetId' => $teamId, 'family' => 'TIME', 'ruleType' => 'HARD', 'config' => $config];
    }

    /**
     * Une contrainte DAY telle que le builder l'émet (avec `family`/`ruleType`) — le calcul des
     * règles de jour ne retient que HARD, comme le moteur.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function dayRule(string $scope, string $targetId, array $config, string $ruleType = 'HARD'): array
    {
        return ['scope' => $scope, 'scopeTargetId' => $targetId, 'family' => 'DAY', 'ruleType' => $ruleType, 'config' => $config];
    }

    /**
     * Une contrainte FACILITY (scope TEAM) telle que le builder l'émet — le gymnase imposé se lit
     * ici, pas seulement sur le champ d'équipe (ALIGN-15).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function facilityRule(string $targetId, array $config, string $ruleType = 'HARD', bool $isActive = true): array
    {
        return ['scope' => 'TEAM', 'scopeTargetId' => $targetId, 'family' => 'FACILITY', 'ruleType' => $ruleType, 'config' => $config, 'isActive' => $isActive];
    }
}
