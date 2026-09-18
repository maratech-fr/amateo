<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\TenantOwnedInterface;
use App\Service\ErasedClubPurger;
use App\Service\SeasonDataPurger;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * BCK-24 — la purge de saison (rétention / reset) ET l'effacement RGPD d'un club
 * doivent couvrir TOUTE donnée tenant. Le trou est INVISIBLE : une entité tenant
 * oubliée survit en silence à un « réinitialiser la saison » ou à un effacement —
 * pour l'effacement, c'est une donnée personnelle qui reste alors qu'on l'a promise
 * détruite. `opponent_travel` (P2-54) était exactement dans ce cas au 2026-09-18.
 *
 * Ce test ne recopie aucune liste — il DÉRIVE des constantes des deux purgers et
 * exige que toute entité tenant soit, pour chaque chemin, purgée ou nommément exclue
 * AVEC sa raison. Une entité tenant nouvelle tombe donc en échec par défaut, ce qui
 * est le bon défaut pour une purge légale (patron {@see RgpdExportCompletenessTest}).
 */
#[Group('phase1')]
final class PurgeCompletenessTest extends KernelTestCase
{
    /**
     * Purge de SAISON (SeasonDataPurger) : toute entité tenant doit être purgée via
     * son parent, par club+saison, traitée à part, ou exclue nommément avec sa raison.
     */
    public function testEveryTenantOwnedEntityIsSeasonPurgedOrExcludedOnPurpose(): void
    {
        $season = new ReflectionClass(SeasonDataPurger::class);

        $covered = array_flip(array_merge(
            $this->seasonPurgeCoverage(),
            array_keys($season->getConstant('EXCLUDED_FROM_SEASON_PURGE')),
        ));

        $unaccounted = [];
        foreach ($this->tenantOwnedTables() as $table) {
            if (isset($covered[$table])) {
                continue;
            }
            $unaccounted[] = $table;
        }

        self::assertSame([], $unaccounted, \sprintf(
            "Ces tables tenant ne sont NI purgées par SeasonDataPurger NI exclues nommément :\n  - %s\n"
            . "Une purge de saison (rétention / reset) les laisserait EN SILENCE — la commande rend un succès.\n"
            . "Deux issues, jamais l'oubli : les faire entrer dans une des boucles de purge, ou les ajouter à\n"
            . 'SeasonDataPurger::EXCLUDED_FROM_SEASON_PURGE AVEC la raison qui le justifie.',
            implode("\n  - ", $unaccounted),
        ));

        /** @var array<string, string> $excluded */
        $excluded = $season->getConstant('EXCLUDED_FROM_SEASON_PURGE');
        foreach ($excluded as $table => $reason) {
            self::assertNotSame('', trim($reason), \sprintf('L\'exclusion de « %s » doit porter sa raison.', $table));
        }
    }

    /**
     * Effacement RGPD (ErasedClubPurger) : toute entité tenant doit être purgée par la
     * couverture de saison (itérée sur chaque saison), par club, ou exclue nommément.
     */
    public function testEveryTenantOwnedEntityIsErasedOrExcludedOnPurpose(): void
    {
        $erased = new ReflectionClass(ErasedClubPurger::class);

        $covered = array_flip(array_merge(
            $this->seasonPurgeCoverage(),
            $this->tablesOf($erased->getConstant('PURGED_BY_CLUB')),
            array_keys($erased->getConstant('EXCLUDED_FROM_ERASURE')),
        ));

        $unaccounted = [];
        foreach ($this->tenantOwnedTables() as $table) {
            if (isset($covered[$table])) {
                continue;
            }
            $unaccounted[] = $table;
        }

        self::assertSame([], $unaccounted, \sprintf(
            "Ces tables tenant ne sont NI effacées par ErasedClubPurger NI exclues nommément :\n  - %s\n"
            . "L'effacement RGPD (art. 17) les GARDERAIT en base — donnée personnelle promise détruite.\n"
            . 'Issue : les purger (par saison ou par club), ou les ajouter à ErasedClubPurger::EXCLUDED_FROM_ERASURE AVEC la raison.',
            implode("\n  - ", $unaccounted),
        ));

        /** @var array<string, string> $excluded */
        $excluded = $erased->getConstant('EXCLUDED_FROM_ERASURE');
        foreach ($excluded as $table => $reason) {
            self::assertNotSame('', trim($reason), \sprintf('L\'exclusion de « %s » doit porter sa raison.', $table));
        }
    }

    /**
     * Les tables couvertes par une purge de saison : via parent, par club+saison,
     * ou traitées à part (avec raison).
     *
     * @return list<string>
     */
    private function seasonPurgeCoverage(): array
    {
        $season = new ReflectionClass(SeasonDataPurger::class);

        /** @var array<class-string, array{0: string, 1: class-string}> $viaParent */
        $viaParent = $season->getConstant('PURGED_VIA_PARENT');
        /** @var list<class-string> $byClubSeason */
        $byClubSeason = $season->getConstant('PURGED_BY_CLUB_SEASON');

        return array_merge(
            $this->tablesOf(array_keys($viaParent)),
            $this->tablesOf($byClubSeason),
            array_keys($season->getConstant('HANDLED_APART')),
        );
    }

    /**
     * @param list<class-string> $entityClasses
     *
     * @return list<string>
     */
    private function tablesOf(array $entityClasses): array
    {
        self::bootKernel();
        $factory = self::getContainer()->get('doctrine')->getManager()->getMetadataFactory();

        return array_map(
            static fn (string $entityClass): string => $factory->getMetadataFor($entityClass)->getTableName(),
            $entityClasses,
        );
    }

    /** @return list<string> */
    private function tenantOwnedTables(): array
    {
        $tables = [];

        self::bootKernel();
        $factory = self::getContainer()->get('doctrine')->getManager()->getMetadataFactory();
        foreach ($factory->getAllMetadata() as $metadata) {
            if (is_a($metadata->getName(), TenantOwnedInterface::class, true)) {
                $tables[] = $metadata->getTableName();
            }
        }

        self::assertNotEmpty($tables, 'Aucune entité tenant trouvée — le marqueur a-t-il changé de nom ?');

        return $tables;
    }
}
