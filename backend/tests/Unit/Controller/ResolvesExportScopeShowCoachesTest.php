<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\ResolvesExportScopeTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Chantier E — la route d'export PDF lit « afficher le nom des coachs » du corps de la requête
 * EN FAIL-CLOSED : le nom des coachs ne part QUE si le corps porte le booléen STRICT `true`.
 * Un `"true"`, un `1`, un `"1"`, l'absence du champ, l'absence de corps → `false`. Un flag
 * laxiste divulguerait des noms de personnes sur un document remis aux familles sans que le
 * gestionnaire l'ait demandé.
 *
 * ⚠ Nom de la méthode PROPOSÉ (`resolveShowCoaches(RequestStack): bool`), jumelle de
 * `resolveExportVenueId` (même trait, même source : le corps JSON). NEO peut l'adapter
 * mécaniquement ; la règle fail-closed (STRICT true seulement) ne bouge pas.
 */
#[Group('phase1')]
final class ResolvesExportScopeShowCoachesTest extends TestCase
{
    public function testOnlyTheStrictBooleanTrueEnablesCoaches(): void
    {
        self::assertTrue($this->resolve('{"showCoaches": true}'), 'le booléen strict true active l’affichage');
    }

    public function testEveryTruthyNonBooleanIsRefused(): void
    {
        self::assertFalse($this->resolve('{"showCoaches": "true"}'), 'la CHAÎNE "true" ne suffit pas (fail-closed)');
        self::assertFalse($this->resolve('{"showCoaches": 1}'), 'l’entier 1 ne suffit pas');
        self::assertFalse($this->resolve('{"showCoaches": "1"}'), 'la chaîne "1" ne suffit pas');
        self::assertFalse($this->resolve('{"showCoaches": "on"}'), 'aucune valeur « vraie-ish » n’active');
    }

    public function testAbsentFieldEmptyBodyAndFalseAllReadAsFalse(): void
    {
        self::assertFalse($this->resolve('{}'), 'champ absent → ne pas afficher');
        self::assertFalse($this->resolve('{"showCoaches": false}'), 'false explicite → ne pas afficher');
        self::assertFalse($this->resolve(''), 'corps vide → ne pas afficher');
    }

    public function testNoRequestAtAllReadsAsFalse(): void
    {
        // Un worker/console sans requête HTTP courante ne doit jamais activer les coachs par défaut.
        self::assertFalse($this->resolveWithStack(new RequestStack));
    }

    private function resolve(string $body): bool
    {
        $stack = new RequestStack([Request::create('/api/schedules/x/export-pdf', 'POST', [], [], [], [], $body)]);

        return $this->resolveWithStack($stack);
    }

    private function resolveWithStack(RequestStack $stack): bool
    {
        $subject = new class {
            use ResolvesExportScopeTrait;

            public function call(RequestStack $stack): bool
            {
                return $this->resolveShowCoaches($stack);
            }
        };

        return $subject->call($stack);
    }
}
