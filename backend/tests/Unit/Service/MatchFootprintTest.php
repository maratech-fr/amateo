<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Service\MatchDurationProfile;
use App\Service\MatchFootprint;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class MatchFootprintTest extends TestCase
{
    public function testHomeFixtureIsWarmupPlusMatch(): void
    {
        // Domicile : 30 min échauffement + 1h45 match = 2h15, de kickoff-30 à kickoff+105.
        $fixture = $this->fixture(FixtureHomeAway::HOME, '2026-10-04', '16:00');
        $window = new MatchFootprint()->occupancy($fixture, $this->profile());

        self::assertNotNull($window);
        self::assertSame('2026-10-04 15:30', $window['start']->format('Y-m-d H:i'));
        self::assertSame('2026-10-04 17:45', $window['end']->format('Y-m-d H:i'));
        self::assertSame(135, new MatchFootprint()->occupancyMinutes($fixture, $this->profile()));
    }

    public function testAwayFixtureWithoutTravelMatchesTheHomeFootprint(): void
    {
        // ⚠ CHANGEMENT DE COMPORTEMENT P2-54 : la douche (30) + le battement (15) sortent
        // de l'empreinte (décision fondateur). Sans trajet, l'extérieur ne se distingue
        // donc plus du domicile : 30 échauffement + 105 match = 135 min (avant : 180).
        $fixture = $this->fixture(FixtureHomeAway::AWAY, '2026-10-04', '15:30');
        self::assertSame(135, new MatchFootprint()->occupancyMinutes($fixture, $this->profile()));
    }

    public function testAwayFixtureAddsRoundTripTravelSplitBeforeAndAfter(): void
    {
        // ⚠ CHANGEMENT DE COMPORTEMENT P2-54 : plus de douche/battement. 80 min
        // aller-retour → 40 avant (aller) + 40 après (retour), en plus des 135.
        $fixture = $this->fixture(FixtureHomeAway::AWAY, '2026-10-04', '15:30');
        $window = new MatchFootprint()->occupancy($fixture, $this->profile(), 80);

        self::assertNotNull($window);
        // start = kickoff - (travelOut 40 + warmup 30) = 15:30 - 70 = 14:20
        self::assertSame('14:20', $window['start']->format('H:i'));
        // end = kickoff + (match 105 + travelBack 40) = 15:30 + 145 = 17:55 (avant : 18:40)
        self::assertSame('17:55', $window['end']->format('H:i'));
        self::assertSame(215, new MatchFootprint()->occupancyMinutes($fixture, $this->profile(), 80));
    }

    public function testProfileDurationsDriveTheFootprint(): void
    {
        // P2-54 — un profil U13 (90/30) rétrécit l'empreinte : 30 avant, 90 après.
        $fixture = $this->fixture(FixtureHomeAway::HOME, '2026-10-04', '16:00');
        $window = new MatchFootprint()->occupancy($fixture, new MatchDurationProfile(90, 30));

        self::assertNotNull($window);
        self::assertSame('15:30', $window['start']->format('H:i'));
        self::assertSame('17:30', $window['end']->format('H:i'));
        self::assertSame(120, new MatchFootprint()->occupancyMinutes($fixture, new MatchDurationProfile(90, 30)));
    }

    public function testNoKickoffYieldsNoFootprint(): void
    {
        $fixture = $this->fixture(FixtureHomeAway::HOME, '2026-10-04', null);
        self::assertNull(new MatchFootprint()->occupancy($fixture, $this->profile()));
        self::assertNull(new MatchFootprint()->occupancyMinutes($fixture, $this->profile()));
    }

    public function testPersonConflictWindowDropsTheWarmupAtHome(): void
    {
        // Lot M — la fenêtre de conflit de PERSONNE retranche l'échauffement : à
        // domicile (pas de trajet) elle vaut exactement [coup d'envoi, fin du match],
        // soit la fenêtre SALLE. L'empreinte AFFICHÉE (occupancy) garde l'échauffement.
        $fixture = $this->fixture(FixtureHomeAway::HOME, '2026-10-04', '16:00');
        $window = new MatchFootprint()->personConflictOccupancy($fixture, $this->profile());

        self::assertNotNull($window);
        self::assertSame('2026-10-04 16:00', $window['start']->format('Y-m-d H:i')); // coup d'envoi, échauffement retranché
        self::assertSame('2026-10-04 17:45', $window['end']->format('Y-m-d H:i')); // kickoff + 105
    }

    public function testPersonConflictWindowKeepsTravelButDropsWarmupAway(): void
    {
        // À l'extérieur, seul l'échauffement sort : le trajet (arrivée réelle) reste.
        // 80 min aller-retour → 40 avant (aller) + 40 après (retour). Sans l'échauffement,
        // start = kickoff - 40 (au lieu de -70), end = kickoff + 105 + 40 (inchangé).
        $fixture = $this->fixture(FixtureHomeAway::AWAY, '2026-10-04', '15:30');
        $window = new MatchFootprint()->personConflictOccupancy($fixture, $this->profile(), 80);

        self::assertNotNull($window);
        self::assertSame('14:50', $window['start']->format('H:i')); // 15:30 - 40 (aller seul, pas d'échauffement)
        self::assertSame('17:55', $window['end']->format('H:i')); // 15:30 + 105 + 40, comme l'empreinte
    }

    public function testPersonConflictWindowAtAnExplicitKickoffDropsWarmupToo(): void
    {
        // La variante « heure estimée » (extérieur empruntant l'heure habituelle) :
        // même règle, l'échauffement retranché, sur un coup d'envoi explicite.
        $fixture = $this->fixture(FixtureHomeAway::AWAY, '2026-10-04', null);
        $window = new MatchFootprint()->personConflictOccupancyAt($fixture, new DateTimeImmutable('17:30'), $this->profile());

        self::assertSame('17:30', $window['start']->format('H:i')); // pas de trajet modélisé, pas d'échauffement → coup d'envoi
        self::assertSame('19:15', $window['end']->format('H:i')); // 17:30 + 105
    }

    public function testPersonConflictWindowNullWithoutKickoff(): void
    {
        $fixture = $this->fixture(FixtureHomeAway::HOME, '2026-10-04', null);
        self::assertNull(new MatchFootprint()->personConflictOccupancy($fixture, $this->profile()));
    }

    // Profil de référence : 105 min match + 30 min échauffement (le défaut Seniors /
    // le repli). Les durées sont désormais RÉSOLUES par catégorie et injectées.
    private function profile(): MatchDurationProfile
    {
        return new MatchDurationProfile(105, 30);
    }

    private function fixture(FixtureHomeAway $homeAway, string $date, ?string $kickoff): Fixture
    {
        $fixture = new Fixture;
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway($homeAway);
        $fixture->setKickoffTime(null === $kickoff ? null : (DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null));

        return $fixture;
    }
}
