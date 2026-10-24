<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\ExportPdfMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Chantier E — le message d'export PDF porte « afficher le nom des coachs ».
 *
 * Trois promesses, une par test :
 *   1. le défaut est FALSE (décoché) — personne ne reçoit le nom des coachs sans l'avoir demandé ;
 *   2. un `true` explicite est transporté jusqu'au handler ;
 *   3. GARDE LEGACY — une enveloppe enfilée AVANT que ce champ n'existe se désérialise avec la
 *      propriété readonly NON initialisée ; le getter doit alors rendre `false`, pas lever
 *      « must not be accessed before initialization » (même patron que `getClubId()`,
 *      `ExportPdfMessage:29`). Sans cette garde, tout export en file au moment du déploiement
 *      ferait planter le worker.
 *
 * ⚠ Nom du getter PROPOSÉ (`getShowCoaches`) : NEO peut l'adapter mécaniquement ; la règle non.
 */
#[Group('phase1')]
final class ExportPdfMessageShowCoachesTest extends TestCase
{
    public function testShowCoachesDefaultsToFalse(): void
    {
        $message = new ExportPdfMessage(scheduleId: 'sched-1', clubId: 'club-1');

        self::assertFalse($message->getShowCoaches(), 'décoché par défaut : aucun nom de coach sans demande explicite');
    }

    public function testAnExplicitTrueIsCarried(): void
    {
        $message = new ExportPdfMessage(scheduleId: 'sched-1', clubId: 'club-1', showCoaches: true);

        self::assertTrue($message->getShowCoaches());
    }

    public function testALegacyPayloadWithoutTheFieldReadsAsFalse(): void
    {
        // Une enveloppe sérialisée avant l'ajout du champ : la propriété readonly reste NON
        // initialisée. Le getter doit retomber sur `false` (isset/?? guard), jamais lever.
        $legacy = new ReflectionClass(ExportPdfMessage::class)->newInstanceWithoutConstructor();

        self::assertFalse($legacy->getShowCoaches(), 'un message legacy (champ absent) vaut « ne pas afficher les coachs »');
    }
}
