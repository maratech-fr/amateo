<?php

declare(strict_types=1);

namespace App\Security;

/**
 * D3 — LA MAISON UNIQUE des routes publiques du firewall `api`, pour le neutraliseur de
 * crédentiels ({@see \App\EventListener\PublicApiCredentialNeutralizer}).
 *
 * ⚠ POURQUOI CETTE CLASSE EXISTE : le firewall `api` (stateless, `jwt: ~`) fait tourner
 * l'authentificateur lexik dès qu'un jeton est PRÉSENT — même sur une route `PUBLIC_ACCESS`.
 * Un jeton mort (expiré, signature invalide, compte supprimé) y échoue AVANT que
 * `access_control` n'accorde l'accès public : la page publique 401-ait sur un cookie que le
 * JS ne peut même pas effacer (httpOnly). Le neutraliseur retire cookie + en-tête
 * `Authorization` sur une requête publique ; pour savoir CE QUI est public, il lui faut la
 * liste des règles `PUBLIC_ACCESS` du firewall `api`. Une liste tenue à la main dériverait en
 * silence de `config/packages/security.yaml` : ces entrées en sont le MIROIR, et
 * {@see \App\Tests\Security\PublicRouteCatalogMatchesAccessControlTest} les y cloue dans les
 * DEUX sens (une règle publique sans entrée = le bug D3 qui revient ; une entrée de trop =
 * une route protégée dont les crédentiels seraient neutralisés).
 *
 * N'appartiennent PAS au firewall `api`, donc EXCLUES ici (gardé nominativement par le test) :
 *  - `^/api/admin/auth/password$` et `^/api/admin/auth/totp$` : portes de connexion admin (SA0,
 *    firewall `^/api/admin` séparé, session stateful) ;
 *  - `^/api/login` : son propre firewall, où lexik pose déjà le cookie.
 */
final class PublicApiRouteCatalog
{
    /**
     * Miroir EXACT des règles `PUBLIC_ACCESS` du firewall `api` de `security.yaml`, hors
     * exclusions nominatives. `path` = la regex `access_control` VERBATIM ; `methods` = []
     * signifie « toutes les méthodes » (règle sans clé `methods`).
     *
     * @var list<array{path: string, methods: list<string>}>
     */
    public const array RULES = [
        ['path' => '^/api/logout$', 'methods' => []],
        ['path' => '^/api/register', 'methods' => []],
        ['path' => '^/api/password', 'methods' => []],
        ['path' => '^/api/me/email/confirm$', 'methods' => ['POST']],
        ['path' => '^/api/health', 'methods' => []],
        ['path' => '^/api/docs', 'methods' => []],
        ['path' => '^/api/clubs/[^/]+/logo$', 'methods' => ['GET']],
        ['path' => '^/api/ffbb-logos/', 'methods' => ['GET']],
        ['path' => '^/api/coach-wishes/public/', 'methods' => ['GET', 'POST']],
        ['path' => '^/api/club-approvals/', 'methods' => ['GET', 'POST']],
        ['path' => '^/api/invitations/public/', 'methods' => ['GET', 'POST']],
        ['path' => '^/api/dev/demo-register$', 'methods' => ['POST']],
    ];

    /**
     * Le matcher runtime du neutraliseur : une requête est-elle une route publique du
     * firewall `api` ? Les `path` sont des regexes `access_control`, appliquées EXACTEMENT
     * comme le matcher Symfony ({@see \Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher})
     * sur `access_control` : chemin `rawurldecode()` d'abord, puis délimiteur `{}` et flag `s`
     * (aucun des motifs ne contient d'accolade). Sans ce décodage, le neutraliseur divergerait du
     * firewall — soit en ne reconnaissant plus une route publique écrite encodée, soit en élargissant
     * une route protégée via un `%2F` que `[^/]+` laisserait passer avant décodage.
     */
    public static function matches(string $pathInfo, string $method): bool
    {
        $method = strtoupper($method);
        $pathInfo = rawurldecode($pathInfo);

        foreach (self::RULES as $rule) {
            if (1 !== preg_match('{' . $rule['path'] . '}s', $pathInfo)) {
                continue;
            }

            if ([] === $rule['methods'] || \in_array($method, $rule['methods'], true)) {
                return true;
            }
        }

        return false;
    }
}
