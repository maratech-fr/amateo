<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ScheduleConstraintBuilder;
use App\Support\SchemaEpoch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : une demande au moteur mise en cache par un code qui n'a pas les mêmes migrations n'est jamais resservie,
 * même quand la migration ajoutée porte une date plus ancienne que la dernière (les migrations sont nommées par leur
 * date d'écriture : une branche longue en apporte une « du passé »).
 *
 * L'époque de la clé (`SchemaEpoch`) est l'empreinte de la liste des noms de fichiers de `backend/migrations/` :
 * tout nom ajouté ou retiré la change, quelle que soit sa date, et l'ordre de lecture du dossier n'y entre pas. Un
 * « plus grand numéro » ne verrait pas la migration du passé : `testAMigrationDatedBeforeTheLastOneStillChangesTheEpoch`
 * garde ce cas, son témoin prouve que le plus grand numéro n'a pas bougé.
 *
 * Jumeau : `Security/TenantCacheIsolationTest` (la clé sépare les clubs et les saisons) ; ici, les codes.
 */
#[Group('phase1')]
final class ScheduleCacheKeyEpochTest extends TestCase
{
    private const string MIGRATIONS_DIR = __DIR__ . '/../../../migrations';

    public function testTheEpochIsTheFingerprintOfTheMigrationFilesShippedWithTheCode(): void
    {
        $names = $this->migrationFileNames();
        self::assertGreaterThan(100, \count($names), 'témoin : le dossier des migrations est lu');

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', SchemaEpoch::current(), 'douze caractères hexadécimaux');
        self::assertSame(SchemaEpoch::of($names), SchemaEpoch::current(), 'l\'époque courante est celle des fichiers du dossier');
    }

    public function testAMigrationDatedBeforeTheLastOneStillChangesTheEpoch(): void
    {
        $names = $this->migrationFileNames();
        $older = 'Version20200101000000.php';
        self::assertNotContains($older, $names);
        self::assertSame(max($names), max([...$names, $older]), 'témoin : le plus grand numéro ne bouge pas, il ne verrait rien');

        self::assertNotSame(SchemaEpoch::of($names), SchemaEpoch::of([...$names, $older]), 'une migration du passé change l\'époque');
        self::assertNotSame(SchemaEpoch::of($names), SchemaEpoch::of([...$names, 'Version29991231235959.php']), 'une migration récente aussi');
        self::assertNotSame(SchemaEpoch::of($names), SchemaEpoch::of(\array_slice($names, 1)), 'une migration retirée aussi');
    }

    public function testTheEpochIgnoresTheOrderInWhichTheFolderIsRead(): void
    {
        $names = $this->migrationFileNames();

        self::assertSame(SchemaEpoch::of($names), SchemaEpoch::of(array_reverse($names)));
    }

    /**
     * La clé du cache porte l'époque à côté de la version du contrat ; l'ancienne forme (sans époque), celle des
     * demandes mises en cache par le code d'avant, n'est plus jamais lue.
     */
    public function testTheCacheKeyCarriesTheEpoch(): void
    {
        $key = ScheduleConstraintBuilder::cacheKey('club-a', 'season-1', null);

        self::assertSame(
            \sprintf('club.club-a.season.season-1.version.none.contract.%s.epoch.%s.schedule_input', ScheduleConstraintBuilder::CONTRACT_VERSION, SchemaEpoch::current()),
            $key,
        );
        self::assertNotSame(
            \sprintf('club.club-a.season.season-1.version.none.contract.%s.schedule_input', ScheduleConstraintBuilder::CONTRACT_VERSION),
            $key,
            'la clé d\'avant l\'époque n\'est plus celle qu\'on lit',
        );
    }

    /** @return list<string> */
    private function migrationFileNames(): array
    {
        $paths = glob(self::MIGRATIONS_DIR . '/*.php');
        self::assertIsArray($paths);

        return array_map(basename(...), $paths);
    }
}
