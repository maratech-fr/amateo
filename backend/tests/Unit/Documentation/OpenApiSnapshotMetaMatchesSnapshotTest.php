<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NR — les métadonnées du snapshot OpenAPI ne doivent pas pouvoir mentir.
 *
 * L'incident : `specs/courantes/openapi-snapshot.meta.md` annonce une empreinte
 * SHA-256 et un nombre de routes pour `specs/courantes/openapi-snapshot.json`,
 * mais AUCUN test ne les lisait — à chaque régénération du snapshot, l'empreinte
 * consignée devenait fausse sans que rien ne rougisse (elle a menti deux fois
 * dans la même journée). Une valeur annoncée que rien ne recalcule dérive en
 * silence ; ce test est le recalcul.
 *
 * Conventions d'extraction (le fichier de métadonnées est de la PROSE, donc on
 * vise le format RÉEL, sans acrobatie) :
 *  - Nombre de routes : l'unique jeton gras `**N paths**` — sans « + ». Le
 *    journal de changements utilise `**+0 path**` / `**+1 path**` (un DELTA
 *    signé), que l'absence de « + » écarte proprement. On l'exige présent une
 *    seule fois ; s'il apparaissait zéro ou plusieurs fois, le format serait
 *    devenu ambigu et c'est LUI qu'il faut refixer, pas ce test qu'il faut
 *    tordre — d'où un message explicite plutôt qu'un regex acrobatique.
 *  - Empreinte : l'unique jeton de 64 hexas (un SHA-256). Les sha de commit
 *    cités dans la prose font 7-8 hexas et ne collisionnent pas.
 *
 * La vérité recalculée : le compte = le nombre de clés sous `paths` du JSON
 * (aujourd'hui identique au `grep -c '"/api/'` que cite la prose, toute route
 * étant sous `/api/`) ; l'empreinte = `hash_file('sha256', …)`, soit ce que
 * rend `sha256sum` sur le fichier.
 */
#[Group('phase1')]
final class OpenApiSnapshotMetaMatchesSnapshotTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private const string SNAPSHOT = self::ROOT . '/specs/courantes/openapi-snapshot.json';

    private const string META = self::ROOT . '/specs/courantes/openapi-snapshot.meta.md';

    public function testTheAnnouncedRouteCountMatchesTheSnapshot(): void
    {
        $announced = $this->announcedRouteCount();
        $actual = $this->actualRouteCount();

        self::assertSame($actual, $announced, \sprintf(
            "openapi-snapshot.meta.md annonce **%d paths** ; le snapshot en compte %d.\n"
            . 'Recalez la métadonnée sur `**%d paths**` (le journal reste en delta `**+N path**`).',
            $announced,
            $actual,
            $actual,
        ));
    }

    public function testTheAnnouncedFingerprintMatchesTheSnapshot(): void
    {
        $announced = $this->announcedFingerprint();
        $actual = hash_file('sha256', self::SNAPSHOT);
        self::assertIsString($actual, 'Snapshot OpenAPI illisible.');

        self::assertSame($actual, $announced, \sprintf(
            "openapi-snapshot.meta.md annonce l'empreinte %s ; le snapshot a %s.\n"
            . 'Recalez la métadonnée sur cette valeur (`sha256sum specs/courantes/openapi-snapshot.json`).',
            $announced,
            $actual,
        ));
    }

    /**
     * L'unique `**N paths**` (bold, sans « + ») de la prose.
     */
    private function announcedRouteCount(): int
    {
        $meta = $this->meta();

        self::assertSame(
            1,
            preg_match_all('/\*\*(\d+) paths?\*\*/', $meta, $m),
            "openapi-snapshot.meta.md doit contenir EXACTEMENT un jeton `**N paths**` (le total, sans « + »).\n"
            . 'Le journal utilise `**+N path**` (un delta) : si le total a changé de forme, refixez le format avant ce test.',
        );

        return (int) $m[1][0];
    }

    /**
     * L'unique empreinte de 64 hexas de la prose.
     */
    private function announcedFingerprint(): string
    {
        $meta = $this->meta();

        self::assertSame(
            1,
            preg_match_all('/\b[0-9a-f]{64}\b/', $meta, $m),
            'openapi-snapshot.meta.md doit contenir EXACTEMENT un SHA-256 (64 hexas) — l’empreinte du snapshot.',
        );

        return $m[0][0];
    }

    /**
     * Nombre de routes RÉEL : les clés de l'objet `paths` du snapshot.
     */
    private function actualRouteCount(): int
    {
        $raw = file_get_contents(self::SNAPSHOT);
        self::assertIsString($raw, 'Snapshot OpenAPI introuvable.');

        /** @var array{paths?: array<string, mixed>} $doc */
        $doc = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($doc['paths'] ?? null, 'Le snapshot OpenAPI n’a plus de bloc `paths`.');

        return \count($doc['paths']);
    }

    private function meta(): string
    {
        $meta = file_get_contents(self::META);
        self::assertIsString($meta, 'openapi-snapshot.meta.md introuvable.');

        return $meta;
    }
}
