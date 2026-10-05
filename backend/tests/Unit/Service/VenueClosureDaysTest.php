<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Constraint;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Service\VenueClosureDays;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NR — P2-5 5b, axe constraint semantics : les DATES/JOURS réellement fermés d'un
 * gymnase. Pur (pas de DB) → couvre les cas limites que le code-review #263 a levés.
 */
#[Group('phase1')]
final class VenueClosureDaysTest extends TestCase
{
    private const VENUE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testClosedDatesAreTheIncidentIntersectedWithTheWindow(): void
    {
        // Incident jeu 05-07 → dim 05-10 dans une semaine lun 05-04 → dim 05-10.
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed('2026-05-07', '2026-05-10')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertSame(['2026-05-07', '2026-05-08', '2026-05-09', '2026-05-10'], array_keys($dates[self::VENUE]));
    }

    public function testMultiWeekWindowDoesNotPhantomTheClosedWeekday(): void
    {
        // LE bug du round 1 : incident du SEUL jeudi 05-07, fenêtre de 3 SEMAINES.
        // Les dates fermées ne doivent contenir QUE 05-07 — pas 05-14 ni 05-21.
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed('2026-05-07', '2026-05-07')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-24'),
        );
        self::assertSame(['2026-05-07'], array_keys($dates[self::VENUE]), 'pas de conflit fantôme les jeudis des autres semaines');
        // Le builder, lui, dérive le JOUR (semaine-type) — jeudi (4) fermé sur le bloc.
        $weekdays = VenueClosureDays::closedWeekdaysByVenue(
            [$this->venueClosed('2026-05-07', '2026-05-07')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-24'),
        );
        self::assertSame([4], array_keys($weekdays[self::VENUE]));
    }

    public function testFullWindowClosureWhenConfigHasNoDates(): void
    {
        // Config nu (legacy) → fermé toute la fenêtre (jamais sous-contraint).
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed(null, null)],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-05'),
        );
        self::assertSame(['2026-05-04', '2026-05-05'], array_keys($dates[self::VENUE]));
    }

    public function testPartialConfigFallsBackToTheWholeWindow(): void
    {
        // Une seule borne (donnée malformée) → toute la fenêtre, jamais sous-contraint.
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed('2026-05-07', null)],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-05'),
        );
        self::assertSame(['2026-05-04', '2026-05-05'], array_keys($dates[self::VENUE]));
    }

    public function testCalendarInvalidStoredDateDoesNotCrash(): void
    {
        // Date syntaxiquement valide mais impossible (2026-13-45) → pas de crash,
        // fallback toute la fenêtre.
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed('2026-13-45', '2026-05-10')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-04'),
        );
        self::assertSame(['2026-05-04'], array_keys($dates[self::VENUE]));
    }

    public function testDisjointIncidentClosesNothing(): void
    {
        $dates = VenueClosureDays::closedDatesByVenue(
            [$this->venueClosed('2026-06-01', '2026-06-07')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertArrayNotHasKey(self::VENUE, $dates, 'un incident hors fenêtre ne ferme rien');
    }

    public function testNonClosureFacilityConstraintIsIgnored(): void
    {
        // Une datée FACILITY qui n'est PAS une fermeture (config.type autre) ne ferme rien.
        $forced = $this->venueClosed('2026-05-04', '2026-05-10');
        $forced->setConfig(['type' => 'forced_venue', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10']);
        $dates = VenueClosureDays::closedDatesByVenue([$forced], new DateTimeImmutable('2026-05-04'), new DateTimeImmutable('2026-05-10'));
        self::assertArrayNotHasKey(self::VENUE, $dates);
    }

    public function testClosureSummaryDescribesAPartialClosure(): void
    {
        // Incident ven 05-08 → dim 05-10 dans la semaine lun 05-04 → dim 05-10.
        $summaries = VenueClosureDays::closureSummaries(
            [$this->venueClosed('2026-05-08', '2026-05-10', 'Pont de mai')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertCount(1, $summaries);
        self::assertSame(self::VENUE, $summaries[0]['venueId']);
        self::assertSame('Pont de mai', $summaries[0]['title']);
        self::assertSame('2026-05-08', $summaries[0]['startDate']);
        self::assertSame('2026-05-10', $summaries[0]['endDate']);
        self::assertSame([5, 6, 7], $summaries[0]['weekdays'], 'ven, sam, dim');
        self::assertNotSame('', $summaries[0]['constraintId']);
    }

    public function testClosureSummaryDisjointFromTheWindowIsAbsent(): void
    {
        $summaries = VenueClosureDays::closureSummaries(
            [$this->venueClosed('2026-06-01', '2026-06-07', 'Travaux')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertSame([], $summaries, 'une fermeture entièrement hors fenêtre ne figure pas');
    }

    public function testClosureSummaryLegacyWithoutDatesSpansTheWholeWindow(): void
    {
        // Config nu (legacy) : bornes = fenêtre de l'entrée, tous les jours fermés.
        $summaries = VenueClosureDays::closureSummaries(
            [$this->venueClosed(null, null, 'Fermeture')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-06'),
        );
        self::assertCount(1, $summaries);
        self::assertSame('2026-05-04', $summaries[0]['startDate']);
        self::assertSame('2026-05-06', $summaries[0]['endDate']);
        self::assertSame([1, 2, 3], $summaries[0]['weekdays']);
    }

    public function testTwoClosuresOnTheSameVenueYieldTwoSummaries(): void
    {
        $summaries = VenueClosureDays::closureSummaries(
            [
                $this->venueClosed('2026-05-04', '2026-05-04', 'Lundi'),
                $this->venueClosed('2026-05-06', '2026-05-06', 'Mercredi'),
            ],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertCount(2, $summaries);
        self::assertSame([1], $summaries[0]['weekdays']);
        self::assertSame([3], $summaries[1]['weekdays']);
        self::assertNotSame($summaries[0]['constraintId'], $summaries[1]['constraintId']);
    }

    public function testFullyClosedWhenOneClosureCoversTheWholeWindow(): void
    {
        // Incident lun 05-04 → dim 05-10 == fenêtre entière : le gymnase est fermé tous les jours.
        $fully = VenueClosureDays::fullyClosedVenueIds(
            [$this->venueClosed('2026-05-04', '2026-05-10')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertSame([self::VENUE], $fully);
    }

    public function testPartialClosureIsNotFullyClosed(): void
    {
        // Incident ven→dim seulement : le gymnase sert encore lun→jeu → PAS entièrement fermé.
        $fully = VenueClosureDays::fullyClosedVenueIds(
            [$this->venueClosed('2026-05-08', '2026-05-10')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertSame([], $fully, 'une fermeture partielle ne rend pas le gymnase entièrement indisponible');
    }

    public function testTwoRelayingClosuresJointlyCoverTheWindow(): void
    {
        // DEUX fermetures partielles qui se RELAIENT : lun→jeu et ven→dim. Ni l'une ni
        // l'autre ne couvre la fenêtre seule (un test « incident ⊇ fenêtre » raterait le
        // cas) ; ensemble elles ferment TOUS les jours → entièrement fermé.
        $fully = VenueClosureDays::fullyClosedVenueIds(
            [
                $this->venueClosed('2026-05-04', '2026-05-07'),
                $this->venueClosed('2026-05-08', '2026-05-10'),
            ],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertSame([self::VENUE], $fully, 'deux fermetures qui se relaient couvrent la fenêtre à elles deux');
    }

    public function testTwoRelayingClosuresWithAGapAreNotFullyClosed(): void
    {
        // Même relais mais avec un TROU (le mercredi 05-06 reste ouvert) → pas entièrement fermé.
        $fully = VenueClosureDays::fullyClosedVenueIds(
            [
                $this->venueClosed('2026-05-04', '2026-05-05'),
                $this->venueClosed('2026-05-07', '2026-05-10'),
            ],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertSame([], $fully, 'un seul jour ouvert suffit à ce que le gymnase ne soit pas entièrement fermé');
    }

    public function testLegacyClosureWithoutDatesIsFullyClosed(): void
    {
        // Config nu (legacy) → fermé toute la fenêtre → entièrement fermé.
        $fully = VenueClosureDays::fullyClosedVenueIds(
            [$this->venueClosed(null, null)],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-06'),
        );
        self::assertSame([self::VENUE], $fully);
    }

    public function testDisjointClosureLeavesNothingFullyClosed(): void
    {
        $fully = VenueClosureDays::fullyClosedVenueIds(
            [$this->venueClosed('2026-06-01', '2026-06-07')],
            new DateTimeImmutable('2026-05-04'),
            new DateTimeImmutable('2026-05-10'),
        );
        self::assertSame([], $fully);
    }

    public function testRawIntervalsReadTheConfigDatesUnclipped(): void
    {
        // P4-300 — l'intervalle BRUT = les dates du config, SANS clip à la fenêtre de repli.
        $intervals = VenueClosureDays::rawIntervals(
            [$this->venueClosed('2026-08-31', '2026-10-16', 'Gymnase en travaux')],
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-07'),
        );

        self::assertCount(1, $intervals);
        self::assertSame(self::VENUE, $intervals[0]['venueId']);
        self::assertSame('Gymnase en travaux', $intervals[0]['title']);
        self::assertSame('2026-08-31', $intervals[0]['startDate']);
        self::assertSame('2026-10-16', $intervals[0]['endDate']);
    }

    public function testRawIntervalsFallBackToTheCarrierWindowWhenDatesAreMissing(): void
    {
        // Config legacy / nu (pas de dates) → repli sur la fenêtre de l'entrée porteuse.
        $intervals = VenueClosureDays::rawIntervals(
            [$this->venueClosed(null, null)],
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-07'),
        );

        self::assertCount(1, $intervals);
        self::assertSame('2026-09-01', $intervals[0]['startDate']);
        self::assertSame('2026-09-07', $intervals[0]['endDate']);
    }

    public function testRawIntervalsTreatInvalidDatesAsLegacyFallback(): void
    {
        // Une date impossible (2026-13-45) est invalide → repli sur la fenêtre porteuse.
        $intervals = VenueClosureDays::rawIntervals(
            [$this->venueClosed('2026-13-45', '2026-10-16')],
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable('2026-09-07'),
        );

        self::assertCount(1, $intervals);
        self::assertSame('2026-09-01', $intervals[0]['startDate']);
        self::assertSame('2026-09-07', $intervals[0]['endDate']);
    }

    private function venueClosed(?string $start, ?string $end, string $name = 'Salle fermée'): Constraint
    {
        $c = new Constraint;
        $c->setFamily(ConstraintFamily::FACILITY);
        $c->setScope(ConstraintScope::FACILITY);
        $c->setScopeTargetId(self::VENUE);
        $c->setRuleType(ConstraintRuleType::HARD);
        $c->setName($name);
        $config = ['type' => 'venue_closed'];
        if (null !== $start) {
            $config['startDate'] = $start;
        }
        if (null !== $end) {
            $config['endDate'] = $end;
        }
        $c->setConfig($config);

        return $c;
    }
}
