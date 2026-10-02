<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\AdminHealthService;
use App\Service\EngineClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * D-17 — l'hôte du moteur est écrit à PLUSIEURS endroits, et rien ne les reliait.
 *
 * `EngineClient` (solve + placement) et `AdminHealthService` (le health-check de la console)
 * portent chacun leur littéral. Le seul test qui semblait garder l'URL — `ContractSchemaTest`
 * — la comparait à sa PROPRE copie : passer `EngineClient` sur un autre port laissait les
 * tests CrossStack verts, et seul le smoke tombait.
 *
 * Ce que la divergence coûte, concrètement : le health-check de la console interroge un hôte
 * que le client n'utilise plus. La console affiche « moteur OK » pendant que les générations
 * échouent — un tableau de bord qui rassure sur une machine que personne n'appelle.
 *
 * ⚠ Ce test ne demande PAS une constante unique : les chemins sont légitimement
 * distincts (endpoints différents). Il exige seulement qu'ils visent le même **hôte**.
 */
#[Group('phase1')]
final class EngineUrlIsSingleHostTest extends TestCase
{
    public function testEveryEngineUrlTargetsTheSameHost(): void
    {
        $urls = [
            'EngineClient::ENGINE_URL' => $this->constant(EngineClient::class, 'ENGINE_URL'),
            'EngineClient::PLACE_MATCHES_URL' => $this->constant(EngineClient::class, 'PLACE_MATCHES_URL'),
            'AdminHealthService::ENGINE_HEALTH_URL' => $this->constant(AdminHealthService::class, 'ENGINE_HEALTH_URL'),
        ];

        $hosts = [];
        foreach ($urls as $where => $url) {
            $host = parse_url($url, \PHP_URL_HOST);
            $port = parse_url($url, \PHP_URL_PORT);
            self::assertIsString($host, \sprintf('%s n\'est pas une URL exploitable : %s', $where, $url));
            $hosts[$where] = $host . ':' . ((string) ($port ?? ''));
        }

        self::assertCount(1, array_unique($hosts), \sprintf(
            "Ces chemins ne visent plus le même moteur :\n%s\n"
            . "Un health-check qui interroge un autre hôte que le client affiche « moteur OK » pendant\n"
            . 'que les générations échouent.',
            implode("\n", array_map(
                static fn (string $where, string $host): string => \sprintf('  - %s → %s', $where, $host),
                array_keys($hosts),
                $hosts,
            )),
        ));
    }

    /** @param class-string $class */
    private function constant(string $class, string $name): string
    {
        $value = new ReflectionClass($class)->getConstant($name);
        self::assertIsString($value, \sprintf('%s::%s a disparu ou changé de type.', $class, $name));

        return $value;
    }
}
