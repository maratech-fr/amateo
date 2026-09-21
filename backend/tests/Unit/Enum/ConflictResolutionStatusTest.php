<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\ConflictResolutionStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ValueError;

#[Group('unit')]
final class ConflictResolutionStatusTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        self::assertSame('DEROGATION_REQUESTED', ConflictResolutionStatus::DEROGATION_REQUESTED->value);
        self::assertSame('RESOLVED_INTERNALLY', ConflictResolutionStatus::RESOLVED_INTERNALLY->value);
        self::assertSame('NO_SOLUTION_YET', ConflictResolutionStatus::NO_SOLUTION_YET->value);
        self::assertSame('COACHES_NOT_PLAYING', ConflictResolutionStatus::COACHES_NOT_PLAYING->value);
        self::assertSame('PLAYS_NOT_COACHING', ConflictResolutionStatus::PLAYS_NOT_COACHING->value);
        self::assertSame('IMPORT_MISSING_MATCHES', ConflictResolutionStatus::IMPORT_MISSING_MATCHES->value);
        self::assertSame('FBI_ERROR', ConflictResolutionStatus::FBI_ERROR->value);
        self::assertSame('MATCH_TO_MOVE', ConflictResolutionStatus::MATCH_TO_MOVE->value);
    }

    public function testOnlyEightStoredCases(): void
    {
        // « À traiter » ne se stocke JAMAIS (c'est l'absence de ligne) : exactement
        // huit cas persistables, sinon la colonne (length 30) et l'API divergent. Deux
        // sont réservés aux conflits où la personne joue, trois sont propres à une famille
        // (garde côté contrôleur / table `casesForFamily`).
        self::assertSame(
            ['DEROGATION_REQUESTED', 'RESOLVED_INTERNALLY', 'NO_SOLUTION_YET', 'COACHES_NOT_PLAYING', 'PLAYS_NOT_COACHING', 'IMPORT_MISSING_MATCHES', 'FBI_ERROR', 'MATCH_TO_MOVE'],
            ConflictResolutionStatus::values(),
        );
        // La plus longue valeur tient dans la colonne length: 30 (aucune migration).
        self::assertLessThanOrEqual(30, max(array_map('strlen', ConflictResolutionStatus::values())));
    }

    public function testCasesForFamilyGivesTheBaseThreePlusTheFamilySpecificOnes(): void
    {
        $base = [ConflictResolutionStatus::DEROGATION_REQUESTED, ConflictResolutionStatus::RESOLVED_INTERNALLY, ConflictResolutionStatus::NO_SOLUTION_YET];

        // Une famille sans statut propre → les trois de base, exactement.
        self::assertSame($base, ConflictResolutionStatus::casesForFamily('LEAGUE_WINDOW_VIOLATION'));
        self::assertSame($base, ConflictResolutionStatus::casesForFamily('UNKNOWN_FAMILY'));

        // Collision de gymnase → base + erreur FBI + match à déplacer.
        self::assertSame(
            [...$base, ConflictResolutionStatus::FBI_ERROR, ConflictResolutionStatus::MATCH_TO_MOVE],
            ConflictResolutionStatus::casesForFamily('VENUE_OVERLAP'),
        );

        // Calendrier incomplet → base + importer les matchs manquants.
        self::assertSame(
            [...$base, ConflictResolutionStatus::IMPORT_MISSING_MATCHES],
            ConflictResolutionStatus::casesForFamily('COMPETITION_INCOMPLETE'),
        );

        // Personne en double → base + les deux statuts « joue/coache » (le côté PLAYER
        // reste vérifié par le contrôleur, pas ici).
        self::assertSame(
            [...$base, ConflictResolutionStatus::COACHES_NOT_PLAYING, ConflictResolutionStatus::PLAYS_NOT_COACHING],
            ConflictResolutionStatus::casesForFamily('MATCH_MATCH'),
        );
        self::assertSame(
            [...$base, ConflictResolutionStatus::COACHES_NOT_PLAYING, ConflictResolutionStatus::PLAYS_NOT_COACHING],
            ConflictResolutionStatus::casesForFamily('MATCH_TRAINING'),
        );

        // Un statut propre à une famille n'est JAMAIS proposé ailleurs (souveraineté serveur).
        self::assertNotContains(ConflictResolutionStatus::FBI_ERROR, ConflictResolutionStatus::casesForFamily('MATCH_MATCH'));
        self::assertNotContains(ConflictResolutionStatus::IMPORT_MISSING_MATCHES, ConflictResolutionStatus::casesForFamily('VENUE_OVERLAP'));
    }

    public function testFromValidValue(): void
    {
        self::assertSame(ConflictResolutionStatus::DEROGATION_REQUESTED, ConflictResolutionStatus::from('DEROGATION_REQUESTED'));
    }

    public function testTryFromUnknownValueIsNull(): void
    {
        self::assertNull(ConflictResolutionStatus::tryFrom('A_TRAITER'));
        self::assertNull(ConflictResolutionStatus::tryFrom(''));
    }

    public function testFromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);
        ConflictResolutionStatus::from('UNKNOWN');
    }
}
