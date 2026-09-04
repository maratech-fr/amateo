<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Socle commun aux contexts fonctionnels (Behat).
 *
 * Ces scénarios parlent HTTP à la stack qui tourne (même cible que les smokes :
 * l'API derrière nginx), afin de prouver le rail réel de bout en bout. Ils
 * n'ouvrent aucun noyau Symfony en mémoire : le jeton, les lectures/écritures
 * base et la garde bac-à-sable passent par `bin/console`, exactement comme le
 * faisait le script shell qu'ils remplacent.
 */
abstract class BaseContext implements Context
{
    protected HttpClientInterface $client;

    private readonly string $apiBase;

    private readonly string $projectDir;

    public function __construct()
    {
        $this->apiBase = rtrim((string) (getenv('BEHAT_API_BASE') ?: 'http://nginx/api'), '/') . '/';
        $this->projectDir = \dirname(__DIR__, 2);
        $this->client = HttpClient::create(['base_uri' => $this->apiBase]);
    }

    /**
     * Garde fail-closed, jumelle de backend/scripts/lib/sandbox-guard.sh : ces
     * scénarios MUTENT la base (création + génération d'un planning). On refuse
     * toute cible autre que le bac à sable amateo_dev ou une base *_test — jamais
     * la base de jeu du fondateur (amateo_local) ni la prod.
     */
    #[BeforeScenario]
    public function guardSandbox(): void
    {
        $db = $this->dbalScalar('SELECT current_database() AS behatval');

        if ('' === $db) {
            throw new RuntimeException('sandbox-guard: cible non résolue — la stack est-elle démarrée (make start) ? Refus par précaution (fail-closed).');
        }

        if ('amateo_dev' !== $db && !str_ends_with($db, '_test')) {
            throw new RuntimeException(\sprintf('sandbox-guard: cible refusée « %s » — seuls le bac à sable amateo_dev et les bases *_test sont permis (jamais amateo_local ni la prod). Reviens au bac à sable avec « make sandbox ».', $db));
        }
    }

    /**
     * Forge un jeton de développement pour l'email donné, comme le smoke le
     * faisait via `lexik:jwt:generate-token`.
     */
    protected function mintToken(string $email): string
    {
        $token = trim($this->console([
            'php', 'bin/console', 'lexik:jwt:generate-token',
            $email, '--ttl=31536000', '--user-class=App\\Entity\\User',
        ]));

        if (1 !== preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token)) {
            throw new RuntimeException(\sprintf('impossible de forger un jeton de développement pour %s', $email));
        }

        return $token;
    }

    /**
     * Lit une valeur scalaire en base. La connexion admin (amateo_owner)
     * traverse la RLS — indispensable pour les lectures/écritures multi-tenant
     * de mise en place et de restauration.
     */
    protected function dbalScalar(string $sql, bool $admin = false): string
    {
        $args = ['php', 'bin/console', 'dbal:run-sql'];
        if ($admin) {
            $args[] = '--connection';
            $args[] = 'admin';
        }
        $args[] = $sql;

        foreach (explode("\n", $this->console($args)) as $line) {
            $value = trim($line);
            if ('' === $value || 'behatval' === $value) {
                continue;
            }
            if (1 === preg_match('/^-+$/', $value)) {
                continue;
            }

            return $value;
        }

        return '';
    }

    protected function dbalExec(string $sql, bool $admin = false): void
    {
        $args = ['php', 'bin/console', 'dbal:run-sql'];
        if ($admin) {
            $args[] = '--connection';
            $args[] = 'admin';
        }
        $args[] = $sql;

        $this->console($args);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, json: array<mixed>}
     */
    protected function apiGet(string $path, string $token, array $headers = []): array
    {
        return $this->decode($this->client->request('GET', ltrim($path, '/'), [
            'headers' => $this->authHeaders($token, $headers),
        ]));
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     *
     * @return array{status: int, json: array<mixed>}
     */
    protected function apiPost(string $path, array $body, string $token, array $headers = []): array
    {
        $options = ['headers' => $this->authHeaders($token, $headers)];
        if ([] !== $body) {
            $options['json'] = $body;
        }

        return $this->decode($this->client->request('POST', ltrim($path, '/'), $options));
    }

    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers
     *
     * @return array{status: int, json: array<mixed>}
     */
    protected function apiPut(string $path, array $body, string $token, array $headers = []): array
    {
        $options = ['headers' => $this->authHeaders($token, $headers)];
        if ([] !== $body) {
            $options['json'] = $body;
        }

        return $this->decode($this->client->request('PUT', ltrim($path, '/'), $options));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, json: array<mixed>}
     */
    protected function apiDelete(string $path, string $token, array $headers = []): array
    {
        return $this->decode($this->client->request('DELETE', ltrim($path, '/'), [
            'headers' => $this->authHeaders($token, $headers),
        ]));
    }

    /**
     * Lecture publique SANS jeton — le chemin non authentifié (page publique de
     * vœux). Aucun en-tête Authorization n'est posé : le token dans l'URL EST
     * l'identité, jamais le JWT.
     *
     * @return array{status: int, json: array<mixed>}
     */
    protected function publicGet(string $path): array
    {
        return $this->decode($this->client->request('GET', ltrim($path, '/'), [
            'headers' => ['Accept' => 'application/ld+json'],
        ]));
    }

    /**
     * Écriture publique SANS jeton — le seul chemin d'écriture /api non
     * authentifié (soumission d'un vœu, inscription d'un club). On ne « répare »
     * jamais l'appel avec un Bearer.
     *
     * @param array<mixed> $body
     *
     * @return array{status: int, json: array<mixed>, headers: array<string, list<string>>}
     */
    protected function publicPost(string $path, array $body): array
    {
        $options = ['headers' => ['Accept' => 'application/ld+json']];
        if ([] !== $body) {
            $options['json'] = $body;
        }

        return $this->decodeWithHeaders($this->client->request('POST', ltrim($path, '/'), $options));
    }

    /**
     * Requête HTTP brute vers une URL ABSOLUE (hors API — ex. le webmail Mailpit,
     * d'où l'inscription retire son lien de vérification). base_uri est ignoré
     * quand l'URL est absolue.
     *
     * @return array{status: int, json: array<mixed>, headers: array<string, list<string>>}
     */
    protected function httpGet(string $url): array
    {
        return $this->decodeWithHeaders($this->client->request('GET', $url));
    }

    /**
     * @param array<string> $args
     */
    private function console(array $args): string
    {
        $process = new Process($args, $this->projectDir, ['APP_ENV' => 'dev']);
        $process->setTimeout(120.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(\sprintf("échec de « %s » :\n%s", implode(' ', $args), $process->getErrorOutput() ?: $process->getOutput()));
        }

        return $process->getOutput();
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function authHeaders(string $token, array $extra): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/ld+json'] + $extra;
    }

    /**
     * @return array{status: int, json: array<mixed>}
     */
    private function decode(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $raw = $response->getContent(false);
        $json = '' === $raw ? [] : json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return ['status' => $status, 'json' => \is_array($json) ? $json : []];
    }

    /**
     * Comme decode(), mais rend aussi les en-têtes de réponse — indispensable
     * pour lire le cookie httpOnly BEARER que /register/verify pose (ce n'est pas
     * un navigateur : on le récupère dans Set-Cookie, exactement comme le smoke).
     *
     * @return array{status: int, json: array<mixed>, headers: array<string, list<string>>}
     */
    private function decodeWithHeaders(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $raw = $response->getContent(false);
        $decoded = '' === $raw ? [] : json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return [
            'status' => $status,
            'json' => \is_array($decoded) ? $decoded : [],
            'headers' => $response->getHeaders(false),
        ];
    }
}
