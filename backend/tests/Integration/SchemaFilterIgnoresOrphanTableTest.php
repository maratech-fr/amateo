<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fermetures de gymnase (C1, correction finale 5) — la table `venue_unavailability` reste en base
 * ORPHELINE entre la PR qui retire l'entité et la release suivante qui la DROP (retour arrière
 * possible, `docs/ops/deploy.md`). D'ici là, le filtre de schéma des DEUX connexions (`default`,
 * runtime ; `admin`, celle des migrations) doit l'ignorer : sans lui, un `migrations:diff` ou un
 * `schema:validate` proposerait de la supprimer (ou la verrait comme une dérive) avant l'heure.
 * Le filtre ne doit écarter QUE cette table : une table vivante reste visible (témoin).
 */
#[Group('integration')]
final class SchemaFilterIgnoresOrphanTableTest extends KernelTestCase
{
    /** @return iterable<string, array{string}> */
    public static function connections(): iterable
    {
        yield 'runtime (default)' => ['doctrine.dbal.default_connection'];
        yield 'migrations (admin)' => ['doctrine.dbal.admin_connection'];
    }

    #[DataProvider('connections')]
    public function testTheOrphanTableIsFilteredOutAndALiveTableIsKept(string $serviceId): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get($serviceId);
        self::assertInstanceOf(Connection::class, $connection);
        $filter = $connection->getConfiguration()->getSchemaAssetsFilter();

        self::assertFalse($filter('venue_unavailability'), 'la table orpheline est ignorée par le filtre de schéma');
        self::assertTrue($filter('venue'), 'témoin : une table vivante reste visible');
        self::assertTrue($filter('venue_unavailability_archive'), 'le filtre vise la table EXACTE, pas un préfixe');
    }
}
