<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * P4-103 — une route d'API club sans AUCUN consommateur se signale toute seule.
 *
 * Précédent fondateur, deux fois : le CRUD `schedule_slot_templates`, puis la route placebo
 * `manual-edit/constraint` (P4-59). « Route non consommée = route supprimée ». Ce test remplace
 * l'audit manuel — qui coûte cher et se refait mal — par un signal continu.
 *
 * ⚑ CONSERVATEUR PAR CONSTRUCTION, et ça n'est pas de la timidité : l'audit du 2026-08-20 a
 * produit 124, puis 74, puis 44 « routes mortes », TOUTES fausses, avant de converger. Deux
 * causes, qui dictent la forme de ce test :
 *
 *   1. le front n'écrit jamais `/api/…` — son client porte `prefix: "/api"` et les features
 *      appellent `api.post("teams", …)`. Chercher le chemin complet ne trouve rien ;
 *   2. une URL peut être CONSTRUITE (`${MAP[kind]}/${id}/deletion-impact`), voire stockée en
 *      base et jamais écrite dans le code (`/api/ffbb-logos/…`, posée par `FfbbClubPopulator`
 *      et rendue en `<img src>`).
 *
 * D'où la règle : une route n'est signalée que si **AUCUN** de ses segments littéraux
 * n'apparaît nulle part — ni dans `frontend/src`, ni dans les scripts, ni dans les e2e. Ce test
 * ne prouve donc pas qu'une route est vivante ; il prouve qu'une route est **manifestement**
 * morte. C'est le seul énoncé qu'on puisse tenir sans mentir.
 *
 * Les exemptions sont NOMMÉES avec leur raison — jamais une famille de chemins.
 */
#[Group('phase1')]
final class ApiRoutesHaveAConsumerTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /**
     * Routes sans consommateur, mais légitimes — chacune avec le POURQUOI.
     *
     * @var array<string, string>
     */
    private const array EXEMPT = [
        '/api/validation_errors/{id}' => 'plomberie native API Platform, pas une route du produit',
        '/api/.well-known/genid/{id}' => 'identifiants JSON-LD générés par API Platform',
        '/api/errors/{status}' => 'page d\'erreur du framework',
        '/api/contexts/{shortName}' => 'contextes JSON-LD servis aux clients hypermedia',
        '/api/docs' => 'documentation OpenAPI — consommée par un humain, pas par le front',
        '/api/health' => 'sonde d\'infrastructure (healthcheck Docker), jamais appelée par un écran',
        // ⚑ LE piège de l'audit, gardé ici comme mémoire : cette route EST consommée, mais
        // son URL n'est écrite nulle part dans le front. `FfbbClubPopulator` la STOCKE en
        // base (`logoUrl = /api/ffbb-logos/…`) et l'écran la rend en `<img src>`. Aucun
        // grep du frontend ne pouvait la voir — c'est pour ce genre de cas qu'une exemption
        // se justifie par une PREUVE, pas par « je n'ai rien trouvé ».
        '/api/ffbb-logos/{scope}/{code}' => 'URL stockée en base par FfbbClubPopulator, rendue en <img src> — jamais écrite dans le front',
        // P2-53 RMM-8 PR-1 : le géocodage BAN naît TESTÉ (CRUD + autofill de la matrice de
        // trajet livrés backend) mais son écran arrive à la PR-3 — le front le câblera alors.
        '/api/geocode' => 'route de géocodage née testée (P2-53 PR-1) — le front la câble à la PR écran (PR-3)',
    ];

    public function testEveryClubApiRouteHasAtLeastOneConsumer(): void
    {
        $haystack = $this->haystack();
        $orphans = [];

        foreach ($this->clubRoutes() as $route) {
            if (isset(self::EXEMPT[$route])) {
                continue;
            }
            $segments = $this->literalSegments($route);
            if ([] === $segments) {
                continue; // `/api/{param}` pur : rien de littéral à chercher.
            }
            foreach ($segments as $segment) {
                if (str_contains($haystack, $segment)) {
                    continue 2;
                }
            }
            $orphans[] = $route;
        }

        sort($orphans);

        self::assertSame([], $orphans, \sprintf(
            "Ces routes d'API n'ont AUCUN consommateur — aucun de leurs segments n'apparaît dans\n"
            . "frontend/src, backend/scripts ni les e2e :\n  - %s\n\n"
            . "« Route non consommée = route supprimée » (précédent fondateur, 2 occurrences).\n"
            . "Si l'une est légitime, exemptez-la NOMMÉMENT dans self::EXEMPT avec sa raison —\n"
            . "et vérifiez d'abord qu'elle n'est pas appelée par une URL CONSTRUITE ou STOCKÉE\n"
            . 'en base : c\'est le piège qui a produit 124 faux positifs à l\'audit du 2026-08-20.',
            implode("\n  - ", $orphans),
        ));
    }

    /**
     * Les routes `/api/**` du rail CLUB, hors console superadmin.
     *
     * `/api/admin/**` est un autre monde : firewall stateful, client HTTP séparé, écrans SA.
     * Les mélanger ferait signaler toute la console comme morte.
     *
     * @return list<string>
     */
    private function clubRoutes(): array
    {
        $routes = [];
        foreach ($this->controllerSources() as $source) {
            preg_match_all('/#\[Route\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);
            foreach ($matches[1] as $path) {
                if (str_starts_with($path, '/api/') && !str_starts_with($path, '/api/admin')) {
                    $routes[$path] = true;
                }
            }
        }

        return array_keys($routes);
    }

    /**
     * Les segments littéraux d'une route, `{param}` exclus — du plus long au plus court, pour
     * que le plus discriminant soit testé d'abord.
     *
     * @return list<string>
     */
    private function literalSegments(string $route): array
    {
        $segments = array_values(array_filter(
            explode('/', substr($route, \strlen('/api'))),
            static fn (string $s): bool => '' !== $s && !str_starts_with($s, '{'),
        ));
        usort($segments, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $segments;
    }

    /** Tout ce qui peut appeler l'API : le front, les scripts, les e2e. */
    private function haystack(): string
    {
        $parts = [];
        foreach ([
            self::ROOT . '/frontend/src',
            self::ROOT . '/frontend/tests',
            self::ROOT . '/backend/scripts',
        ] as $dir) {
            if (is_dir($dir)) {
                $parts[] = $this->concatFiles($dir, ['ts', 'tsx', 'sh', 'json']);
            }
        }

        return implode("\n", $parts);
    }

    /** @return list<string> */
    private function controllerSources(): array
    {
        $dir = self::ROOT . '/backend/src/Controller';
        self::assertDirectoryExists($dir);

        return [$this->concatFiles($dir, ['php'])];
    }

    /** @param list<string> $extensions */
    private function concatFiles(string $dir, array $extensions): string
    {
        $out = '';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && \in_array($file->getExtension(), $extensions, true)) {
                $out .= file_get_contents($file->getPathname()) . "\n";
            }
        }

        return $out;
    }
}
