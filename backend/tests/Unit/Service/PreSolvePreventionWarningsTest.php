<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PreSolvePreventionWarnings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

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
            'Alex Martin encadre 4 séances par semaine mais n\'a que 2 jours disponibles : plusieurs séances tomberont le même jour.',
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

        self::assertSame([], array_filter($found, static fn (string $w): bool => str_contains($w, 'encadre')));
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
                $this->v2Constraint('COACH', 'c1', ['unavailableDays' => [3]]),
            ],
        ));

        self::assertContains(
            'Alex Martin encadre 3 séances par semaine mais n\'a que 2 jours disponibles : plusieurs séances tomberont le même jour.',
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

    /** Un payload vide n'invente rien : zéro équipe, zéro gymnase, zéro bruit. */
    public function testAnEmptyPayloadSaysNothing(): void
    {
        self::assertSame([], $this->warnings->detect([]));
        self::assertSame([], $this->warnings->detectBlockers([]));
    }

    protected function setUp(): void
    {
        $this->warnings = new PreSolvePreventionWarnings;
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
    private function teamCoach(string $teamId, string $coachId): array
    {
        return ['type' => 'TEAM_COACH', 'teamId' => $teamId, 'metadata' => ['coachId' => $coachId]];
    }

    /**
     * Une contrainte DAY telle que le builder l'émet (avec `family`/`ruleType`) — le calcul des
     * règles de jour ne retient que HARD/LOCK, comme le moteur.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function dayRule(string $scope, string $targetId, array $config, string $ruleType = 'HARD'): array
    {
        return ['scope' => $scope, 'scopeTargetId' => $targetId, 'family' => 'DAY', 'ruleType' => $ruleType, 'config' => $config];
    }
}
