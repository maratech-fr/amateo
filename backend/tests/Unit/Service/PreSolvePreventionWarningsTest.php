<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\TeamCoach;
use App\Enum\TeamCoachRole;
use App\Service\PreSolvePreventionWarnings;
use App\Service\ScheduleConstraintBuilder;
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
    private PreSolvePreventionWarnings $warnings;

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
            constraints: [$this->v2Constraint('TEAM', 't1', ['allowedDays' => [5]])],
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
            constraints: [$this->v2Constraint('TEAM', 't1', ['allowedDays' => [2, 5]])],
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

    // ── ALIGN-19 — indispo d'un coach UNIQUEMENT adjoint = indicative ─────────────────────────

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

        self::assertNotSame([], array_filter($found, static fn (string $w): bool => str_contains($w, 'Anna B') && str_contains($w, 'indicative')));
    }

    public function testAMainCoachUnavailabilityIsNotFlaggedAsIndicative(): void
    {
        // Même indisponibilité, mais le coach est PRINCIPAL : elle ferme bien → pas « indicative ».
        $found = $this->warnings->detect($this->payload(
            venues: [$this->venue('v1', 'Matéo', [1])],
            teams: [$this->team('t1', 'U13 F1')],
            coaches: [$this->coach('c1', 'Anna', 'B')],
            constraints: [
                $this->teamCoach('t1', 'c1', 'MAIN'),
                $this->coachUnavailable('c1', [3]),
            ],
        ));

        self::assertSame([], array_filter($found, static fn (string $w): bool => str_contains($w, 'indicative')));
    }

    // ── ALIGN-16 — jour imposé VIDÉ par une autre règle HARD (créneau existe mais fermé) ──────

    public function testForcedDayEmptiedByMainCoachFullDayUnavailabilityIsFlagged(): void
    {
        // t1 doit s'entraîner le mercredi (3), le gymnase A un créneau mercredi — MAIS son
        // entraîneur PRINCIPAL est indisponible tout le mercredi : la génération échouera.
        $found = $this->warnings->detect($this->payload(
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
        $found = $this->warnings->detect($this->payload(
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
        $found = $this->warnings->detect($this->payload(
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
        $found = $this->warnings->detect($this->payload(
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
        $found = $this->warnings->detect($this->payload(
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
        $found = $this->warnings->detect($this->payload(
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
        $found = $this->warnings->detect($this->overlapBlockPayload('19:00'));

        $blockWarnings = array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'coach du groupe')));
        self::assertCount(1, $blockWarnings);
        self::assertStringContainsString('Contrainte en cause : « Indispo cA »', $blockWarnings[0]);
    }

    /** TÉMOIN sans marge : dès 19:30, le groupe garde sa case, silence. */
    public function testSharedBlockIsSilentWhenTheUnavailabilityStartsAtTheEndOfTheSlot(): void
    {
        $found = $this->warnings->detect($this->overlapBlockPayload('19:30'));

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
        $found = $this->warnings->detect($this->blockPayload(
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
        $found = $this->warnings->detect($this->blockPayload(
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
        $found = $this->warnings->detect(
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
        $found = $this->warnings->detect(
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

        $found = $this->warnings->detect($payload);

        self::assertSame([], array_values(array_filter($found, static fn (string $w): bool => str_contains($w, 'coach du groupe'))));
    }

    protected function setUp(): void
    {
        $this->warnings = new PreSolvePreventionWarnings;
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
        return $this->warnings->detect($this->payload(
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
        $teamCoach = (new TeamCoach)->setTeamId($teamId)->setCoachId($coachId)->setRole($role)->setIsRequired(true);

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
