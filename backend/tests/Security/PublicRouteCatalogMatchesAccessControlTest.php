<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\PublicApiRouteCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\Yaml\Yaml;

/**
 * D3 — LA MAISON UNIQUE des routes publiques du firewall `api` ⇄ `access_control`, les DEUX sens.
 *
 * Le neutraliseur de crédentiels (D-a) retire cookie/Authorization sur une requête qui matche une
 * route publique. Il ne peut pas tenir une liste à la main : elle dériverait silencieusement de
 * `config/packages/security.yaml`. {@see PublicApiRouteCatalog} est cette maison
 * unique, et ce test la cloue à `access_control` dans les DEUX sens — une route `PUBLIC_ACCESS`
 * ajoutée à security.yaml sans entrée au catalogue reste bloquée par un cookie mort (le bug D3 qui
 * revient) ; une entrée au catalogue sans règle `PUBLIC_ACCESS` neutraliserait une route protégée.
 *
 * ⚠ API ATTENDUE de `App\Security\PublicApiRouteCatalog` (que NEO créera) :
 *   - `public const array RULES` : liste de `['path' => <regex access_control verbatim>,
 *     'methods' => list<string>]`, miroir de CHAQUE règle `PUBLIC_ACCESS` du firewall `api` —
 *     `methods` = [] signifie « toutes les méthodes » (règle sans clé `methods`).
 *   - `public static function matches(string $pathInfo, string $method): bool` : le matcher runtime
 *     dont se sert le neutraliseur.
 *
 * Exclusions NOMINATIVES (règles `PUBLIC_ACCESS` qui n'appartiennent PAS au firewall `api` et ne
 * doivent donc jamais entrer au catalogue) : les deux portes de connexion admin (SA0, firewall
 * séparé) et `^/api/login` (lexik pose/efface déjà le cookie, et `/login` est son propre firewall).
 *
 * Non bloquant (ce test ne gate pas, il n'est pas un step de `blocking-tests`) — le NR BLOQUANT de
 * l'axe est {@see PublicRouteStaleJwtTest}.
 */
#[Group('security')]
final class PublicRouteCatalogMatchesAccessControlTest extends TestCase
{
    private const string SECURITY_YAML = __DIR__ . '/../../config/packages/security.yaml';

    /** Les règles PUBLIC_ACCESS qui ne relèvent pas du firewall `api` → jamais au catalogue. */
    private const array EXCLUSIONS = [
        '^/api/admin/auth/password$',
        '^/api/admin/auth/totp$',
        '^/api/login',
    ];

    public function testEveryPublicAccessApiRuleIsInTheCatalog(): void
    {
        $missing = array_diff(array_keys($this->publicAccessRulesFromSecurityYaml()), array_keys($this->catalogRules()));

        self::assertSame([], array_values($missing), \sprintf(
            "Ces règles PUBLIC_ACCESS du firewall `api` ne sont pas au catalogue PublicApiRouteCatalog :\n  - %s\n"
            . 'Sans entrée, un cookie JWT mort rebloquerait ces pages publiques (le bug D3).',
            implode("\n  - ", $missing),
        ));
    }

    public function testEveryCatalogEntryIsAPublicAccessApiRule(): void
    {
        $phantom = array_diff(array_keys($this->catalogRules()), array_keys($this->publicAccessRulesFromSecurityYaml()));

        self::assertSame([], array_values($phantom), \sprintf(
            "Ces entrées du catalogue ne correspondent à aucune règle PUBLIC_ACCESS du firewall `api` :\n  - %s\n"
            . 'Une entrée de trop neutraliserait les crédentiels d\'une route protégée.',
            implode("\n  - ", $phantom),
        ));
    }

    public function testCatalogAndAccessControlAgreeOnMethodsNotJustPaths(): void
    {
        // array_diff ci-dessus compare des clés `path|METHODES` : une divergence de MÉTHODES (p. ex.
        // un GET public élargi à POST au catalogue) produit deux clés distinctes, donc rougit des
        // deux côtés. Cette assertion l'énonce frontalement sur l'ensemble complet.
        self::assertSame(
            $this->publicAccessRulesFromSecurityYaml(),
            $this->catalogRules(),
            'le catalogue doit refléter chemins ET méthodes des règles PUBLIC_ACCESS du firewall `api`',
        );
    }

    public function testTheRuntimeMatcherAgreesWithTheCatalog(): void
    {
        // Smoke du matcher que le neutraliseur appelle : une page publique matche, une route
        // protégée et une exclusion nominative ne matchent pas, et les méthodes sont respectées.
        self::assertTrue(PublicApiRouteCatalog::matches('/api/health', 'GET'), 'la page publique /api/health doit matcher');
        self::assertTrue(PublicApiRouteCatalog::matches('/api/coach-wishes/public/abc', 'POST'), 'une page à token publique doit matcher');
        self::assertFalse(PublicApiRouteCatalog::matches('/api/coach-wishes/public/abc', 'DELETE'), 'une méthode hors catalogue ne matche pas');
        self::assertFalse(PublicApiRouteCatalog::matches('/api/me', 'GET'), 'une route protégée ne matche jamais');
        self::assertFalse(PublicApiRouteCatalog::matches('/api/login', 'POST'), '^/api/login est exclu nominativement');
        self::assertFalse(PublicApiRouteCatalog::matches('/api/admin/auth/password', 'POST'), 'les portes admin sont exclues nominativement');
    }

    /**
     * Le matcher doit décoder le pour-cent AVANT d'appliquer la regex, comme `access_control` qui
     * matche sur `rawurldecode($request->getPathInfo())` ({@see PathRequestMatcher}).
     * Sans ce décodage, il DIVERGE du firewall dans les deux sens — les deux cloués ici :
     *  - une route publique écrite ENCODÉE n'est plus reconnue → un cookie mort la rebloque (le bug
     *    D3 qui revient par l'encodage) ;
     *  - un `%2F` (slash encodé) laisse le motif `[^/]+` d'une route protégée matcher un segment qui,
     *    décodé, contient des slashes → le neutraliseur élargirait une route que `access_control`,
     *    lui, ne tient PAS pour publique après décodage.
     */
    public function testTheMatcherDecodesPercentEncodingLikeAccessControl(): void
    {
        self::assertTrue(
            PublicApiRouteCatalog::matches('/api/regist%65r/config', 'GET'),
            '`%65` = `e` : `/api/register/config` écrit encodé reste une route publique, comme pour access_control',
        );
        self::assertFalse(
            PublicApiRouteCatalog::matches('/api/clubs/a%2F..%2Fb/logo', 'GET'),
            '`%2F` décodé = `/` : le slash brise `[^/]+`, donc PAS public — le matcher ne doit pas élargir une route protégée via l\'encodage',
        );
    }

    /**
     * Les règles PUBLIC_ACCESS du firewall `api`, lues de security.yaml, moins les exclusions
     * nominatives. Clé = `path|METHODES` (méthodes triées majuscules, `*` = toutes).
     *
     * @return array<string, true>
     */
    private function publicAccessRulesFromSecurityYaml(): array
    {
        $config = Yaml::parseFile(self::SECURITY_YAML);
        self::assertIsArray($config);
        self::assertIsArray($config['security'] ?? null);
        self::assertIsArray($config['security']['access_control'] ?? null, 'access_control a disparu de security.yaml');

        $rules = [];
        foreach ($config['security']['access_control'] as $rule) {
            if (!\is_array($rule) || !$this->isPublicAccess($rule['roles'] ?? null)) {
                continue;
            }
            $path = $rule['path'] ?? null;
            self::assertIsString($path);
            if (\in_array($path, self::EXCLUSIONS, true)) {
                continue;
            }
            $rules[$this->key($path, $rule['methods'] ?? [])] = true;
        }

        self::assertNotEmpty($rules, 'aucune règle PUBLIC_ACCESS api trouvée — le parsing a-t-il cassé ?');

        return $rules;
    }

    /**
     * Les entrées de PublicApiRouteCatalog::RULES, mêmes clés `path|METHODES`.
     *
     * @return array<string, true>
     */
    private function catalogRules(): array
    {
        /** @var list<array{path: string, methods: list<string>}> $rules */
        $rules = PublicApiRouteCatalog::RULES;

        $keyed = [];
        foreach ($rules as $rule) {
            $keyed[$this->key($rule['path'], $rule['methods'])] = true;
        }

        return $keyed;
    }

    private function isPublicAccess(mixed $roles): bool
    {
        if (\is_array($roles)) {
            return \in_array('PUBLIC_ACCESS', $roles, true);
        }

        return 'PUBLIC_ACCESS' === $roles;
    }

    /** @param list<string> $methods */
    private function key(string $path, array $methods): string
    {
        if ([] === $methods) {
            return $path . '|*';
        }
        $normalized = array_map(strtoupper(...), $methods);
        sort($normalized);

        return $path . '|' . implode(',', $normalized);
    }
}
