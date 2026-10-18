<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * La COUTURE sortante de la « mise en prod programmée » : tout ce qui part vers l'API GitHub
 * (console superadmin). Un geste et un seul — déclencher `deploy.yml` à l'heure dite — plus les
 * lectures qui le préparent (tags déployables, CI verte) et le suivent (statut du run).
 *
 * Durcissement (même posture que {@see Basketball\FfbbApiClient}) :
 *  - l'HÔTE est une CONSTANTE ({@see self::API_HOST}), jamais dérivé d'une entrée utilisateur ;
 *  - seuls les tags `^v` sont des versions déployables (le CLIENT filtre) ;
 *  - la version demandée ne réécrit JAMAIS l'URL : elle part dans le CORPS JSON du dispatch ;
 *  - redirections coupées, timeout serré ;
 *  - le jeton voyage en en-tête `Authorization: Bearer …`, jamais dans une URL, un corps ni un
 *    message — et {@see GitHubDeployException} est expurgée par construction.
 *
 * Jeton ou dépôt absent → le client est INERTE ({@see self::isConfigured()} faux) et n'émet
 * aucune requête ; la console refuse alors la programmation (422).
 */
final class GitHubDeployClient
{
    public const string API_HOST = 'https://api.github.com';

    private const string API_VERSION = '2022-11-28';

    private const float TIMEOUT = 10.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $deployToken,
        private readonly string $deployRepository,
    ) {}

    public function isConfigured(): bool
    {
        return '' !== $this->deployToken && '' !== $this->deployRepository;
    }

    /**
     * Les tags DÉPLOYABLES (`^v`), dans l'ordre rendu par GitHub (le plus récent d'abord).
     * Le bruit (`main`, `sha-…`, `2.1.0`) est écarté par le client.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $names = [];
        foreach ($this->rawTags() as $tag) {
            $name = $tag['name'] ?? null;
            if (\is_string($name) && str_starts_with($name, 'v')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** La CI est verte quand le dernier run `ci.yml` du head_sha du tag a conclu `success`. */
    public function isCiGreen(string $version): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }
        $sha = $this->resolveSha($version);
        if (null === $sha) {
            return false;
        }

        $response = $this->request('GET', $this->repoPath('/actions/workflows/ci.yml/runs'), [
            'query' => ['head_sha' => $sha, 'per_page' => 1],
        ]);
        $latest = $this->firstWorkflowRun($response);

        return null !== $latest && 'success' === ($latest['conclusion'] ?? null);
    }

    /**
     * Déclenche le workflow de déploiement sur `main`, en lui passant la version à déployer et
     * l'instant (ISO-8601 STRICT) auquel la maintenance doit annoncer un retour.
     */
    public function dispatchDeploy(string $version, DateTimeImmutable $maintenanceUntil): void
    {
        // La version reste CONFINÉE au corps JSON — jamais dans l'URL (défense en profondeur
        // contre une version forgée qui tenterait de réécrire l'hôte ou le chemin).
        $this->request('POST', $this->repoPath('/actions/workflows/deploy.yml/dispatches'), [
            'json' => [
                'ref' => 'main',
                'inputs' => [
                    'version' => $version,
                    'maintenance_until' => $maintenanceUntil->format(DateTimeInterface::ATOM),
                ],
            ],
        ]);
    }

    /** L'id du dernier run `deploy.yml` (celui que le dispatch vient de créer), ou null. */
    public function latestDeployRunId(string $version): ?int
    {
        if (!$this->isConfigured()) {
            return null;
        }

        // `$version` ne sert pas à construire l'URL (aucune dérivation) : le run le plus récent
        // du workflow de déploiement EST celui qu'on vient de dispatcher.
        unset($version);
        $response = $this->request('GET', $this->repoPath('/actions/workflows/deploy.yml/runs'), [
            'query' => ['event' => 'workflow_dispatch', 'per_page' => 1],
        ]);
        $latest = $this->firstWorkflowRun($response);
        $id = null === $latest ? null : ($latest['id'] ?? null);

        return \is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    /** La conclusion d'un run (`success`/`failure`/…), ou null tant qu'il n'est pas terminé. */
    public function runConclusion(int $runId): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $response = $this->request('GET', $this->repoPath('/actions/runs/' . $runId));
        $conclusion = $response->toArray()['conclusion'] ?? null;

        return \is_string($conclusion) ? $conclusion : null;
    }

    private function resolveSha(string $version): ?string
    {
        foreach ($this->rawTags() as $tag) {
            if (($tag['name'] ?? null) === $version) {
                $sha = $tag['commit']['sha'] ?? null;

                return \is_string($sha) ? $sha : null;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function rawTags(): array
    {
        $response = $this->request('GET', $this->repoPath('/tags'));

        return array_values(array_filter($response->toArray(), 'is_array'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function firstWorkflowRun(ResponseInterface $response): ?array
    {
        $runs = $response->toArray()['workflow_runs'] ?? null;
        if (!\is_array($runs)) {
            return null;
        }
        $first = $runs[0] ?? null;

        return \is_array($first) ? $first : null;
    }

    private function repoPath(string $suffix): string
    {
        return '/repos/' . $this->deployRepository . $suffix;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $options['headers'] = [
            'Authorization' => 'Bearer ' . $this->deployToken,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
            ...($options['headers'] ?? []),
        ];
        $options['timeout'] = self::TIMEOUT;
        $options['max_duration'] = self::TIMEOUT;
        $options['max_redirects'] = 0;

        $response = $this->httpClient->request($method, self::API_HOST . $path, $options);

        // getStatusCode() ne lève pas sur 4xx/5xx (contrairement à toArray()) : on vérifie
        // nous-mêmes et on lève notre propre exception EXPURGÉE, avant toute lecture du corps —
        // aucune exception du transport portant les options (donc le jeton) n'est créée.
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw GitHubDeployException::fromResponse($method, $path, $status);
        }

        return $response;
    }
}
