<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\MatchPlacementPayloadBuilder;
use App\Service\MoveSlotService;
use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * LE GARDE d'alignement des versions (P4-85).
 *
 * Trois constantes de version se confondaient dans les docs pour une seule et
 * même chose : la version du CONTRAT backend⇄engine que le PAYLOAD s'attribue.
 * `engine/CONTRACT_VERSION` (le fichier) est la SOURCE DE VÉRITÉ ; l'engine y
 * compare le champ `version` de tout payload reçu. Les payloads du backend
 * doivent donc annoncer EXACTEMENT cette valeur.
 *
 * Ça marchait par accident : l'engine ne compare que le MAJOR
 * (`version.split(".")[0]`), donc `2.2` passait face à `2.4`. Le risque n'est
 * pas la panne, c'est le bump manqué — le jour où la FORME du payload change
 * sans toucher au MAJOR, les deux côtés se taisent. Ce garde exige l'ÉGALITÉ
 * STRICTE (pas « même MAJOR »), pour crier au prochain bump du fichier.
 */
final class PayloadVersionMatchesContractVersionTest extends TestCase
{
    /** Le fichier, lu depuis le disque — la seule façon d'en faire un garde. */
    private const string CONTRACT_VERSION_FILE = __DIR__ . '/../../../engine/CONTRACT_VERSION';

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function payloadVersionConstants(): iterable
    {
        yield 'ScheduleConstraintBuilder (/generate)' => [
            ScheduleConstraintBuilder::class,
            'CONTRACT_VERSION',
        ];
        yield 'MatchPlacementPayloadBuilder (/place-matches)' => [
            MatchPlacementPayloadBuilder::class,
            'CONTRACT_VERSION',
        ];
        yield 'MoveSlotService (/validate-assignments)' => [
            MoveSlotService::class,
            'CONTRACT_VERSION',
        ];
    }

    #[Group('phase1')]
    #[Group('contract')]
    #[DataProvider('payloadVersionConstants')]
    public function testPayloadVersionEqualsTheContractFile(string $class, string $constant): void
    {
        $expected = $this->contractVersionFromDisk();

        /** @var string $actual */
        $actual = new ReflectionClass($class)->getConstant($constant);

        self::assertSame(
            $expected,
            $actual,
            \sprintf(
                "%s::%s vaut %s mais engine/CONTRACT_VERSION vaut %s.\n"
                . "Les deux DOIVENT être égaux (le payload s'attribue la version du contrat).\n"
                . 'À mettre à jour : la constante PHP dans backend/src/Service/%s.php.',
                $class,
                $constant,
                var_export($actual, true),
                var_export($expected, true),
                new ReflectionClass($class)->getShortName(),
            ),
        );
    }

    private function contractVersionFromDisk(): string
    {
        $path = realpath(self::CONTRACT_VERSION_FILE);
        self::assertIsString($path, 'engine/CONTRACT_VERSION introuvable depuis ' . self::CONTRACT_VERSION_FILE);

        $raw = file_get_contents($path);
        self::assertIsString($raw, 'engine/CONTRACT_VERSION illisible');

        return trim($raw);
    }
}
