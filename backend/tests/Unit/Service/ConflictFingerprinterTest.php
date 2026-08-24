<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ConflictFingerprinter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * RMM-3 — la maison unique de l'empreinte d'un conflit. Pure : elle prend un item
 * du tableau de MatchConflictDetector et en rend l'IDENTITÉ stable. Ce test épingle
 * les 9 types (les champs d'identité, tels que le détecteur les émet), la STABILITÉ
 * (même empreinte quand sévérité / segment / rôle bougent) et le changement de
 * NATURE (autre paire, ou autre type → autre empreinte).
 */
#[Group('phase1')]
final class ConflictFingerprinterTest extends TestCase
{
    private ConflictFingerprinter $fingerprinter;

    public function testMatchMatchIsCoachPlusSortedFixturePair(): void
    {
        $conflict = [
            'type' => 'MATCH_MATCH',
            'severity' => 3,
            'coachRole' => 'MAIN',
            'coachId' => 'coach-1',
            'start' => '2026-10-04T16:30:00+02:00',
            'end' => '2026-10-04T17:45:00+02:00',
            'left' => ['fixtureId' => 'fix-bbb'],
            'right' => ['fixtureId' => 'fix-aaa'],
        ];
        self::assertSame('MATCH_MATCH:coach-1:fix-aaa,fix-bbb', $this->fingerprinter->fingerprint($conflict));
    }

    public function testMatchTrainingIsCoachPlusFixturePlusSlot(): void
    {
        $conflict = [
            'type' => 'MATCH_TRAINING',
            'severity' => 5,
            'coachId' => 'coach-9',
            'fixture' => ['fixtureId' => 'fix-1'],
            'training' => ['slotTemplateId' => 'slot-7'],
        ];
        self::assertSame('MATCH_TRAINING:coach-9:fix-1:slot-7', $this->fingerprinter->fingerprint($conflict));
    }

    public function testVenueOverlapIsVenuePlusSortedPair(): void
    {
        $conflict = [
            'type' => 'VENUE_OVERLAP',
            'venueId' => 'venue-3',
            'left' => ['fixtureId' => 'fix-z'],
            'right' => ['fixtureId' => 'fix-a'],
        ];
        self::assertSame('VENUE_OVERLAP:venue-3:fix-a,fix-z', $this->fingerprinter->fingerprint($conflict));
    }

    public function testTeamLinkOverlapIsLinkPlusSortedPair(): void
    {
        $conflict = [
            'type' => 'TEAM_LINK_OVERLAP',
            'teamLinkId' => 'link-2',
            'left' => ['fixtureId' => 'fix-m'],
            'right' => ['fixtureId' => 'fix-c'],
        ];
        self::assertSame('TEAM_LINK_OVERLAP:link-2:fix-c,fix-m', $this->fingerprinter->fingerprint($conflict));
    }

    public function testSingleFixtureTypesAreFixtureId(): void
    {
        foreach (['LEAGUE_WINDOW_VIOLATION', 'ACCESS_WINDOW_LOST', 'AWAY_NO_FOOTPRINT'] as $type) {
            $conflict = ['type' => $type, 'severity' => 2, 'fixture' => ['fixtureId' => 'fix-42']];
            self::assertSame($type . ':fix-42', $this->fingerprinter->fingerprint($conflict));
        }
    }

    public function testVenueUnavailableIsFixturePlusUnavailability(): void
    {
        $conflict = [
            'type' => 'VENUE_UNAVAILABLE',
            'venueId' => 'venue-1',
            'unavailabilityId' => 'unavail-8',
            'fixture' => ['fixtureId' => 'fix-5'],
        ];
        self::assertSame('VENUE_UNAVAILABLE:fix-5:unavail-8', $this->fingerprinter->fingerprint($conflict));
    }

    public function testCompetitionIncompleteIsCompetitionIdAlone(): void
    {
        $conflict = [
            'type' => 'COMPETITION_INCOMPLETE',
            'severity' => 6,
            'competitionId' => 'comp-11',
            'imported' => 9,
            'expected' => 22,
        ];
        self::assertSame('COMPETITION_INCOMPLETE:comp-11', $this->fingerprinter->fingerprint($conflict));
    }

    /** La sévérité, le rôle et le segment bougent — l'empreinte NE bouge PAS. */
    public function testFingerprintIsStableWhenSeverityAndSegmentChange(): void
    {
        $base = [
            'type' => 'MATCH_MATCH',
            'coachId' => 'coach-1',
            'left' => ['fixtureId' => 'fix-a'],
            'right' => ['fixtureId' => 'fix-b'],
            'severity' => 3,
            'coachRole' => 'MAIN',
            'start' => '2026-10-04T16:30:00+02:00',
            'end' => '2026-10-04T17:00:00+02:00',
        ];
        $shifted = array_merge($base, [
            'severity' => 5,
            'coachRole' => 'ASSISTANT',
            'start' => '2026-10-04T18:00:00+02:00',
            'end' => '2026-10-04T19:30:00+02:00',
        ]);

        self::assertSame($this->fingerprinter->fingerprint($base), $this->fingerprinter->fingerprint($shifted));
    }

    /** Un COMPETITION_INCOMPLETE 9/22 → 15/22 reste LE MÊME (sinon chaque import le re-badge). */
    public function testCompetitionIncompleteIsStableWhenCountsGrow(): void
    {
        $before = ['type' => 'COMPETITION_INCOMPLETE', 'competitionId' => 'comp-11', 'imported' => 9, 'expected' => 22];
        $after = ['type' => 'COMPETITION_INCOMPLETE', 'competitionId' => 'comp-11', 'imported' => 15, 'expected' => 22];

        self::assertSame($this->fingerprinter->fingerprint($before), $this->fingerprinter->fingerprint($after));
    }

    /** Nature changée : le match A passe d'un conflit avec B à un conflit avec C → autre empreinte. */
    public function testNatureChangeYieldsADifferentFingerprint(): void
    {
        $withB = ['type' => 'MATCH_MATCH', 'coachId' => 'coach-1', 'left' => ['fixtureId' => 'fix-a'], 'right' => ['fixtureId' => 'fix-b']];
        $withC = ['type' => 'MATCH_MATCH', 'coachId' => 'coach-1', 'left' => ['fixtureId' => 'fix-a'], 'right' => ['fixtureId' => 'fix-c']];

        self::assertNotSame($this->fingerprinter->fingerprint($withB), $this->fingerprinter->fingerprint($withC));
    }

    /** MATCH_MATCH → MATCH_TRAINING sur la même fixture : le type est dans l'identité → autre empreinte. */
    public function testTypeChangeYieldsADifferentFingerprint(): void
    {
        $matchMatch = ['type' => 'MATCH_MATCH', 'coachId' => 'coach-1', 'left' => ['fixtureId' => 'fix-a'], 'right' => ['fixtureId' => 'fix-b']];
        $matchTraining = ['type' => 'MATCH_TRAINING', 'coachId' => 'coach-1', 'fixture' => ['fixtureId' => 'fix-a'], 'training' => ['slotTemplateId' => 'slot-b']];

        self::assertNotSame($this->fingerprinter->fingerprint($matchMatch), $this->fingerprinter->fingerprint($matchTraining));
    }

    /** L'ordre gauche/droite est un artefact de la double boucle — pas de l'identité. */
    public function testSortedPairIsOrderIndependent(): void
    {
        $ab = ['type' => 'MATCH_MATCH', 'coachId' => 'c', 'left' => ['fixtureId' => 'fix-a'], 'right' => ['fixtureId' => 'fix-b']];
        $ba = ['type' => 'MATCH_MATCH', 'coachId' => 'c', 'left' => ['fixtureId' => 'fix-b'], 'right' => ['fixtureId' => 'fix-a']];

        self::assertSame($this->fingerprinter->fingerprint($ab), $this->fingerprinter->fingerprint($ba));
    }

    public function testUnknownTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->fingerprinter->fingerprint(['type' => 'SOMETHING_NEW', 'fixture' => ['fixtureId' => 'x']]);
    }

    protected function setUp(): void
    {
        $this->fingerprinter = new ConflictFingerprinter;
    }
}
