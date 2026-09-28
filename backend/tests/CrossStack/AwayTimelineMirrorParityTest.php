<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Service\MatchDurationProfile;
use App\Service\MatchFootprint;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-240 ③ (décision C) — CÔTÉ BACKEND de la parité mécanique de la fenêtre PERSONNE d'un
 * match à l'extérieur.
 *
 * `matches/lib/awayKickoff.ts::awayTimeline` (front, DESSINE le bloc extérieur + la fiche) et
 * `MatchFootprint::personConflictOccupancy` (backend, DÉTECTE les conflits de personne)
 * partagent CETTE algèbre AWAY : la fenêtre va de `[coup d'envoi − échauffement − aller,
 * coup d'envoi + match + aller]` (aller = aller-retour / 2). Les MÊMES cas
 * (`awayTimeline.parity.json`) les traversent : changer l'algèbre d'un seul côté rougit ce
 * côté-là. Ce module front figure au registre `FrontRederivationRegistryTest`.
 */
#[Group('contract')]
final class AwayTimelineMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/matches/lib/awayTimeline.parity.json';

    public function testBackendAwayPersonWindowMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            $oneWay = $case['oneWayMinutes'];
            $roundTrip = null === $oneWay ? 0 : 2 * (int) $oneWay;

            $fixture = new Fixture;
            $fixture->setMatchDate(new DateTimeImmutable('2026-10-03'));
            $fixture->setHomeAway(FixtureHomeAway::AWAY);
            $fixture->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', (string) $case['kickoff']) ?: null);

            $profile = new MatchDurationProfile((int) $case['matchMinutes'], (int) $case['warmupMinutes']);
            $window = new MatchFootprint()->personConflictOccupancy($fixture, $profile, $roundTrip);

            self::assertNotNull($window, (string) $case['name']);
            self::assertSame(
                [(string) $case['departure'], (string) $case['return']],
                [$window['start']->format('H:i'), $window['end']->format('H:i')],
                \sprintf(
                    "PARITÉ ROMPUE (« %s ») : la fenêtre personne AWAY backend diverge du front.\n"
                    . 'Front `awayTimeline` et backend `MatchFootprint::personConflictOccupancy` (AWAY) doivent coïncider sur awayTimeline.parity.json.',
                    (string) $case['name'],
                ),
            );
        }
    }

    /** @return list<array{name: string, kickoff: string, matchMinutes: int, warmupMinutes: int, oneWayMinutes: int|null, departure: string, matchEnd: string, return: string}> */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{name: string, kickoff: string, matchMinutes: int, warmupMinutes: int, oneWayMinutes: int|null, departure: string, matchEnd: string, return: string}> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'awayTimeline.parity.json ne porte plus aucun cas.');

        return $list;
    }
}
