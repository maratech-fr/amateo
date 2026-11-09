<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * La COUTURE sortante de la « mise en prod programmée » : tout ce qui part vers l'API GitHub
 * (console superadmin). Un geste et un seul — déclencher `deploy.yml` à l'heure dite — plus les
 * lectures qui le préparent (tags déployables, CI verte, dernier run connu) et le suivent (run
 * retrouvé par date de création, conclusion, jobs).
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

    /** Runs lus par recherche : notre run et, au pire, quelques dispatches tiers de la même fenêtre. */
    private const int RUN_LOOKUP_PAGE = 10;

    /** Jobs lus par run : `deploy.yml` en compte trois. */
    private const int JOBS_PAGE = 50;

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

    /**
     * La CI est verte quand le dernier run `ci.yml` du head_sha du tag a conclu `success`.
     *
     * Sur un commit de `main`, ce run est ALLÉGÉ : il dit « les images prod se construisent
     * (Trivy CRITICAL), la perf tient, lint/secrets/semgrep/audit sont verts » — les suites
     * (bloquants, complète, e2e, Behat, sémantique moteur) ont été prouvées par la PR sur l'arbre
     * mergé (branche à jour obligatoire + squash). Un run non conclu (runner éteint) n'est pas vert.
     */
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

    /**
     * Le dernier run `workflow_dispatch` de `deploy.yml` sur `main` — le SNAPSHOT pris AVANT le
     * dispatch : son `created_at` (horloge GitHub) devient la borne basse de la recherche du run
     * que le dispatch va créer. Null si aucun run n'est connu.
     *
     * @return array{id: int, createdAt: DateTimeImmutable}|null
     */
    public function latestDeployRun(): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $response = $this->request('GET', $this->repoPath('/actions/workflows/deploy.yml/runs'), [
            'query' => ['event' => 'workflow_dispatch', 'branch' => 'main', 'per_page' => 1],
        ]);
        $latest = $this->firstWorkflowRun($response);

        return null === $latest ? null : $this->runIdentity($latest);
    }

    /**
     * Le run créé par NOTRE dispatch : le plus ANCIEN des runs `workflow_dispatch` de `main` créés
     * STRICTEMENT après `$createdAfter`, `$excludeRunId` écarté. Null si aucun n'est encore listé
     * — jamais un repli sur le run d'avant.
     */
    public function findDispatchRunAfter(DateTimeImmutable $createdAfter, ?int $excludeRunId): ?int
    {
        return $this->dispatchRunsAfter($createdAfter, $excludeRunId)[0] ?? null;
    }

    /**
     * Tous les candidats de {@see self::findDispatchRunAfter()}, du plus ancien au plus récent :
     * plus d'un candidat veut dire qu'un autre dispatch a démarré dans la même fenêtre.
     *
     * La borne est formatée ICI (UTC, `…Z`), jamais reprise d'une entrée : la requête GitHub
     * (`created=>=…`) l'inclut, le filtre strict côté client exclut le run qui l'a posée.
     *
     * @return list<int>
     */
    public function dispatchRunsAfter(DateTimeImmutable $createdAfter, ?int $excludeRunId): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $bound = $createdAfter->setTimezone(new DateTimeZone('UTC'));
        $response = $this->request('GET', $this->repoPath('/actions/workflows/deploy.yml/runs'), [
            'query' => [
                'event' => 'workflow_dispatch',
                'branch' => 'main',
                'created' => '>=' . $bound->format('Y-m-d\TH:i:s\Z'),
                'per_page' => self::RUN_LOOKUP_PAGE,
            ],
        ]);
        $runs = $response->toArray()['workflow_runs'] ?? null;
        if (!\is_array($runs)) {
            return [];
        }

        $candidates = [];
        foreach ($runs as $run) {
            $identity = \is_array($run) ? $this->runIdentity($run) : null;
            if (null === $identity || $identity['id'] === $excludeRunId || $identity['createdAt'] <= $createdAfter) {
                continue;
            }
            $candidates[] = $identity;
        }
        usort($candidates, static fn (array $a, array $b): int => $a['createdAt'] <=> $b['createdAt'] ?: $a['id'] <=> $b['id']);

        return array_map(static fn (array $candidate): int => $candidate['id'], $candidates);
    }

    /**
     * Les jobs d'un run, réduits à ce que lit le verdict ({@see DeployRunOutcome}) : nom, conclusion
     * et, pour chaque step, nom et conclusion.
     *
     * @return list<array{name: string, conclusion: ?string, steps: list<array{name: string, conclusion: ?string}>}>
     */
    public function runJobs(int $runId): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $response = $this->request('GET', $this->repoPath('/actions/runs/' . $runId . '/jobs'), [
            'query' => ['per_page' => self::JOBS_PAGE],
        ]);
        $jobs = $response->toArray()['jobs'] ?? null;
        if (!\is_array($jobs)) {
            return [];
        }

        $read = [];
        foreach ($jobs as $job) {
            if (!\is_array($job) || !\is_string($job['name'] ?? null)) {
                continue;
            }
            $steps = [];
            foreach (\is_array($job['steps'] ?? null) ? $job['steps'] : [] as $step) {
                if (\is_array($step) && \is_string($step['name'] ?? null)) {
                    $steps[] = ['name' => $step['name'], 'conclusion' => $this->conclusionOf($step)];
                }
            }
            $read[] = ['name' => $job['name'], 'conclusion' => $this->conclusionOf($job), 'steps' => $steps];
        }

        return $read;
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

    /**
     * Id et date de création d'un run tel que GitHub le rend, ou null s'il est illisible.
     *
     * @param array<mixed> $run
     *
     * @return array{id: int, createdAt: DateTimeImmutable}|null
     */
    private function runIdentity(array $run): ?array
    {
        $id = $run['id'] ?? null;
        $id = \is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
        $createdAt = $run['created_at'] ?? null;
        if (null === $id || !\is_string($createdAt)) {
            return null;
        }

        try {
            return ['id' => $id, 'createdAt' => new DateTimeImmutable($createdAt)];
        } catch (Exception) {
            return null;
        }
    }

    /** @param array<mixed> $item un job ou un step */
    private function conclusionOf(array $item): ?string
    {
        $conclusion = $item['conclusion'] ?? null;

        return \is_string($conclusion) ? $conclusion : null;
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
        // Une panne de TRANSPORT (DNS, connexion refusée, timeout) surface ICI, sur getStatusCode() :
        // on la ré-emballe dans notre exception EXPURGÉE (le message brut du transport, qui pourrait
        // porter l'hôte/port, ne ressort jamais) — « injoignable », pas « a répondu N ».
        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            throw GitHubDeployException::fromTransport($method, $path);
        }
        if ($status < 200 || $status >= 300) {
            throw GitHubDeployException::fromResponse($method, $path, $status);
        }

        return $response;
    }
}
