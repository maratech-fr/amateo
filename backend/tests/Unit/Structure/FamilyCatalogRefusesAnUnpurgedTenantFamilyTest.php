<?php

declare(strict_types=1);

namespace App\Tests\Unit\Structure;

use App\Structure\Family;
use App\Structure\FamilyCatalog;
use App\Structure\Level;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Lot conception 1, PR 4b (plan §7 ligne 18) — « une famille tenant qu'aucune purge n'emporte fait
 * échouer la purge » : une famille de niveau club, saison, plan ou version qui n'entre ni dans la
 * purge de saison, ni dans l'effacement, ni par un propriétaire, ne déclare aucune épargne et n'est
 * pas un cas nommé survivrait en silence à l'effacement RGPD. Le catalogue lève une `LogicException`
 * qui la nomme dès qu'une purge lui demande sa liste.
 *
 * Décor : un catalogue sur une seule famille, de niveau club, dont le club n'est PAS une colonne
 * `clubId` (le cas réel visé : un club désigné par une association). Témoin : la même famille avec sa
 * colonne `clubId` entre dans l'effacement et rien ne lève. Le catalogue réel ne lève pas : prouvé par
 * chaque purge jouée de `Security/PurgeCompletenessTest` et `Integration/PurgeOrderRespectsForeignKeysTest`.
 */
final class FamilyCatalogRefusesAnUnpurgedTenantFamilyTest extends TestCase
{
    public function testAClubFamilyWithoutAClubIdColumnIsRefusedByBothPurges(): void
    {
        $unpurged = new #[Family(Level::CLUB)] class {};

        foreach (['purgedWithClub', 'purgedWithSeason'] as $purge) {
            try {
                $this->catalogOf($unpurged::class, withClubIdColumn: false)->{$purge}();
                self::fail($purge . '() a rendu sa liste sans refuser la famille que rien ne purge.');
            } catch (LogicException $refusal) {
                self::assertStringContainsString($unpurged::class . ' (niveau CLUB) n\'entre dans aucune purge', $refusal->getMessage());
            }
        }
    }

    public function testTheSameFamilyWithItsClubIdColumnIsErasedWithTheClub(): void
    {
        $erased = new #[Family(Level::CLUB)] class {};

        self::assertSame([$erased::class], $this->catalogOf($erased::class, withClubIdColumn: true)->purgedWithClub());
    }

    /** @param class-string $class */
    private function catalogOf(string $class, bool $withClubIdColumn): FamilyCatalog
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable(['name' => 'fixture_family']);
        if ($withClubIdColumn) {
            $metadata->mapField(['fieldName' => 'clubId', 'type' => 'guid']);
        }

        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('getAllMetadata')->willReturn([$metadata]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getMetadataFactory')->willReturn($factory);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        return new FamilyCatalog($entityManager);
    }
}
