<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Promesse : l'inspecteur de file du rattrapage des placements (`PlacementQueueInspector`) lit
 * la MÊME file que le worker. Il rebâtit sa connexion depuis `MESSENGER_TRANSPORT_DSN` seul
 * (stream, sérialiseur PHP posé par le transport à la construction) : si le transport `async`
 * gagnait des `options:` dans `messenger.yaml` (autre stream, `lazy`, sérialiseur…), ou si le DSN
 * devenait paresseux, l'inspecteur lirait une autre file — ou n'en décoderait plus aucune entrée —
 * et le rattrapage déclarerait « interrompus » des placements bel et bien en file.
 *
 * Ce garde rougit dès que l'hypothèse change : il faut alors faire suivre l'inspecteur.
 * Angle comportemental jumeau : `Integration/Service/PlacementQueueInspectorTest`.
 */
final class PlacementQueueInspectorReadsTheAsyncTransportTest extends TestCase
{
    private const string MESSENGER = __DIR__ . '/../../../config/packages/messenger.yaml';

    private const array ENV_FILES = [__DIR__ . '/../../../.env', __DIR__ . '/../../../.env.dist'];

    public function testTheAsyncTransportIsTheBareDsnTheInspectorRebuilds(): void
    {
        $config = Yaml::parseFile(self::MESSENGER);
        self::assertIsArray($config);
        $async = $config['framework']['messenger']['transports']['async'] ?? null;
        self::assertIsArray($async, 'le transport async a changé de forme dans messenger.yaml');

        self::assertSame('%env(MESSENGER_TRANSPORT_DSN)%', $async['dsn'] ?? null, 'l\'inspecteur lit MESSENGER_TRANSPORT_DSN : le transport async doit lire le même');
        self::assertArrayNotHasKey('options', $async, 'des options sur le transport async échappent à l\'inspecteur de file (stream, sérialiseur, lazy) : faites-le suivre');
        self::assertArrayNotHasKey('serializer', $async, 'un sérialiseur propre au transport async échappe à l\'inspecteur de file');
    }

    public function testTheDefaultDsnIsNotLazy(): void
    {
        foreach (self::ENV_FILES as $file) {
            $raw = file_get_contents($file);
            self::assertIsString($raw);
            self::assertSame(1, preg_match('/^MESSENGER_TRANSPORT_DSN=(.*)$/m', $raw, $m), basename($file) . ' doit déclarer MESSENGER_TRANSPORT_DSN');
            self::assertStringNotContainsString('lazy', $m[1], basename($file) . ' : un DSN paresseux ne configure pas le client Redis que l\'inspecteur emprunte');
        }
    }
}
